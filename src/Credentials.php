<?php

declare(strict_types=1);

namespace Bruno;

use PDO;
use SensitiveParameter;

final class Credentials
{
    private readonly PDO $database;

    /**
     * Store only identity, authentication and throttling data outside Anki.
     */
    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, permissions: 0o700, recursive: true) && !is_dir($directory)) {
            throw new AppException('Der private Datenspeicher ist nicht verfügbar.', 503);
        }
        $this->database = new PDO('sqlite:' . $directory . '/users.sqlite');
        $this->database->exec('PRAGMA busy_timeout = 3000');
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, password TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS attempts (client TEXT PRIMARY KEY, slot INTEGER NOT NULL, attempts INTEGER NOT NULL)',
        );
    }

    /**
     * Keep the original account ID when rotating a password so deck assignments remain intact.
     */
    public function setPassword(string $email, #[SensitiveParameter] string $password): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new AppException('Ungültige E-Mail-Adresse.', 422);
        }
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new AppException('Das Passwort muss 12 bis 72 Bytes lang sein.', 422);
        }

        $statement = $this->database->prepare(
            'INSERT INTO users (id, email, password) VALUES (?, ?, ?) ON CONFLICT (email) DO UPDATE SET password = excluded.password',
        );
        $statement->execute([bin2hex(random_bytes(16)), $email, password_hash($password, PASSWORD_DEFAULT)]);
    }

    /**
     * Resolve the opaque identifier stored in deck metadata.
     */
    public function accountId(string $email): ?string
    {
        $statement = $this->database->prepare('SELECT id FROM users WHERE email = ?');
        $statement->execute([strtolower(trim($email))]);
        $id = $statement->fetchColumn();

        return is_string($id) ? $id : null;
    }

    /**
     * Bound login attempts without making unknown accounts distinguishable.
     *
     * @return array{id: string, email: string}|null
     */
    public function authenticate(string $email, #[SensitiveParameter] string $password, string $client): ?array
    {
        $slot = intdiv(time(), num2: 900);
        $this->database->prepare('DELETE FROM attempts WHERE slot < ?')->execute([$slot]);
        $attempt = $this->database->prepare(
            'INSERT INTO attempts (client, slot, attempts) VALUES (?, ?, 1)
            ON CONFLICT (client) DO UPDATE SET
                attempts = CASE WHEN slot = excluded.slot THEN attempts + 1 ELSE 1 END,
                slot = excluded.slot RETURNING attempts',
        );
        $attempt->execute([hash('sha256', $client), $slot]);
        $count = (int) $attempt->fetchColumn();
        $attempt->closeCursor();
        if ($count > 12) {
            throw new AppException('Zu viele Anmeldeversuche. Bitte später erneut versuchen.', 429);
        }

        $query = $this->database->prepare('SELECT id, email, password FROM users WHERE email = ?');
        $query->execute([strtolower($email)]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        $hash = $user['password'] ?? '$2y$12$JzEbvbvSX2Yw4iIk4RfubeEAhYOnWB5wYIQjgUXQ06czCX/RVRtpC';
        if (strlen($password) > 72 || str_contains($password, "\0") || !password_verify($password, $hash) || !$user) {
            return null;
        }

        return ['id' => $user['id'], 'email' => $user['email']];
    }
}
