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
    'preview' => 'Kartenvorschau',
    'review', 'study' => 'Wiederholen',
    'learn', 'lesson' => 'Lernen',
    'backups' => 'Backups',
    'sync' => 'Anki-Sync',
    'users' => 'Benutzer',
    'profile' => 'Mein Profil',
    default => 'Übersicht',
};
$selectedType = input($_POST, key: 'type') ?: ($deckId === 0 ? 'vocabulary' : 'topic');
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
    <link rel="stylesheet" href="/assets/app.css?v=14">
    <script defer src="/assets/app.js?v=17"></script>
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
                <a href="<?= $route('create') ?>" <?= in_array($view, ['create', 'preview'], strict: true) ? 'aria-current="page"' : '' ?>>
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
                <div class="sidebar-profile">
                    <form class="sidebar-sync" method="post" action="<?= $route('sync') ?>" data-sync-shortcut>
                        <?php $hidden(verb: 'sync-now'); ?>
                        <button class="button secondary sync-shortcut" type="submit" aria-label="Anki jetzt synchronisieren" title="Anki jetzt synchronisieren" <?= !$settings['sync'] || ($data['sync']['running'] ?? false) ? 'disabled' : '' ?>>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5m5 0a8 8 0 1 0-2.5 5.8"/></svg>
                        </button>
                        <span class="small" role="status"></span>
                    </form>
                    <a class="account" href="<?= $route('profile') ?>" <?= $view === 'profile' ? 'aria-current="page"' : '' ?>>
                        <span class="avatar" aria-hidden="true"><?= escape(mb_strtoupper(mb_substr($account->email, start: 0, length: 1))) ?></span>
                        <span class="small account-email"><?= escape($account->email) ?></span>
                    </a>
                </div>
                <nav class="sidebar-actions" aria-label="Weitere Funktionen">
                    <a class="users-link" href="<?= $route('users') ?>" <?= $view === 'users' ? 'aria-current="page"' : '' ?>>Benutzer</a>
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
                case 'home':
                case 'learn':
                case 'review': ?>
                <?php
                    $overview = $view === 'home';
                    $review = $view === 'review';
                    $decks = $data['decks'];
                    ?>
                <header class="screen-header"><h1><?= escape($label) ?></h1></header>
                <?php if ($overview): ?>
                    <dl class="stats" aria-label="Lernstand">
                        <div class="stat-accent"><dt>Jetzt bereit</dt><dd><?= array_sum(array_column($decks, 'due')) ?></dd></div>
                        <div><dt>Davon neu</dt><dd><?= array_sum(array_column($decks, 'new')) ?></dd></div>
                        <div><dt>Stapel</dt><dd><?= count($decks) ?></dd></div>
                    </dl>
                <?php endif; ?>
                <?php if (!$decks): ?>
                    <p class="panel muted">Noch keine Stapel.</p>
                <?php endif; ?>
                <?php if ($decks): ?>
                    <div class="deck-grid">
                        <?php foreach ($decks as $deck): ?>
                            <?php $notDue = $review && $deck['due'] === 0; ?>
                            <article class="panel deck">
                                <div class="deck-top">
                                    <span class="deck-icon<?= $deck['vocabulary'] ? ' vocabulary' : '' ?>" aria-hidden="true"><?= $deck['vocabulary'] ? 'Aa' : '▤' ?></span>
                                    <?php if ($review && !$notDue): ?><span class="badge"><?= $deck['due'] ?> fällig</span><?php endif; ?>
                                </div>
                                <h2><?= escape($deck['name']) ?></h2>
                                <p class="muted small"><?= $deck['total'] ?> <?= $deck['total'] === 1 ? 'Karte' : 'Karten' ?></p>
                                <div class="inline-actions">
                                    <?php if ($overview): ?>
                                        <?php $editing = $errorMessage !== '' && $verb === 'rename-deck' && $deckId === $deck['id']; ?>
                                        <details <?= $editing ? 'open' : '' ?>>
                                            <summary class="button secondary">Bearbeiten</summary>
                                            <form class="form-grid page-actions" method="post" action="/">
                                                <?php $hidden(verb: 'rename-deck', fields: ['deck' => $deck['id']]); ?>
                                                <label>Stapelname <input name="title" value="<?= escape($editing ? input($_POST, key: 'title') : $deck['name']) ?>" maxlength="100" required></label>
                                                <button class="button secondary" type="submit">Namen speichern</button>
                                                <p><a class="button secondary" href="<?= $route('create', $deck['id']) ?>">Karten hinzufügen</a></p>
                                            </form>
                                        </details>
                                        <?php if ($deck['deletable']): ?>
                                            <details <?= $errorMessage !== '' && $verb === 'delete-deck' && $deckId === $deck['id'] ? 'open' : '' ?>>
                                                <summary class="button secondary">Löschen</summary>
                                                <form class="form-grid page-actions" method="post" action="/">
                                                    <?php $hidden(verb: 'delete-deck', fields: ['deck' => $deck['id']]); ?>
                                                    <p class="small muted">Der Stapel und seine Karten werden aus Anki gelöscht.</p>
                                                    <button class="button secondary" type="submit">Stapel endgültig löschen</button>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($notDue): ?>
                                        <span class="badge">Keine Karten fällig</span>
                                    <?php endif; ?>
                                    <?php if (!$overview && !$notDue): ?>
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
                case 'profile': ?>
                <header class="screen-header"><h1>Mein Profil</h1></header>
                <form class="panel form-grid" method="post" action="<?= $route('profile') ?>">
                    <?php $hidden(verb: 'save-profile'); ?>
                    <label>E-Mail-Adresse <input type="email" name="email" value="<?= escape($account->email) ?>" autocomplete="username" maxlength="254" required></label>
                    <label>Aktuelles Passwort <input type="password" name="current_password" autocomplete="current-password" required></label>
                    <label>Neues Passwort (optional) <input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72"></label>
                    <button class="button primary" type="submit">Profil speichern</button>
                </form>
            <?php break;
                case 'users': ?>
                <?php $canDeleteUser = count($data['users']) > 1; ?>
                <header class="screen-header"><h1>Benutzer</h1></header>
                <div class="form-grid">
                    <form class="panel form-grid" method="post" action="<?= $route('users') ?>">
                        <h2>Benutzer anlegen</h2>
                        <?php $hidden(verb: 'create-user'); ?>
                        <label>E-Mail-Adresse <input type="email" name="email" autocomplete="off" maxlength="254" required></label>
                        <label>Passwort <input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required></label>
                        <button class="button primary" type="submit">Benutzer anlegen</button>
                    </form>
                    <section class="panel form-grid" aria-labelledby="users-list">
                        <h2 id="users-list">Bestehende Benutzer</h2>
                        <?php foreach ($data['users'] as $userId => $email): ?>
                            <details class="user-entry" <?= $errorMessage !== '' && input($_POST, key: 'user') === $userId ? 'open' : '' ?>>
                                <summary><?= escape($email) ?></summary>
                                <?php if ($userId === $account->id): ?>
                                    <p class="page-actions"><a class="button secondary" href="<?= $route('profile') ?>">Eigenes Profil bearbeiten</a></p>
                                <?php endif; ?>
                                <?php if ($userId !== $account->id): ?>
                                    <form class="form-grid page-actions" method="post" action="<?= $route('users') ?>">
                                        <?php $hidden(verb: 'update-user', fields: ['user' => $userId]); ?>
                                        <label>E-Mail-Adresse <input type="email" name="email" value="<?= escape($email) ?>" autocomplete="off" maxlength="254" required></label>
                                        <label>Neues Passwort (optional) <input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72"></label>
                                        <button class="button secondary" type="submit">Benutzer speichern</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canDeleteUser): ?>
                                    <details class="page-actions">
                                        <summary class="button secondary">Benutzer löschen</summary>
                                        <form class="inline-actions page-actions" method="post" action="<?= $route('users') ?>">
                                            <?php $hidden(verb: 'delete-user', fields: ['user' => $userId]); ?>
                                            <span class="small muted">Anki-Stapel bleiben erhalten.</span>
                                            <button class="button secondary" type="submit">Endgültig löschen</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </details>
                        <?php endforeach; ?>
                    </section>
                </div>
            <?php break;
                case 'create': ?>
                <header class="screen-header">
                    <div><p class="eyebrow">Schritt 1 von 2</p><h1>Stapel erstellen</h1></div>
                    <?php if (isset($_SESSION['deckDraft'])): ?>
                        <a class="button secondary" href="<?= $route('preview') ?>">Offener Entwurf</a>
                    <?php endif; ?>
                </header>
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
                            <option value="vocabulary" <?= $selectedType === 'vocabulary' ? 'selected' : '' ?>>Vokabeln</option>
                            <option value="topic" <?= $selectedType === 'topic' ? 'selected' : '' ?>>Wissensfragen</option>
                            <option value="test" <?= $selectedType === 'test' ? 'selected' : '' ?>>Schülertest</option>
                        </select>
                    </label>
                    <label>Anzahl der Karten
                        <input name="card_count" type="number" min="1" max="<?= Gateway::MAX_CARD_COUNT ?>" step="1" required value="<?= escape(isset($_POST['card_count']) ? input($_POST, key: 'card_count') : (string) Gateway::DEFAULT_CARD_COUNT) ?>">
                    </label>
                    <label>Thema oder Schwerpunkt
                        <textarea name="focus" rows="3" maxlength="2000" placeholder="Englisch · Reisen · A1"><?= escape(input($_POST, key: 'focus')) ?></textarea>
                    </label>
                    <label>Schulbuchauszug oder anonymisierter Test <span class="muted small">PDF, PNG, JPG, TXT · bis zu 10 Dateien, zusammen max. 8 MB</span>
                        <input type="file" name="document[]" accept="application/pdf,image/jpeg,image/png,text/plain" multiple>
                    </label>
                    <button type="submit" class="button primary" <?= $settings['ai'] ? '' : 'disabled' ?>>Kartenvorschau erstellen</button>
                </form>
            <?php break;
                case 'preview': ?>
                <?php
                $draft = $data['draft'];
                    $proposal = $draft['payload'];
                    $generating = $draft['generating'];
                    $autoGenerate = $generating && $errorMessage === '';
                    $cardCount = count($proposal['cards']);
                    $saveLabel = $proposal['deck'] ? 'Freigeben und Karten hinzufügen' : 'Freigeben und Stapel anlegen';
                    $heading = match (true) {
                        !$generating => 'Karten prüfen',
                        $autoGenerate => 'Kartenvorschau wird erstellt',
                        default => 'Kartenvorschau unterbrochen',
                    };
                    ?>
                <header class="screen-header">
                    <div>
                        <p class="eyebrow">Schritt 2 von 2</p>
                        <h1><?= $heading ?></h1>
                        <p class="muted"><?= escape($proposal['title']) ?> · <?= $cardCount ?> von <?= $draft['cardCount'] ?> Karten</p>
                    </div>
                </header>
                <?php if ($autoGenerate): ?>
                    <progress class="preview-progress" aria-label="Fortschritt der Kartenvorschau" max="<?= $draft['cardCount'] ?>" <?= $cardCount > 0 ? 'value="' . $cardCount . '"' : '' ?>></progress>
                    <form id="preview-batch" method="post" action="<?= $route('preview') ?>" data-generate-batch hidden>
                        <?php $hidden(verb: 'generate-batch', fields: ['draft' => $draft['id'], 'offset' => $cardCount]); ?>
                    </form>
                <?php endif; ?>
                <?php if (!$generating): ?>
                    <div class="deck-grid">
                        <section class="panel form-grid">
                            <h2>Lerntext</h2>
                            <p class="preline"><?= escape($proposal['lesson']) ?></p>
                        </section>
                        <?php foreach ($proposal['cards'] as $index => $card): ?>
                            <article class="panel form-grid">
                                <h2>Karte <?= $index + 1 ?></h2>
                                <div><h3>Vorderseite</h3><p class="preline"><?= escape($card['front']) ?></p></div>
                                <div><h3>Rückseite</h3><p class="preline"><?= escape($card['back']) ?></p></div>
                                <?php if ($proposal['vocabulary']): ?>
                                    <div><h3>Eselsbrücke</h3><p class="preline"><?= escape($card['hint']) ?></p></div>
                                    <div><h3>Bildidee</h3><p class="preline"><?= escape($card['imagePrompt']) ?></p></div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($autoGenerate): ?><noscript><?php endif; ?>
                <div class="inline-actions page-actions">
                    <?php if ($autoGenerate): ?>
                        <button type="submit" form="preview-batch" class="button primary">Vorschau fortsetzen</button>
                    <?php endif; ?>
                    <?php if ($generating && !$autoGenerate): ?>
                        <a href="<?= $route('preview') ?>" class="button primary">Vorschau fortsetzen</a>
                    <?php endif; ?>
                    <?php if (!$generating): ?>
                        <form method="post" action="<?= $route('preview') ?>">
                            <?php $hidden(verb: 'save-preview', fields: ['draft' => $draft['id']]); ?>
                            <button type="submit" class="button primary"><?= $saveLabel ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?= $route('preview') ?>">
                        <?php $hidden(verb: 'discard-draft', fields: ['draft' => $draft['id']]); ?>
                        <button type="submit" class="button secondary">Entwurf verwerfen</button>
                    </form>
                </div>
                <?php if ($autoGenerate): ?></noscript><?php endif; ?>
            <?php break;
                case 'lesson': ?>
                <?php
                $lesson = $data['lesson'];
                    $videoState = $lesson['jobs']['video'] ?? '';
                    $videoPending = in_array($videoState, PENDING_MEDIA_STATES, strict: true);
                    $imageProgress = $lesson['imageProgress'];
                    $canGenerateVideo = in_array($videoState, ['', 'failed', 'ready'], strict: true)
                        && $settings['fal'] && $lesson['text'] !== '';
                    ?>
                <a class="back-link" href="<?= $route('learn') ?>">← Lernen</a>
                <header class="screen-header">
                    <div><p class="eyebrow">Lernen</p><h1><?= escape($lesson['name']) ?></h1></div>
                </header>
                <article class="panel lesson" <?= $videoPending || $imageProgress['pending'] > 0 ? 'data-media-status="' . $route('media-status', $deckId) . '"' : '' ?> <?= $videoPending ? 'data-watch-video' : '' ?>>
                    <h2>Lerntext</h2>
                    <p class="preline"><?= escape($lesson['text'] ?: 'Kein Lerntext vorhanden.') ?></p>
                    <?php if ($lesson['audio'] !== ''): ?>
                        <section class="lesson-media">
                            <h3>Audio</h3>
                            <audio controls preload="metadata" src="<?= $mediaUrl($deckId, $lesson['audio']) ?>"></audio>
                        </section>
                    <?php endif; ?>
                    <section class="lesson-media">
                        <h3>Video</h3>
                        <?php if ($lesson['video'] !== ''): ?>
                            <video controls preload="metadata" playsinline src="<?= $mediaUrl($deckId, $lesson['video']) ?>"></video>
                        <?php endif; ?>
                        <?php if (isset(MEDIA_STAGE_LABELS[$videoState])): ?>
                            <p class="muted small" role="status" data-media-stage><?= MEDIA_STAGE_LABELS[$videoState] ?></p>
                        <?php endif; ?>
                        <?php if ($canGenerateVideo): ?>
                            <?php
                                $videoLabel = $lesson['video'] === '' ? 'Video generieren' : 'Video neu generieren';
                            $price = number_format($lesson['estimatedVideoCost'], decimals: 2, decimal_separator: ',');
                            ?>
                            <form method="post" action="<?= $route('lesson', $deckId) ?>">
                                <?php $hidden(verb: 'queue-video', fields: ['deck' => $deckId]); ?>
                                <button class="button secondary" type="submit"><?= $videoLabel ?> (ca. <?= $price ?>&nbsp;US-$)</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($lesson['video'] === '' && $videoState === '' && !$canGenerateVideo): ?>
                            <p class="muted small">Noch nicht verfügbar.</p>
                        <?php endif; ?>
                    </section>
                    <?php if ($imageProgress['total'] > 0): ?>
                        <section class="lesson-media">
                            <h3>Merkbilder</h3>
                            <p class="muted small" role="status" data-image-progress><?= $imageProgress['ready'] ?> von <?= $imageProgress['total'] ?> fertig.</p>
                        </section>
                    <?php endif; ?>
                    <?php if ($lesson['sources']): ?>
                        <section class="lesson-media"><h3>Lernvorlagen</h3>
                            <ul>
                                <?php foreach ($lesson['sources'] as $index => $source): ?>
                                <li><a href="<?= $mediaUrl($deckId, $source['file']) ?>"><?= escape($source['type']) ?><?= count($lesson['sources']) > 1 ? ' ' . ($index + 1) : '' ?> herunterladen</a></li>
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
                    <div>
                        <p class="eyebrow">Wiederholen</p><h1><?= escape($data['name']) ?></h1>
                        <?php if ($card && $data['vocabulary']): ?>
                            <p class="small muted"><?= $data['reversed'] ? 'Rückseite zuerst' : 'Vorderseite zuerst' ?></p>
                        <?php endif; ?>
                    </div>
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
                    $reviewFields = $cardFields + ['shown' => $shown, 'signature' => $signature];
                    $canGrade = $settings['ai'] && $card['question'] !== '' && $card['answer'] !== '';
                    $imageState = $card['imageState'];
                    $imagePending = in_array($imageState, PENDING_MEDIA_STATES, strict: true);
                    ?>
                    <div data-study>
                        <article class="panel flashcard">
                            <p class="eyebrow">Frage</p>
                            <p class="question preline"><?= escape($card['question']) ?></p>
                            <?php if ($card['questionMedia']): ?>
                                <div class="card-media"><?php $cardMedia($card['questionMedia'], $deckId, $card['id']); ?></div>
                            <?php endif; ?>
                            <div class="inline-actions page-actions" data-answer-actions>
                                <?php if ($canGrade): ?>
                                    <button type="button" class="button secondary" data-record>Audioaufnahme</button>
                                    <button type="button" class="button secondary" aria-controls="text-answer" aria-expanded="false" data-text-answer>Direkteingabe</button>
                                <?php endif; ?>
                                <button type="button" class="button primary" aria-controls="solution" aria-expanded="false" data-reveal>Direkt aufdecken</button>
                            </div>
                            <?php if ($canGrade): ?>
                                <form id="text-answer" method="post" class="form-grid answer-form" data-grade hidden>
                                    <?php $hidden(verb: 'grade-answer', fields: $reviewFields + ['reversed' => (int) $data['reversed']]); ?>
                                    <label>Deine Antwort <textarea name="answer" rows="2" maxlength="1500" required></textarea></label>
                                    <button class="button primary" type="submit">Mit KI prüfen</button>
                                </form>
                                <p class="small muted" data-record-status role="status">Audio und Text gehen zur KI-Prüfung. Die Bewertung wird automatisch gespeichert.</p>
                                <div class="notice" data-feedback role="status" hidden></div>
                            <?php endif; ?>
                            <section id="solution" class="solution" tabindex="-1" hidden>
                                <p class="eyebrow">Lösung</p><p class="answer preline"><?= escape($card['answer']) ?></p>
                                <?php if ($card['answerMedia']): ?>
                                    <div class="card-media"><?php $cardMedia($card['answerMedia'], $deckId, $card['id']); ?></div>
                                <?php endif; ?>
                                <?php if ($card['hint'] !== ''): ?>
                                    <div class="mnemonic" <?= $imagePending ? 'data-media-status="' . $route('media-status', $deckId, ['card' => $card['id']]) . '"' : '' ?>>
                                        <?php if ($card['image'] !== '' || $imagePending): ?>
                                            <img <?= $card['image'] !== '' ? 'src="' . $mediaUrl($deckId, $card['image'], $card['id']) . '"' : 'hidden' ?> alt="Illustration zur Eselsbrücke" loading="lazy" data-mnemonic-image>
                                        <?php endif; ?>
                                        <div><h3>Eselsbrücke</h3><p><?= escape($card['hint']) ?></p>
                                        <?php if ($card['image'] === '' && isset(MEDIA_STAGE_LABELS[$imageState])): ?>
                                            <p class="small muted" role="status">Merkbild: <span data-media-stage><?= MEDIA_STAGE_LABELS[$imageState] ?></span></p>
                                        <?php endif; ?>
                                        <?php if ($settings['ai'] && ($imageState === 'failed' || $imagePending)): ?>
                                            <form method="post" action="<?= $route('study', $deckId) ?>" data-media-retry <?= $imageState === 'failed' ? '' : 'hidden' ?>>
                                                <?php $hidden(verb: 'retry-image', fields: $cardFields); ?>
                                                <button class="button secondary" type="submit">Bild erneut generieren</button>
                                            </form>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </article>
                        <div class="inline-actions page-actions" data-next-card hidden>
                            <a class="button primary" href="<?= $route('study', $deckId) ?>">Nächste Karte</a>
                        </div>
                        <form class="panel rating-form" method="post" data-ratings hidden>
                            <?php $hidden(verb: 'answer', fields: $reviewFields); ?>
                            <h2>Antwort selbst bewerten</h2>
                            <div class="ratings">
                                <?php foreach (REVIEW_RATINGS as $rating => $text): ?>
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
