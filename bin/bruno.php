<?php

declare(strict_types=1);

namespace Bruno;

require __DIR__ . '/../src/bootstrap.php';

$action = $argv[1] ?? '';
$email = $argv[2] ?? '';
$anki = new AnkiBridge($config['python'], $config['data_dir']);
$sync = new AnkiSync($anki, $config['data_dir'], $config['sync_endpoint'] ?? '');

try {
    if ($action === 'user') {
        $password = rtrim((string) fgets(STDIN), characters: "\r\n");
        (new Credentials($config['data_dir']))->setPassword($email, $password);
        echo "Zugang gespeichert.\n";
        exit(0);
    }

    $result = match ($action) {
        'assign' => $anki->call('assign', [
            'owner' => (new Credentials($config['data_dir']))->accountId($email)
                ?? throw new AppException('Unbekanntes Konto.'),
            'name' => $argv[3] ?? '',
        ]),
        'sync-login' => $anki->call('sync', [
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ?: throw new AppException('Ungültige E-Mail-Adresse.'),
            'password' => rtrim((string) fgets(STDIN), characters: "\r\n") ?: throw new AppException('Passwort fehlt.'),
            'endpoint' => $config['sync_endpoint'] ?? '',
        ]),
        'sync' => $sync->run(
            manual: ($argv[2] ?? '') !== '--scheduled',
            initialUpload: ($argv[2] ?? '') === '--initial-upload',
        ),
        'backup' => $anki->call('backup'),
        'mark-vocabulary-decks' => $anki->call('mark-vocabulary-decks'),
        'media' => (new MediaWorker($anki, new Gateway($config['ai_key'], $config['ai_model']), $config['data_dir'], $config['ai_key'], $config['fal_key']))->run(),
        default => throw new AppException('Kommandos: user, assign, sync-login, sync, backup, media, mark-vocabulary-decks.'),
    };
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (AppException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
