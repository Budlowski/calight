<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DailyReportService;
use App\Application\MaterialRequestService;
use App\Application\MeasurementService;
use App\Application\MobileWorkspace;
use App\Application\ModuleService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\OrderWorkRules;
use App\Application\PurchaseOrderPdfService;
use App\Application\PurchaseOrderService;
use App\Application\TimeTrackingService;
use App\Application\WorkReportService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\DailyReportValidationException;
use App\Domain\Document\DocumentValidationException;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Domain\Order\OrderOpenItemsException;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\MaterialRequestRepository;
use App\Infrastructure\Persistence\MeasurementRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\PurchaseOrderRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Infrastructure\Persistence\WorkReportRepository;
use App\Security\AuthorizationPolicy;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;
use PDOException;

/**
 * Einheitliche Auftragsstatus-Regeln (Systemaudit F-01/F-02): neue Leistungen nur bei offenen und
 * laufenden Aufträgen, Entwürfe/Korrekturen auch bei abgeschlossenen, kaufmännische Nachbearbeitung
 * immer; Statuswechsel mit Bestätigung offener Vorgänge, Rücknahme nur Administratoren mit Begründung.
 * Nur temporäre Datenbanken.
 */
final class OrderStatusRulesTest extends TestCase
{
    public function testCentralRuleTable(): void
    {
        $this->assertSame([true, true, false, false], array_map([OrderWorkRules::class, 'acceptsNewWork'], ['open', 'in_progress', 'completed', 'invoiced']));
        $this->assertSame([true, true, true, false], array_map([OrderWorkRules::class, 'acceptsChanges'], ['open', 'in_progress', 'completed', 'invoiced']));
        $this->assertSame(OrderWorkRules::NEW_WORK_STATUSES, WorkReportService::ASSIGNABLE_ORDER_STATUSES, 'Rapporte nutzen die zentrale Regel.');
        $this->assertSame(['invoiced' => 'completed', 'completed' => 'in_progress'], OrderWorkRules::REVERT, 'Rücknahme genau einen Schritt.');
        $this->assertTrue(!isset(OrderWorkRules::REVERT['in_progress']), 'Keine Rücknahme auf „Offen“.');
    }

    public function testNoNewWorkOnCompletedOrInvoicedOrdersInAllModules(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['orders' => $orders, 'order' => $order, 'office' => $office, 'max' => $max, 'pdo' => $pdo] = $env;
            $orders->changeStatus($order, 'in_progress', 'completed', false, $office);
            foreach (['completed', 'invoiced'] as $status) {
                if ($status === 'invoiced') {
                    $orders->changeStatus($order, 'completed', 'invoiced', false, $office);
                }
                $this->assertThrows(DocumentValidationException::class, fn () => $env['times']->createEntry(['date' => '2026-11-18', 'start' => '08:00', 'end' => '09:00', 'orderId' => $order], $max), 'Zeitbuchung ' . $status);
                $this->assertThrows(DailyReportValidationException::class, fn () => $env['daily']->create(['orderId' => $order, 'reportDate' => '2026-11-18', 'activities' => 'Montage', 'entries' => []], $max), 'Tagesbericht ' . $status);
                $this->assertThrows(OrderValidationException::class, fn () => $env['work']->create(['orderId' => $order, 'workDate' => '2026-11-18'], $max), 'Rapport ' . $status);
                $this->assertThrows(OrderValidationException::class, fn () => $env['measurements']->create(['orderId' => $order], $office), 'Aufmaß ' . $status);
                $this->assertThrows(DailyReportValidationException::class, fn () => $env['materials']->create(['orderId' => $order, 'description' => 'Kabel'], $office), 'Material ' . $status);
                $detail = $env['workspace']->order($order, $office);
                $this->assertSame([false, []], [$detail['order']['acceptsNewWork'], $detail['actions']], 'Mobil ohne Erfassungseinstiege: ' . $status);
                $this->assertSame(false, $orders->detail($order)['acceptsNewWork']);
                $this->assertTrue(!in_array($order, array_column($env['times']->referenceData($max)['orders'], 'id'), true), 'Nicht in der Auswahl der Zeiterfassung.');
                $this->assertTrue(!in_array($order, array_column($env['daily']->referenceData($office)['costTargets'], 'id'), true), 'Nicht in der Auswahl der Tagesberichte.');
            }
            // Fehlermeldung nennt den Grund.
            try {
                $env['daily']->create(['orderId' => $order, 'reportDate' => '2026-11-18', 'activities' => 'Montage', 'entries' => []], $office);
            } catch (DailyReportValidationException $exception) {
                $this->assertTrue(str_contains($exception->getMessage(), 'nimmt keine neuen Leistungen auf'));
            }

            // Kaufmännische Nachbearbeitung bleibt zulässig (Bestellung/Beleg zu abgerechnetem Auftrag).
            $pdo->exec("INSERT INTO suppliers (id, supplier_number, name, created_at, updated_at) VALUES ('s1', 'L-1', 'Großhandel', '2026-11-01T08:00:00Z', '2026-11-01T08:00:00Z')");
            $purchase = $env['purchases']->createInternal(['supplierId' => 's1', 'orderId' => $order], $env['admin']);
            $this->assertSame($order, $purchase['orderId'] ?? $purchase['order']['id'] ?? null, 'Bestellung zu abgerechnetem Auftrag möglich.');
        });
    }

    public function testExistingDraftsAndCorrectionsFollowTheChangeRule(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['orders' => $orders, 'order' => $order, 'office' => $office, 'max' => $max, 'admin' => $admin] = $env;
            // Entwurf vor dem Abschluss angelegt: bleibt bearbeit- und einreichbar, solange nicht abgerechnet.
            $draft = $env['daily']->create(['orderId' => $order, 'reportDate' => '2026-11-18', 'activities' => 'Montage', 'entries' => []], $max);
            $second = $env['daily']->create(['orderId' => $order, 'reportDate' => '2026-11-19', 'activities' => 'Montage', 'entries' => []], $max);
            $orders->changeStatus($order, 'in_progress', 'completed', true, $office);
            $draft = $env['daily']->update($draft['id'], ['version' => $draft['version'], 'reportDate' => '2026-11-18', 'activities' => 'Montage ergänzt', 'entries' => []], $max);
            $this->assertSame('submitted', $env['daily']->submit($draft['id'], ['version' => $draft['version']], $max)['status'], 'Bestehender Entwurf eines abgeschlossenen Auftrags.');
            $orders->changeStatus($order, 'completed', 'invoiced', true, $office);
            $this->assertThrows(DailyReportValidationException::class, fn () => $env['daily']->submit($second['id'], ['version' => $second['version']], $max), 'Nie bei abgerechneten Aufträgen.');
            // Rücknahme öffnet den Weg wieder; Bestehendes bleibt unverändert.
            $orders->revertStatus($order, 'invoiced', 'Rechnung storniert', $admin);
            $this->assertSame('submitted', $env['daily']->submit($second['id'], ['version' => $second['version']], $max)['status']);
            $this->assertSame('submitted', $env['daily']->detail($draft['id'], $office)['status']);
        });
    }

    public function testStatusChangesWithOpenItemsNeedConfirmationAndRevertsNeedAdminAndReason(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['orders' => $orders, 'order' => $order, 'office' => $office, 'max' => $max, 'admin' => $admin, 'pdo' => $pdo] = $env;
            $this->assertThrows(OrderValidationException::class, fn () => $orders->changeStatus($order, 'in_progress', 'invoiced', true, $office), 'Keine Sprünge.');
            $env['daily']->create(['orderId' => $order, 'reportDate' => '2026-11-18', 'activities' => 'Montage', 'entries' => []], $max);
            $env['materials']->create(['orderId' => $order, 'description' => 'Kabel'], $office);
            try {
                $orders->changeStatus($order, 'in_progress', 'completed', false, $office);
                $this->assertTrue(false, 'Offene Vorgänge müssen bestätigt werden.');
            } catch (OrderOpenItemsException $exception) {
                $this->assertSame(['daily_reports', 'material_requests'], array_column($exception->items, 'key'));
            }
            $this->assertSame('in_progress', $orders->detail($order)['status'], 'Ohne Bestätigung kein Wechsel.');
            $completed = $orders->changeStatus($order, 'in_progress', 'completed', true, $office);
            $this->assertSame('completed', $completed['status']);
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM daily_reports WHERE status = 'draft'")->fetchColumn(), 'Vorgänge werden nicht automatisch abgeschlossen.');
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM material_requests WHERE status = 'open'")->fetchColumn());
            $entry = $completed['history'][0];
            $this->assertSame(['advance', $office['id'], 2], [$entry['change_kind'], $entry['changed_by_user_id'], count(json_decode($entry['open_items_json'], true))]);

            // Rücknahme: nur Administratoren, mit Begründung, genau ein Schritt.
            $this->assertThrows(AccessDeniedException::class, fn () => $orders->revertStatus($order, 'completed', 'Versehen', $office));
            $this->assertThrows(OrderValidationException::class, fn () => $orders->revertStatus($order, 'completed', '  ', $admin));
            $this->assertThrows(OrderValidationException::class, fn () => $orders->revertStatus($order, 'invoiced', 'Falscher Ausgangsstatus', $admin), 'Erwarteter Status passt nicht.');
            $reverted = $orders->revertStatus($order, 'completed', 'Abschluss versehentlich', $admin);
            $this->assertSame(['in_progress', 'revert', 'Abschluss versehentlich', $admin['id']], [$reverted['status'], $reverted['history'][0]['change_kind'],
                $reverted['history'][0]['reason'], $reverted['history'][0]['changed_by_user_id']]);
            $this->assertThrows(OrderValidationException::class, fn () => $orders->revertStatus($order, 'in_progress', 'Zurück auf offen', $admin), 'Nicht auf „Offen“.');
            $this->assertSame(true, $reverted['acceptsNewWork'], 'Nach Rücknahme wieder Leistungen möglich.');
            $env['times']->createEntry(['date' => '2026-11-20', 'start' => '08:00', 'end' => '09:00', 'orderId' => $order], $max);
            // Die Datenbank verlangt für Rücknahmen Begründung und Benutzer.
            $this->assertThrows(PDOException::class, fn () => $pdo->exec("INSERT INTO order_status_history (id, order_id, previous_status, new_status, changed_at, change_kind) VALUES ('h1', " . $pdo->quote($order) . ", 'completed', 'in_progress', 'x', 'revert')"));
            // API: Rücknahme-Route unter dem Auftragsschreibrecht; Administratorprüfung im Dienst.
            $this->assertSame(['orders.write'], AuthorizationPolicy::required(new Request('POST', '/api/orders/x/status/revert', [], [], [], '')));
            $this->assertSame(['orders.write'], AuthorizationPolicy::required(new Request('PATCH', '/api/orders/x/status', [], [], [], '')));
        });
    }

    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-order-status-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            self::useReleasedOrderVisibility($pdo);
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Kunde','2026-11-01T08:00:00Z','2026-11-01T08:00:00Z')")
                ->execute(['id' => $customer]);
            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);
            $order = (string) $orders->createRegie(['customerId' => $customer, 'title' => 'Montage', 'orderDate' => '2026-11-01'])['id'];
            $orders->changeStatus($order, 'open', 'in_progress');

            $max = $this->user($pdo, 'technician');
            $office = $this->user($pdo, 'office');
            $admin = $this->user($pdo, 'admin');
            $pdo->prepare("INSERT INTO employees (id, user_id, display_name, hourly_cost_rate, active, created_at, updated_at) VALUES (:id, :user, 'Max', '42.0000', 1, '2026-11-01T08:00:00Z', '2026-11-01T08:00:00Z')")
                ->execute(['id' => Uuid::v4(), 'user' => $max['id']]);

            $store = new AttachmentStore($tmp . '/documents');
            $modules = new ModuleService($pdo);
            $daily = new DailyReportService($pdo, new DailyReportRepository($pdo), new EmployeeRepository($pdo), new OrderRepository($pdo), $store, new DocumentSettingsRepository($pdo));
            $work = new WorkReportService($pdo, new WorkReportRepository($pdo), new OrderRepository($pdo), $numbers, new PriceCalculator(), $store, $orders);
            $measurements = new MeasurementService($pdo, new MeasurementRepository($pdo), new OrderRepository($pdo), $numbers);
            $times = new TimeTrackingService(new TimeTrackingRepository($pdo), $store);
            $test([
                'pdo' => $pdo, 'orders' => $orders, 'order' => $order, 'max' => $max, 'office' => $office, 'admin' => $admin,
                'daily' => $daily, 'work' => $work, 'measurements' => $measurements, 'times' => $times,
                'materials' => new MaterialRequestService(new MaterialRequestRepository($pdo), new OrderRepository($pdo), new DailyReportRepository($pdo), $modules),
                'purchases' => new PurchaseOrderService($pdo, new PurchaseOrderRepository($pdo), $numbers, $store, new PurchaseOrderPdfService(new DocumentSettingsRepository($pdo))),
                'workspace' => new MobileWorkspace($modules, $orders, $measurements, $daily, $work, null, null, $times),
            ]);
        } finally {
            $pdo = null;
            $this->remove($tmp);
        }
    }

    /** @return array{id:string,role:string} */
    private function user(PDO $pdo, string $role): array
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:username,'hash',:name,:role,1,'2026-11-01T08:00:00Z','2026-11-01T08:00:00Z')")
            ->execute(['id' => $id, 'username' => $role . '-' . substr($id, 0, 8), 'name' => ucfirst($role), 'role' => $role]);
        return ['id' => $id, 'role' => $role];
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
