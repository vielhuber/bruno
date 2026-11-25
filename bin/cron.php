<?php

declare(strict_types=1);

namespace Bruno;

use DateTimeImmutable;
use DateTimeZone;

require __DIR__ . '/../src/bootstrap.php';

$directory = $config['data_dir'];
$ready = is_dir($directory) || mkdir($directory, permissions: 0o700, recursive: true) || is_dir($directory);
$lock = $ready ? fopen($directory . '/scheduled-cron.lock', mode: 'c') : false;
if ($lock === false) {
    fwrite(STDERR, data: "Cronjob konnte nicht gesperrt werden.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo '{"busy":true}', PHP_EOL;
    exit(0);
}

$started = new DateTimeImmutable(timezone: new DateTimeZone('Europe/Berlin'));
$anki = new AnkiBridge($config['python'], $directory);
$sync = new AnkiSync($anki, $directory, $config['sync_endpoint'] ?? '');
$worker = new MediaWorker($anki, new Gateway($config['ai_key'], $config['ai_model']), $directory, $config['ai_key'], $config['fal_key']);
$history = new CronHistory($directory);
$jobs = [];
if ($started->format('H:i') === '03:17') {
    $jobs['backup'] = fn (): array => $anki->call('backup');
}
if (in_array($started->format('i'), ['00', '30'], strict: true)) {
    $jobs['sync'] = $sync->run(...);
}
$jobs['media'] = $worker->run(...);

$results = [];
$failed = false;
foreach ($jobs as $job => $action) {
    try {
        $results[$job] = $history->run($job, $action);
    } catch (AppException $error) {
        $results[$job] = ['error' => $error->getMessage()];
        $failed = true;
    }
}
fclose($lock);

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
exit($failed ? 1 : 0);
