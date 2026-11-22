<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\EmployeeService;
use App\Application\TimeTrackingService;
use App\Application\UserAccessService;
use App\Domain\DailyReportValidationException;
use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Security\AuthorizationPolicy;
use App\Security\RolePermissions;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Verwaltung: Benutzerkonten über die Oberfläche, Mitarbeiterverknüpfung, Schutzregeln, Navigation. */
final class UserManagementTest extends TestCase
{
    public function testAdministrationRoutesUseExistingPermissions(): void
    {
        $required = static fn (string $method, string $path): array => AuthorizationPolicy::required(new Request($method, $path, [], [], [], ''));
        $this->assertSame(['users.manage'], $required('GET', '/settings/users'));
        $this->assertSame(['users.manage'], $required('GET', '/settings/permissions'));
        $this->assertSame(['users.manage'], $required('POST', '/api/users'));
        $this->assertSame(['users.manage'], $required('POST', '/api/users/x/password'));
        $this->assertSame(['employees.manage', 'costs.read'], $required('GET', '/master-data/employees'));
        $this->assertSame(['employees.manage', 'costs.read'], $required('GET', '/settings/employees'), 'Alter Pfad (Weiterleitung) bleibt geschützt.');
        $this->assertSame(['operations.manage'], $required('GET', '/settings/work-tracking'));
        $this->assertSame(['operations.manage'], $required('GET', '/settings/operations'));
        $this->assertSame(['master_data.read', 'costs.read'], $required('GET', '/master-data'));
        $this->assertTrue(!RolePermissions::allows('office', 'users.manage') && RolePermissions::allows('admin', 'users.manage'));
        $described = array_merge(...array_values(array_map('array_keys', RolePermissions::DESCRIPTIONS)));
        foreach (['office', 'technician'] as $role) {
            foreach (RolePermissions::forRole($role) as $permission) {
                $this->assertTrue(in_array($permission, $described, true), 'Recht ohne Beschreibung: ' . $permission);
            }
        }
    }

    public function testCreateUserHashesPasswordEnforcesUniqueNamesAndLogs(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['accounts' => $accounts, 'users' => $users, 'admin' => $admin, 'office' => $office, 'pdo' => $pdo] = $env;
            $created = $accounts->create(['username' => 'max.monteur', 'displayName' => 'Max Monteur', 'role' => 'technician', 'password' => 'geheim-1234', 'preferredInterface' => ''], $admin);
            $this->assertSame(['technician', true, null], [$created['role'], $created['active'], $created['preferredInterface']]);
            $row = $users->findActiveByUsername('max.monteur');
            $this->assertTrue(password_verify('geheim-1234', (string) $row['password_hash']) && $row['password_hash'] !== 'geheim-1234', 'Passwort nur als Hash.');
            $this->assertTrue(!str_contains(json_encode($accounts->overview($admin), JSON_THROW_ON_ERROR), 'password'), 'Kein Hash in der Übersicht.');
            $this->assertThrows(DocumentValidationException::class, fn () => $accounts->create(['username' => 'MAX.MONTEUR', 'displayName' => 'X', 'role' => 'office', 'password' => 'geheim-1234'], $admin));
            $this->assertThrows(DocumentValidationException::class, fn () => $accounts->create(['username' => 'kurz', 'displayName' => 'X', 'role' => 'office', 'password' => '1234567'], $admin), 'Mindestlänge.');
            $this->assertThrows(DocumentValidationException::class, fn () => $accounts->create(['username' => 'boss', 'displayName' => 'X', 'role' => 'superuser', 'password' => 'geheim-1234'], $admin), 'Nur bestehende Rollen.');
            $this->assertThrows(AccessDeniedException::class, fn () => $accounts->create(['username' => 'neu', 'displayName' => 'X', 'role' => 'admin', 'password' => 'geheim-1234'], $office), 'Keine Rechteausweitung durch Büro.');
            $this->assertSame('created', $accounts->account($created['id'], $admin)['events'][0]['action']);
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM user_account_events WHERE details LIKE '%geheim%'")->fetchColumn());
        });
    }

    public function testLastAdministratorSelfChangesAndSessionInvalidation(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['accounts' => $accounts, 'users' => $users, 'admin' => $admin, 'office' => $office] = $env;
            $input = static fn (array $account, array $changes = []): array => $changes + ['username' => $account['username'], 'displayName' => $account['displayName'], 'role' => $account['role'], 'active' => $account['active'], 'preferredInterface' => $account['preferredInterface'] ?? ''];
            $self = $accounts->account($admin['id'], $admin);
            $this->assertThrows(DocumentValidationException::class, fn () => $accounts->updateAccount($admin['id'], $input($self, ['role' => 'office']), $admin), 'Eigene Rolle nicht änderbar.');
            $this->assertThrows(DocumentValidationException::class, fn () => $accounts->updateAccount($admin['id'], $input($self, ['active' => false]), $admin), 'Eigenes Konto nicht deaktivierbar.');

            $second = $accounts->create(['username' => 'admin2', 'displayName' => 'Zweiter Admin', 'role' => 'admin', 'password' => 'geheim-1234'], $admin);
            $secondActor = ['id' => $second['id'], 'role' => 'admin'];
            $demoted = $accounts->updateAccount($admin['id'], $input($self, ['role' => 'office']), $secondActor);
            $this->assertSame('office', $demoted['role'], 'Mit zweitem Administrator zulässig.');
            $this->assertThrows(DocumentValidationException::class, fn () => $accounts->updateAccount($second['id'], $input($second, ['active' => false]), ['id' => 'fremd', 'role' => 'admin']), 'Letzter aktiver Administrator bleibt.');
            $this->assertSame(['role_changed'], array_column(array_filter($accounts->account($admin['id'], $secondActor)['events'], static fn (array $event): bool => $event['action'] === 'role_changed'), 'action'));

            // Passwortwechsel und Deaktivierung beenden Sitzungen (Sitzungsversion steigt).
            $before = (int) $users->findAccount($office['id'])['session_version'];
            $accounts->resetPassword($office['id'], ['password' => 'neues-passwort'], $secondActor);
            $after = (int) $users->findAccount($office['id'])['session_version'];
            $this->assertSame($before + 1, $after);
            $this->assertTrue(password_verify('neues-passwort', (string) $users->findActiveById($office['id'])['password_hash']));
            $officeAccount = $accounts->account($office['id'], $secondActor);
            $accounts->updateAccount($office['id'], $input($officeAccount, ['active' => false]), $secondActor);
            $this->assertSame($after + 1, (int) $users->findAccount($office['id'])['session_version']);
            $this->assertSame(null, $users->findActiveById($office['id']), 'Deaktivierte Konten werden bei der nächsten Anfrage abgemeldet.');
            $this->assertTrue($users->findAccount($office['id']) !== null, 'Konten werden nie gelöscht.');
        });
    }

    public function testEmployeesLinkUsersOneToOneAndCreateAccountsTransactionally(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['accounts' => $accounts, 'employees' => $employees, 'admin' => $admin, 'office' => $office, 'pdo' => $pdo, 'times' => $times, 'users' => $users] = $env;
            // Gemeinsame Anlage: Mitarbeiter und Monteurkonto in einer Transaktion.
            $employee = $employees->save(null, ['displayName' => 'Max Monteur', 'hourlyCostRate' => '42', 'active' => true, 'accountMode' => 'new',
                'newUser' => ['username' => 'max', 'password' => 'geheim-1234', 'role' => 'technician']], $admin);
            $this->assertSame(['max', 'technician', []], [$employee['username'], $employee['role'], $employee['hints']]);
            $technician = ['id' => (string) $employee['user_id'], 'role' => 'technician'];
            $this->assertSame((string) $employee['id'], $times->ownEmployee($technician)['id'], 'Neu angelegte Monteure erfassen sofort mobil.');
            $this->assertSame(240, $times->createEntry(['date' => '2026-11-20', 'start' => '08:00', 'end' => '12:00'], $technician)['totals']['netMinutes']);
            $this->assertTrue($users->findActiveByUsername('max') !== null);

            // Fehler im Konto (doppelter Benutzername) → auch kein Mitarbeiter.
            $count = (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();
            $this->assertThrows(DailyReportValidationException::class, fn () => $employees->save(null, ['displayName' => 'Doppelt', 'hourlyCostRate' => '1', 'accountMode' => 'new',
                'newUser' => ['username' => 'MAX', 'password' => 'geheim-1234']], $admin));
            $this->assertSame($count, (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn(), 'Transaktion vollständig zurückgerollt.');
            $this->assertThrows(AccessDeniedException::class, fn () => $employees->save(null, ['displayName' => 'Ohne Recht', 'hourlyCostRate' => '1', 'accountMode' => 'new',
                'newUser' => ['username' => 'ohne', 'password' => 'geheim-1234']], $office), 'Kontoanlage nur mit users.manage.');
            $this->assertSame($count, (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn());

            // 1:1 – ein Konto nur bei einem Mitarbeiter; Verknüpfung über die Benutzerverwaltung.
            $other = $employees->save(null, ['displayName' => 'Eva', 'hourlyCostRate' => '40', 'active' => true], $admin);
            $this->assertThrows(DailyReportValidationException::class, fn () => $employees->save((string) $other['id'], ['displayName' => 'Eva', 'hourlyCostRate' => '40', 'accountMode' => 'existing', 'userId' => $technician['id']], $admin));
            $officeAccount = $accounts->account($office['id'], $admin);
            $input = ['username' => $officeAccount['username'], 'displayName' => $officeAccount['displayName'], 'role' => 'office', 'active' => true, 'preferredInterface' => ''];
            $this->assertThrows(DocumentValidationException::class, fn () => $accounts->updateAccount($office['id'], $input + ['employeeId' => (string) $employee['id']], $admin), 'Bereits verknüpfter Mitarbeiter.');
            $this->assertSame('Eva', $accounts->updateAccount($office['id'], $input + ['employeeId' => (string) $other['id']], $admin)['employee']['name']);
            $this->assertSame(null, $accounts->updateAccount($office['id'], $input + ['employeeId' => ''], $admin)['employee'], 'Verknüpfung aufheben.');
            $this->assertSame(null, $times->ownEmployee($office));

            // Getrennte Deaktivierung mit Hinweis; historische Zuordnung bleibt.
            $inactive = $employees->save((string) $employee['id'], ['displayName' => 'Max Monteur', 'hourlyCostRate' => '45', 'active' => false, 'accountMode' => 'existing', 'userId' => $technician['id']], $admin);
            $this->assertTrue(count($inactive['hints']) === 1 && $users->findActiveById($technician['id']) !== null, 'Benutzerkonto bleibt aktiv, Hinweis erscheint.');
            $this->assertSame('42.0000', (string) $pdo->query('SELECT hourly_cost_rate_snapshot FROM time_entries')->fetchColumn(), 'Kostensnapshot unverändert.');
        });
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-users-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $users = new UserRepository($pdo);
            $employeeRepository = new EmployeeRepository($pdo);
            $accounts = new UserAccessService($users, $pdo, $employeeRepository);
            $test([
                'pdo' => $pdo, 'users' => $users, 'accounts' => $accounts,
                'employees' => new EmployeeService($employeeRepository, $users, $pdo, $accounts),
                'times' => new TimeTrackingService(new TimeTrackingRepository($pdo), new AttachmentStore($tmp . '/documents')),
                'admin' => $this->user($users, 'admin'), 'office' => $this->user($users, 'office'),
            ]);
        } finally {
            $this->remove($tmp);
        }
    }

    /** @return array{id:string,role:string} */
    private function user(UserRepository $users, string $role): array
    {
        $id = Uuid::v4();
        $users->create($id, $role . '-' . substr($id, 0, 8), password_hash('start-passwort', PASSWORD_DEFAULT), ucfirst($role), '2026-11-01T08:00:00Z', $role);
        return ['id' => $id, 'role' => $role];
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            is_dir($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}
