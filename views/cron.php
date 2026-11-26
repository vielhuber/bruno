<?php

declare(strict_types=1);

namespace Bruno;

$generationLabels = ['text' => 'Merksätze', 'image' => 'Merkbilder', 'video' => 'Lernvideos', 'audio' => 'Audio'];
$backlogLabels = [
    'queued' => 'wartend', 'waiting' => 'warten auf einen Merksatz', 'working' => 'in Bearbeitung',
    'failed' => 'fehlgeschlagen', 'unknown' => 'mit unklarem Status',
];
$jobLabels = ['media' => 'Medien und Merksätze', 'sync' => 'Anki-Sync', 'backup' => 'Backups'];
$runLabels = [
    'started' => 'Gestartet, kein Abschluss erfasst',
    'success' => 'Erfolgreich', 'skipped' => 'Übersprungen', 'warning' => 'Mit Hinweisen', 'failed' => 'Fehlgeschlagen',
];
$resultLabels = [
    'mnemonics' => 'Merksätze gespeichert', 'images' => 'Merkbilder gespeichert', 'videos' => 'Lernvideos gespeichert',
    'audios' => 'Audios gespeichert', 'processed' => 'Medienaufträge bearbeitet', 'issues' => 'Aufträge mit Problemen',
];
$number = static fn (int $value): string => number_format($value, thousands_separator: '.');
$berlin = new \DateTimeZone('Europe/Berlin');
$formatTime = static function (int $timestamp) use ($berlin): string {
    $instant = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($berlin);

    return '<time datetime="' . $instant->format(DATE_ATOM) . '"><span>' . $instant->format('d.m.Y')
        . '</span> <span class="muted">' . $instant->format('H:i:s') . '</span></time>';
};
?>
<header class="section-header">
    <div><h2 id="maintenance-cron">Cronjob</h2><p class="muted">Geplante Aufgaben und Generierungsfortschritt</p></div>
</header>
<div class="deck-grid">
    <section aria-labelledby="cron-backlog">
        <header class="section-header"><h3 id="cron-backlog">Noch zu generieren</h3></header>
        <?php if ($data['backlog'] === null): ?>
            <p class="panel muted">Der Generierungsstand ist gerade nicht verfügbar.</p>
        <?php endif; ?>
        <?php if ($data['backlog'] !== null): ?>
            <div class="panel backup-list">
                <table aria-labelledby="cron-backlog">
                    <thead><tr><th scope="col">Aufträge</th><th scope="col">Noch offen</th><th scope="col">Fertig</th></tr></thead>
                    <tbody>
                        <?php foreach ($generationLabels as $kind => $title): ?>
                            <?php
                            $counts = $data['backlog'][$kind];
                            if ($kind === 'audio' && !$counts['missing'] && !$counts['ready']) {
                                continue;
                            }
                            $breakdown = [];
                            foreach ($backlogLabels as $field => $description) {
                                if ($counts[$field]) {
                                    $breakdown[] = $number($counts[$field]) . ' ' . $description;
                                }
                            }
                            ?>
                            <tr>
                                <td><strong><?= $title ?></strong><?php if ($breakdown !== []): ?><br><span class="small muted"><?= implode(' · ', $breakdown) ?></span><?php endif; ?></td>
                                <td><strong><?= $number($counts['missing']) ?></strong></td>
                                <td><?= $number($counts['ready']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small muted">Fehlgeschlagene oder unklare Aufträge zählen als offen. Lernvideos zählen nur, wenn sie beauftragt wurden.</p>
        <?php endif; ?>
    </section>
    <section aria-labelledby="cron-history">
        <header class="section-header"><h3 id="cron-history">Letzte Läufe</h3><span class="small muted">Zeitzone: Berlin</span></header>
        <?php if ($data['runs'] === null): ?>
            <p class="panel muted">Der Cronverlauf ist gerade nicht verfügbar.</p>
        <?php endif; ?>
        <?php if ($data['runs'] !== null): ?>
            <div class="deck-grid">
                <?php foreach ($jobLabels as $job => $title): ?>
                    <?php
                    $runs = $data['runs'][$job] ?? [];
                    $latest = $runs[0] ?? null;
                    ?>
                    <details class="panel">
                        <summary>
                            <strong><?= $title ?></strong> · <?= $latest === null ? 'Noch kein Lauf erfasst' : $runLabels[$latest['state']] ?>
                            <?php if ($latest !== null): ?> · <?= $formatTime($latest['started']) ?> <?php endif; ?>
                        </summary>
                        <?php if ($runs): ?>
                            <div class="backup-list">
                                <table aria-label="<?= $title ?>: Verlauf">
                                    <thead><tr><th scope="col">Zeitpunkt</th><th scope="col">Ergebnis</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($runs as $run): ?>
                                            <?php
                                            $details = $run['details'];
                                            $messages = [];
                                            foreach ($resultLabels as $field => $description) {
                                                if ($details[$field] ?? 0) {
                                                    $messages[] = $number($details[$field]) . ' ' . $description;
                                                }
                                            }
                                            $message = match (true) {
                                                !($details['configured'] ?? true) => 'Nicht eingerichtet.',
                                                $details['skipped'] ?? false => 'Intervall noch nicht erreicht.',
                                                !($details['created'] ?? true) => 'Tagesbackup schon vorhanden.',
                                                $details['busy'] ?? false => 'Ein anderer Lauf bearbeitet bereits Aufträge.',
                                                ($details['errorCode'] ?? 0) > 0 => 'Fehlercode: ' . $details['errorCode'] . '.',
                                                $run['state'] !== 'success' => '',
                                                $job === 'backup' => 'Tagesbackup erstellt.',
                                                $job === 'sync' => 'Mit AnkiWeb abgeglichen.',
                                                !$messages => 'Keine Aufträge bearbeitet.',
                                                default => '',
                                            };
                                            if ($message !== '') {
                                                $messages[] = $message;
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    Start: <?= $formatTime($run['started']) ?>
                                                    <?php if ($run['finished'] !== null): ?><br>Ende: <?= $formatTime($run['finished']) ?> <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?= $runLabels[$run['state']] ?></strong>
                                                    <?php if ($messages !== []): ?><br><span class="small muted"><?= implode(' · ', $messages) ?></span><?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <?php if (!$runs): ?><p class="small muted">Der Verlauf beginnt mit der Einführung dieser Ansicht.</p><?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
            <p class="small muted">Pro Aufgabe werden die letzten <?= CronHistory::LIMIT_PER_JOB ?> Läufe gespeichert.</p>
        <?php endif; ?>
    </section>
</div>
