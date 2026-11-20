<?php

declare(strict_types=1);

namespace Bruno;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RuntimeException;

final class BackupHistory
{
    private const UNAVAILABLE = 'Der Backup-Verlauf ist derzeit nicht verfügbar.';

    /**
     * Use the same private storage as the scheduled backup job.
     */
    public function __construct(private readonly string $dataDirectory)
    {
    }

    /**
     * Include finalized archives without opening Anki or disclosing their paths.
     *
     * @return list<array{id: string, createdAt: DateTimeImmutable, bytes: int}>
     */
    public function entries(): array
    {
        $berlinTime = new DateTimeImmutable(timezone: new DateTimeZone('Europe/Berlin'));
        $entries = [];
        foreach ($this->archives() as $id => $archive) {
            $entries[] = ['id' => $id, 'createdAt' => $berlinTime->setTimestamp($archive['timestamp']), 'bytes' => $archive['bytes']];
        }

        usort($entries, static fn (array $left, array $right): int => $right['createdAt'] <=> $left['createdAt']);
        return $entries;
    }

    /**
     * Remove only the selected archive while excluding concurrent backup writes.
     */
    public function delete(string $id): void
    {
        $lock = fopen($this->dataDirectory . '/collection.lock', mode: 'c');
        if ($lock === false) {
            throw new AppException(self::UNAVAILABLE, 503);
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new AppException('Anki ist gerade beschäftigt. Bitte gleich erneut versuchen.', 503);
            }

            foreach ($this->archives() as $archiveId => $archive) {
                if ($id !== $archiveId) {
                    continue;
                }
                if (!unlink($archive['path'])) {
                    throw new AppException('Das Backup konnte nicht gelöscht werden.', 503);
                }
                return;
            }
            throw new AppException('Backup nicht gefunden.', 404);
        } finally {
            fclose($lock);
        }
    }

    /**
     * Bind selections to a finalized file snapshot, not a user-supplied path.
     *
     * @return iterable<string, array{path: string, timestamp: int, bytes: int}>
     */
    private function archives(): iterable
    {
        $directory = $this->dataDirectory . '/backups';
        if (is_link($directory)) {
            throw new AppException(self::UNAVAILABLE, 503);
        }
        if (!file_exists($directory) && is_readable($this->dataDirectory)) {
            return;
        }

        try {
            foreach (new FilesystemIterator($directory) as $archive) {
                if ($archive->getExtension() !== 'colpkg' || $archive->isLink() || !$archive->isFile()) {
                    continue;
                }
                $bytes = $archive->getSize();
                if ($bytes === 0) {
                    continue;
                }
                $timestamp = $archive->getMTime();
                $id = hash('sha256', implode("\0", [$archive->getFilename(), $archive->getInode(), $timestamp, $bytes]));
                yield $id => ['path' => $archive->getPathname(), 'timestamp' => $timestamp, 'bytes' => $bytes];
            }
        } catch (RuntimeException) {
            throw new AppException(self::UNAVAILABLE, 503);
        }
    }
}
