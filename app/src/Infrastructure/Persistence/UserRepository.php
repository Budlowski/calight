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

    /** Bevorzugte Oberfläche ('office', 'mobile' oder null für den Standard der Rolle). */
    public function updatePreferredInterface(string $id, ?string $interface, string $now): bool
    {
        $statement = $this->pdo->prepare('UPDATE users SET preferred_interface = :interface, updated_at = :updated_at WHERE id = :id AND active = 1');
        $statement->execute(['interface' => $interface, 'updated_at' => $now, 'id' => $id]);
        return $statement->rowCount() === 1;
    }

    /** Konto unabhängig vom Aktivstatus (ohne Passwort-Hash). @return array<string,mixed>|null */
    public function findAccount(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT u.id, u.username, u.display_name, u.role, u.active, u.preferred_interface, u.session_version, u.created_at, u.updated_at,
                e.id AS employee_id, e.display_name AS employee_name, e.active AS employee_active
            FROM users u LEFT JOIN employees e ON e.user_id = u.id WHERE u.id = :id');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    /** Alle Konten mit zugeordnetem Mitarbeiter (ohne Passwort-Hash). @return list<array<string,mixed>> */
    public function accounts(): array
    {
        return $this->pdo->query('SELECT u.id, u.username, u.display_name, u.role, u.active, u.preferred_interface, u.created_at, u.updated_at,
                e.id AS employee_id, e.display_name AS employee_name, e.active AS employee_active
            FROM users u LEFT JOIN employees e ON e.user_id = u.id ORDER BY u.active DESC, u.display_name COLLATE NOCASE, u.username COLLATE NOCASE')->fetchAll();
    }

    public function activeAdministrators(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
    }

    /** @param array{username:string,display_name:string,role:string,active:int,preferred_interface:?string,updated_at:string} $values */
    public function updateAccount(string $id, array $values): bool
    {
        $statement = $this->pdo->prepare('UPDATE users SET username = :username, display_name = :display_name, role = :role, active = :active,
            preferred_interface = :preferred_interface, updated_at = :updated_at WHERE id = :id');
        $statement->execute($values + ['id' => $id]);
        return $statement->rowCount() === 1;
    }

    /** Neues Passwort (Hash) und neue Sitzungsversion: bestehende Sitzungen werden ungültig. */
    public function updatePassword(string $id, string $passwordHash, string $now): int
    {
        $this->pdo->prepare('UPDATE users SET password_hash = :hash, session_version = session_version + 1, updated_at = :now WHERE id = :id')
            ->execute(['hash' => $passwordHash, 'now' => $now, 'id' => $id]);
        return $this->sessionVersion($id);
    }

    /** Bestehende Sitzungen beenden (z. B. bei Deaktivierung). */
    public function invalidateSessions(string $id): int
    {
        $this->pdo->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = :id')->execute(['id' => $id]);
        return $this->sessionVersion($id);
    }

    private function sessionVersion(string $id): int
    {
        $statement = $this->pdo->prepare('SELECT session_version FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        return (int) $statement->fetchColumn();
    }

    /** Protokoll sicherheitsrelevanter Kontoänderungen (nie Passwörter oder Hashes). */
    public function addEvent(string $id, string $userId, ?string $actorId, string $action, string $details, string $now): void
    {
        $this->pdo->prepare('INSERT INTO user_account_events (id, user_id, actor_user_id, action, details, created_at) VALUES (:id, :user, :actor, :action, :details, :now)')
            ->execute(['id' => $id, 'user' => $userId, 'actor' => $actorId, 'action' => $action, 'details' => $details, 'now' => $now]);
    }

    /** @return list<array<string,mixed>> */
    public function events(string $userId, int $limit = 30): array
    {
        $statement = $this->pdo->prepare('SELECT ev.action, ev.details, ev.created_at, actor.display_name AS actor_name FROM user_account_events ev
            LEFT JOIN users actor ON actor.id = ev.actor_user_id WHERE ev.user_id = :user ORDER BY ev.created_at DESC, ev.id DESC LIMIT ' . max(1, min(100, $limit)));
        $statement->execute(['user' => $userId]);
        return $statement->fetchAll();
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
