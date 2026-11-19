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
     * @return list<array{createdAt: DateTimeImmutable, bytes: int}>
     */
    public function entries(): array
    {
        $directory = $this->dataDirectory . '/backups';
        if (is_link($directory)) {
            throw new AppException(self::UNAVAILABLE, 503);
        }
        if (!file_exists($directory) && is_readable($this->dataDirectory)) {
            return [];
        }

        $berlinTime = new DateTimeImmutable(timezone: new DateTimeZone('Europe/Berlin'));
        $entries = [];
        try {
            foreach (new FilesystemIterator($directory) as $archive) {
                if ($archive->getExtension() !== 'colpkg' || $archive->isLink() || !$archive->isFile()) {
                    continue;
                }
                $bytes = $archive->getSize();
                if ($bytes === 0) {
                    continue;
                }
                $entries[] = ['createdAt' => $berlinTime->setTimestamp($archive->getMTime()), 'bytes' => $bytes];
            }
        } catch (RuntimeException) {
            throw new AppException(self::UNAVAILABLE, 503);
        }

        usort($entries, static fn (array $left, array $right): int => $right['createdAt'] <=> $left['createdAt']);
        return $entries;
    }
}
