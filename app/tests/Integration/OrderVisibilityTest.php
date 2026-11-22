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
use App\Application\OperationalSettingsService;
use App\Application\OrderService;
use App\Application\TimeTrackingService;
use App\Application\WorkReportService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Document\DocumentValidationException;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
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
use App\Infrastructure\Persistence\OrderVisibility;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Infrastructure\Persistence\WorkReportRepository;
use App\Security\AuthorizationPolicy;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/**
 * Auftragssichtbarkeit für Monteure (Migration 0048): Mitarbeiterzuordnung, globale Einstellung,
 * zentrale Prüfung in allen Fachdiensten, Entzug bei bestehenden Vorgängen, automatische Regieaufträge.
 */
final class OrderVisibilityTest extends TestCase
{
    public function testDefaultShowsOnlyAssignedReleasedOrdersAndSeveralEmployeesPerOrder(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['orders' => $orders, 'max' => $max, 'eva' => $eva, 'office' => $office, 'assigned' => $assigned, 'other' => $other, 'openOrder' => $open, 'maxEmployee' => $maxEmployee, 'evaEmployee' => $evaEmployee] = $env;
            $this->assertSame([$assigned], array_column($orders->page([], $max)['items'], 'id'), 'Standard: nur zugewiesene freigegebene Aufträge.');
            $this->assertSame(0, $orders->page([], $eva)['total'], 'Ohne Zuordnung keine Aufträge.');
            $this->assertSame(3, $orders->page([], $office)['total'], 'Büro sieht alle Aufträge.');
            $this->assertThrows(AccessDeniedException::class, fn () => $orders->readableDetail($other, $max));
            $this->assertThrows(AccessDeniedException::class, fn () => $orders->readableDetail($open, $max), 'Zuordnung ersetzt nicht die Freigabe.');

            // Mehrere Mitarbeiter je Auftrag, Zuordnung über die Mitarbeiter-ID, nur mit orders.write.
            $this->assertThrows(AccessDeniedException::class, fn () => $orders->assignEmployee($assigned, $evaEmployee, true, $max));
            $result = $orders->assignEmployee($assigned, $evaEmployee, true, $office);
            $ids = array_column($result['assigned'], 'id');
            sort($ids);
            $expected = [$evaEmployee, $maxEmployee];
            sort($expected);
            $this->assertSame($expected, $ids, 'Zwei Mitarbeiter zugeordnet.');
            $this->assertSame(1, $orders->page([], $eva)['total']);
            $this->assertSame([], $orders->employees($assigned, $max)['available'], 'Monteure erhalten keine Auswahlliste.');
        });
    }

    public function testReleasedSettingShowsAllReleasedOrdersAndIsValidatedLikeOtherSettings(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['orders' => $orders, 'eva' => $eva, 'admin' => $admin, 'office' => $office, 'pdo' => $pdo] = $env;
            $settings = new OperationalSettingsService($pdo, new ModuleService($pdo));
            $this->assertThrows(AccessDeniedException::class, fn () => $settings->save([OrderVisibility::SETTING => OrderVisibility::RELEASED], $office));
            $this->assertThrows(DocumentValidationException::class, fn () => $settings->save([OrderVisibility::SETTING => 'everything'], $admin));
            $settings->save([OrderVisibility::SETTING => OrderVisibility::RELEASED], $admin);
            $this->assertSame(2, $orders->page([], $eva)['total'], 'Alle freigegebenen, nie offene Aufträge.');
            $this->assertSame(['operations.manage'], AuthorizationPolicy::required(new Request('GET', '/settings/orders', [], [], [], '')));
            $this->assertSame(['orders.write'], AuthorizationPolicy::required(new Request('POST', '/api/orders/x/employees', [], [], [], '')));
            $this->assertSame(['orders.read'], AuthorizationPolicy::required(new Request('GET', '/api/orders/x/employees', [], [], [], '')));
        });
    }

    public function testAllModulesApplyTheSameRuleIncludingSelectionLists(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['daily' => $daily, 'work' => $work, 'measurements' => $measurements, 'materials' => $materials, 'times' => $times, 'workspace' => $workspace,
                'max' => $max, 'eva' => $eva, 'office' => $office, 'assigned' => $assigned, 'other' => $other, 'customer' => $customer, 'maxEmployee' => $maxEmployee] = $env;
            // Auswahllisten der Erfassungsformulare.
            $this->assertSame([$assigned], array_column($daily->referenceData($max)['orders'], 'id'));
            $this->assertSame([$assigned], array_column($work->referenceData($max)['orders'], 'id'));
            $this->assertSame([$assigned], array_column($times->referenceData($max)['orders'], 'id'));
            $this->assertSame(3, count($daily->referenceData($office)['orders']));

            // Direkte Aufrufe auf nicht zugewiesene Aufträge.
            $this->assertThrows(AccessDeniedException::class, fn () => $daily->create(['orderId' => $other, 'reportDate' => '2026-11-20', 'activities' => 'x', 'entries' => []], $max));
            $this->assertThrows(AccessDeniedException::class, fn () => $daily->assertOrderReadable($other, $max));
            $this->assertThrows(AccessDeniedException::class, fn () => $work->create(['orderId' => $other], $max));
            $this->assertThrows(AccessDeniedException::class, fn () => $work->forOrder($other, $max));
            $this->assertThrows(AccessDeniedException::class, fn () => $measurements->forOrder($other, $max));
            $this->assertThrows(AccessDeniedException::class, fn () => $materials->create(['orderId' => $other, 'description' => 'Kabel'], $max));
            $this->assertThrows(DocumentValidationException::class, fn () => $times->createEntry(['date' => '2026-11-20', 'start' => '08:00', 'end' => '09:00', 'orderId' => $other], $max));
            $this->assertThrows(AccessDeniedException::class, fn () => $workspace->order($other, $max));
            $this->assertSame(0, $workspace->orders(['status' => 'all', 'q' => 'Fremd'], $max)['total'], 'Suche findet nichts Verborgenes.');

            // Zugewiesener Auftrag funktioniert in allen Modulen.
            $report = $daily->create(['orderId' => $assigned, 'reportDate' => '2026-11-20', 'activities' => 'Montage',
                'entries' => [['employeeId' => $maxEmployee, 'durationHours' => '2', 'targetKey' => 'order']]], $max);
            $this->assertSame($assigned, $report['order_id']);
            $this->assertSame($assigned, $work->create(['orderId' => $assigned], $max)['orderId']);
            $this->assertSame('open', $materials->create(['orderId' => $assigned, 'description' => 'Kabel'], $max)['status']);
            $this->assertSame(1, count($times->createEntry(['date' => '2026-11-21', 'start' => '08:00', 'end' => '09:00', 'orderId' => $assigned], $max)['entries']));

            // Fremde Berichte an nicht sichtbaren Aufträgen bleiben verborgen, Büro sieht alles.
            $daily->create(['orderId' => $other, 'reportDate' => '2026-11-20', 'activities' => 'Büro', 'entries' => []], $office);
            $this->assertSame(1, $daily->page([], $max)['total']);
            $this->assertSame(0, $daily->page([], $eva)['total']);
            $this->assertSame(2, $daily->page([], $office)['total']);
            $this->assertSame(0, $materials->page([], $eva)['total']);
            $this->assertSame(1, $work->page([], $max)['total']);
            $this->assertSame(0, $work->page([], $eva)['total']);
            // Kaufmännischer Schutz unverändert: keine Preis- oder Kostenfelder.
            $this->assertTrue(!str_contains(json_encode($work->detail($work->page([], $max)['items'][0]['id'], $max), JSON_THROW_ON_ERROR), 'Price'));
        });
    }

    public function testRemovingAnAssignmentKeepsHistoryAndOwnDraftsReachable(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['orders' => $orders, 'daily' => $daily, 'work' => $work, 'times' => $times, 'max' => $max, 'office' => $office, 'assigned' => $assigned, 'maxEmployee' => $maxEmployee, 'pdo' => $pdo] = $env;
            $report = $daily->create(['orderId' => $assigned, 'reportDate' => '2026-11-18', 'activities' => 'Begonnen',
                'entries' => [['employeeId' => $maxEmployee, 'durationHours' => '3', 'targetKey' => 'order']]], $max);
            $draft = $work->create(['orderId' => $assigned, 'workDate' => '2026-11-18'], $max);
            $entry = $times->createEntry(['date' => '2026-11-19', 'start' => '08:00', 'end' => '10:00', 'orderId' => $assigned], $max)['entries'][0];

            $orders->assignEmployee($assigned, $maxEmployee, false, $office);
            $this->assertSame(0, $orders->page([], $max)['total'], 'Auftrag nicht mehr sichtbar.');
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM time_entries')->fetchColumn(), 'Zeiten bleiben vollständig erhalten.');

            // Eigene Vorgänge bleiben erreichbar und lassen sich kontrolliert fertigstellen.
            $this->assertSame($report['id'], $daily->detail($report['id'], $max)['id']);
            $submitted = $daily->submit($report['id'], ['version' => $report['version']], $max);
            $this->assertSame('submitted', $submitted['status']);
            $saved = $work->update($draft['id'], ['version' => $draft['version'], 'workDate' => '2026-11-18', 'workDateTo' => '', 'contactPerson' => '', 'workLocation' => '',
                'description' => 'Fertiggestellt', 'notes' => '', 'labor' => [['employeeId' => $maxEmployee, 'hours' => '1', 'workDate' => '2026-11-18']], 'materials' => []], $max);
            $this->assertSame('submitted', $work->submit($saved['id'], ['version' => $saved['version']], $max)['status'], 'Eigener Rapportentwurf einreichbar.');
            $this->assertSame(1, count($times->day(null, '2026-11-19', $max)['entries']), 'Eigene Zeiten sichtbar.');
            $this->assertSame($assigned, $times->updateEntry($entry['id'], ['version' => $entry['version'], 'start' => '08:00', 'end' => '10:30', 'orderId' => $assigned], $max)['entries'][0]['orderId'],
                'Bestehende Zuordnung einer Buchung bleibt erlaubt.');
            // Neue Vorgänge für den Auftrag sind gesperrt.
            $this->assertThrows(AccessDeniedException::class, fn () => $daily->create(['orderId' => $assigned, 'reportDate' => '2026-11-22', 'activities' => 'neu', 'entries' => []], $max));
            $this->assertThrows(AccessDeniedException::class, fn () => $work->create(['orderId' => $assigned], $max));
        });
    }

    public function testStandaloneWorkReportAssignsCreatorToGeneratedRegieOrder(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['work' => $work, 'eva' => $eva, 'customer' => $customer, 'evaEmployee' => $evaEmployee, 'orders' => $orders, 'pdo' => $pdo] = $env;
            $draft = $work->create(['customerId' => $customer, 'workDate' => '2026-11-20'], $eva);
            $saved = $work->update($draft['id'], ['version' => $draft['version'], 'workDate' => '2026-11-20', 'workDateTo' => '', 'contactPerson' => '', 'workLocation' => 'Halle 3',
                'description' => 'Störung behoben', 'notes' => '', 'labor' => [['employeeId' => $evaEmployee, 'hours' => '2', 'workDate' => '2026-11-20']], 'materials' => []], $eva);
            $submitted = $work->submit($saved['id'], ['version' => $saved['version'], 'createOrder' => true], $eva);
            $orderId = (string) $submitted['orderId'];
            $this->assertSame([$evaEmployee], array_column($orders->employees($orderId, ['id' => 'x', 'role' => 'admin'])['assigned'], 'id'), 'Ersteller zugeordnet.');
            $this->assertSame('open', (string) $pdo->query("SELECT status FROM orders WHERE id = " . $pdo->quote($orderId))->fetchColumn(), 'Freigabe bleibt Sache des Büros.');
            $this->assertSame($submitted['id'], $work->detail($submitted['id'], $eva)['id'], 'Eigener Rapport bleibt erreichbar.');
        });
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-visibility-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM operational_settings WHERE setting_key = 'orders.technician_visibility'")->fetchColumn(),
                'Neue Installation ohne Aufträge: Standard „nur zugewiesene“.');
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
            $released = static function (string $title) use ($orders, $customer): string {
                $order = $orders->createRegie(['customerId' => $customer, 'title' => $title, 'orderDate' => '2026-11-01']);
                $orders->changeStatus($order['id'], 'open', 'in_progress');
                return (string) $order['id'];
            };
            $assigned = $released('Montage Zugewiesen');
            $other = $released('Fremd Auftrag');
            $open = (string) $orders->createRegie(['customerId' => $customer, 'title' => 'Noch offen', 'orderDate' => '2026-11-01'])['id'];

            $max = $this->user($pdo, 'technician');
            $eva = $this->user($pdo, 'technician');
            $office = $this->user($pdo, 'office');
            $admin = $this->user($pdo, 'admin');
            $maxEmployee = $this->employee($pdo, $max['id'], 'Max Monteur');
            $evaEmployee = $this->employee($pdo, $eva['id'], 'Eva Elektrikerin');
            $orders->assignEmployee($assigned, $maxEmployee, true, $office);
            $orders->assignEmployee($open, $maxEmployee, true, $office);

            $store = new AttachmentStore($tmp . '/documents');
            $modules = new ModuleService($pdo);
            $daily = new DailyReportService($pdo, new DailyReportRepository($pdo), new EmployeeRepository($pdo), new OrderRepository($pdo), $store, new DocumentSettingsRepository($pdo));
            $work = new WorkReportService($pdo, new WorkReportRepository($pdo), new OrderRepository($pdo), $numbers, new PriceCalculator(), $store, $orders);
            $measurements = new MeasurementService($pdo, new MeasurementRepository($pdo), new OrderRepository($pdo), $numbers);
            $times = new TimeTrackingService(new TimeTrackingRepository($pdo), $store);
            $test([
                'pdo' => $pdo, 'customer' => $customer, 'orders' => $orders, 'assigned' => $assigned, 'other' => $other, 'openOrder' => $open,
                'max' => $max, 'eva' => $eva, 'office' => $office, 'admin' => $admin, 'maxEmployee' => $maxEmployee, 'evaEmployee' => $evaEmployee,
                'daily' => $daily, 'work' => $work, 'measurements' => $measurements, 'times' => $times,
                'materials' => new MaterialRequestService(new MaterialRequestRepository($pdo), new OrderRepository($pdo), new DailyReportRepository($pdo), $modules),
                'workspace' => new MobileWorkspace($modules, $orders, $measurements, $daily, $work, null, null, $times),
            ]);
        } finally {
            $this->remove($tmp);
        }
    }

    private function employee(PDO $pdo, string $userId, string $name): string
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO employees (id, user_id, display_name, hourly_cost_rate, active, created_at, updated_at) VALUES (:id, :user, :name, '42.0000', 1, '2026-11-01T08:00:00Z', '2026-11-01T08:00:00Z')")
            ->execute(['id' => $id, 'user' => $userId, 'name' => $name]);
        return $id;
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
