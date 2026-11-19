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
    'create' => 'Stapel erstellen',
    'study' => 'Wiederholen',
    'lesson' => 'Vorbereiten',
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
    <meta name="theme-color" content="#f6f5fa">
    <meta name="apple-mobile-web-app-title" content="bruno">
    <title><?= $view === 'login' ? 'bruno · Dein Lernstudio' : 'bruno · ' . escape($label) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="stylesheet" href="/assets/app.css">
    <script defer src="/assets/app.js"></script>
</head>
<body class="<?= $account === null ? 'guest' : 'signed-in' ?>">
<?php if ($account === null): ?>
    <main class="guest-main" id="main">
        <article class="panel login-panel">
            <a class="brand" href="/"><span class="brand-icon" aria-hidden="true">🎓</span> bruno</a>
            <p class="eyebrow">Dein Lernstudio</p>
            <h1>Wissen, das bleibt.</h1>
            <p class="muted">Lerne in kleinen Etappen. Melde dich an, um deine persönlichen Stapel zu öffnen.</p>
            <?php if ($errorMessage !== ''): ?>
                <p class="notice error" role="alert"><?= escape($errorMessage) ?></p>
            <?php endif; ?>
            <form method="post" class="form-grid">
                <?php $hidden(verb: 'login'); ?>
                <label>E-Mail-Adresse <input type="email" name="email" autocomplete="username" maxlength="254" required autofocus></label>
                <label>Passwort <input type="password" name="password" autocomplete="current-password" required></label>
                <button class="button primary" type="submit">Anmelden <span aria-hidden="true">→</span></button>
            </form>
        </article>
    </main>
<?php endif; ?>
<?php if ($account !== null): ?>
    <a class="skip" href="#main">Zum Inhalt</a>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-top">
                <a class="brand" href="/"><span class="brand-icon" aria-hidden="true">🎓</span> bruno</a>
                <form class="signout mobile-signout" method="post">
                    <?php $hidden(verb: 'logout'); ?>
                    <button type="submit" class="quiet">Abmelden</button>
                </form>
            </div>
            <div class="sidebar-content">
                <p class="nav-heading">Lernstudio</p>
                <nav aria-label="Hauptnavigation">
                    <a href="/" <?= in_array($view, ['home', 'lesson', 'study'], strict: true) ? 'aria-current="page"' : '' ?>>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 10.5 12 3l8.5 7.5v9a1 1 0 0 1-1 1h-5v-6h-5v6h-5a1 1 0 0 1-1-1z"/></svg>
                        Übersicht
                    </a>
                    <a href="<?= $route('create') ?>" <?= $view === 'create' ? 'aria-current="page"' : '' ?>>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8.5 12h7m-3.5-3.5v7"/></svg>
                        Erstellen
                    </a>
                </nav>
                <div class="sidebar-foot">
                    <button class="quiet" type="button" aria-expanded="false" aria-controls="install-help" data-install>App installieren</button>
                    <p id="install-help" class="small muted" hidden>Im Browsermenü „App installieren“ oder unter iOS „Zum Home-Bildschirm“ wählen.</p>
                    <div class="account">
                        <span class="avatar" aria-hidden="true"><?= escape(mb_strtoupper(mb_substr($account['email'], start: 0, length: 1))) ?></span>
                        <div class="account-info">
                            <div class="small strong">Mein Konto</div>
                            <div class="small muted account-email" title="<?= escape($account['email']) ?>"><?= escape($account['email']) ?></div>
                        </div>
                    </div>
                    <form class="signout" method="post">
                        <?php $hidden(verb: 'logout'); ?>
                        <button type="submit" class="quiet">Abmelden</button>
                    </form>
                </div>
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
                    [$heroLink, $heroLabel, $heroArrow] = match (true) {
                        $due > 0 => [$route('study', $decks[0]['id']), 'Jetzt wiederholen', '→'],
                        $decks === [] => [$route('create'), 'Ersten Stapel erstellen', '→'],
                        default => ['#stapel', 'Meine Stapel', '↓'],
                    };
                    ?>
                <header class="screen-header">
                    <div><p class="eyebrow">Dein Lernbereich</p><h1>Übersicht</h1></div>
                    <button class="button secondary install-mobile" type="button" aria-expanded="false" aria-controls="install-mobile-help" data-install>App installieren</button>
                </header>
                <p id="install-mobile-help" class="notice" hidden>Im Browsermenü „App installieren“ oder unter iOS „Zum Home-Bildschirm“ wählen.</p>
                <section class="hero" aria-labelledby="hero-title">
                    <div class="hero-copy">
                        <span class="hero-badge"><span aria-hidden="true">✦</span> Lernen mit Spaced Repetition</span>
                        <h2 id="hero-title">Kleine Schritte.<br>Großes Wissen.</h2>
                        <p>bruno zeigt dir die Karten, die jetzt dran sind. Ein paar Minuten reichen für die nächste Runde.</p>
                        <a class="button hero-button" href="<?= $heroLink ?>"><?= $heroLabel ?> <span aria-hidden="true"><?= $heroArrow ?></span></a>
                    </div>
                    <svg class="hero-art" viewBox="0 0 400 288" aria-hidden="true">
                        <ellipse cx="242" cy="243" rx="108" ry="12" fill="#3f24b1" opacity=".42"/>
                        <path d="M33 179c21-68 128-114 232-111 46 1 86 14 103 38 29 43-57 99-159 112-48 6-92 4-123-7" fill="none" stroke="#a895fb" stroke-width="1.5" stroke-dasharray="4 7"/>
                        <rect x="140" y="61" width="150" height="173" rx="22" transform="rotate(13 215 148)" fill="#a695ff"/>
                        <rect x="119" y="69" width="151" height="173" rx="22" transform="rotate(-14 195 156)" fill="#e3dcff"/>
                        <g transform="rotate(6 221 152)">
                            <rect x="148" y="61" width="149" height="172" rx="21" fill="#f9f7ff"/>
                            <rect x="166" y="81" width="112" height="71" rx="12" fill="#f0eaff"/>
                            <path d="m220 88 9 24 24 9-24 9-9 24-9-24-24-9 24-9Z" fill="#784ded"/>
                            <path d="m248 92 4 9 9 4-9 4-4 9-4-9-9-4 9-4Z" fill="#f9bb3e"/>
                            <rect x="169" y="173" width="96" height="8" rx="4" fill="#d4c9fb"/>
                            <rect x="169" y="192" width="69" height="8" rx="4" fill="#e9e2ff"/>
                        </g>
                        <circle cx="301" cy="208" r="28" fill="#d5f579"/>
                        <path d="m289 208 8 8 15-17" stroke="#252032" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="m313 44 5 16 16 5-16 5-5 16-5-16-16-5 16-5ZM95 133l4 12 12 4-12 4-4 12-4-12-12-4 12-4Z" fill="#fbd068"/>
                        <circle cx="351" cy="150" r="4" fill="#d3c5ff"/>
                        <circle cx="129" cy="46" r="4" fill="#d3c5ff"/>
                    </svg>
                </section>
                <dl class="stats" aria-label="Lernstand">
                    <div><dt>Jetzt bereit</dt><dd><?= $due ?></dd></div>
                    <div><dt>Davon neue Karten</dt><dd><?= $new ?></dd></div>
                    <div><dt>Meine Stapel</dt><dd><?= count($decks) ?></dd></div>
                </dl>
                <section id="stapel" aria-labelledby="deck-title">
                    <header class="section-header"><div><p class="eyebrow">Bibliothek</p><h2 id="deck-title">Meine Stapel</h2></div>
                        <a class="button secondary" href="<?= $route('create') ?>"><span aria-hidden="true">＋</span> Neuer Stapel</a>
                    </header>
                    <?php if (!$decks): ?>
                        <article class="panel empty"><span class="deck-icon" aria-hidden="true">▤</span><h3>Hier beginnt deine Sammlung.</h3>
                            <p class="muted">Erstelle aus einem Thema oder einer Lernvorlage deinen ersten Stapel. Zugewiesene Anki-Stapel erscheinen ebenfalls hier.</p>
                        </article>
                    <?php endif; ?>
                    <?php if ($decks): ?>
                        <div class="deck-grid">
                        <?php foreach ($decks as $deck): ?>
                            <article class="panel deck">
                                <div class="deck-top">
                                    <span class="deck-icon" aria-hidden="true"><?= $deck['vocabulary'] ? 'Aa' : '▤' ?></span>
                                    <span class="badge <?= $deck['due'] ? 'due' : '' ?>"><?= $deck['due'] ? $deck['due'] . ' bereit' : 'Gerade nichts fällig' ?></span>
                                </div>
                                <h3><?= escape($deck['name']) ?></h3>
                                <p class="muted small"><?= $deck['total'] ?> <?= $deck['total'] === 1 ? 'Karte' : 'Karten' ?></p>
                                <div class="inline-actions">
                                    <a class="button secondary" href="<?= $route('lesson', $deck['id']) ?>">Vorbereiten</a>
                                    <a class="button primary" href="<?= $route('study', $deck['id']) ?>">Wiederholen</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php break;
                case 'create': ?>
                <a class="back-link" href="/">← Zur Übersicht</a>
                <header class="screen-header">
                    <div><p class="eyebrow">Werkstatt</p><h1>Stapel erstellen</h1>
                    <p class="muted">Aus einem Thema, einer Schulbuchseite oder einem Test werden bis zu zwölf Karten und ein Lerntext.</p></div>
                </header>
                <?php if (!$settings['ai']): ?><p class="notice">Der KI-Zugang ist noch nicht eingerichtet. Vorhandene Stapel kannst du weiterhin lernen.</p><?php endif; ?>
                <form method="post" action="<?= $route('create') ?>" enctype="multipart/form-data" class="panel form-grid editor" data-editor>
                    <?php $hidden(verb: 'generate'); ?>
                    <label>In welchem Stapel sollen die Karten landen?
                        <select name="deck">
                            <option value="0">Neuer Stapel</option>
                            <?php foreach ($data['decks'] as $deck): ?>
                                <option value="<?= $deck['id'] ?>" data-vocabulary="<?= $deck['vocabulary'] ? 'yes' : 'no' ?>" <?= $deckId === $deck['id'] ? 'selected' : '' ?>><?= escape($deck['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Titel für einen neuen Stapel
                        <input name="title" maxlength="100" placeholder="Beispiel: Englisch · Ferien" value="<?= escape(input($_POST, key: 'title')) ?>">
                    </label>
                    <label>Art der Karten
                        <select name="type">
                            <option value="topic" <?= $selectedType === 'topic' ? 'selected' : '' ?>>Wissensfragen</option>
                            <option value="vocabulary" <?= $selectedType === 'vocabulary' ? 'selected' : '' ?>>Vokabeln · Deutsch → Fremdsprache</option>
                            <option value="test" <?= $selectedType === 'test' ? 'selected' : '' ?>>Aus einem Test üben</option>
                        </select>
                    </label>
                    <label>Thema, Sprache oder Schwerpunkt
                        <textarea name="focus" rows="4" maxlength="2000" placeholder="Beispiel: Englische Vokabeln rund ums Reisen, Niveau A1"><?= escape(input($_POST, key: 'focus')) ?></textarea>
                    </label>
                    <label>Schulbuchauszug oder anonymisierter Test <span class="muted small">PDF, PNG, JPG, TXT · max. 8 MB</span>
                        <input type="file" name="document" accept="application/pdf,image/jpeg,image/png,text/plain">
                    </label>
                    <label class="check"><input type="checkbox" name="consent" value="yes">
                        <span>Ich habe personenbezogene Angaben entfernt. Die Vorlage darf zur Analyse an den KI-Dienst gesendet werden.</span>
                    </label>
                    <label class="check" data-picture-consent><input type="checkbox" name="pictureConsent" value="yes">
                        <span>Bei Vokabelkarten automatisch je eine Bild-Eselsbrücke mit fal.ai beauftragen (kostenpflichtig).</span>
                    </label>
                    <button type="submit" class="button primary" <?= $settings['ai'] ? '' : 'disabled' ?>>Karten erstellen <span aria-hidden="true">✦</span></button>
                </form>
            <?php break;
                case 'lesson': ?>
                <?php $lesson = $data['lesson']; ?>
                <a class="back-link" href="/">← Meine Stapel</a>
                <header class="screen-header">
                    <div><p class="eyebrow">Vorbereiten</p><h1><?= escape($lesson['name']) ?></h1>
                    <p class="muted">Mach dich mit dem Thema vertraut, bevor du die Karten wiederholst.</p></div>
                </header>
                <article class="panel lesson">
                    <h2>Lerntext</h2>
                    <p class="preline"><?= escape($lesson['text'] ?: 'Für diesen Stapel ist noch kein Lerntext hinterlegt.') ?></p>
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
                            <p class="muted small"><?= $mediaState === 'unknown' ? 'Auftragsstatus unklar. Es wird kein weiterer Auftrag gestartet.' : 'In Bearbeitung. Öffne diese Seite später erneut.' ?></p>
                        <?php endif; ?>
                        <?php if ($lesson[$kind] === '' && in_array($mediaState, ['', 'failed'], strict: true) && $settings['fal'] && $lesson['text'] !== ''): ?>
                            <?php if ($mediaState === 'failed'): ?><p class="muted small">Die Generierung ist fehlgeschlagen.</p><?php endif; ?>
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
                <a class="back-link" href="/">← Meine Stapel</a>
                <header class="screen-header">
                    <div><p class="eyebrow">Wiederholen</p><h1><?= escape($data['name']) ?></h1></div>
                    <a class="button secondary" href="<?= $route('lesson', $deckId) ?>">Vorbereiten</a>
                </header>
                <?php if (!$card): ?>
                    <article class="panel empty"><span class="deck-icon" aria-hidden="true">✓</span><h2>Fürs Erste geschafft.</h2>
                        <p class="muted">Anki hat gerade keine weitere Karte für dich. Die nächste Wiederholung erscheint, sobald sie fällig ist.</p>
                        <a class="button primary" href="/">Zur Übersicht</a>
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
                            <div class="flashcard-top"><span class="eyebrow">Frage</span><span class="deck-icon" aria-hidden="true">?</span></div>
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
                                            <p class="small muted"><?= in_array($card['imageState'], ['failed', 'unknown'], strict: true) ? 'Das Merkbild steht nicht zur Verfügung.' : 'Das Merkbild wird noch erstellt.' ?></p>
                                        <?php endif; ?>
                                        <?php if ($card['imageState'] === 'failed' && $settings['fal']): ?>
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
                                <label>Oder antworte selbst <textarea name="answer" rows="2" maxlength="1500" placeholder="Antwort eingeben …"></textarea></label>
                                <div class="inline-actions">
                                    <button class="button secondary" type="button" data-record>Aufnehmen</button>
                                    <button class="button primary" type="submit">Mit KI prüfen</button>
                                </div>
                                <p class="small muted">Text oder Aufnahme wird beim Prüfen an den KI-Dienst gesendet.</p>
                                <p class="small" data-record-status role="status"></p>
                            </form>
                            <div class="notice" data-feedback role="status" hidden></div>
                        <?php endif; ?>
                        <form class="panel rating-form" method="post" data-ratings hidden>
                            <?php $hidden(verb: 'answer', fields: $cardFields + ['shown' => $shown, 'signature' => $signature]); ?>
                            <h2>Wie gut wusstest du es?</h2>
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
                <a class="back-link" href="/">← Zur Übersicht</a>
                <h1>Das hat nicht geklappt.</h1>
            <?php endswitch; ?>
            <footer class="workspace-foot"><span>bruno</span><span>Dein Lernplan aus Anki</span></footer>
        </main>
    </div>
<?php endif; ?>
</body>
</html>
