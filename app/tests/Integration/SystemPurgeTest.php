<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\SystemPurgeBackup;
use App\Application\SystemPurgeConflictException;
use App\Application\SystemPurgePlanner;
use App\Application\SystemPurgeService;
use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Security\AuthorizationPolicy;
use App\Security\RolePermissions;
use App\Tests\TestCase;
use PDO;
use PDOException;

/**
 * Datenbereinigung (Einstellungen → System): Rechte, Vorschau, Abhängigkeiten, Zeiträume,
 * Sicherung, Bestätigung, Rollback, gemeinsam genutzte Dokumente, Nummernkreise und
 * Produktivstatus. Ausschließlich mit temporären Datenbanken und Verzeichnissen (Testdaten:
 * fixtures/system-purge.sql).
 */
final class SystemPurgeTest extends TestCase
{
    private const PASSWORD = 'Geheim-Test-123';
    private const BUSINESS = ['customers', 'projects', 'orders', 'offers', 'measurements', 'work_reports', 'daily_reports', 'time', 'travel', 'purchases', 'materials', 'documents'];
    private const ADMIN = ['id' => 'u-admin', 'role' => 'admin'];
    private const FILES = ['daily-report-statements/d1/ds1.pdf', 'time-statements/e1/ts1.pdf', 'attachments/a1.pdf', 'attachments/a2.pdf'];

    public function testOnlyAdministratorsMayPurge(): void
    {
        $this->withEnvironment(function (array $env): void {
            $service = $env['service'];
            $office = ['id' => 'u-office', 'role' => 'office'];
            $this->assertThrows(AccessDeniedException::class, fn () => $service->overview($office));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->preview(['areas' => ['customers']], $office));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->execute(['previewId' => 'x'], $office));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->activateProduction([], $office));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->preview(['areas' => ['customers']], ['id' => 'u-admin', 'role' => 'employee']), 'Altrolle ohne Vollzugriff auf die Bereinigung.');
            $this->assertTrue(RolePermissions::allows('admin', 'system.purge') && !RolePermissions::allows('office', 'system.purge') && !RolePermissions::allows('technician', 'system.purge'));
            foreach ([['GET', '/settings/system/purge'], ['GET', '/api/system/purge'], ['POST', '/api/system/purge/preview'], ['POST', '/api/system/purge/backup'],
                ['POST', '/api/system/purge/execute'], ['POST', '/api/system/production']] as [$method, $path]) {
                $this->assertSame(['system.purge'], AuthorizationPolicy::required(new Request($method, $path, [], [], [], '')), $path);
            }
            $this->assertSame(18, count($service->overview(self::ADMIN)['areas']));
        });
    }

    public function testPreviewShowsAllAffectedDataAndBlocksIncompleteSelections(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'pdo' => $pdo] = $env;
            $preview = $service->preview(['areas' => self::BUSINESS], self::ADMIN);
            $this->assertSame([true, [], 28], [$preview['executable'], $preview['blockers'], $preview['total']]);
            $this->assertSame([4, 4 * strlen('%PDF-test')], [$preview['files']['count'], $preview['files']['bytes']], 'Dateien in der Vorschau.');
            $areas = array_column($preview['areas'], 'total', 'key');
            $this->assertSame([2, 1, 1, 5, 12, 1, 1, 5], [$areas['customers'], $areas['projects'], $areas['orders'], $areas['daily_reports'], $areas['time'], $areas['travel'], $areas['materials'], $areas['documents']]);
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(), 'Die Vorschau verändert nichts.');
            $this->assertTrue(in_array('Benutzerkonten, Rollen und Berechtigungen', $preview['preserved'], true));

            // Nur Kunden: abhängige Vorgänge und verwaiste Dokumente blockieren.
            $customers = $service->preview(['areas' => ['customers']], self::ADMIN);
            $text = implode("\n", $customers['blockers']);
            $this->assertTrue(!$customers['executable'], 'Konflikte verhindern die Ausführung.');
            foreach (['„Projekte“', '„Aufträge einschließlich Nachträge“', '„Tagesberichte“', '„Materialanforderungen“', '„Sonstige Geschäftsdokumente“'] as $needed) {
                $this->assertTrue(str_contains($text, $needed), 'Fehlende Auswahl genannt: ' . $needed);
            }
            $this->assertThrows(DocumentValidationException::class, fn () => $service->execute(['previewId' => $customers['previewId'], 'backupId' => 'x',
                'password' => self::PASSWORD, 'confirmation' => SystemPurgeService::CONFIRMATION, 'confirmed' => true], self::ADMIN));

            // Mitarbeiter mit historischen Zeiten bleiben geschützt.
            $this->assertTrue(str_contains(implode("\n", $service->preview(['areas' => ['employees']], self::ADMIN)['blockers']), 'Zeitbuchungen'));

            // Zeitraum: gesperrte Tage und Verweise außerhalb der Auswahl blockieren, Einreichungen nur vollständig.
            $march = $service->preview(['areas' => ['daily_reports'], 'mode' => 'period', 'from' => '2026-03-01', 'to' => '2026-03-31'], self::ADMIN);
            $text = implode("\n", $march['blockers']);
            $this->assertTrue(str_contains($text, '„Materialanforderungen“') && str_contains($text, 'Zeiterfassungstagen'), 'Sperre und SET-NULL-Verweis erkannt.');
            $straddle = $service->preview(['areas' => ['time'], 'mode' => 'period', 'from' => '2026-03-12', 'to' => '2026-03-31'], self::ADMIN);
            $this->assertTrue(str_contains(implode("\n", $straddle['blockers']), '2026-W11'), 'Einreichung über die Zeitraumgrenze.');
            $april = $service->preview(['areas' => ['daily_reports'], 'mode' => 'period', 'from' => '2026-04-01', 'to' => '2026-04-30'], self::ADMIN);
            $this->assertSame([true, 3], [$april['executable'], $april['total']], 'Entwurf d2 mit Position und Buchung.');

            $this->assertThrows(DocumentValidationException::class, fn () => $service->preview(['areas' => ['customers'], 'mode' => 'period', 'from' => '2026-01-01', 'to' => '2026-12-31'], self::ADMIN), 'Kein Zeitraum für Kunden.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->preview(['areas' => ['time'], 'mode' => 'period', 'from' => '2026-03-31', 'to' => '2026-03-01'], self::ADMIN));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->preview(['areas' => ['users']], self::ADMIN), 'Benutzerkonten sind kein Bereich.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->preview(['areas' => []], self::ADMIN));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->preview(['areas' => ['time'], 'mode' => 'period', 'from' => '2026-01-01', 'to' => '2026-01-31', 'resetNumbers' => true], self::ADMIN));
        });
    }

    public function testExecutionNeedsBackupPasswordAndConfirmationAndKeepsUnselectedData(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'pdo' => $pdo, 'documents' => $documents] = $env;
            $preview = $service->preview(['areas' => self::BUSINESS], self::ADMIN);
            $request = ['previewId' => $preview['previewId'], 'password' => self::PASSWORD, 'confirmation' => SystemPurgeService::CONFIRMATION, 'confirmed' => true];
            $this->assertThrows(DocumentValidationException::class, fn () => $service->execute($request + ['backupId' => 'fehlt'], self::ADMIN), 'Ohne Sicherung keine Löschung.');

            $backup = $service->createBackup($preview['previewId'], self::ADMIN);
            $this->assertTrue(is_file($backup['directory'] . '/database.sqlite') && is_file($backup['directory'] . '/manifest.json'));
            $this->assertSame(4, $backup['fileCount']);
            $manifest = json_decode((string) file_get_contents($backup['directory'] . '/manifest.json'), true);
            $this->assertSame([hash_file('sha256', $backup['directory'] . '/database.sqlite'), 4], [$manifest['database']['sha256'], count($manifest['files'])]);
            $this->assertTrue(!str_contains((string) file_get_contents($backup['directory'] . '/manifest.json'), 'password'), 'Keine Zugangsdaten im Protokoll.');
            // Nur lesend öffnen: ConnectionFactory würde den WAL-Modus setzen und die Sicherung verändern.
            $copy = new \Pdo\Sqlite('sqlite:' . $backup['directory'] . '/database.sqlite', null, null, [\Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY]);
            $this->assertSame(2, (int) $copy->query('SELECT COUNT(*) FROM customers')->fetchColumn());
            $copy = null;

            $request['backupId'] = $backup['backupId'];
            $this->assertThrows(DocumentValidationException::class, fn () => $service->execute(['password' => 'falsch'] + $request, self::ADMIN), 'Falsches Passwort.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->execute(['confirmation' => 'daten löschen'] + $request, self::ADMIN), 'Bestätigungstext exakt.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->execute(['confirmed' => false] + $request, self::ADMIN), 'Ausdrückliche Bestätigung.');
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn());

            $result = $service->execute($request, self::ADMIN);
            $this->assertSame([28, 4, []], [$result['total'], $result['filesDeleted'], $result['warnings']]);
            foreach (['customers', 'projects', 'orders', 'daily_reports', 'daily_report_statements', 'time_entries', 'time_day_locks', 'time_submissions', 'time_statements',
                'time_private_trips', 'material_requests', 'attachments', 'attachment_links'] as $table) {
                $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $table . ' geleert.');
            }
            $this->assertSame([2, 1, 1, 2], [(int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn(),
                (int) $pdo->query('SELECT COUNT(*) FROM cost_centers')->fetchColumn(), (int) $pdo->query("SELECT COUNT(*) FROM assigned_numbers")->fetchColumn()],
                'Benutzer, Stammdaten und Nummernreservierungen bleiben.');
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM operational_settings WHERE setting_key = 'orders.technician_visibility'")->fetchColumn());
            foreach (self::FILES as $file) {
                $this->assertTrue(!is_file($documents . '/' . $file) && is_file($backup['directory'] . '/documents/' . $file), 'Datei entfernt und gesichert: ' . $file);
            }
            $this->assertSame([[], 'ok', 0], [$pdo->query('PRAGMA foreign_key_check')->fetchAll(), (string) $pdo->query('PRAGMA integrity_check')->fetchColumn(),
                (int) $pdo->query('SELECT COUNT(*) FROM system_purge_guard')->fetchColumn()]);
            $run = $pdo->query('SELECT status, user_id, backup_id, counts_json FROM system_purge_runs')->fetchAll();
            $this->assertSame([1, 'succeeded', 'u-admin', $backup['backupId'], 2], [count($run), $run[0]['status'], $run[0]['user_id'], $run[0]['backup_id'], json_decode($run[0]['counts_json'], true)['customers']]);
            $this->assertThrows(PDOException::class, fn () => $pdo->exec('DELETE FROM system_purge_runs'), 'Protokoll bleibt erhalten.');

            // Wiederholter Request: keine zweite Ausführung.
            $this->assertThrows(SystemPurgeConflictException::class, fn () => $service->execute($request, self::ADMIN));
            // Sperrtrigger sind außerhalb der Bereinigung wieder wirksam.
            $pdo->exec("INSERT INTO time_statements (id, employee_id, period_type, period_key, from_date, to_date, revision, status, snapshot_json, file_name, storage_path, sha256, size_bytes, created_by_user_id, created_at)
                VALUES ('ts9', 'e1', 'week', '2026-W20', '2026-05-11', '2026-05-17', 1, 'current', '{}', 'n.pdf', 'time-statements/e1/ts9.pdf', 'x', 1, 'u-admin', '2026-05-18T00:00:00Z')");
            $this->assertThrows(PDOException::class, fn () => $pdo->exec("DELETE FROM time_statements WHERE id = 'ts9'"), 'Nachweise bleiben unveränderlich.');
        });
    }

    public function testBackupFilesAreOnlyAccessibleToTheWebServerUser(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tmp' => $tmp] = $env;
            mkdir($tmp . '/backups', 0755);
            chmod($tmp . '/backups', 0755);
            $preview = $service->preview(['areas' => self::BUSINESS], self::ADMIN);
            $umask = umask();
            $backup = $service->createBackup($preview['previewId'], self::ADMIN);
            $this->assertSame($umask, umask(), 'umask nach der Sicherung wiederhergestellt.');
            clearstatcache();
            $this->assertSame(0700, fileperms($tmp . '/backups') & 0777, 'Zu offenes Sicherungsverzeichnis wird auf 0700 gesetzt.');
            $paths = [$backup['directory']];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($backup['directory'], \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
                $paths[] = $item->getPathname();
            }
            $this->assertTrue(count($paths) >= 7, 'Datenbank, Manifest, Dokumente.');
            foreach ($paths as $path) {
                $this->assertSame(is_dir($path) ? 0700 : 0600, fileperms($path) & 0777, 'Rechte: ' . basename($path));
            }
        });
    }

    public function testFailedBackupPreventsAnyDeletion(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['pdo' => $pdo, 'tmp' => $tmp] = $env;
            file_put_contents($tmp . '/kein-verzeichnis', 'x');
            foreach ([$tmp . '/kein-verzeichnis', $tmp . '/documents/backups', 'relativ/pfad'] as $target) {
                $service = $this->service($pdo, $tmp, $target);
                $preview = $service->preview(['areas' => self::BUSINESS], self::ADMIN);
                $this->assertThrows(DocumentValidationException::class, fn () => $service->createBackup($preview['previewId'], self::ADMIN), 'Sicherung schlägt fehl: ' . $target);
                $this->assertThrows(DocumentValidationException::class, fn () => $service->execute(['previewId' => $preview['previewId'], 'backupId' => '', 'password' => self::PASSWORD,
                    'confirmation' => SystemPurgeService::CONFIRMATION, 'confirmed' => true], self::ADMIN));
            }
            $this->assertSame([2, 0, 0], [(int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(), (int) $pdo->query('SELECT COUNT(*) FROM system_purge_backups')->fetchColumn(),
                (int) $pdo->query('SELECT COUNT(*) FROM system_purge_runs')->fetchColumn()]);
            $this->assertTrue(is_file($tmp . '/documents/attachments/a1.pdf'));
        });
    }

    public function testStalePreviewsAndChangedDataAreRejected(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'pdo' => $pdo] = $env;
            $preview = $service->preview(['areas' => self::BUSINESS], self::ADMIN);
            $pdo->exec("INSERT INTO customers (id, customer_number, customer_type, company_name, created_at, updated_at) VALUES ('c3', 'K-3', 'company', 'Neu', '2026-05-01T00:00:00Z', '2026-05-01T00:00:00Z')");
            $this->assertThrows(SystemPurgeConflictException::class, fn () => $service->createBackup($preview['previewId'], self::ADMIN), 'Vorschau nach Datenänderung veraltet.');

            $preview = $service->preview(['areas' => self::BUSINESS], self::ADMIN);
            $backup = $service->createBackup($preview['previewId'], self::ADMIN);
            $pdo->exec("UPDATE cost_centers SET name = 'Geändert' WHERE id = 'k1'");
            $request = ['previewId' => $preview['previewId'], 'backupId' => $backup['backupId'], 'password' => self::PASSWORD, 'confirmation' => SystemPurgeService::CONFIRMATION, 'confirmed' => true];
            $this->assertThrows(SystemPurgeConflictException::class, fn () => $service->execute($request, self::ADMIN), 'Änderung nach der Sicherung.');
            $this->assertSame([3, 'failed', null], [(int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(), $pdo->query('SELECT status FROM system_purge_runs')->fetchColumn(),
                $pdo->query("SELECT used_at FROM system_purge_previews WHERE id = " . $pdo->quote($preview['previewId']))->fetchColumn()]);

            $pdo->exec("UPDATE system_purge_previews SET expires_at = '2000-01-01T00:00:00Z'");
            $this->assertThrows(SystemPurgeConflictException::class, fn () => $service->createBackup($preview['previewId'], self::ADMIN), 'Abgelaufene Vorschau.');
        });
    }

    public function testDatabaseErrorRollsBackEverything(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'pdo' => $pdo, 'documents' => $documents] = $env;
            $preview = $service->preview(['areas' => self::BUSINESS], self::ADMIN);
            $backup = $service->createBackup($preview['previewId'], self::ADMIN);
            // Simulierter Datenbankfehler mitten in der Löschung (nach bereits gelöschten Zeilen).
            $pdo->exec("CREATE TRIGGER test_purge_failure BEFORE DELETE ON customers BEGIN SELECT RAISE(ABORT, 'test_failure'); END");
            $this->assertThrows(DocumentValidationException::class, fn () => $service->execute(['previewId' => $preview['previewId'], 'backupId' => $backup['backupId'],
                'password' => self::PASSWORD, 'confirmation' => SystemPurgeService::CONFIRMATION, 'confirmed' => true], self::ADMIN));
            $this->assertSame([2, 2, 1, 1, 3, 0], [(int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(), (int) $pdo->query('SELECT COUNT(*) FROM daily_reports')->fetchColumn(),
                (int) $pdo->query('SELECT COUNT(*) FROM daily_report_statements')->fetchColumn(), (int) $pdo->query('SELECT COUNT(*) FROM time_statements')->fetchColumn(),
                (int) $pdo->query('SELECT COUNT(*) FROM attachment_links')->fetchColumn(), (int) $pdo->query('SELECT COUNT(*) FROM system_purge_guard')->fetchColumn()], 'Vollständiger Rollback.');
            foreach (self::FILES as $file) {
                $this->assertTrue(is_file($documents . '/' . $file), 'Dateien bleiben bei Fehlern erhalten.');
            }
            $this->assertSame(['failed', null], [$pdo->query('SELECT status FROM system_purge_runs')->fetchColumn(),
                $pdo->query('SELECT used_at FROM system_purge_previews WHERE id = ' . $pdo->quote($preview['previewId']))->fetchColumn()]);
            $this->assertThrows(PDOException::class, fn () => $pdo->exec("DELETE FROM daily_reports WHERE id = 'd1'"), 'Sperrtrigger nach dem Rollback aktiv.');
        });
    }

    public function testSharedDocumentsPeriodsAndNumberRanges(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'pdo' => $pdo, 'documents' => $documents] = $env;
            // Nur Projekte: das gemeinsam genutzte Dokument a2 bleibt mit Datei und Kundenverknüpfung.
            $this->purge($service, ['areas' => ['projects']]);
            $this->assertSame([[['a1'], ['a2']], [['customer', 'c1'], ['customer', 'c2']]], [$pdo->query('SELECT id FROM attachments ORDER BY id')->fetchAll(PDO::FETCH_NUM),
                $pdo->query('SELECT target_type, target_id FROM attachment_links ORDER BY target_id')->fetchAll(PDO::FETCH_NUM)]);
            $this->assertTrue(is_file($documents . '/attachments/a2.pdf'));

            // Zeitraum: nur vollständig im Zeitraum liegende Vorgänge (Entwurf d2 im April).
            $this->purge($service, ['areas' => ['daily_reports'], 'mode' => 'period', 'from' => '2026-04-01', 'to' => '2026-04-30']);
            $this->assertSame([['d1']], $pdo->query('SELECT id FROM daily_reports')->fetchAll(PDO::FETCH_NUM));
            $this->assertSame([['t-d1'], ['t-m1']], $pdo->query('SELECT id FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_NUM), 'Keine Buchung ohne Bericht.');

            // Vollständig mit Nummernkreis-Reset: Kundenkreis zurück auf den ersten vergebenen Wert.
            $pdo->exec("UPDATE number_ranges SET next_counter = 10003 WHERE code = 'customer'");
            $remaining = array_values(array_diff(self::BUSINESS, ['projects']));
            $preview = $service->preview(['areas' => $remaining, 'resetNumbers' => true], self::ADMIN);
            $customerRange = array_values(array_filter($preview['numberRanges'], static fn (array $range): bool => $range['code'] === 'customer'))[0];
            $this->assertSame([10003, 10001], [$customerRange['next'], $customerRange['resetTo']]);
            $this->purge($service, ['areas' => $remaining, 'resetNumbers' => true]);
            $this->assertSame([10001, 0], [(int) $pdo->query("SELECT next_counter FROM number_ranges WHERE code = 'customer'")->fetchColumn(),
                (int) $pdo->query('SELECT COUNT(*) FROM assigned_numbers')->fetchColumn()]);
            $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        });
    }

    public function testProductionStatusLocksPurgePermanently(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'pdo' => $pdo] = $env;
            $this->assertThrows(DocumentValidationException::class, fn () => $service->activateProduction(['password' => 'falsch', 'confirmation' => SystemPurgeService::PRODUCTION_CONFIRMATION], self::ADMIN));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->activateProduction(['password' => self::PASSWORD, 'confirmation' => 'produktivbetrieb'], self::ADMIN));
            $preview = $service->preview(['areas' => self::BUSINESS], self::ADMIN);
            $backup = $service->createBackup($preview['previewId'], self::ADMIN);
            $service->activateProduction(['password' => self::PASSWORD, 'confirmation' => SystemPurgeService::PRODUCTION_CONFIRMATION], self::ADMIN);
            $this->assertSame('Admin', $service->overview(self::ADMIN)['production']['user']);
            $this->assertThrows(DocumentValidationException::class, fn () => $service->preview(['areas' => self::BUSINESS], self::ADMIN));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->execute(['previewId' => $preview['previewId'], 'backupId' => $backup['backupId'],
                'password' => self::PASSWORD, 'confirmation' => SystemPurgeService::CONFIRMATION, 'confirmed' => true], self::ADMIN), 'Auch eine vorbereitete Bereinigung ist gesperrt.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->activateProduction(['password' => self::PASSWORD, 'confirmation' => SystemPurgeService::PRODUCTION_CONFIRMATION], self::ADMIN));
            $this->assertThrows(PDOException::class, fn () => $pdo->exec('DELETE FROM system_production_status'), 'Nicht zurücksetzbar.');
            $this->assertThrows(PDOException::class, fn () => $pdo->exec("UPDATE system_production_status SET activated_at = 'x'"));
            $this->assertThrows(PDOException::class, fn () => $pdo->exec("INSERT INTO system_purge_guard (run_id) VALUES ('x')"), 'Auch direkt gesperrt.');
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn());
        });
    }

    /** Vollständiger Ablauf (Vorschau, Sicherung, Ausführung). @param array<string,mixed> $selection */
    private function purge(SystemPurgeService $service, array $selection): void
    {
        $preview = $service->preview($selection, self::ADMIN);
        $this->assertSame([], $preview['blockers'], implode(' ', $preview['blockers']));
        $backup = $service->createBackup($preview['previewId'], self::ADMIN);
        $service->execute(['previewId' => $preview['previewId'], 'backupId' => $backup['backupId'], 'password' => self::PASSWORD,
            'confirmation' => SystemPurgeService::CONFIRMATION, 'confirmed' => true], self::ADMIN);
    }

    private function service(PDO $pdo, string $tmp, string $backups): SystemPurgeService
    {
        return new SystemPurgeService($pdo, new SystemPurgePlanner($pdo, $tmp . '/documents', $tmp . '/offers'),
            new SystemPurgeBackup($pdo, $tmp . '/db.sqlite', $tmp . '/documents', $tmp . '/offers', $backups));
    }

    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-purge-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        mkdir($tmp . '/offers', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            self::useReleasedOrderVisibility($pdo);
            $pdo->exec((string) file_get_contents(__DIR__ . '/fixtures/system-purge.sql'));
            $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = 'u-admin'")->execute(['hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
            foreach (self::FILES as $file) {
                @mkdir(dirname($tmp . '/documents/' . $file), 0770, true);
                file_put_contents($tmp . '/documents/' . $file, '%PDF-test');
            }
            $test(['pdo' => $pdo, 'tmp' => $tmp, 'documents' => $tmp . '/documents', 'service' => $this->service($pdo, $tmp, $tmp . '/backups')]);
        } finally {
            $pdo = null;
            $this->remove($tmp);
        }
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
