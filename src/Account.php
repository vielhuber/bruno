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

    /**
     * Retain the stable identity and the password version needed to resume a session.
     *
     * @return array{id: string, email: string, passwordVersion: string}
     */
    public function sessionData(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'passwordVersion' => $this->passwordVersion];
    }
}
