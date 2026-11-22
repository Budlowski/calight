<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DailyReportService;
use App\Application\MeasurementService;
use App\Application\MobileWorkspace;
use App\Application\ModuleService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\TimeTrackingService;
use App\Application\WorkReportService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\MeasurementRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Infrastructure\Persistence\WorkReportRepository;
use App\Security\AuthorizationPolicy;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;
use Throwable;

/**
 * Monteurs-Frontend Phase 3: mobile Tagesberichte und Rapporte über dieselben Dienste
 * (Übersichten, Vorbelegung des eigenen Mitarbeiters, Lebenszyklus, Korrektur, Modul und Rechte).
 */
final class MobileReportsTest extends TestCase
{
    public function testMobileReportRoutesUseExistingPermissionsAndModule(): void
    {
        $required = static fn (string $method, string $path): array => AuthorizationPolicy::required(new Request($method, $path, [], [], [], ''));
        $this->assertSame(['reports.read'], $required('GET', '/mobile/daily-reports'));
        $this->assertSame(['reports.read'], $required('GET', '/mobile/daily-reports/abc'));
        $this->assertSame(['workreports.read'], $required('GET', '/mobile/rapports'));
        $this->assertSame(['workreports.read'], $required('GET', '/mobile/rapports/abc'));
        $this->assertSame(['workreports.read'], $required('GET', '/mobile/rapports/abc/sign'));
        foreach (['/mobile/daily-reports', '/mobile/daily-reports/x', '/mobile/rapports', '/mobile/rapports/x/sign'] as $path) {
            $this->assertSame('work_tracking', ModuleService::moduleForPath($path), $path);
        }
        // Bestehende Schreib- und Unterschriftsrouten bleiben maßgeblich (mobil dieselbe API).
        $this->assertSame(['workreports.write'], $required('POST', '/api/work-reports/x/signature'));
        $this->assertSame(['reports.write'], $required('POST', '/api/daily-reports/x/submit'));
    }

    public function testListsFollowServiceVisibilityWithoutCostsAndPreferOwnEmployee(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['workspace' => $workspace, 'daily' => $daily, 'work' => $work, 'tech' => $tech, 'colleague' => $colleague, 'office' => $office,
                'order' => $order, 'customer' => $customer, 'employee' => $employee] = $env;
            $this->assertSame($employee, $daily->referenceData($tech)['ownEmployeeId'], 'Vorbelegung nur über employees.user_id.');
            $this->assertSame($employee, $work->referenceData($tech)['ownEmployeeId']);
            $this->assertSame(null, $daily->referenceData($office)['ownEmployeeId'], 'Ohne Zuordnung keine Vorbelegung.');

            $own = $daily->create(['orderId' => $order, 'reportDate' => '2026-11-20', 'title' => 'Montage EG', 'activities' => 'Leitungen verlegt',
                'entries' => [['employeeId' => $employee, 'durationHours' => '6', 'targetKey' => 'order']]], $tech);
            $daily->create(['customerId' => $customer, 'reportDate' => '2026-11-20', 'title' => 'Fremd ohne Auftrag', 'activities' => 'x', 'entries' => []], $colleague);

            $list = $workspace->dailyReports([], $tech);
            $this->assertSame(['Montage EG'], array_column($list['items'], 'title'), 'Fremde Berichte ohne Auftrag bleiben für Monteure unsichtbar.');
            $this->assertSame(['id', 'url', 'date', 'title', 'customerName', 'target', 'targetLabel', 'detail', 'hours', 'status', 'statusLabel'], array_keys($list['items'][0]));
            $this->assertSame('/mobile/daily-reports/' . $own['id'], $list['items'][0]['url']);
            $this->assertSame(2, $workspace->dailyReports([], $office)['total']);
            $this->assertSame(0, $workspace->dailyReports(['status' => 'submitted'], $tech)['total']);
            $this->assertSame(1, $workspace->dailyReports(['order' => $order], $office)['total'], 'Einstieg aus dem Auftrag filtert nach Auftrag.');
            $this->assertSame('all', $workspace->dailyReports(['status' => 'unbekannt'], $tech)['status']);
            $this->assertThrows(AccessDeniedException::class, fn () => $workspace->workReports([], ['id' => 'x', 'role' => 'unknown']));
        });
    }

    public function testDailyReportLifecycleAndBookingsStayUnchanged(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['daily' => $daily, 'tech' => $tech, 'order' => $order, 'employee' => $employee, 'times' => $times, 'workspace' => $workspace] = $env;
            $report = $daily->create(['orderId' => $order, 'reportDate' => '2026-11-19', 'activities' => 'Verteilung gesetzt',
                'entries' => [['employeeId' => $employee, 'durationHours' => '7.5', 'targetKey' => 'order', 'description' => 'Montage']]], $tech);
            $booking = $times->day(null, '2026-11-19', $tech)['entries'][0];
            $this->assertSame([450, 'daily_report', $order], [$booking['netMinutes'], $booking['sourceKind'], $booking['orderId']], 'Eine zentrale Buchung je Stundenposition.');
            $submitted = $daily->submit($report['id'], ['version' => $report['version']], $tech);
            $this->assertSame('submitted', $submitted['status']);
            $this->assertThrows(Throwable::class, fn () => $daily->update($report['id'], ['version' => $submitted['version'], 'reportDate' => '2026-11-19', 'activities' => 'geändert',
                'entries' => [['employeeId' => $employee, 'durationHours' => '8', 'targetKey' => 'order']]], $tech));
            $this->assertSame('Eingereicht', $workspace->dailyReports([], $tech)['items'][0]['statusLabel']);
        });
    }

    public function testWorkReportFromOrderSubmitCorrectAndSignatureBinding(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['work' => $work, 'tech' => $tech, 'colleague' => $colleague, 'order' => $order, 'employee' => $employee, 'workspace' => $workspace, 'modules' => $modules, 'admin' => $admin] = $env;
            $draft = $work->create(['orderId' => $order, 'workDate' => '2026-11-18'], $tech);
            $saved = $work->update($draft['id'], ['version' => $draft['version'], 'workDate' => '2026-11-18', 'workDateTo' => '', 'contactPerson' => 'Frau Mack', 'workLocation' => 'Halle 2',
                'description' => 'Störung behoben', 'notes' => '', 'labor' => [['employeeId' => $employee, 'hours' => '2', 'workDate' => '2026-11-18']],
                'materials' => [['quantity' => '1', 'unit' => 'Stk.', 'description' => 'Sicherung']]], $tech);
            $this->assertTrue(!array_key_exists('billing', $saved) && !str_contains(json_encode($saved, JSON_THROW_ON_ERROR), 'Price'), 'Keine kaufmännischen Daten für Monteure.');
            $submitted = $work->submit($saved['id'], ['version' => $saved['version']], $tech);
            $this->assertTrue($submitted['canSign'] && !$work->detail($submitted['id'], $colleague)['canSign'], 'Unterschrift durch Ersteller (und Büro).');
            $this->assertThrows(OrderValidationException::class, fn () => $work->sign($submitted['id'], ['version' => $submitted['version'], 'signerName' => 'Frau Mack',
                'image' => 'data:image/jpeg;base64,' . base64_encode('x'), 'submittedSha256' => str_repeat('0', 64)], $tech));

            $correction = $work->create(['correctionOf' => $submitted['id']], $tech);
            $this->assertSame([$submitted['id'], $order], [$correction['correctionOfId'], $correction['orderId']], 'Korrektur übernimmt den Auftrag des Originals.');
            $items = $workspace->workReports(['status' => 'all'], $tech)['items'];
            $this->assertSame(2, count($items));
            $this->assertTrue(str_starts_with($items[0]['url'], '/mobile/rapports/'));

            // Deaktivierte Arbeitserfassung: lesbar, aber Unterschrift und Bearbeitung über die API gesperrt.
            $modules->setEnabled('work_tracking', false, true, $admin);
            $this->assertTrue($modules->blockedMessage('POST', '/api/work-reports/' . $submitted['id'] . '/signature') !== null);
            $this->assertTrue($modules->blockedMessage('PUT', '/api/daily-reports/x') !== null);
            $this->assertSame(null, $modules->blockedMessage('GET', '/mobile/rapports/' . $submitted['id']));
            $this->assertSame(2, $workspace->workReports(['status' => 'all'], $tech)['total'], 'Historische Rapporte bleiben lesbar.');
            $actions = array_column($workspace->quickActions($tech), 'label');
            $this->assertTrue(!in_array('Rapport', $actions, true) && !in_array('Tagesbericht', $actions, true), 'Ohne Modul keine Anlegeeinstiege.');
        });
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-mobile-reports-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);
            $now = '2026-11-01T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Kunde',:now,:now)")
                ->execute(['id' => $customer, 'now' => $now]);
            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);
            $order = $orders->createRegie(['customerId' => $customer, 'title' => 'Wartung', 'orderDate' => '2026-11-01']);
            $orders->changeStatus($order['id'], 'open', 'in_progress');

            $tech = $this->user($pdo, 'technician');
            $colleague = $this->user($pdo, 'technician');
            $office = $this->user($pdo, 'office');
            $admin = $this->user($pdo, 'admin');
            $employee = $this->employee($pdo, $tech['id'], 'Max Monteur');
            $this->employee($pdo, $colleague['id'], 'Eva Elektrikerin');

            $store = new AttachmentStore($tmp . '/documents');
            $modules = new ModuleService($pdo);
            $daily = new DailyReportService($pdo, new DailyReportRepository($pdo), new EmployeeRepository($pdo), new OrderRepository($pdo), $store, new DocumentSettingsRepository($pdo));
            $work = new WorkReportService($pdo, new WorkReportRepository($pdo), new OrderRepository($pdo), $numbers, new PriceCalculator(), $store, $orders);
            $times = new TimeTrackingService(new TimeTrackingRepository($pdo), $store);
            $measurements = new MeasurementService($pdo, new MeasurementRepository($pdo), new OrderRepository($pdo), $numbers);
            $test([
                'pdo' => $pdo, 'customer' => $customer, 'order' => $order['id'], 'modules' => $modules,
                'daily' => $daily, 'work' => $work, 'times' => $times,
                'workspace' => new MobileWorkspace($modules, $orders, $measurements, $daily, $work, null, null, $times),
                'tech' => $tech, 'colleague' => $colleague, 'office' => $office, 'admin' => $admin, 'employee' => $employee,
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
