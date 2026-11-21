<?php

declare(strict_types=1);

namespace Bruno;

final class Account
{
    /**
     * Carry the login identity without exposing the stored password hash.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $passwordVersion,
    ) {
    }
}
