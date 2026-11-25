<?php

declare(strict_types=1);

namespace Bruno;

use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;

final class ScheduledCron
{
    /**
     * Share the private configuration between the CLI and HTTPS entry points.
     *
     * @param array{data_dir: string, python: string, ai_key: string, ai_model: string, fal_key: string, sync_endpoint?: string} $config
     */
    public function __construct(#[SensitiveParameter] private readonly array $config)
    {
    }

    /**
     * Exclude overlapping invocations while recording the outcome of each due task.
     *
     * @return array{tasks: array<string, mixed>, failed: bool}
     */
    public function run(): array
    {
        $config = $this->config;
        $directory = $config['data_dir'];
        $ready = is_dir($directory) || mkdir($directory, permissions: 0o700, recursive: true) || is_dir($directory);
        $lock = $ready ? fopen($directory . '/scheduled-cron.lock', mode: 'c') : false;
        if ($lock === false) {
            throw new AppException('Cronjob konnte nicht gesperrt werden.', 503);
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return ['tasks' => ['busy' => true], 'failed' => false];
            }

            $started = new DateTimeImmutable(timezone: new DateTimeZone('Europe/Berlin'));
            $anki = new AnkiBridge($config['python'], $directory);
            $sync = new AnkiSync($anki, $directory, $config['sync_endpoint'] ?? '');
            $worker = new MediaWorker($anki, new Gateway($config['ai_key'], $config['ai_model']), $directory, $config['ai_key'], $config['fal_key']);
            $history = new CronHistory($directory);
            $jobs = [];
            if ($started->format('H:i') === '03:17') {
                $jobs['backup'] = static fn (): array => $anki->call('backup');
            }
            if (in_array($started->format('i'), ['00', '30'], strict: true)) {
                $jobs['sync'] = $sync->run(...);
            }
            $jobs['media'] = $worker->run(...);

            $tasks = [];
            $failed = false;
            foreach ($jobs as $job => $action) {
                try {
                    $tasks[$job] = $history->run($job, $action);
                } catch (AppException $error) {
                    $tasks[$job] = ['error' => $error->getMessage()];
                    $failed = true;
                }
            }

            return ['tasks' => $tasks, 'failed' => $failed];
        } finally {
            fclose($lock);
        }
    }
}
