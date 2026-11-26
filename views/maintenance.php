<?php

declare(strict_types=1);

namespace Bruno;

$backups = $data['backups'];
$latest = $backups[0]['createdAt'] ?? null;
$formatSize = static fn (int $bytes): string => match (true) {
    $bytes >= 1_048_576 => number_format($bytes / 1_048_576, decimals: 2, decimal_separator: ',', thousands_separator: '.') . ' MiB',
    $bytes >= 1024 => number_format($bytes / 1024, decimals: 2, decimal_separator: ',', thousands_separator: '.') . ' KiB',
    default => $bytes . ' B',
};
$syncStatus = $data['sync'];
$berlin = new \DateTimeZone('Europe/Berlin');
?>
<header class="screen-header">
    <h1>Wartung</h1>
    <a class="button secondary" href="<?= $route('maintenance') ?>"><?= $actionLabel(text: 'Aktualisieren', emoji: '🔄') ?></a>
</header>
<div class="inline-actions maintenance-links">
    <a class="button secondary" href="#backups"><?= $actionLabel(text: 'Backups', emoji: '💾') ?></a>
    <a class="button secondary" href="#sync"><?= $actionLabel(text: 'Anki-Sync', emoji: '🔄') ?></a>
    <a class="button secondary" href="#cron"><?= $actionLabel(text: 'Cronjob', emoji: '⏱️') ?></a>
</div>
<div class="maintenance-sections">
    <section id="backups" aria-labelledby="maintenance-backups">
        <header class="section-header">
            <div><h2 id="maintenance-backups">Backups</h2><p class="muted">Anki-Sammlung und Medien</p></div>
            <form method="post" action="<?= $route('maintenance') ?>#backups">
                <?php $hidden(verb: 'backup'); ?>
                <button class="button primary" type="submit"><?= $actionLabel(text: 'Backup erstellen', emoji: '💾') ?></button>
            </form>
        </header>
        <?php if ($backups === null): ?><p class="panel muted">Der Backup-Verlauf ist gerade nicht verfügbar.</p><?php endif; ?>
        <?php if ($backups !== null): ?>
            <dl class="stats maintenance-stats" aria-label="Backup-Status">
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
        <?php endif; ?>
        <?php if ($backups === []): ?><p class="panel muted">Noch keine Backups.</p><?php endif; ?>
        <?php if ($backups): ?>
            <section aria-labelledby="backup-history">
                <header class="section-header"><h3 id="backup-history">Backup-Verlauf</h3><span class="small muted">Zeitzone: Berlin</span></header>
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
                                            <summary class="button secondary"><?= $actionLabel(text: 'Backup löschen', emoji: '🗑️') ?></summary>
                                            <form method="post" action="<?= $route('maintenance') ?>#backups">
                                                <?php $hidden(verb: 'delete-backup', fields: ['backup' => $backup['id']]); ?>
                                                <button class="button secondary" type="submit"><?= $actionLabel(text: 'Endgültig löschen', emoji: '🗑️') ?></button>
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
    </section>
    <section id="sync" aria-labelledby="maintenance-sync">
        <header class="section-header">
            <div><h2 id="maintenance-sync">Anki-Sync</h2><p class="muted">bruno ↔ AnkiWeb · Karten, Lernstände und Medien</p></div>
            <?php if ($syncStatus !== null): ?>
                <form method="post" action="<?= $route('maintenance') ?>#sync" data-sync>
                    <?php $hidden(verb: 'sync-now'); ?>
                    <button class="button primary" type="submit" <?= !$syncStatus['configured'] || $syncStatus['running'] ? 'disabled' : '' ?>><?= $actionLabel(text: 'Jetzt synchronisieren', emoji: '🔄') ?></button>
                </form>
            <?php endif; ?>
        </header>
        <?php if ($syncStatus === null): ?><p class="panel muted">Der Synchronisationsstatus ist gerade nicht verfügbar.</p><?php endif; ?>
        <?php if ($syncStatus !== null): ?>
            <?php
            $syncLabel = match (true) {
                !$syncStatus['configured'] => 'Nicht eingerichtet',
                $syncStatus['running'] => 'Läuft',
                $syncStatus['result'] === 'success' => 'Erfolgreich',
                $syncStatus['result'] === 'failed' => 'Fehlgeschlagen',
                $syncStatus['result'] === 'started' => 'Unterbrochen',
                default => 'Noch kein Lauf erfasst',
            };
            ?>
            <dl class="stats maintenance-stats" aria-label="Synchronisationsstatus">
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
            <form class="panel form-grid" method="post" action="<?= $route('maintenance') ?>#sync">
                <?php $hidden(verb: 'sync-interval'); ?>
                <label>Automatischer Abgleich
                    <select name="interval" <?= $syncStatus['running'] ? 'disabled' : '' ?>>
                        <?php foreach (AnkiSync::INTERVALS as $minutes): ?>
                            <option value="<?= $minutes ?>" <?= $syncStatus['interval'] === $minutes ? 'selected' : '' ?>>Alle <?= $minutes ?> Minuten</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="small muted">Gilt für alle Konten. Änderungen in Anki Desktop zuerst dort mit AnkiWeb synchronisieren. Zeiten: Berlin.</p>
                <button class="button secondary" type="submit" <?= $syncStatus['running'] ? 'disabled' : '' ?>><?= $actionLabel(text: 'Intervall speichern', emoji: '⏱️') ?></button>
            </form>
        <?php endif; ?>
    </section>
    <section id="cron" aria-labelledby="maintenance-cron">
        <?php require __DIR__ . '/cron.php'; ?>
    </section>
</div>
