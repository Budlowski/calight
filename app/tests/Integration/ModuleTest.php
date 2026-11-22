<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\MaterialRequestService;
use App\Application\ModuleService;
use App\Application\OperationalSettingsService;
use App\Domain\DailyReportValidationException;
use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Security\AuthorizationPolicy;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\MaterialRequestRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Modulverwaltung: Standardzustand, Routenprüfung, Deaktivierung mit Bestandsschutz. */
final class ModuleTest extends TestCase
{
    public function testExistingInstallationsKeepAllImplementedModules(): void
    {
        $this->withDatabase(function (PDO $pdo): void {
            $modules = new ModuleService($pdo);
            foreach (['core', 'work_tracking', 'procurement'] as $module) {
                $this->assertTrue($modules->isEnabled($module), $module . ' muss ohne Konfiguration aktiv sein.');
            }
            foreach (['billing', 'job_costing', 'contacts', 'calendar', 'lexware', 'unknown'] as $module) {
                $this->assertTrue(!$modules->isEnabled($module), $module . ' ist nicht implementiert.');
            }
            $this->assertTrue($modules->costCentersEnabled());
        });
    }

    public function testRoutesOfDisabledModulesStayReadableButRejectWrites(): void
    {
        $this->withDatabase(function (PDO $pdo, array $admin): void {
            $modules = new ModuleService($pdo);
            $this->assertSame('work_tracking', ModuleService::moduleForPath('/api/daily-reports/x/submit'));
            $this->assertSame('procurement', ModuleService::moduleForPath('/material-requests'));
            $this->assertSame(null, ModuleService::moduleForPath('/orders/x'));
            $this->assertSame(null, ModuleService::moduleForPath('/daily-reportsx'));
            $this->assertSame(true, $modules->setEnabled('procurement', false, false, $admin)['saved']);
            $this->assertTrue(!$modules->isEnabled('procurement'));
            $this->assertSame(null, $modules->blockedMessage('GET', '/api/purchase-orders'));
            $this->assertTrue($modules->blockedMessage('POST', '/api/purchase-orders') !== null);
            $this->assertTrue($modules->blockedMessage('POST', '/api/material-requests') !== null);
            $this->assertSame(null, $modules->blockedMessage('POST', '/api/daily-reports'), 'Arbeitserfassung bleibt ohne Beschaffung nutzbar.');
            $this->assertSame(null, $modules->blockedMessage('POST', '/api/cost-centers'), 'Kostenstellen bleiben mit Arbeitserfassung pflegbar.');
            $this->assertSame(true, $modules->setEnabled('work_tracking', false, false, $admin)['saved']);
            $this->assertTrue($modules->blockedMessage('PUT', '/api/time-tracking/entries/x') !== null);
            $this->assertTrue($modules->blockedMessage('POST', '/api/cost-centers') !== null);
            $this->assertSame(null, $modules->blockedMessage('POST', '/api/orders'), 'Basissystem ist nie betroffen.');
            $this->assertSame(true, $modules->setEnabled('procurement', true, false, $admin)['saved']);
            $this->assertSame(null, $modules->blockedMessage('POST', '/api/purchase-orders'), 'Beschaffung funktioniert ohne Arbeitserfassung.');
            $this->assertSame('0', (string) $pdo->query("SELECT setting_value FROM operational_settings WHERE setting_key = 'modules.work_tracking'")->fetchColumn());
        });
    }

    public function testOnlyAdministratorsChangeModulesAndCoreOrPlannedModulesAreFixed(): void
    {
        $this->withDatabase(function (PDO $pdo, array $admin, array $office): void {
            $modules = new ModuleService($pdo);
            $this->assertThrows(AccessDeniedException::class, fn () => $modules->setEnabled('procurement', false, false, $office));
            $this->assertThrows(AccessDeniedException::class, fn () => $modules->overview($office));
            $this->assertThrows(DocumentValidationException::class, fn () => $modules->setEnabled('core', false, false, $admin));
            $this->assertThrows(DocumentValidationException::class, fn () => $modules->setEnabled('billing', true, false, $admin));
            $this->assertSame(8, count($modules->overview($admin)));
            $this->assertSame(['operations.manage'], AuthorizationPolicy::required(new Request('PUT', '/api/modules/procurement', [], [], [], '')));
            $this->assertSame(['operations.manage'], AuthorizationPolicy::required(new Request('GET', '/settings/modules', [], [], [], '')));
        });
    }

    public function testOpenItemsBlockOrRequireConfirmationAndNoDataIsDeleted(): void
    {
        $this->withDatabase(function (PDO $pdo, array $admin): void {
            $modules = new ModuleService($pdo);
            $now = '2026-09-01T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Kunde',:now,:now)")->execute(['id' => $customer, 'now' => $now]);
            $request = Uuid::v4();
            $pdo->prepare("INSERT INTO material_requests(id,customer_id,description,status,created_by_user_id,created_at,updated_at) VALUES(:id,:customer,'Kabel','open',:user,:now,:now)")
                ->execute(['id' => $request, 'customer' => $customer, 'user' => $admin['id'], 'now' => $now]);

            // Offene Materialanforderung: Hinweis, Deaktivierung erst nach Bestätigung.
            $first = $modules->setEnabled('procurement', false, false, $admin);
            $this->assertSame(false, $first['saved']);
            $this->assertSame(['1 offene Materialanforderung'], $first['openItems']);
            $this->assertTrue($modules->isEnabled('procurement'));
            $this->assertSame(true, $modules->setEnabled('procurement', false, true, $admin)['saved']);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM material_requests')->fetchColumn(), 'Daten bleiben erhalten.');

            // Ohne Beschaffung: keine neuen Anforderungen, auch nicht über den Dienst; Lesen bleibt.
            $service = new MaterialRequestService(new MaterialRequestRepository($pdo), new OrderRepository($pdo), new DailyReportRepository($pdo), $modules);
            $this->assertTrue(!$service->enabled());
            $this->assertThrows(DailyReportValidationException::class, fn () => $service->create(['description' => 'Rohr'], $admin));
            $this->assertThrows(DailyReportValidationException::class, fn () => $service->delete($request, 1, $admin));
            $this->assertSame(true, $modules->setEnabled('procurement', true, false, $admin)['saved'], 'Wiederaktivierung jederzeit.');
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM material_requests WHERE status = 'open'")->fetchColumn());

            // Offene Freigabe (zur Korrektur geöffneter Tag): nur nach Bestätigung, Zustand bleibt.
            $employee = Uuid::v4();
            $pdo->prepare("INSERT INTO employees (id, user_id, display_name, hourly_cost_rate, active, created_at, updated_at) VALUES (:id, NULL, 'Max', '40.0000', 1, :now, :now)")
                ->execute(['id' => $employee, 'now' => $now]);
            $pdo->prepare("INSERT INTO time_day_locks (employee_id, work_date, status, updated_by_user_id, updated_at) VALUES (:employee, '2026-08-31', 'reopened', :user, :now)")
                ->execute(['employee' => $employee, 'user' => $admin['id'], 'now' => $now]);
            $pending = $modules->setEnabled('work_tracking', false, false, $admin);
            $this->assertSame(false, $pending['saved']);
            $this->assertSame(1, count($pending['openItems']));
            $this->assertTrue($modules->isEnabled('work_tracking'));
            $this->assertSame(true, $modules->setEnabled('work_tracking', false, true, $admin)['saved']);
            $this->assertSame('reopened', (string) $pdo->query("SELECT status FROM time_day_locks WHERE employee_id = " . $pdo->quote($employee))->fetchColumn(), 'Keine automatische Freigabe oder Änderung.');
            $this->assertSame(true, $modules->setEnabled('work_tracking', true, false, $admin)['saved']);
            $this->assertSame(null, $modules->blockedMessage('POST', '/api/time-tracking/submissions'), 'Nach Wiederaktivierung regulär bearbeitbar.');
        });
    }

    public function testSettingsOfDisabledModulesAreHiddenAndRestoredOnReactivation(): void
    {
        $this->withDatabase(function (PDO $pdo, array $admin): void {
            $modules = new ModuleService($pdo);
            $settings = new OperationalSettingsService($pdo, $modules);
            $settings->save(['time_limits.max_daily_hours' => '9'], $admin);
            $modules->setEnabled('work_tracking', false, true, $admin);
            $hidden = $settings->all($admin);
            // Übrig bleibt nur die Auftragsabwicklung (Basissystem), keine Einstellung der Arbeitserfassung.
            $this->assertSame(['orders.technician_visibility'], array_column($hidden['settings'], 'key'));
            $this->assertSame(['orders'], array_keys($hidden['groups']));
            $this->assertThrows(DocumentValidationException::class, fn () => $settings->save(['time_limits.max_daily_hours' => '8'], $admin));
            $modules->setEnabled('work_tracking', true, false, $admin);
            $this->assertSame('9', $settings->values()['time_limits.max_daily_hours']);
            $this->assertTrue(count($settings->all($admin)['settings']) > 0);
        });
    }

    private function withDatabase(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-modules-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $test($pdo, $this->user($pdo, 'admin'), $this->user($pdo, 'office'));
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tmp);
        }
    }

    /** @return array{id:string,role:string} */
    private function user(PDO $pdo, string $role): array
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:username,'hash',:name,:role,1,'2026-09-01T08:00:00Z','2026-09-01T08:00:00Z')")
            ->execute(['id' => $id, 'username' => $role . '-' . substr($id, 0, 8), 'name' => ucfirst($role), 'role' => $role]);
        return ['id' => $id, 'role' => $role];
    }
}
