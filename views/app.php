<?php

declare(strict_types=1);

namespace Bruno;

$route = fn (string $page, int $deck = 0, array $query = []): string => escape(
    '/?' . http_build_query(['view' => $page] + ($deck ? ['deck' => $deck] : []) + $query),
);
$mediaUrl = fn (int $deck, string $file, int $card = 0): string => $route(
    'media',
    $deck,
    ['file' => $file] + ($card ? ['card' => $card] : []),
);
$cardMedia = static function (array $media, int $deck, int $card) use ($mediaUrl): void {
    foreach ($media as $item) {
        $src = $mediaUrl($deck, $item['file'], $card);
        echo match ($item['kind']) {
            'image' => '<img src="' . $src . '" alt="Kartenbild" loading="lazy">',
            'audio' => '<audio src="' . $src . '" controls preload="none" aria-label="Karten-Audio"></audio>',
            'video' => '<video src="' . $src . '" controls preload="none" playsinline aria-label="Karten-Video"></video>',
        };
    }
};
$hidden = static function (string $verb, array $fields = []) use ($csrf): void {
    foreach (['verb' => $verb, 'csrf' => $csrf] + $fields as $name => $value) {
        echo '<input type="hidden" name="' . escape($name) . '" value="' . escape((string) $value) . '">';
    }
};
$label = match ($view) {
    'login' => 'Anmelden',
    'create' => 'Stapel erstellen',
    'review', 'study' => 'Wiederholen',
    'learn', 'lesson' => 'Lernen',
    'backups' => 'Backups',
    'sync' => 'Anki-Sync',
    default => 'Übersicht',
};
$selectedType = input($_POST, key: 'type') ?: 'topic';
if ($view === 'create' && !isset($_POST['type'])) {
    foreach ($data['decks'] as $deck) {
        if ($deck['id'] === $deckId && $deck['vocabulary']) {
            $selectedType = 'vocabulary';
            break;
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, minimum-scale=1">
    <meta name="format-detection" content="telephone=no">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#101117">
    <meta name="apple-mobile-web-app-title" content="bruno">
    <title>bruno · <?= escape($label) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg?v=4">
    <link rel="apple-touch-icon" href="/assets/icon-192.png?v=4">
    <link rel="manifest" href="/manifest.webmanifest?v=4">
    <link rel="stylesheet" href="/assets/app.css?v=9">
    <script defer src="/assets/app.js?v=8"></script>
</head>
<body class="<?= $account === null ? 'guest' : 'signed-in' ?>">
<?php if ($account === null): ?>
    <main class="guest-main" id="main">
        <article class="panel login-panel">
            <a class="brand" href="/"><img class="brand-icon" src="/assets/icon.svg?v=4" width="41" height="41" alt=""> bruno</a>
            <h1>Anmelden</h1>
            <?php if ($errorMessage !== ''): ?>
                <p class="notice error" role="alert"><?= escape($errorMessage) ?></p>
            <?php endif; ?>
            <form method="post" class="form-grid">
                <?php $hidden(verb: 'login'); ?>
                <label>E-Mail-Adresse <input type="email" name="email" autocomplete="username" maxlength="254" required autofocus></label>
                <label>Passwort <input type="password" name="password" autocomplete="current-password" required></label>
                <button class="button primary" type="submit">Anmelden</button>
            </form>
        </article>
    </main>
<?php endif; ?>
<?php if ($account !== null): ?>
    <a class="skip" href="#main">Zum Inhalt</a>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="/"><img class="brand-icon" src="/assets/icon.svg?v=4" width="41" height="41" alt=""> bruno</a>
            <nav aria-label="Hauptnavigation">
                <a href="/" <?= $view === 'home' ? 'aria-current="page"' : '' ?>>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>
                    Übersicht
                </a>
                <a href="<?= $route('create') ?>" <?= $view === 'create' ? 'aria-current="page"' : '' ?>>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8.5 12h7m-3.5-3.5v7"/></svg>
                    Erstellen
                </a>
                <a href="<?= $route('learn') ?>" <?= in_array($view, ['learn', 'lesson'], strict: true) ? 'aria-current="page"' : '' ?>>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6a8 8 0 0 0-9-2v14a8 8 0 0 1 9 2 8 8 0 0 1 9-2V4a8 8 0 0 0-9 2Zm0 0v14"/></svg>
                    Lernen
                </a>
                <a href="<?= $route('review') ?>" <?= in_array($view, ['review', 'study'], strict: true) ? 'aria-current="page"' : '' ?>>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 5v5h-5M4 19v-5h5m11-4a8 8 0 0 0-14-4m-2 8a8 8 0 0 0 14 4"/></svg>
                    Wiederholen
                </a>
            </nav>
            <div class="sidebar-foot">
                <div class="account">
                    <span class="avatar" aria-hidden="true"><?= escape(mb_strtoupper(mb_substr($account['email'], start: 0, length: 1))) ?></span>
                    <span class="small account-email"><?= escape($account['email']) ?></span>
                </div>
                <nav class="sidebar-actions" aria-label="Weitere Funktionen">
                    <a href="<?= $route('backups') ?>" <?= $view === 'backups' ? 'aria-current="page"' : '' ?>>Backups</a>
                    <a href="<?= $route('sync') ?>" <?= $view === 'sync' ? 'aria-current="page"' : '' ?>>Anki-Sync</a>
                    <button type="button" aria-expanded="false" aria-controls="install-help" data-install>App installieren</button>
                    <form method="post" action="/">
                        <?php $hidden(verb: 'logout'); ?>
                        <button type="submit">Abmelden</button>
                    </form>
                </nav>
                <p id="install-help" class="small muted" hidden>Browsermenü: „App installieren“. iOS: „Zum Home-Bildschirm“.</p>
            </div>
        </aside>
        <main class="workspace" id="main" tabindex="-1">
            <?php if ($flash !== ''): ?><p class="notice" role="status"><?= escape($flash) ?></p><?php endif; ?>
            <?php if ($errorMessage !== ''): ?><p class="notice error" role="alert"><?= escape($errorMessage) ?></p><?php endif; ?>

            <?php switch ($view):
                case 'home': ?>
                <?php
                $decks = $data['decks'];
                    $due = array_sum(array_column($decks, 'due'));
                    $new = array_sum(array_column($decks, 'new'));
                    ?>
                <header class="screen-header"><h1>Übersicht</h1></header>
                <dl class="stats" aria-label="Lernstand">
                    <div class="stat-accent"><dt>Jetzt bereit</dt><dd><?= $due ?></dd></div>
                    <div><dt>Davon neu</dt><dd><?= $new ?></dd></div>
                    <div><dt>Stapel</dt><dd><?= count($decks) ?></dd></div>
                </dl>
            <?php break;
                case 'learn':
                case 'review': ?>
                <?php $review = $view === 'review'; ?>
                <header class="screen-header"><h1><?= escape($label) ?></h1></header>
                <?php if (!$data['decks']): ?>
                    <p class="panel muted">Noch keine Stapel.</p>
                <?php endif; ?>
                <?php if ($data['decks']): ?>
                    <div class="deck-grid">
                        <?php foreach ($data['decks'] as $deck): ?>
                            <?php $notDue = $review && $deck['due'] === 0; ?>
                            <article class="panel deck">
                                <div class="deck-top">
                                    <span class="deck-icon<?= $deck['vocabulary'] ? ' vocabulary' : '' ?>" aria-hidden="true"><?= $deck['vocabulary'] ? 'Aa' : '▤' ?></span>
                                    <?php if ($review && !$notDue): ?><span class="badge"><?= $deck['due'] ?> fällig</span><?php endif; ?>
                                </div>
                                <h2><?= escape($deck['name']) ?></h2>
                                <p class="muted small"><?= $deck['total'] ?> <?= $deck['total'] === 1 ? 'Karte' : 'Karten' ?></p>
                                <div class="inline-actions">
                                    <?php if ($notDue): ?>
                                        <span class="badge">Keine Karten fällig</span>
                                    <?php endif; ?>
                                    <?php if (!$notDue): ?>
                                        <a class="button primary" href="<?= $route($review ? 'study' : 'lesson', $deck['id']) ?>"><?= escape($label) ?></a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php break;
                case 'backups': ?>
                <?php
                $backups = $data['backups'];
                    $latest = $backups[0]['createdAt'] ?? null;
                    $formatSize = static fn (int $bytes): string => match (true) {
                        $bytes >= 1_048_576 => number_format($bytes / 1_048_576, decimals: 2, decimal_separator: ',', thousands_separator: '.') . ' MiB',
                        $bytes >= 1024 => number_format($bytes / 1024, decimals: 2, decimal_separator: ',', thousands_separator: '.') . ' KiB',
                        default => $bytes . ' B',
                    };
                    ?>
                <header class="screen-header">
                    <div><h1>Backups</h1><p class="muted">Anki-Sammlung und Medien</p></div>
                    <form method="post" action="<?= $route('backups') ?>">
                        <?php $hidden(verb: 'backup'); ?>
                        <button class="button primary" type="submit">Backup erstellen</button>
                    </form>
                </header>
                <dl class="stats backup-stats" aria-label="Backup-Status">
                    <div class="stat-accent"><dt>Anzahl</dt><dd><?= count($backups) ?></dd></div>
                    <div><dt>Letztes Backup</dt>
                        <dd>
                            <?php if ($latest !== null): ?>
                                <time datetime="<?= $latest->format(DATE_ATOM) ?>"><?= $latest->format('d.m.Y') ?> <span class="small muted"><?= $latest->format('H:i:s') ?></span></time>
                            <?php endif; ?>
                            <?php if ($latest === null): ?>–<?php endif; ?>
                        </dd>
                    </div>
                    <div><dt>Gesamtgröße</dt><dd><?= $formatSize(array_sum(array_column($backups, 'bytes'))) ?></dd></div>
                </dl>
                <?php if (!$backups): ?><p class="panel muted">Noch keine Backups.</p><?php endif; ?>
                <?php if ($backups): ?>
                    <section aria-labelledby="backup-history">
                        <header class="section-header"><h2 id="backup-history">Verlauf</h2><span class="small muted">Zeitzone: Berlin</span></header>
                        <div class="panel backup-list">
                            <table aria-labelledby="backup-history">
                                <thead><tr><th scope="col">Erstellt</th><th scope="col">Größe</th></tr></thead>
                                <tbody>
                                    <?php foreach ($backups as $backup): ?>
                                        <?php $createdAt = $backup['createdAt']; ?>
                                        <tr>
                                            <td>
                                                <time datetime="<?= $createdAt->format(DATE_ATOM) ?>"><span><?= $createdAt->format('d.m.Y') ?></span> <span class="muted"><?= $createdAt->format('H:i:s') ?></span></time>
                                                <details class="backup-delete">
                                                    <summary class="button secondary">Backup löschen</summary>
                                                    <form method="post" action="<?= $route('backups') ?>">
                                                        <?php $hidden(verb: 'delete-backup', fields: ['backup' => $backup['id']]); ?>
                                                        <button class="button secondary" type="submit">Endgültig löschen</button>
                                                    </form>
                                                </details>
                                            </td>
                                            <td><?= $formatSize($backup['bytes']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>
            <?php break;
                case 'sync': ?>
                <?php
                $syncStatus = $data['sync'];
                    $syncLabel = match (true) {
                        !$syncStatus['configured'] => 'Nicht eingerichtet',
                        $syncStatus['running'] => 'Läuft',
                        $syncStatus['result'] === 'success' => 'Erfolgreich',
                        $syncStatus['result'] === 'failed' => 'Fehlgeschlagen',
                        $syncStatus['result'] === 'started' => 'Unterbrochen',
                        default => 'Noch kein Lauf erfasst',
                    };
                    $berlin = new \DateTimeZone('Europe/Berlin');
                    ?>
                <header class="screen-header">
                    <div><h1>Anki-Sync</h1><p class="muted">bruno ↔ AnkiWeb · Karten, Lernstände und Medien</p></div>
                    <div class="inline-actions">
                        <a class="button secondary" href="<?= $route('sync') ?>">Aktualisieren</a>
                        <form method="post" action="<?= $route('sync') ?>" data-sync>
                            <?php $hidden(verb: 'sync-now'); ?>
                            <button class="button primary" type="submit" <?= !$syncStatus['configured'] || $syncStatus['running'] ? 'disabled' : '' ?>>Jetzt synchronisieren</button>
                        </form>
                    </div>
                </header>
                <dl class="stats" aria-label="Synchronisationsstatus">
                    <div class="stat-accent"><dt>Status</dt><dd><?= $syncLabel ?></dd></div>
                    <?php foreach (['lastSuccess' => 'Zuletzt erfolgreich', 'lastAttempt' => 'Letzter Versuch'] as $field => $title): ?>
                        <div><dt><?= $title ?></dt><dd>
                            <?php if ($syncStatus[$field] === null): ?>–<?php endif; ?>
                            <?php if ($syncStatus[$field] !== null): ?>
                                <?php $instant = (new \DateTimeImmutable('@' . $syncStatus[$field]))->setTimezone($berlin); ?>
                                <time datetime="<?= $instant->format(DATE_ATOM) ?>"><?= $instant->format('d.m.Y') ?> <span class="small muted"><?= $instant->format('H:i:s') ?></span></time>
                            <?php endif; ?>
                        </dd></div>
                    <?php endforeach; ?>
                </dl>
                <?php if (!$syncStatus['running'] && $syncStatus['error'] !== null && $syncStatus['error'] !== $errorMessage): ?>
                    <p class="notice error" role="alert"><?= escape($syncStatus['error']) ?></p>
                <?php endif; ?>
                <form class="panel form-grid" method="post" action="<?= $route('sync') ?>">
                    <?php $hidden(verb: 'sync-interval'); ?>
                    <label>Automatischer Abgleich
                        <select name="interval" <?= $syncStatus['running'] ? 'disabled' : '' ?>>
                            <?php foreach (AnkiSync::INTERVALS as $minutes): ?>
                                <option value="<?= $minutes ?>" <?= $syncStatus['interval'] === $minutes ? 'selected' : '' ?>>Alle <?= $minutes ?> Minuten</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="small muted">Gilt für alle Konten. Änderungen in Anki Desktop zuerst dort mit AnkiWeb synchronisieren. Zeiten: Berlin.</p>
                    <button class="button secondary" type="submit" <?= $syncStatus['running'] ? 'disabled' : '' ?>>Intervall speichern</button>
                </form>
            <?php break;
                case 'create': ?>
                <header class="screen-header"><h1>Stapel erstellen</h1></header>
                <?php if (!$settings['ai']): ?><p class="notice">Kein KI-Zugang eingerichtet.</p><?php endif; ?>
                <form method="post" action="<?= $route('create') ?>" enctype="multipart/form-data" class="panel form-grid" data-editor>
                    <?php $hidden(verb: 'generate'); ?>
                    <label>Stapel
                        <select name="deck">
                            <option value="0">Neuer Stapel</option>
                            <?php foreach ($data['decks'] as $deck): ?>
                                <option value="<?= $deck['id'] ?>" data-vocabulary="<?= $deck['vocabulary'] ? 'yes' : 'no' ?>" <?= $deckId === $deck['id'] ? 'selected' : '' ?>><?= escape($deck['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Stapelname
                        <input name="title" maxlength="100" placeholder="Englisch · Ferien" value="<?= escape(input($_POST, key: 'title')) ?>">
                    </label>
                    <label>Kartentyp
                        <select name="type">
                            <option value="topic" <?= $selectedType === 'topic' ? 'selected' : '' ?>>Wissensfragen</option>
                            <option value="vocabulary" <?= $selectedType === 'vocabulary' ? 'selected' : '' ?>>Vokabeln · Deutsch → Fremdsprache</option>
                            <option value="test" <?= $selectedType === 'test' ? 'selected' : '' ?>>Schülertest</option>
                        </select>
                    </label>
                    <label>Thema oder Schwerpunkt
                        <textarea name="focus" rows="3" maxlength="2000" placeholder="Englisch · Reisen · A1"><?= escape(input($_POST, key: 'focus')) ?></textarea>
                    </label>
                    <label>Schulbuchauszug oder anonymisierter Test <span class="muted small">PDF, PNG, JPG, TXT · max. 8 MB</span>
                        <input type="file" name="document" accept="application/pdf,image/jpeg,image/png,text/plain">
                    </label>
                    <button type="submit" class="button primary" <?= $settings['ai'] ? '' : 'disabled' ?>>Karten erstellen</button>
                </form>
            <?php break;
                case 'lesson': ?>
                <?php $lesson = $data['lesson']; ?>
                <a class="back-link" href="<?= $route('learn') ?>">← Lernen</a>
                <header class="screen-header">
                    <div><p class="eyebrow">Lernen</p><h1><?= escape($lesson['name']) ?></h1></div>
                </header>
                <article class="panel lesson">
                    <h2>Lerntext</h2>
                    <p class="preline"><?= escape($lesson['text'] ?: 'Kein Lerntext vorhanden.') ?></p>
                    <?php foreach (['audio' => 'Audio', 'video' => 'Video'] as $kind => $name): ?>
                        <?php $mediaState = $lesson['jobs'][$kind] ?? ''; ?>
                        <section class="lesson-media">
                        <h3><?= $name ?></h3>
                        <?php if ($lesson[$kind] !== '' && $kind === 'audio'): ?>
                            <audio controls preload="metadata" src="<?= $mediaUrl($deckId, $lesson[$kind]) ?>"></audio>
                        <?php endif; ?>
                        <?php if ($lesson[$kind] !== '' && $kind === 'video'): ?>
                            <video controls preload="metadata" playsinline src="<?= $mediaUrl($deckId, $lesson[$kind]) ?>"></video>
                        <?php endif; ?>
                        <?php if ($lesson[$kind] === '' && !in_array($mediaState, ['', 'failed'], strict: true)): ?>
                            <p class="muted small"><?= $mediaState === 'unknown' ? 'Auftragsstatus unklar. Kein neuer Auftrag.' : 'In Bearbeitung.' ?></p>
                        <?php endif; ?>
                        <?php if ($lesson[$kind] === '' && in_array($mediaState, ['', 'failed'], strict: true) && $settings['fal'] && $lesson['text'] !== ''): ?>
                            <?php if ($mediaState === 'failed'): ?><p class="muted small">Generierung fehlgeschlagen.</p><?php endif; ?>
                            <form method="post" action="<?= $route('lesson', $deckId) ?>">
                                <?php $hidden(verb: 'queue', fields: ['deck' => $deckId, 'kind' => $kind]); ?>
                                <button class="button secondary" type="submit"><?= $name ?> generieren (kostenpflichtig)</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($lesson[$kind] === '' && $mediaState === '' && (!$settings['fal'] || $lesson['text'] === '')): ?>
                            <p class="muted small">Noch nicht verfügbar.</p>
                        <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                    <?php if ($lesson['sources']): ?>
                        <section class="lesson-media"><h3>Lernvorlagen</h3>
                            <ul>
                            <?php foreach ($lesson['sources'] as $source): ?>
                                <li><a href="<?= $mediaUrl($deckId, $source['file']) ?>"><?= escape($source['type']) ?> herunterladen</a></li>
                            <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endif; ?>
                </article>
                <div class="inline-actions page-actions">
                    <a class="button secondary" href="<?= $route('create', $deckId) ?>">Karten ergänzen</a>
                    <a class="button primary" href="<?= $route('study', $deckId) ?>">Jetzt wiederholen <span aria-hidden="true">→</span></a>
                </div>
            <?php break;
                case 'study': ?>
                <?php $card = $data['card']; ?>
                <a class="back-link" href="<?= $route('review') ?>">← Wiederholen</a>
                <header class="screen-header">
                    <div><p class="eyebrow">Wiederholen</p><h1><?= escape($data['name']) ?></h1></div>
                    <a class="button secondary" href="<?= $route('lesson', $deckId) ?>">Lernen</a>
                </header>
                <?php if (!$card): ?>
                    <article class="panel empty"><h2>Keine Karten fällig.</h2>
                        <a class="button primary" href="<?= $route('review') ?>">Zur Wiederholung</a>
                    </article>
                <?php endif; ?>
                <?php if ($card): ?>
                    <?php
                    $cardFields = ['deck' => $deckId, 'card' => $card['id'], 'reps' => $card['reps'], 'modified' => $card['modified']];
                    $shown = time();
                    $signature = hash_hmac('sha256', implode(':', [...$cardFields, $shown]), $_SESSION['reviewSecret']);
                    ?>
                    <div data-study>
                        <article class="panel flashcard">
                            <p class="eyebrow">Frage</p>
                            <p class="question preline"><?= escape($card['question']) ?></p>
                            <?php if ($card['questionMedia']): ?>
                                <div class="card-media"><?php $cardMedia($card['questionMedia'], $deckId, $card['id']); ?></div>
                            <?php endif; ?>
                            <button type="button" class="button primary" aria-controls="solution" aria-expanded="false" data-reveal>Antwort aufdecken</button>
                            <section id="solution" class="solution" tabindex="-1" hidden>
                                <p class="eyebrow">Lösung</p><p class="answer preline"><?= escape($card['answer']) ?></p>
                                <?php if ($card['answerMedia']): ?>
                                    <div class="card-media"><?php $cardMedia($card['answerMedia'], $deckId, $card['id']); ?></div>
                                <?php endif; ?>
                                <?php if ($card['hint'] !== ''): ?>
                                    <div class="mnemonic">
                                        <?php if ($card['image'] !== ''): ?>
                                            <img src="<?= $mediaUrl($deckId, $card['image'], $card['id']) ?>" alt="Illustration zur Eselsbrücke" loading="lazy">
                                        <?php endif; ?>
                                        <div><h3>Eselsbrücke</h3><p><?= escape($card['hint']) ?></p>
                                        <?php if ($card['image'] === '' && $card['imageState'] !== ''): ?>
                                            <p class="small muted"><?= in_array($card['imageState'], ['failed', 'unknown'], strict: true) ? 'Merkbild nicht verfügbar.' : 'Merkbild in Arbeit.' ?></p>
                                        <?php endif; ?>
                                        <?php if ($card['imageState'] === 'failed' && $settings['ai']): ?>
                                            <form method="post" action="<?= $route('study', $deckId) ?>">
                                                <?php $hidden(verb: 'retry-image', fields: $cardFields); ?>
                                                <button class="button secondary" type="submit">Bild erneut beauftragen (kostenpflichtig)</button>
                                            </form>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </article>
                        <?php if ($settings['ai'] && $card['question'] !== '' && $card['answer'] !== ''): ?>
                            <form method="post" enctype="multipart/form-data" class="panel form-grid answer-form" data-grade>
                                <?php $hidden(verb: 'grade', fields: $cardFields); ?>
                                <label>Deine Antwort <textarea name="answer" rows="2" maxlength="1500"></textarea></label>
                                <div class="inline-actions">
                                    <button class="button secondary" type="button" data-record>Aufnehmen</button>
                                    <button class="button primary" type="submit">Mit KI prüfen</button>
                                </div>
                                <p class="small muted">Die Antwort wird zur Prüfung an den KI-Dienst gesendet.</p>
                                <p class="small" data-record-status role="status"></p>
                            </form>
                            <div class="notice" data-feedback role="status" hidden></div>
                        <?php endif; ?>
                        <form class="panel rating-form" method="post" data-ratings hidden>
                            <?php $hidden(verb: 'answer', fields: $cardFields + ['shown' => $shown, 'signature' => $signature]); ?>
                            <h2>Antwort bewerten</h2>
                            <div class="ratings">
                                <?php foreach ([1 => 'Nochmal', 2 => 'Schwer', 3 => 'Gut', 4 => 'Einfach'] as $rating => $text): ?>
                                    <button type="submit" name="rating" value="<?= $rating ?>">
                                        <?= $text ?><span><?= escape($card['intervals'][$rating - 1] ?? '') ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </form>
                        <noscript><p class="notice">Zum Aufdecken und Bewerten benötigst du JavaScript.</p></noscript>
                    </div>
                <?php endif; ?>
            <?php break;
                default: ?>
                <h1>Fehler</h1>
            <?php endswitch; ?>
        </main>
    </div>
<?php endif; ?>
</body>
</html>
