<?php

declare(strict_types=1);

namespace Bruno;

final class Upload
{
    /**
     * Keep the validated PHP upload path instead of a client-supplied filename.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $mime,
        public readonly string $extension,
    ) {
    }

    /**
     * Read the source before PHP removes the temporary file at the end of the request.
     */
    public function contents(): string
    {
        $bytes = file_get_contents($this->path);
        if ($bytes === false) {
            throw new AppException('Die Datei konnte nicht gelesen werden.', 422);
        }

        return $bytes;
    }
}
