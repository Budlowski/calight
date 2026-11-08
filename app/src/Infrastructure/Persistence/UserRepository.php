<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final readonly class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findActiveByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE username = :username AND active = 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function create(string $id, string $username, string $passwordHash, string $displayName, string $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, display_name, role, created_at, updated_at)
             VALUES (:id, :username, :password_hash, :display_name, :role, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'username' => $username,
            'password_hash' => $passwordHash,
            'display_name' => $displayName,
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
