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

    /** @return array<string,mixed>|null */
    public function findActiveById(string $id): ?array
    {
        $statement=$this->pdo->prepare('SELECT * FROM users WHERE id=:id AND active=1');$statement->execute(['id'=>$id]);$user=$statement->fetch();return is_array($user)?$user:null;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT id,username,display_name,role,active,created_at,updated_at FROM users ORDER BY username COLLATE NOCASE')->fetchAll();
    }

    public function updateAccess(string $id,string $role,bool $active,string $now): bool
    {
        $statement=$this->pdo->prepare('UPDATE users SET role=:role,active=:active,updated_at=:updated_at WHERE id=:id');
        $statement->execute(['role'=>$role,'active'=>$active?1:0,'updated_at'=>$now,'id'=>$id]);return $statement->rowCount()===1;
    }

    public function create(string $id, string $username, string $passwordHash, string $displayName, string $now,string $role='admin'): void
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
            'role' => $role,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
