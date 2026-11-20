<?php

declare(strict_types=1);

namespace Bruno;

use PDOException;

require __DIR__ . '/../src/bootstrap.php';

header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
session_start([
    'use_strict_mode' => 1,
    'cookie_httponly' => true,
    'cookie_secure' => true,
    'cookie_samesite' => 'Lax',
]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

$anki = new AnkiBridge($config['python'], $config['data_dir']);
$gateway = new Gateway($config['ai_key'], $config['ai_model']);
$account = $_SESSION['account'] ?? null;
$csrf = $_SESSION['csrf'];
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$verb = '';
$view = 'home';
$deckId = 0;
$settings = ['ai' => $config['ai_key'] !== '', 'fal' => $config['fal_key'] !== ''];
$errorMessage = '';
$data = [];

/**
 * Only redirect to an internal route after a successful form submission.
 */
function go(string $view = 'home', int $deck = 0): never
{
    $query = $view === 'home' ? '' : '?' . http_build_query(['view' => $view] + ($deck ? ['deck' => $deck] : []));
    header('Location: /' . $query, response_code: 303);
    exit;
}

try {
    $verb = input($_POST, key: 'verb');
    $view = input($_GET, key: 'view') ?: 'home';
    $deckId = (int) input($_POST + $_GET, key: 'deck');
    $request = ['owner' => $account['id'] ?? '', 'deck' => $deckId];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8 * 1024 * 1024) {
            throw new AppException('Die Datei ist zu groß. Bitte höchstens 8 MB hochladen.', 413);
        }
        if (!hash_equals($csrf, input($_POST, key: 'csrf'))) {
            throw new AppException('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.', 403);
        }

        if ($verb === 'login' && $account === null) {
            $credentials = new Credentials($config['data_dir']);
            $authenticated = $credentials->authenticate(
                input($_POST, key: 'email', maximum: 254),
                is_string($_POST['password'] ?? null) ? $_POST['password'] : '',
                $_SERVER['REMOTE_ADDR'] ?? '',
            );
            if (!$authenticated) {
                throw new AppException('E-Mail-Adresse oder Passwort stimmt nicht.', 401);
            }
            session_regenerate_id(delete_old_session: true);
            $_SESSION = ['account' => $authenticated, 'reviewSecret' => bin2hex(random_bytes(32))];
            go();
        }
        if ($account === null) {
            throw new AppException('Bitte erneut anmelden.', 401);
        }

        if ($verb === 'logout') {
            $_SESSION = [];
            session_regenerate_id(delete_old_session: true);
            go();
        }

        if ($verb === 'generate') {
            $owned = $deckId ? $anki->call('lesson', $request) : null;
            $title = $owned['name'] ?? input($_POST, key: 'title', maximum: 100);
            $focus = input($_POST, key: 'focus', maximum: 2000);
            $type = input($_POST, key: 'type');
            if ($title === '' || (!$owned && (str_contains($title, '::') || preg_match('/[[:cntrl:]]/', $title)))) {
                throw new AppException('Bitte einen flachen Stapeltitel ohne Sondertrennzeichen eingeben.', 422);
            }
            if (!in_array($type, ['topic', 'vocabulary', 'test'], strict: true)) {
                throw new AppException('Bitte einen gültigen Kartentyp auswählen.', 422);
            }
            if (isset($owned['vocabulary']) && $owned['vocabulary'] !== ($type === 'vocabulary')) {
                throw new AppException('Der Kartentyp muss zum bestehenden Stapel passen.', 422);
            }
            if (array_intersect($owned['jobs'] ?? [], ['queued', 'submitting', 'working'])) {
                throw new AppException('Bitte warten, bis die Medien des Stapels fertig sind.', 409);
            }
            $document = upload('document', [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'text/plain' => 'txt',
            ], 8 * 1024 * 1024);
            if ($type === 'test' && $document === null) {
                throw new AppException('Bitte einen anonymisierten Test hochladen.', 422);
            }
            if ($document !== null && input($_POST, key: 'consent') !== 'yes') {
                throw new AppException('Bitte die Zustimmung zur Verarbeitung des anonymisierten Dokuments bestätigen.', 422);
            }
            if ($type === 'vocabulary' && (!$settings['fal'] || input($_POST, key: 'pictureConsent') !== 'yes')) {
                throw new AppException('Vokabelstapel benötigen den fal.ai-Zugang und die Freigabe für ein Bild je Karte.', 422);
            }

            $content = $gateway->create($title, $focus, $type, $document, $owned['text'] ?? '');
            $result = $anki->call('generate', $request + $content + [
                'title' => $title,
                'focus' => $focus,
                'vocabulary' => $type === 'vocabulary',
                'document' => $document === null ? null : $document + [
                    'type' => $type === 'test' ? 'Test' : 'Lernvorlage',
                ],
            ]);
            $_SESSION['flash'] = $result['added'] . ' neue Karte(n) gespeichert. Bestehende Wiederholungen bleiben erhalten.';
            go('lesson', $result['id']);
        }

        if ($verb === 'queue') {
            if (!$settings['fal']) {
                throw new AppException('fal.ai ist noch nicht eingerichtet.', 503);
            }
            $anki->call('queue', $request + ['kind' => input($_POST, key: 'kind')]);
            $_SESSION['flash'] = 'Medium beauftragt. Die Generierung läuft im Hintergrund.';
            go('lesson', $deckId);
        }

        if ($verb === 'retry-image') {
            if (!$settings['fal']) {
                throw new AppException('fal.ai ist noch nicht eingerichtet.', 503);
            }
            $anki->call('retry-image', $request + ['card' => (int) input($_POST, key: 'card')]);
            $_SESSION['flash'] = 'Merkbild erneut beauftragt.';
            go('study', $deckId);
        }

        if ($verb === 'answer') {
            $values = [
                'card' => (int) input($_POST, key: 'card'),
                'reps' => (int) input($_POST, key: 'reps'),
                'modified' => (int) input($_POST, key: 'modified'),
                'shown' => (int) input($_POST, key: 'shown'),
            ];
            $signature = hash_hmac('sha256', implode(':', [$deckId, ...$values]), $_SESSION['reviewSecret']);
            $rating = (int) input($_POST, key: 'rating');
            if (!hash_equals($signature, input($_POST, key: 'signature')) || !in_array($rating, [1, 2, 3, 4], strict: true)) {
                throw new AppException('Bitte die Lernrunde neu öffnen.', 422);
            }
            $anki->call('answer', $request + $values + ['rating' => $rating, 'elapsed' => time() - $values['shown']]);
            go('study', $deckId);
        }

        if ($verb === 'grade') {
            $card = $anki->call('study', $request)['card'];
            if (
                !$card || $card['id'] !== (int) input($_POST, key: 'card') || $card['reps'] !== (int) input($_POST, key: 'reps')
                || $card['modified'] !== (int) input($_POST, key: 'modified')
            ) {
                throw new AppException('Diese Karte ist nicht mehr aktuell.', 409);
            }
            if ($card['question'] === '' || $card['answer'] === '') {
                throw new AppException('Bei dieser Karte kannst du deine Antwort nur selbst bewerten.', 422);
            }
            $answer = input($_POST, key: 'answer', maximum: 1500);
            $voice = upload('voice', ['audio/wav' => 'wav'], limit: 900_000);
            if ($answer === '' && $voice === null) {
                throw new AppException('Bitte eine Antwort schreiben oder aufnehmen.', 422);
            }
            $feedback = $gateway->grade($card['question'], $card['answer'], $answer, $voice);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($feedback, JSON_THROW_ON_ERROR);
            exit;
        }

        throw new AppException('Unbekannte Anfrage.', 400);
    }

    if ($account === null) {
        if ($view === 'media') {
            throw new AppException('Bitte anmelden.', 401);
        }
        $view = 'login';
    }

    if ($view === 'media') {
        $file = input($_GET, key: 'file', maximum: 255);
        $media = $anki->call('media', $request + ['file' => $file, 'card' => (int) input($_GET, key: 'card')]);
        session_write_close();
        $path = $media['path'];
        if (!is_file($path)) {
            throw new AppException('Datei nicht gefunden.', 404);
        }
        $length = filesize($path);
        $stream = fopen($path, mode: 'rb');
        if ($length === false || $stream === false) {
            throw new AppException('Datei nicht gefunden.', 404);
        }
        $start = 0;
        $end = $length - 1;
        if (preg_match('/^bytes=(\d*)-(\d*)$/D', $_SERVER['HTTP_RANGE'] ?? '', $range) && ($range[1] !== '' || $range[2] !== '')) {
            $start = $range[1] === '' ? max(0, $length - (int) $range[2]) : (int) $range[1];
            $end = $range[1] !== '' && $range[2] !== '' ? min($end, (int) $range[2]) : $end;
            if ($start > $end) {
                header('Content-Range: bytes */' . $length, response_code: 416);
                exit;
            }
            header("Content-Range: bytes {$start}-{$end}/{$length}", response_code: 206);
        }
        $remaining = $end - $start + 1;
        header('Content-Type: ' . $media['mime']);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $remaining);
        if ($media['attachment']) {
            header('Content-Disposition: attachment; filename="' . $file . '"');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            fseek($stream, $start);
            while ($remaining > 0 && !feof($stream)) {
                $part = fread($stream, min(65_536, $remaining));
                if ($part === false || $part === '') {
                    break;
                }
                echo $part;
                $remaining -= strlen($part);
            }
        }
        fclose($stream);
        exit;
    }

    $data = match ($view) {
        'login' => [],
        'home', 'create', 'learn' => ['decks' => $anki->call('list', $request)],
        'review' => ['decks' => array_filter($anki->call('list', $request), static fn (array $deck): bool => $deck['due'] > 0)],
        'lesson' => ['lesson' => $anki->call('lesson', $request)],
        'study' => $anki->call('study', $request),
        'backups' => ['backups' => (new BackupHistory($config['data_dir']))->entries()],
        default => throw new AppException('Diese Seite gibt es nicht.', 404),
    };
} catch (AppException | PDOException $error) {
    $errorMessage = $error instanceof AppException ? $error->getMessage() : 'Die Anmeldung ist momentan nicht verfügbar.';
    http_response_code($error instanceof AppException ? ($error->getCode() ?: 400) : 503);
    if ($verb === 'grade') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $errorMessage], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($view === 'media') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $errorMessage;
        exit;
    }
    if ($account !== null && $verb === 'generate') {
        $view = 'create';
        try {
            $data['decks'] = $anki->call('list', $request);
        } catch (AppException) {
            $data['decks'] = [];
        }
    }
    if ($account === null || $verb !== 'generate') {
        $view = $account === null ? 'login' : 'error';
    }
}

require __DIR__ . '/../views/app.php';
