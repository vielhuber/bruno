<?php

declare(strict_types=1);

namespace Bruno;

use PDO;
use PDOException;

final class CronHistory
{
    public const JOBS = ['media', 'sync', 'backup'];
    public const LIMIT_PER_JOB = 60;

    private const RESULT_FIELDS = ['configured', 'skipped', 'busy', 'created', 'processed', 'mnemonics', 'images', 'videos', 'audios', 'issues'];

    /**
     * Keep the journal in the private data directory shared by the web and CLI workers.
     */
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Record both boundaries of an invocation without delaying or preventing it if the journal fails.
     *
     * @return array<string, mixed>
     */
    public function run(string $job, callable $action): array
    {
        $database = null;
        $id = null;
        try {
            if (is_dir($this->directory) || mkdir($this->directory, permissions: 0o700, recursive: true) || is_dir($this->directory)) {
                $database = $this->connect();
                $database->exec(
                    "CREATE TABLE IF NOT EXISTS runs (
                        id INTEGER PRIMARY KEY, job TEXT NOT NULL, started INTEGER NOT NULL,
                        finished INTEGER, state TEXT NOT NULL DEFAULT 'started', details TEXT NOT NULL DEFAULT '{}'
                    )",
                );
                $database->prepare('INSERT INTO runs (job, started) VALUES (?, ?)')->execute([$job, time()]);
                $id = $database->lastInsertId();
            }
        } catch (PDOException) {
            error_log('bruno: Could not record the start of a cron run.');
        }

        $state = 'failed';
        $details = [];
        try {
            $result = $action();
            $details = array_filter(
                array_intersect_key($result, array_flip(self::RESULT_FIELDS)),
                static fn (mixed $value): bool => is_bool($value) || is_int($value),
            );
            $busy = $details['busy'] ?? false;
            $issues = $details['issues'] ?? 0;
            $worked = ($details['processed'] ?? 0) > 0 || ($details['mnemonics'] ?? 0) > 0 || $issues > 0;
            $state = match (true) {
                !($details['configured'] ?? true), $details['skipped'] ?? false, !($details['created'] ?? true), $busy && !$worked => 'skipped',
                $issues > 0, $busy => 'warning',
                default => 'success',
            };

            return $result;
        } catch (AppException $error) {
            $details = ['errorCode' => $error->getCode()];
            throw $error;
        } finally {
            if ($database instanceof PDO && $id !== null) {
                try {
                    $database->prepare('UPDATE runs SET finished = ?, state = ?, details = ? WHERE id = ?')
                        ->execute([time(), $state, json_encode((object) $details, JSON_THROW_ON_ERROR), $id]);
                    $database->prepare(
                        'DELETE FROM runs WHERE job = :job AND id < (
                            SELECT id FROM runs WHERE job = :job ORDER BY id DESC LIMIT 1 OFFSET :offset
                        )',
                    )->execute(['job' => $job, 'offset' => self::LIMIT_PER_JOB - 1]);
                } catch (PDOException) {
                    error_log('bruno: Could not finish recording a cron run.');
                }
            }
        }
    }

    /**
     * Return the newest runs per job, or null if the journal is unavailable.
     *
     * @return ?array<string, list<array{started: int, finished: ?int, state: string, details: array<string, bool|int>}>>
     */
    public function recent(): ?array
    {
        if (!is_file($this->directory . '/cron.sqlite3')) {
            return [];
        }
        try {
            $runs = [];
            foreach ($this->connect()->query('SELECT job, started, finished, state, details FROM runs ORDER BY id DESC') as $row) {
                $job = $row['job'];
                if (count($runs[$job] ?? []) >= self::LIMIT_PER_JOB) {
                    continue;
                }
                $runs[$job][] = [
                    'started' => (int) $row['started'],
                    'finished' => $row['finished'] === null ? null : (int) $row['finished'],
                    'state' => $row['state'],
                    'details' => json_decode($row['details'], associative: true, flags: JSON_THROW_ON_ERROR),
                ];
            }

            return $runs;
        } catch (PDOException | \JsonException) {
            error_log('bruno: Could not read the cron journal.');

            return null;
        }
    }

    /**
     * Wait briefly for overlapping journal writes, never for the worker itself.
     */
    private function connect(): PDO
    {
        $database = new PDO('sqlite:' . $this->directory . '/cron.sqlite3', options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $database->exec('PRAGMA busy_timeout = 1000');

        return $database;
    }
}
