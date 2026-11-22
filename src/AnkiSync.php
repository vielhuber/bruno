<?php

declare(strict_types=1);

namespace Bruno;

final class AnkiSync
{
    public const INTERVALS = [30, 60, 120];

    private const LOCK_FILE = '/sync.lock';
    private const STATUS_FILE = '/sync-status.json';
    private const UNAVAILABLE = 'Der Synchronisationsstatus ist derzeit nicht verfügbar.';

    /**
     * Keep the server-wide status outside the web root and the Anki collection.
     */
    public function __construct(
        private readonly AnkiBridge $anki,
        private readonly string $directory,
        private readonly string $endpoint,
    ) {
    }

    /**
     * Check for a saved login without opening the collection.
     */
    public function isConfigured(): bool
    {
        return is_file($this->directory . '/anki-sync.key');
    }

    /**
     * Check for an active run without opening or waiting for the collection.
     *
     * @return array{interval: int, lastAttempt: ?int, lastSuccess: ?int, result: ?string, error: ?string, running: bool, configured: bool}
     */
    public function status(): array
    {
        $path = $this->directory . self::LOCK_FILE;
        $running = false;
        if (is_file($path)) {
            $lock = fopen($path, mode: 'r');
            if ($lock === false) {
                throw new AppException(self::UNAVAILABLE, 503);
            }
            $running = !flock($lock, LOCK_SH | LOCK_NB);
            fclose($lock);
        }

        return $this->read() + ['running' => $running, 'configured' => $this->isConfigured()];
    }

    /**
     * Apply the selected interval to the shared collection rather than one account.
     */
    public function setInterval(int $minutes): void
    {
        if (!in_array($minutes, self::INTERVALS, strict: true)) {
            throw new AppException('Bitte ein gültiges Synchronisationsintervall wählen.', 422);
        }

        $this->locked(function () use ($minutes): void {
            $state = $this->read();
            $state['interval'] = $minutes;
            $this->write($state);
        });
    }

    /**
     * Skip scheduled requests before opening Anki and retain the last successful run on errors.
     *
     * @return array{configured?: bool, skipped?: bool, synced?: bool}
     */
    public function run(bool $manual = false, bool $initialUpload = false): array
    {
        if (!$this->isConfigured()) {
            if ($manual) {
                throw new AppException('AnkiWeb ist noch nicht eingerichtet.', 503);
            }

            return ['configured' => false];
        }

        return $this->locked(function () use ($manual, $initialUpload): array {
            $state = $this->read();
            $started = time();
            if (!$manual && intdiv($started, num2: 60) - intdiv($state['lastAttempt'] ?? 0, num2: 60) < $state['interval']) {
                return ['skipped' => true];
            }

            $state['lastAttempt'] = $started;
            $state['result'] = 'started';
            $state['error'] = null;
            $this->write($state);
            try {
                $result = $this->anki->call('sync', ['endpoint' => $this->endpoint, 'initialUpload' => $initialUpload]);
            } catch (AppException $error) {
                $state['result'] = 'failed';
                $state['error'] = $error->getMessage();
                $this->write($state);
                throw $error;
            }
            if ($result['synced'] ?? false) {
                $state['lastSuccess'] = time();
                $state['result'] = 'success';
                $this->write($state);
            }

            return $result;
        });
    }

    /**
     * Serialize runs and settings changes without keeping parallel requests waiting.
     */
    private function locked(callable $action): mixed
    {
        $lock = fopen($this->directory . self::LOCK_FILE, mode: 'c');
        if ($lock === false) {
            throw new AppException(self::UNAVAILABLE, 503);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new AppException('Eine Synchronisierung läuft bereits.', 409);
            }

            return $action();
        } finally {
            fclose($lock);
        }
    }

    /**
     * Distinguish a new installation from one with an unreadable status file.
     *
     * @return array{interval: int, lastAttempt: ?int, lastSuccess: ?int, result: ?string, error: ?string}
     */
    private function read(): array
    {
        $path = $this->directory . self::STATUS_FILE;
        if (!is_file($path)) {
            return ['interval' => self::INTERVALS[0], 'lastAttempt' => null, 'lastSuccess' => null, 'result' => null, 'error' => null];
        }
        $content = file_get_contents($path);
        $state = $content === false ? null : json_decode($content, associative: true);
        if (!is_array($state)) {
            throw new AppException(self::UNAVAILABLE, 503);
        }

        return $state;
    }

    /**
     * Publish the next complete status in one atomic rename while the run lock is held.
     *
     * @param array{interval: int, lastAttempt: ?int, lastSuccess: ?int, result: ?string, error: ?string} $state
     */
    private function write(array $state): void
    {
        $path = $this->directory . self::STATUS_FILE;
        $pending = $path . '.new';
        $content = json_encode($state, JSON_THROW_ON_ERROR);
        if (file_put_contents($pending, $content) !== strlen($content) || !rename($pending, $path)) {
            throw new AppException('Der Synchronisationsstatus konnte nicht gespeichert werden.', 503);
        }
    }
}
