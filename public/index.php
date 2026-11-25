<?php

declare(strict_types=1);

namespace Bruno;

use PDOException;

require __DIR__ . '/../src/bootstrap.php';

const REVIEW_BLOCK_SIZE = 4;
const REVIEW_CYCLE_BLOCKS = 3;
const REVIEW_RATINGS = [1 => 'Nochmal', 2 => 'Schwer', 3 => 'Gut', 4 => 'Einfach'];
const PENDING_MEDIA_STATES = ['queued', 'submitting', 'working'];
const MEDIA_STAGE_LABELS = [
    'queued' => 'Wartet auf Start.',
    'submitting' => 'Wird erstellt …',
    'working' => 'In Bearbeitung …',
    'failed' => 'Generierung fehlgeschlagen.',
    'unknown' => 'Auftragsstatus unklar. Kein neuer Auftrag.',
];
const LOGIN_COOKIE = '__Host-bruno-login';
const LOGIN_LIFETIME = 30 * 24 * 60 * 60;

header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$sessionDirectory = $config['data_dir'] . '/sessions';
$sessionDirectoryReady = is_dir($sessionDirectory)
    || mkdir($sessionDirectory, permissions: 0o700, recursive: true)
    || is_dir($sessionDirectory);
if (!$sessionDirectoryReady || !session_start([
    'save_handler' => 'files',
    'save_path' => $sessionDirectory,
    'gc_probability' => 1,
    'gc_divisor' => 100,
    'gc_maxlifetime' => LOGIN_LIFETIME,
    'use_strict_mode' => 1,
    'cookie_lifetime' => LOGIN_LIFETIME,
    'cookie_httponly' => true,
    'cookie_secure' => true,
    'cookie_samesite' => 'Lax',
])) {
    http_response_code(503);
    exit('Die Anmeldung ist momentan nicht verfügbar.');
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

$anki = new AnkiBridge($config['python'], $config['data_dir']);
$sync = new AnkiSync($anki, $config['data_dir'], $config['sync_endpoint'] ?? '');
$gateway = new Gateway($config['ai_key'], $config['ai_model']);
$backupHistory = new BackupHistory($config['data_dir']);
$account = null;
$credentials = null;
$csrf = $_SESSION['csrf'];
$flash = $_SESSION['flash'] ?? '';
$verb = '';
$ajaxSync = false;
$ajaxReview = false;
$view = 'home';
$deckId = 0;
$settings = ['ai' => $config['ai_key'] !== '', 'fal' => $config['fal_key'] !== '', 'sync' => $sync->isConfigured()];
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

/**
 * Prevent submitted names from creating a deck hierarchy.
 */
$readDeckTitle = static function (array $values): string {
    $title = input($values, key: 'title', maximum: 100);
    if ($title === '' || str_contains($title, '::') || preg_match('/[[:cntrl:]]/', $title)) {
        throw new AppException('Bitte einen flachen Stapeltitel ohne Sondertrennzeichen eingeben.', 422);
    }

    return $title;
};

/**
 * Return the data for an asynchronous request without rendering the page.
 *
 * @param array<string, mixed> $payload
 */
function respond(array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Scope the bearer token to this HTTPS host and keep it inaccessible to scripts.
 */
$setLoginCookie = static function (#[\SensitiveParameter] string $token, int $expiresAt): void {
    setcookie(LOGIN_COOKIE, $token, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
};

/**
 * Discard this browser's private workspace after the login has been revoked.
 */
$endBrowserLogin = static function () use ($setLoginCookie): never {
    $setLoginCookie('', expiresAt: 1);
    $_SESSION = [];
    session_regenerate_id(delete_old_session: true);
    go();
};

/**
 * Reverse the presentation for one randomly placed block without changing the scheduled card.
 *
 * @param array{owner: string, deck: int} $request
 * @return array<string, mixed>
 */
function study(AnkiBridge $anki, array $request, bool $daily = false): array
{
    $data = $anki->call($daily ? 'daily-study' : 'study', $request);
    $data['reversed'] = false;
    $data['hintUsed'] = false;
    $data['hintLetter'] = '';
    $data['questionLanguage'] = 'de';
    $data['answerLanguage'] = 'de';
    if ($data['card'] === null) {
        return $data;
    }
    $deck = $data['deck'];
    if (!$data['vocabulary']) {
        unset($_SESSION['reviewBlocks'][$deck]);
        return $data;
    }

    $_SESSION['reviewBlocks'][$deck] ??= [
        'answered' => 0,
        'reverseBlock' => random_int(0, REVIEW_CYCLE_BLOCKS - 1),
    ];
    $block = $_SESSION['reviewBlocks'][$deck];
    $swap = intdiv($block['answered'], REVIEW_BLOCK_SIZE) === $block['reverseBlock'];
    $card = $data['card'];
    $data['reversed'] = $swap !== $card['backFirst'];
    if ($swap) {
        $data['card']['question'] = $card['answer'];
        $data['card']['answer'] = $card['question'];
        $data['card']['questionMedia'] = $card['answerMedia'];
        $data['card']['answerMedia'] = $card['questionMedia'];
    }
    $languages = ['englisch' => 'en', 'english' => 'en', 'französisch' => 'fr', 'spanisch' => 'es', 'italienisch' => 'it', 'latein' => 'la'];
    $pattern = '/\b(' . implode('|', array_keys($languages)) . ')(?:e[nmrs]?)?\b/u';
    $context = mb_strtolower($data['name'] . ' ' . $data['focus']);
    $foreignLanguage = preg_match($pattern, $context, $language) === 1 ? $languages[$language[1]] : '';
    $data[$data['reversed'] ? 'questionLanguage' : 'answerLanguage'] = $foreignLanguage;
    $data['hintUsed'] = ($_SESSION['studyHints'][$deck][$card['id']] ?? null) === [$card['reps'], $card['modified']];
    if (preg_match('/\p{L}\p{M}*/u', $data['card']['answer'], $letter) === 1) {
        $data['hintLetter'] = $letter[0];
    }

    return $data;
}

try {
    $verb = input($_POST, key: 'verb');
    $isJson = ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json';
    $ajaxSync = $verb === 'sync-now' && $isJson;
    $ajaxReview = $verb === 'answer' && $isJson;
    $view = input($_GET, key: 'view') ?: 'home';
    if ($view !== 'media-status' && !$ajaxSync) {
        unset($_SESSION['flash']);
    }
    $deckId = (int) input($_POST + $_GET, key: 'deck');
    $cookie = $_COOKIE[LOGIN_COOKIE] ?? null;
    $loginToken = is_string($cookie) ? $cookie : '';
    $legacyAccount = $_SESSION['account'] ?? null;
    $legacyExpiry = $_SESSION['expiresAt'] ?? 0;
    $previousOwner = $_SESSION['owner'] ?? $legacyAccount['id'] ?? null;
    if ($cookie !== null) {
        $credentials = new Credentials($config['data_dir']);
        $account = $credentials->resumeLogin($loginToken);
        if (!$account instanceof Account) {
            $setLoginCookie('', expiresAt: 1);
        }
    }
    if ($cookie === null && isset($legacyAccount['passwordVersion']) && time() < $legacyExpiry) {
        $credentials = new Credentials($config['data_dir']);
        $account = $credentials->resume($legacyAccount['id'], $legacyAccount['passwordVersion']);
        if ($account instanceof Account) {
            $loginToken = $credentials->createLogin($account, $legacyExpiry);
            $setLoginCookie($loginToken, $legacyExpiry);
        }
    }
    unset($_SESSION['account'], $_SESSION['expiresAt']);
    if ($previousOwner !== $account?->id) {
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        $csrf = $_SESSION['csrf'];
        $flash = '';
        session_regenerate_id(delete_old_session: true);
    }
    if ($account instanceof Account) {
        $_SESSION['owner'] = $account->id;
        $_SESSION['reviewSecret'] ??= bin2hex(random_bytes(32));
    }
    $request = ['owner' => $account->id ?? '', 'deck' => $deckId];
    if ($view === 'media-status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$account instanceof Account) {
            throw new AppException('Bitte erneut anmelden.', 401);
        }
        $cardId = (int) input($_GET, key: 'card');
        session_write_close();
        $status = $anki->call('media-status', $request + ['card' => $cardId]);
        if (($status['file'] ?? '') !== '') {
            $status['url'] = '/?' . http_build_query(['view' => 'media', 'deck' => $deckId, 'card' => $cardId, 'file' => $status['file']]);
        }
        respond($status + [
            'pending' => in_array($status['state'], PENDING_MEDIA_STATES, strict: true),
            'label' => MEDIA_STAGE_LABELS[$status['state']] ?? '',
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8 * 1024 * 1024) {
            throw new AppException('Die Anfrage ist zu groß. Vorlagen dürfen zusammen höchstens 8 MB groß sein.', 413);
        }
        if (!hash_equals($csrf, input($_POST, key: 'csrf'))) {
            throw new AppException('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.', 403);
        }
        $password = $_POST['password'] ?? '';
        $password = is_string($password) ? $password : '';

        if ($verb === 'login' && !$account instanceof Account) {
            $credentials ??= new Credentials($config['data_dir']);
            $authenticated = $credentials->authenticate(
                input($_POST, key: 'email', maximum: 254),
                $password,
                $_SERVER['REMOTE_ADDR'] ?? '',
            );
            if (!$authenticated instanceof Account) {
                throw new AppException('E-Mail-Adresse oder Passwort stimmt nicht.', 401);
            }
            $expiresAt = time() + LOGIN_LIFETIME;
            $setLoginCookie($credentials->createLogin($authenticated, $expiresAt), $expiresAt);
            session_regenerate_id(delete_old_session: true);
            $_SESSION = ['owner' => $authenticated->id];
            go();
        }
        if (!$account instanceof Account) {
            throw new AppException('Bitte erneut anmelden.', 401);
        }

        if ($verb === 'logout') {
            $credentials->forgetLogin($loginToken);
            $endBrowserLogin();
        }

        if ($verb === 'save-profile') {
            $currentPassword = $_POST['current_password'] ?? '';
            $authenticated = $credentials->authenticate($account->email, is_string($currentPassword) ? $currentPassword : '', $_SERVER['REMOTE_ADDR'] ?? '');
            if ($authenticated?->id !== $account->id) {
                throw new AppException('Das aktuelle Passwort stimmt nicht.', 403);
            }
            $updated = $credentials->updateAccount($account->id, input($_POST, key: 'email', maximum: 254), $password);
            if ($password !== '') {
                $expiresAt = time() + LOGIN_LIFETIME;
                $setLoginCookie($credentials->createLogin($updated, $expiresAt), $expiresAt);
                $credentials->forgetLogin($loginToken);
            }
            session_regenerate_id(delete_old_session: true);
            $_SESSION['flash'] = 'Profil gespeichert.';
            go('profile');
        }

        if (in_array($verb, ['create-user', 'update-user', 'delete-user'], strict: true)) {
            if ($verb === 'create-user') {
                $credentials->createAccount(input($_POST, key: 'email', maximum: 254), $password);
                $_SESSION['flash'] = 'Benutzer angelegt.';
                go('users');
            }
            $userId = input($_POST, key: 'user', maximum: 32);
            if ($verb === 'delete-user') {
                $credentials->deleteAccount($userId);
                if ($userId === $account->id) {
                    $endBrowserLogin();
                }
                $_SESSION['flash'] = 'Benutzer gelöscht.';
                go('users');
            }
            if ($userId === $account->id) {
                go('profile');
            }
            $credentials->updateAccount($userId, input($_POST, key: 'email', maximum: 254), $password);
            $_SESSION['flash'] = 'Benutzer gespeichert.';
            go('users');
        }

        if ($verb === 'backup') {
            $anki->call('backup', ['manual' => true]);
            $_SESSION['flash'] = 'Backup erstellt.';
            go('backups');
        }

        if ($verb === 'delete-backup') {
            $backupHistory->delete(input($_POST, key: 'backup', maximum: 64));
            $_SESSION['flash'] = 'Backup gelöscht.';
            go('backups');
        }

        if ($verb === 'sync-now') {
            session_write_close();
            ignore_user_abort(enable: true);
            set_time_limit(seconds: 0);
            $result = $sync->run(manual: true);
            if ($ajaxSync) {
                respond($result);
            }
            go('sync');
        }

        if ($verb === 'sync-interval') {
            $minutes = filter_var(input($_POST, key: 'interval'), FILTER_VALIDATE_INT);
            $sync->setInterval($minutes === false ? 0 : $minutes);
            $_SESSION['flash'] = 'Synchronisationsintervall gespeichert.';
            go('sync');
        }

        if ($verb === 'rename-deck') {
            $anki->call('rename-deck', $request + ['title' => $readDeckTitle($_POST)]);
            $_SESSION['flash'] = 'Stapel umbenannt.';
            go('decks');
        }

        if ($verb === 'delete-deck') {
            $anki->call('delete-deck', $request);
            unset($_SESSION['reviewBlocks'][$deckId], $_SESSION['studyHints'][$deckId]);
            if (($_SESSION['deckDraft']['payload']['deck'] ?? null) === $deckId) {
                unset($_SESSION['deckDraft']);
            }
            $_SESSION['flash'] = 'Stapel gelöscht.';
            go('decks');
        }

        if ($verb === 'generate') {
            $cardCount = filter_var($_POST['card_count'] ?? Gateway::DEFAULT_CARD_COUNT, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => Gateway::MAX_CARD_COUNT],
            ]);
            if ($cardCount === false) {
                throw new AppException('Bitte eine Kartenanzahl zwischen 1 und ' . Gateway::MAX_CARD_COUNT . ' eingeben.', 422);
            }
            $owned = $deckId ? $anki->call('lesson', $request) : null;
            $title = $owned['name'] ?? $readDeckTitle($_POST);
            $focus = input($_POST, key: 'focus', maximum: 2000);
            $type = input($_POST, key: 'type');
            if (!in_array($type, ['topic', 'vocabulary', 'test'], strict: true)) {
                throw new AppException('Bitte einen gültigen Kartentyp auswählen.', 422);
            }
            if (isset($owned['vocabulary']) && $owned['vocabulary'] !== ($type === 'vocabulary')) {
                throw new AppException('Der Kartentyp muss zum bestehenden Stapel passen.', 422);
            }
            if (array_intersect($owned['jobs'] ?? [], ['queued', 'submitting', 'working'])) {
                throw new AppException('Bitte warten, bis die Medien des Stapels fertig sind.', 409);
            }
            if ($deckId === 0) {
                $anki->call('check-deck-title', $request + ['title' => $title]);
            }
            $documents = uploads('document', [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'text/plain' => 'txt',
            ], 8 * 1024 * 1024, maxFiles: 10);
            if ($type === 'test' && !$documents) {
                throw new AppException('Bitte einen anonymisierten Test hochladen.', 422);
            }
            $sources = [];
            foreach ($documents as $document) {
                $sources[] = [
                    'extension' => $document->extension,
                    'mime' => $document->mime,
                    'data' => base64_encode($document->contents()),
                    'type' => $type === 'test' ? 'Test' : 'Lernvorlage',
                ];
            }
            $previousLesson = $owned['text'] ?? '';
            $_SESSION['deckDraft'] = [
                'id' => bin2hex(random_bytes(16)),
                'generating' => true,
                'cardCount' => $cardCount,
                'type' => $type,
                'payload' => [
                    'deck' => $deckId,
                    'title' => $title,
                    'focus' => $focus,
                    'vocabulary' => $type === 'vocabulary',
                    'previousLesson' => $previousLesson,
                    'lesson' => $previousLesson,
                    'cards' => [],
                    'documents' => $sources,
                ],
            ];
            go('preview');
        }

        // Old approval forms did not explicitly confirm saving to Anki.
        if ($view === 'preview' && in_array($verb, ['approve-draft', 'save-draft'], strict: true)) {
            go('preview');
        }

        if (in_array($verb, ['generate-batch', 'save-preview', 'discard-draft'], strict: true)) {
            $draft = $_SESSION['deckDraft'] ?? null;
            if ($draft === null || !hash_equals($draft['id'], input($_POST, key: 'draft', maximum: 32))) {
                throw new AppException('Dieser Entwurf ist nicht mehr aktuell. Bitte die aktuelle Vorschau öffnen.', 409);
            }
            if ($verb === 'discard-draft') {
                unset($_SESSION['deckDraft']);
                go('create', $draft['payload']['deck']);
            }
            if ($verb === 'generate-batch') {
                $payload = $draft['payload'];
                $offset = count($payload['cards']);
                if (!$draft['generating'] || input($_POST, key: 'offset') !== (string) $offset) {
                    go('preview');
                }
                $batchSize = min($draft['cardCount'] - $offset, Gateway::CARD_BATCH_SIZE);
                $temporaryFiles = [];
                $documents = [];
                try {
                    foreach ($payload['documents'] as $source) {
                        $file = tmpfile();
                        if ($file === false) {
                            throw new AppException('Eine Vorlage konnte nicht vorbereitet werden.', 503);
                        }
                        $temporaryFiles[] = $file;
                        $contents = base64_decode($source['data'], strict: true);
                        if ($contents === false || fwrite($file, $contents) !== strlen($contents)) {
                            throw new AppException('Eine Vorlage konnte nicht vorbereitet werden.', 503);
                        }
                        $documents[] = new Upload(stream_get_meta_data($file)['uri'], $source['mime'], $source['extension']);
                    }
                    $content = $gateway->create(
                        title: $payload['title'],
                        focus: $payload['focus'],
                        type: $draft['type'],
                        documents: $documents,
                        previousLesson: $payload['lesson'],
                        cardCount: $batchSize,
                        previousCards: $payload['cards'],
                    );
                } finally {
                    foreach ($temporaryFiles as $file) {
                        fclose($file);
                    }
                }
                $payload['lesson'] = $content['lesson'];
                $payload['cards'] = array_merge($payload['cards'], $content['cards']);
                $draft['payload'] = $payload;
                $draft['generating'] = count($content['cards']) === $batchSize && count($payload['cards']) < $draft['cardCount'];
                $_SESSION['deckDraft'] = $draft;
                go('preview');
            }
            if ($draft['generating']) {
                throw new AppException('Bitte warten, bis die Vorschau fertig ist.', 409);
            }
            $result = $anki->call('generate', $draft['payload'] + ['owner' => $account->id]);
            unset($_SESSION['deckDraft']);
            $_SESSION['flash'] = $result['added'] . ' neue Karte(n) gespeichert. Bestehende Wiederholungen bleiben erhalten.';
            go('lesson', $result['id']);
        }

        if ($view === 'lesson' && $verb === 'queue') {
            $_SESSION['flash'] = 'Dieses Formular ist nicht mehr aktuell. Es wurde nichts beauftragt.';
            go('lesson', $deckId);
        }

        if ($verb === 'queue-video') {
            if (!$settings['fal']) {
                throw new AppException('fal.ai ist noch nicht eingerichtet.', 503);
            }
            $anki->call('queue-video', $request);
            $_SESSION['flash'] = 'Video beauftragt. Die Generierung läuft im Hintergrund.';
            go('lesson', $deckId);
        }

        if ($verb === 'retry-image') {
            if (!$settings['ai']) {
                throw new AppException('Der KI-Zugang ist noch nicht eingerichtet.', 503);
            }
            $anki->call('retry-image', $request + ['card' => (int) input($_POST, key: 'card')]);
            $_SESSION['flash'] = 'Merkbild erneut beauftragt.';
            go($view === 'home' ? 'home' : 'study', $deckId);
        }

        if ($verb === 'grade') {
            throw new AppException('Die Lernrunde wurde aktualisiert. Bitte die Seite neu laden.', 409);
        }

        if (in_array($verb, ['answer', 'grade-answer', 'study-hint'], strict: true)) {
            $values = [
                'card' => (int) input($_POST, key: 'card'),
                'reps' => (int) input($_POST, key: 'reps'),
                'modified' => (int) input($_POST, key: 'modified'),
                'shown' => (int) input($_POST, key: 'shown'),
            ];
            $signature = hash_hmac('sha256', implode(':', [$deckId, ...$values]), $_SESSION['reviewSecret']);
            if (!hash_equals($signature, input($_POST, key: 'signature'))) {
                throw new AppException('Bitte die Lernrunde neu öffnen.', 422);
            }
            $elapsed = time() - $values['shown'];
            $feedback = null;
            $rating = (int) input($_POST, key: 'rating');
            $cardVersion = [$values['reps'], $values['modified']];
            $hintUsed = ($_SESSION['studyHints'][$deckId][$values['card']] ?? null) === $cardVersion;
            if ($verb !== 'answer') {
                $presentation = study($anki, $request);
                $card = $presentation['card'];
                if (
                    !$card || $card['id'] !== $values['card'] || $card['reps'] !== $values['reps'] || $card['modified'] !== $values['modified']
                    || (int) $presentation['reversed'] !== (int) input($_POST, key: 'reversed')
                ) {
                    throw new AppException('Diese Karte ist nicht mehr aktuell. Bitte die Lernrunde neu öffnen.', 409);
                }
                if ($verb === 'study-hint') {
                    if ($presentation['hintLetter'] === '') {
                        throw new AppException('Für diese Karte ist kein Tipp verfügbar.', 422);
                    }
                    $_SESSION['studyHints'][$deckId][$values['card']] = $cardVersion;
                    respond(['letter' => $presentation['hintLetter']]);
                }
                if ($card['question'] === '' || $card['answer'] === '') {
                    throw new AppException('Bei dieser Karte kannst du deine Antwort nur selbst bewerten.', 422);
                }
                $answer = input($_POST, key: 'answer', maximum: 1500);
                $voice = uploads('voice', ['audio/wav' => 'wav'], limit: 900_000)[0] ?? null;
                if (($answer !== '') === ($voice !== null)) {
                    throw new AppException('Bitte entweder eine Antwort schreiben oder aufnehmen.', 422);
                }
                $feedback = $gateway->grade($card['question'], $card['answer'], $answer, $voice, $hintUsed);
                $rating = $feedback['rating'];
            }
            if (!isset(REVIEW_RATINGS[$rating])) {
                throw new AppException('Bitte die Lernrunde neu öffnen.', 422);
            }
            if ($hintUsed && $rating === 4) {
                throw new AppException('Mit Tipp kannst du höchstens „Gut“ speichern. Bitte lade die Karte neu.', 422);
            }
            $daily = $view === 'home';
            $saved = $anki->call('answer', $request + $values + [
                'rating' => $rating, 'elapsed' => $elapsed, 'daily' => $daily && $feedback !== null,
            ]);
            if ($daily) {
                $_SESSION['dailyAnswered'] = true;
            }
            unset($_SESSION['studyHints'][$deckId][$values['card']]);
            if (isset($_SESSION['reviewBlocks'][$deckId])) {
                $_SESSION['reviewBlocks'][$deckId]['answered']++;
                if ($_SESSION['reviewBlocks'][$deckId]['answered'] === REVIEW_BLOCK_SIZE * REVIEW_CYCLE_BLOCKS) {
                    unset($_SESSION['reviewBlocks'][$deckId]);
                }
            }
            if ($feedback !== null) {
                respond($feedback + ['ratingLabel' => REVIEW_RATINGS[$rating]] + ($saved ?? []));
            }
            if ($ajaxReview) {
                respond(['rating' => $rating]);
            }
            $_SESSION['reviewRating'] = $rating;
            go($daily ? 'home' : 'study', $deckId);
        }

        throw new AppException('Unbekannte Anfrage.', 400);
    }

    if (!$account instanceof Account) {
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
        if ($media['mime'] === 'image/svg+xml') {
            header("Content-Security-Policy: default-src 'none'; img-src data:; frame-ancestors 'none'; sandbox");
        }
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

    if ($view === 'preview' && !isset($_SESSION['deckDraft'])) {
        go('create');
    }
    $data = match ($view) {
        'login', 'profile' => [],
        'users' => ['users' => $credentials->accounts()],
        'preview' => ['draft' => $_SESSION['deckDraft']],
        'home' => study($anki, ['deck' => $_SESSION['dailyDeck'] ?? 0] + $request, daily: true),
        'decks', 'create', 'learn', 'review' => ['decks' => $anki->call('list', $request)],
        'deck' => $anki->call('deck-cards', $request),
        'lesson' => ['lesson' => $anki->call('lesson', $request)],
        'study' => study($anki, $request),
        'backups' => ['backups' => $backupHistory->entries()],
        'sync' => ['sync' => $sync->status()],
        'cron' => ['runs' => (new CronHistory($config['data_dir']))->recent()],
        default => throw new AppException('Diese Seite gibt es nicht.', 404),
    };
    if ($view === 'cron') {
        try {
            $data['backlog'] = $anki->call('cron-backlog');
        } catch (AppException) {
            $data['backlog'] = null;
        }
    }
    if ($view === 'home' || $view === 'study') {
        $data['reviewRating'] = $_SESSION['reviewRating'] ?? null;
        unset($_SESSION['reviewRating']);
    }
    if ($view === 'home') {
        $deckId = $data['deck'];
        $_SESSION['dailyDeck'] = $deckId;
        if ($data['card'] === null) {
            $data['dailyCompleted'] = isset($_SESSION['dailyAnswered']);
            $data['syncAfterCompletion'] = $settings['sync'] && $data['dailyCompleted'];
            unset($_SESSION['dailyAnswered']);
        }
    }
} catch (AppException | PDOException $error) {
    $errorMessage = $error instanceof AppException ? $error->getMessage() : 'Die Anmeldung ist momentan nicht verfügbar.';
    http_response_code($error instanceof AppException ? ($error->getCode() ?: 400) : 503);
    if ($ajaxSync || $ajaxReview || in_array($verb, ['grade', 'grade-answer', 'study-hint'], strict: true) || $view === 'media-status') {
        respond(['error' => $errorMessage]);
    }
    if ($view === 'media') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $errorMessage;
        exit;
    }
    $managingDeck = in_array($verb, ['rename-deck', 'delete-deck'], strict: true);
    if ($account instanceof Account && ($managingDeck || $verb === 'generate' || ($view === 'create' && $_POST === []))) {
        $view = $managingDeck ? 'decks' : 'create';
        try {
            $data['decks'] = $anki->call('list', $request);
        } catch (AppException) {
            $data['decks'] = [];
        }
    }
    if ($account instanceof Account && $view === 'sync' && in_array($verb, ['sync-now', 'sync-interval'], strict: true)) {
        try {
            $data['sync'] = $sync->status();
        } catch (AppException) {
            $data = [];
        }
    }
    if ($view === 'users' && $account instanceof Account && $error instanceof AppException) {
        $data['users'] = $credentials->accounts();
    }
    if ($view === 'preview' && $account instanceof Account && isset($_SESSION['deckDraft'])) {
        $data['draft'] = $_SESSION['deckDraft'];
    }
    if (!$account instanceof Account || ($view !== 'profile' && !$data)) {
        $view = $account instanceof Account ? 'error' : 'login';
    }
}

require __DIR__ . '/../views/app.php';
