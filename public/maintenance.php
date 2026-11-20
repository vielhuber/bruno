<?php

declare(strict_types=1);

namespace Bruno;

require __DIR__ . '/../src/bootstrap.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$job = input($_GET, key: 'job');
if (
    $config['cron_password_hash'] === ''
    || !filter_var($_SERVER['HTTPS'] ?? '', FILTER_VALIDATE_BOOL)
    || !in_array($job, ['backup', 'sync', 'media'], strict: true)
) {
    http_response_code(404);
    exit;
}

if (
    ($_SERVER['PHP_AUTH_USER'] ?? '') !== 'bruno-cron'
    || !hash_equals($config['cron_password_hash'], hash('sha256', $_SERVER['PHP_AUTH_PW'] ?? ''))
) {
    header('WWW-Authenticate: Basic realm="bruno maintenance"', response_code: 401);
    exit;
}

ignore_user_abort(enable: true);
set_time_limit(seconds: 0);

try {
    $anki = new AnkiBridge($config['python'], $config['data_dir']);
    $sync = new AnkiSync($anki, $config['data_dir'], $config['sync_endpoint'] ?? '');
    $result = match ($job) {
        'backup' => $anki->call('backup'),
        'sync' => $sync->run(),
        'media' => $anki->call('jobs', ['aiKey' => $config['ai_key'], 'falKey' => $config['fal_key']]),
    };
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (AppException $error) {
    http_response_code($error->getCode() ?: 503);
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
