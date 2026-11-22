<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Security\RolePermissions;
use App\Support\Clock;
use App\Support\Uuid;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Benutzerkonten (Einstellungen → Benutzerkonten, nur users.manage).
 *
 * Nutzt die bestehende Session-Authentifizierung, `password_hash` und die festen Rollen aus
 * RolePermissions – keine eigene Rechteverwaltung. Konten werden nie gelöscht, nur deaktiviert.
 * Passwortwechsel und Deaktivierung erhöhen die Sitzungsversion und beenden damit bestehende
 * Sitzungen. Das letzte aktive Administratorkonto bleibt erhalten; die eigene Rolle und der eigene
 * Aktivstatus sind nicht änderbar (keine Selbstzuweisung oder Selbstaussperrung). Jede Änderung
 * wird in user_account_events protokolliert (ohne Passwörter).
 */
final readonly class UserAccessService
{
    public const MIN_PASSWORD_LENGTH = 8;
    public const MAX_PASSWORD_LENGTH = 200;

    public function __construct(private UserRepository $users, private ?PDO $pdo = null, private ?EmployeeRepository $employees = null)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->users->all();
    }

    /**
     * Übersicht: Konten mit Mitarbeiter, Rollen und wählbare Mitarbeiter.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function overview(array $actor): array
    {
        $this->requireManage($actor);
        return [
            'users' => array_map($this->mapAccount(...), $this->users->accounts()),
            'roles' => RolePermissions::ROLES,
            'employees' => array_map(static fn (array $employee): array => [
                'id' => $employee['id'], 'name' => $employee['display_name'], 'personnelNumber' => $employee['personnel_number'],
                'active' => (bool) $employee['active'], 'userId' => $employee['user_id'],
            ], $this->employees?->all() ?? []),
            'currentUserId' => (string) ($actor['id'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function account(string $id, array $actor): array
    {
        $this->requireManage($actor);
        $account = $this->users->findAccount($id) ?? throw new DocumentValidationException(['Das Benutzerkonto wurde nicht gefunden.']);
        return $this->mapAccount($account) + ['events' => array_map(static fn (array $event): array => [
            'action' => $event['action'], 'details' => $event['details'], 'at' => $event['created_at'], 'actorName' => $event['actor_name'],
        ], $this->users->events($id))];
    }

    /**
     * Konto anlegen (optional mit bestehendem Mitarbeiter verknüpfen), transaktional.
     *
     * @param array<string,mixed> $input username, displayName, role, password, active, preferredInterface, employeeId
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function create(array $input, array $actor): array
    {
        $this->requireManage($actor);
        $values = $this->values($input);
        $password = $this->password($input['password'] ?? '');
        $id = Uuid::v4();
        $now = Clock::now();
        $this->transaction(function () use ($id, $values, $password, $input, $actor, $now): void {
            $this->insertAccount($id, $values, $password, $now);
            $this->users->addEvent(Uuid::v4(), $id, $this->actorId($actor), 'created', 'Rolle ' . RolePermissions::ROLES[$values['role']] . ($values['active'] ? '' : ', inaktiv'), $now);
            $this->linkEmployee($id, (string) ($input['employeeId'] ?? ''), $actor, $now);
        });
        return $this->account($id, $actor);
    }

    /**
     * Anlage innerhalb einer bereits laufenden Transaktion (gemeinsame Anlage mit einem Mitarbeiter).
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     */
    public function createWithinTransaction(array $input, array $actor): string
    {
        $this->requireManage($actor);
        $values = $this->values($input);
        $password = $this->password($input['password'] ?? '');
        $id = Uuid::v4();
        $now = Clock::now();
        $this->insertAccount($id, $values, $password, $now);
        $this->users->addEvent(Uuid::v4(), $id, $this->actorId($actor), 'created', 'Rolle ' . RolePermissions::ROLES[$values['role']] . ' (mit Mitarbeiter angelegt)', $now);
        return $id;
    }

    /**
     * Konto bearbeiten: Benutzername, Anzeigename, Rolle, Aktivstatus, bevorzugte Oberfläche und
     * Mitarbeiter („employeeId“ leer = Verknüpfung aufheben; nicht angegeben = unverändert).
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function updateAccount(string $id, array $input, array $actor): array
    {
        $this->requireManage($actor);
        $current = $this->users->findAccount($id) ?? throw new DocumentValidationException(['Das Benutzerkonto wurde nicht gefunden.']);
        $values = $this->values($input);
        $self = $id === (string) ($actor['id'] ?? '');
        if ($self && $values['role'] !== $current['role']) {
            throw new DocumentValidationException(['Die eigene Rolle kann nicht geändert werden.']);
        }
        if ($self && !$values['active']) {
            throw new DocumentValidationException(['Das eigene Benutzerkonto kann nicht deaktiviert werden.']);
        }
        $now = Clock::now();
        $this->transaction(function () use ($id, $current, $values, $input, $actor, $now): void {
            // Prüfung innerhalb der Transaktion, damit zwei gleichzeitige Änderungen nicht beide den letzten Administrator entfernen.
            if ($current['role'] === 'admin' && (int) $current['active'] === 1 && ($values['role'] !== 'admin' || !$values['active']) && $this->users->activeAdministrators() <= 1) {
                throw new DocumentValidationException(['Das letzte aktive Administratorkonto muss erhalten bleiben.']);
            }
            try {
                $this->users->updateAccount($id, [
                    'username' => $values['username'], 'display_name' => $values['displayName'], 'role' => $values['role'],
                    'active' => $values['active'] ? 1 : 0, 'preferred_interface' => $values['preferredInterface'], 'updated_at' => $now,
                ]);
            } catch (PDOException $exception) {
                throw $this->uniqueViolation($exception);
            }
            $actorId = $this->actorId($actor);
            if ($values['role'] !== $current['role']) {
                $this->users->addEvent(Uuid::v4(), $id, $actorId, 'role_changed', RolePermissions::ROLES[$current['role']] . ' → ' . RolePermissions::ROLES[$values['role']], $now);
            }
            if ($values['active'] !== (bool) $current['active']) {
                $this->users->addEvent(Uuid::v4(), $id, $actorId, $values['active'] ? 'activated' : 'deactivated', '', $now);
                if (!$values['active']) {
                    $this->users->invalidateSessions($id);
                }
            }
            $changes = array_filter([
                $values['username'] !== $current['username'] ? 'Benutzername ' . $current['username'] . ' → ' . $values['username'] : null,
                $values['displayName'] !== $current['display_name'] ? 'Anzeigename geändert' : null,
                $values['preferredInterface'] !== $current['preferred_interface'] ? 'Bevorzugte Oberfläche geändert' : null,
            ]);
            if ($changes !== []) {
                $this->users->addEvent(Uuid::v4(), $id, $actorId, 'updated', implode('; ', $changes), $now);
            }
            if (array_key_exists('employeeId', $input)) {
                $this->linkEmployee($id, (string) $input['employeeId'], $actor, $now);
            }
        });
        return $this->account($id, $actor);
    }

    /**
     * Administratives Zurücksetzen des Passworts; beendet alle bestehenden Sitzungen des Kontos.
     *
     * @param array<string,mixed> $input password
     * @param array<string,mixed> $actor
     * @return array{sessionVersion:int,account:array<string,mixed>}
     */
    public function resetPassword(string $id, array $input, array $actor): array
    {
        $this->requireManage($actor);
        $this->users->findAccount($id) ?? throw new DocumentValidationException(['Das Benutzerkonto wurde nicht gefunden.']);
        $hash = password_hash($this->password($input['password'] ?? ''), PASSWORD_DEFAULT);
        $now = Clock::now();
        $version = 0;
        $this->transaction(function () use ($id, $hash, $actor, $now, &$version): void {
            $version = $this->users->updatePassword($id, $hash, $now);
            $this->users->addEvent(Uuid::v4(), $id, $this->actorId($actor), 'password_reset', 'Bestehende Sitzungen beendet', $now);
        });
        return ['sessionVersion' => $version, 'account' => $this->account($id, $actor)];
    }

    /**
     * Bisherige Kurzänderung von Rolle und Aktivstatus (PATCH /api/users/{id}); bleibt kompatibel.
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    public function update(string $id, array $data, string $currentUserId): array
    {
        $role = (string) ($data['role'] ?? '');
        $active = array_key_exists('active', $data) ? filter_var($data['active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;
        if (!isset(RolePermissions::ROLES[$role]) || $active === null) {
            throw new InvalidArgumentException('Rolle oder Aktivstatus ist ungültig.');
        }
        if ($id === $currentUserId && !$active) {
            throw new InvalidArgumentException('Das eigene Benutzerkonto kann nicht deaktiviert werden.');
        }
        $users = $this->users->all();
        $target = null;
        foreach ($users as $user) {
            if ((string) $user['id'] === $id) {
                $target = $user;
                break;
            }
        }
        $activeAdmins = count(array_filter($users, static fn (array $user): bool => $user['role'] === 'admin' && (int) $user['active'] === 1));
        if (is_array($target) && $target['role'] === 'admin' && (int) $target['active'] === 1 && ($role !== 'admin' || !$active) && $activeAdmins <= 1) {
            throw new InvalidArgumentException('Das letzte aktive Administratorkonto muss erhalten bleiben.');
        }
        if (!$this->users->updateAccess($id, $role, $active, Clock::now())) {
            throw new InvalidArgumentException('Benutzerkonto wurde nicht gefunden.');
        }
        if (is_array($target) && (int) $target['active'] === 1 && !$active) {
            $this->users->invalidateSessions($id);
        }
        return $this->users->all();
    }

    // -------------------------------------------------------------------------
    // Hilfen
    // -------------------------------------------------------------------------

    /**
     * Mitarbeiter verknüpfen bzw. lösen. Ein Konto gehört höchstens zu einem Mitarbeiter und
     * umgekehrt (UNIQUE employees.user_id); bereits anderweitig verknüpfte Mitarbeiter werden
     * nicht übernommen. Keine Zuordnung über Namen.
     *
     * @param array<string,mixed> $actor
     */
    private function linkEmployee(string $userId, string $employeeId, array $actor, string $now): void
    {
        if ($this->employees === null) {
            return;
        }
        $employeeId = trim($employeeId);
        $current = $this->employees->findAnyByUser($userId);
        if (($current['id'] ?? '') === $employeeId) {
            return;
        }
        if ($current !== null) {
            $this->employees->linkUser((string) $current['id'], null, $now);
            $this->users->addEvent(Uuid::v4(), $userId, $this->actorId($actor), 'employee_unlinked', (string) $current['display_name'], $now);
        }
        if ($employeeId === '') {
            return;
        }
        $employee = $this->employees->find($employeeId) ?? throw new DocumentValidationException(['Der Mitarbeiter wurde nicht gefunden.']);
        if ($employee['user_id'] !== null && $employee['user_id'] !== $userId) {
            throw new DocumentValidationException(['Der Mitarbeiter „' . $employee['display_name'] . '“ ist bereits mit dem Benutzerkonto „' . $employee['username'] . '“ verknüpft.']);
        }
        $this->employees->linkUser($employeeId, $userId, $now);
        $this->users->addEvent(Uuid::v4(), $userId, $this->actorId($actor), 'employee_linked', (string) $employee['display_name'], $now);
    }

    /** @param array{username:string,displayName:string,role:string,active:bool,preferredInterface:?string} $values */
    private function insertAccount(string $id, array $values, string $password, string $now): void
    {
        try {
            $this->users->create($id, $values['username'], password_hash($password, PASSWORD_DEFAULT), $values['displayName'], $now, $values['role']);
        } catch (PDOException $exception) {
            throw $this->uniqueViolation($exception);
        }
        $this->users->updateAccount($id, [
            'username' => $values['username'], 'display_name' => $values['displayName'], 'role' => $values['role'],
            'active' => $values['active'] ? 1 : 0, 'preferred_interface' => $values['preferredInterface'], 'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{username:string,displayName:string,role:string,active:bool,preferredInterface:?string}
     */
    private function values(array $input): array
    {
        $errors = [];
        $username = trim((string) ($input['username'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $username) !== 1) {
            $errors[] = 'Der Benutzername muss 3 bis 64 Zeichen lang sein (Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich, @).';
        }
        $displayName = trim((string) ($input['displayName'] ?? ''));
        if ($displayName === '' || mb_strlen($displayName) > 150) {
            $errors[] = 'Der Anzeigename ist erforderlich und darf höchstens 150 Zeichen lang sein.';
        }
        $role = (string) ($input['role'] ?? '');
        if (!isset(RolePermissions::ROLES[$role])) {
            $errors[] = 'Bitte eine gültige Rolle wählen.';
        }
        $interface = (string) ($input['preferredInterface'] ?? '');
        if (!in_array($interface, ['', UserInterface::OFFICE, UserInterface::MOBILE], true)) {
            $errors[] = 'Die bevorzugte Oberfläche ist ungültig.';
        }
        if ($errors !== []) {
            throw new DocumentValidationException($errors);
        }
        return [
            'username' => $username, 'displayName' => $displayName, 'role' => $role,
            'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOL),
            'preferredInterface' => $interface === '' ? null : $interface,
        ];
    }

    private function password(mixed $value): string
    {
        $password = (string) $value;
        $length = mb_strlen($password);
        if ($length < self::MIN_PASSWORD_LENGTH || $length > self::MAX_PASSWORD_LENGTH || trim($password) === '') {
            throw new DocumentValidationException(['Das Passwort muss mindestens ' . self::MIN_PASSWORD_LENGTH . ' und höchstens ' . self::MAX_PASSWORD_LENGTH . ' Zeichen lang sein.']);
        }
        return $password;
    }

    private function uniqueViolation(PDOException $exception): Throwable
    {
        return str_starts_with((string) $exception->getCode(), '23')
            ? new DocumentValidationException(['Dieser Benutzername ist bereits vergeben.'])
            : $exception;
    }

    /** Läuft in einer bestehenden Transaktion mit, sonst in einer eigenen (BEGIN IMMEDIATE). */
    private function transaction(callable $operation): void
    {
        if ($this->pdo === null || $this->pdo->inTransaction()) {
            $operation();
            return;
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $operation();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $account @return array<string,mixed> */
    private function mapAccount(array $account): array
    {
        return [
            'id' => $account['id'], 'username' => $account['username'], 'displayName' => $account['display_name'],
            'role' => $account['role'], 'roleLabel' => RolePermissions::ROLES[$account['role']] ?? (string) $account['role'],
            'active' => (bool) $account['active'], 'preferredInterface' => $account['preferred_interface'],
            'employee' => $account['employee_id'] !== null ? ['id' => $account['employee_id'], 'name' => $account['employee_name'], 'active' => (bool) $account['employee_active']] : null,
            'createdAt' => $account['created_at'], 'updatedAt' => $account['updated_at'],
        ];
    }

    /** @param array<string,mixed> $actor */
    private function actorId(array $actor): ?string
    {
        $id = (string) ($actor['id'] ?? '');
        return $id === '' ? null : $id;
    }

    /** @param array<string,mixed> $actor */
    private function requireManage(array $actor): void
    {
        if (!RolePermissions::allows((string) ($actor['role'] ?? ''), 'users.manage')) {
            throw new AccessDeniedException('users.manage');
        }
    }
}
