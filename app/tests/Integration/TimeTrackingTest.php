<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\TimeTrackingService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Document\DocumentValidationException;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Security\AuthorizationPolicy;
use App\Security\RolePermissions;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Phase 3.4.3: Zeiterfassung. */
final class TimeTrackingTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    public function testPermissions(): void
    {
        $this->assertTrue(RolePermissions::allows('technician', 'timesheet.own') && !RolePermissions::allows('technician', 'timesheet.manage'));
        $this->assertTrue(RolePermissions::allows('office', 'timesheet.manage'));
        $this->assertSame(['timesheet.own'], AuthorizationPolicy::required(new Request('POST', '/api/time-tracking/entries', [], [], [], '')));
    }

    public function testBlocksBreaksTravelAndTotals(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'order' => $order] = $env;
            $add = fn (array $input): array => $service->createEntry(['date' => '2026-11-16'] + $input, $tech);
            $add(['start' => '07:00', 'end' => '08:00', 'activity' => 'travel', 'orderId' => $order]);
            $add(['start' => '08:00', 'end' => '12:00', 'activity' => 'work', 'orderId' => $order]);
            $add(['start' => '12:30', 'end' => '14:30', 'breakMinutes' => '15', 'activity' => 'work', 'orderId' => $order]);
            $add(['start' => '14:30', 'end' => '15:30', 'activity' => 'travel']);
            $day = $add(['start' => '16:00', 'end' => '17:00', 'activity' => 'preparation', 'notes' => 'Werkstatt']);
            $this->assertSame(5, count($day['entries']));
            $this->assertSame(105, $day['entries'][2]['netMinutes'], '2 h abzüglich 15 min Pause.');
            $this->assertSame(525, $day['totals']['netMinutes']);
            $this->assertSame(120, $day['totals']['travelMinutes'], 'Reisezeit getrennt auswertbar.');
            $this->assertSame(405, $day['totals']['workMinutes']);
            $this->assertSame(null, $day['entries'][4]['orderId'], 'Tätigkeit ohne Auftrag.');
            $this->assertSame(null, $day['entries'][0]['hourlyCostRate'], 'Kostensatz ohne costs.read nicht sichtbar.');

            // Ungültige Eingaben.
            $this->assertThrows(DocumentValidationException::class, fn () => $add(['start' => '25:00', 'end' => '26:00', 'activity' => 'work']));
            $this->assertThrows(DocumentValidationException::class, fn () => $add(['start' => '18:00', 'end' => '18:00', 'activity' => 'work']));
            $this->assertThrows(DocumentValidationException::class, fn () => $add(['start' => '18:00', 'end' => '19:00', 'breakMinutes' => '60', 'activity' => 'work']), 'Pause kürzer als Block.');
            $this->assertThrows(DocumentValidationException::class, fn () => $add(['start' => '18:00', 'end' => '19:00', 'breakMinutes' => '-5', 'activity' => 'work']));
            $this->assertThrows(DocumentValidationException::class, fn () => $add(['start' => '18:00', 'end' => '19:00', 'activity' => 'lunch']));
            // Überschneidung.
            $this->assertThrows(DocumentValidationException::class, fn () => $add(['start' => '11:00', 'end' => '13:00', 'activity' => 'work']));

            $week = $service->range(null, '2026-11-16', '2026-11-22', $tech);
            $this->assertSame(7, count($week['days']), 'Tage ohne Einträge sind enthalten.');
            $this->assertSame(525, $week['days'][0]['netMinutes']);
            $this->assertSame(0, $week['days'][1]['entryCount']);
            $this->assertSame(525, $week['totals']['netMinutes']);
            $this->assertSame(30, count($service->range(null, '2026-11-01', '2026-11-30', $tech)['days']), 'Monatsansicht mit allen Tagen.');
        });
    }

    public function testBlocksAcrossMidnightAndEditing(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech] = $env;
            $night = $service->createEntry(['date' => '2026-11-16', 'start' => '22:00', 'end' => '02:00', 'activity' => 'work'], $tech);
            $entry = $night['entries'][0];
            $this->assertTrue($entry['endsNextDay']);
            $this->assertSame(240, $entry['netMinutes']);
            // Folgetag: Überschneidung mit dem Nachtblock erkannt.
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-11-17', 'start' => '01:00', 'end' => '03:00', 'activity' => 'work'], $tech));
            $next = $service->createEntry(['date' => '2026-11-17', 'start' => '08:00', 'end' => '09:00', 'activity' => 'work'], $tech);
            $this->assertTrue(in_array('Weniger als 11 Stunden Ruhezeit seit dem Vortag – bitte prüfen.', $next['warnings'], true), 'Hinweis auf kurze Ruhezeit.');

            $edited = $service->updateEntry($entry['id'], ['version' => $entry['version'], 'date' => '2026-11-16', 'start' => '21:00', 'end' => '23:00', 'activity' => 'internal'], $tech);
            $this->assertSame(120, $edited['entries'][0]['netMinutes']);
            $this->assertThrows(DocumentValidationException::class, fn () => $service->updateEntry($entry['id'], ['version' => $entry['version'], 'date' => '2026-11-16', 'start' => '21:00', 'end' => '22:00', 'activity' => 'work'], $tech), 'Versionskonflikt.');
            $deleted = $service->deleteEntry($entry['id'], ['version' => $edited['entries'][0]['version']], $tech);
            $this->assertSame([], $deleted['entries']);
        });
    }

    public function testDetailsKilometersAndExpenses(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'order' => $order, 'tmp' => $tmp, 'files' => $files] = $env;
            $day = $service->saveDetails(['date' => '2026-11-18', 'assignmentKind' => 'external', 'overnight' => true, 'travelDay' => 'arrival', 'location' => 'Leipzig',
                'privateKm' => '182,5', 'privateKmPurpose' => 'Anreise', 'privateKmOrderId' => $order], $tech);
            $this->assertSame('external', $day['details']['assignmentKind']);
            $this->assertSame('182.5', $day['details']['privateKm']);
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveDetails(['date' => '2026-11-18', 'travelDay' => 'holiday'], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveDetails(['date' => '2026-11-18', 'privateKm' => '-3'], $tech));

            $receipt = $tmp . '/hotel.pdf';
            file_put_contents($receipt, self::PDF);
            $withExpense = $service->addExpense(['date' => '2026-11-18', 'amount' => '89,00', 'purpose' => 'Hotel', 'orderId' => $order], ['path' => $receipt, 'name' => 'Hotel.pdf'], $tech);
            $withExpense = $service->addExpense(['date' => '2026-11-18', 'amount' => '6.50', 'purpose' => 'Parken'], null, $tech);
            $this->assertSame(2, count($withExpense['expenses']));
            $this->assertSame('95.50', $withExpense['expenseTotal']);
            $this->assertSame(self::PDF, $service->receipt($withExpense['expenses'][0]['id'], $tech)['contents']);
            $script = $tmp . '/x.sh';
            file_put_contents($script, "#!/bin/sh\n");
            $this->assertThrows(DocumentValidationException::class, fn () => $service->addExpense(['date' => '2026-11-18', 'amount' => '1', 'purpose' => 'X'], ['path' => $script, 'name' => 'x.pdf'], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->addExpense(['date' => '2026-11-18', 'amount' => '0', 'purpose' => 'X'], null, $tech));

            $month = $service->range(null, '2026-11-01', '2026-11-30', $tech);
            $this->assertSame('182.5', $month['totals']['privateKm']);
            $this->assertSame('95.50', $month['totals']['expenseTotal']);
            $path = glob($files . '/expenses/*/*.pdf')[0] ?? '';
            $this->assertTrue(is_file($path));
            $service->deleteExpense($withExpense['expenses'][0]['id'], $tech);
            $this->assertTrue(!is_file($path), 'Beleg wird mit der Auslage entfernt.');
        });
    }

    public function testPermissionsOrdersAndOfficeCorrections(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'otherTech' => $other, 'office' => $office, 'employee' => $employee, 'openOrder' => $openOrder, 'order' => $order, 'noEmployee' => $noEmployee] = $env;
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-11-16', 'start' => '08:00', 'end' => '09:00', 'activity' => 'work', 'orderId' => $openOrder], $tech), 'Techniker: nur freigegebene Aufträge.');
            $this->assertTrue(!in_array($openOrder, array_column($service->referenceData($tech)['orders'], 'id'), true));
            $own = $service->createEntry(['date' => '2026-11-16', 'start' => '08:00', 'end' => '09:00', 'activity' => 'work', 'orderId' => $order], $tech);
            $this->assertThrows(AccessDeniedException::class, fn () => $service->day($employee, '2026-11-16', $other), 'Keine fremden Zeiten.');
            $this->assertThrows(AccessDeniedException::class, fn () => $service->updateEntry($own['entries'][0]['id'], ['version' => 1, 'date' => '2026-11-16', 'start' => '08:00', 'end' => '10:00', 'activity' => 'work'], $other));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->report('2026-11-01', '2026-11-30', null, null, $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->day(null, '2026-11-16', $noEmployee), 'Ohne Mitarbeiterzuordnung keine Erfassung.');

            // Büro korrigiert; die Änderung bleibt nachvollziehbar.
            $corrected = $service->updateEntry($own['entries'][0]['id'], ['version' => 1, 'date' => '2026-11-16', 'start' => '08:00', 'end' => '10:00', 'activity' => 'work', 'orderId' => $order], $office);
            $this->assertTrue($corrected['entries'][0]['changedByOther']);
            $this->assertTrue($corrected['entries'][0]['hourlyCostRate'] === '42.0000', 'Büro mit costs.read sieht den Snapshot.');
            $report = $service->report('2026-11-01', '2026-11-30', $employee, null, $office);
            $this->assertSame(120, $report['totals']['netMinutes']);
            $this->assertSame($order, $report['byOrder'][0]['orderId']);

            // Archivierte Mitarbeiter: keine eigenen Einträge mehr, Büro kann korrigieren.
            $env['pdo']->prepare('UPDATE employees SET active = 0 WHERE id = :id')->execute(['id' => $employee]);
            $this->assertThrows(AccessDeniedException::class, fn () => $service->createEntry(['date' => '2026-11-17', 'start' => '08:00', 'end' => '09:00', 'activity' => 'work'], $tech));
            $this->assertSame(1, count($service->createEntry(['employeeId' => $employee, 'date' => '2026-11-17', 'start' => '08:00', 'end' => '09:00', 'activity' => 'work'], $office)['entries']));
        });
    }

    public function testDailyReportsAndWorkReportsAreNotCountedTwice(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'office' => $office, 'employee' => $employee, 'order' => $order, 'pdo' => $pdo, 'customer' => $customer] = $env;
            // Bestehende Bautagesberichte (z. B. vor Einführung der Zeiterfassung).
            foreach (['2026-11-16' => '7.500', '2026-11-17' => '6.000'] as $date => $hours) {
                $report = Uuid::v4();
                $pdo->prepare("INSERT INTO daily_reports (id, order_id, report_date, activities, created_by_user_id, created_at, updated_at) VALUES (:id, :order, :date, 'Montage', :user, :now, :now)")
                    ->execute(['id' => $report, 'order' => $order, 'date' => $date, 'user' => $tech['id'], 'now' => '2026-11-18T08:00:00Z']);
                $pdo->prepare("INSERT INTO daily_report_time_entries (id, report_id, employee_id, work_date, duration_hours, target_kind, hourly_cost_rate_snapshot, labor_cost, created_at, updated_at)
                    VALUES (:id, :report, :employee, :date, :hours, 'order', '42.0000', '0.00', :now, :now)")
                    ->execute(['id' => Uuid::v4(), 'report' => $report, 'employee' => $employee, 'date' => $date, 'hours' => $hours, 'now' => '2026-11-18T08:00:00Z']);
            }
            $before = $pdo->query('SELECT id, duration_hours, updated_at FROM daily_report_time_entries ORDER BY work_date')->fetchAll();
            // Rapport mit 6 h abrechenbarer Leistung am 16.11.
            $workReport = Uuid::v4();
            $pdo->prepare("INSERT INTO work_reports (id, customer_id, order_id, work_date, description, created_by_user_id, created_at, updated_at) VALUES (:id, :customer, :order, '2026-11-16', 'Regie', :user, :now, :now)")
                ->execute(['id' => $workReport, 'customer' => $customer, 'order' => $order, 'user' => $tech['id'], 'now' => '2026-11-16T18:00:00Z']);
            $pdo->prepare("INSERT INTO work_report_labor (id, report_id, employee_id, worker_name, hours, sort_order) VALUES (:id, :report, :employee, 'Monteur', '6.000', 1)")
                ->execute(['id' => Uuid::v4(), 'report' => $workReport, 'employee' => $employee]);

            // Am 16.11. Zeiterfassung für denselben Auftrag: ersetzt den Bericht (8 h statt 7,5 h + 8 h).
            $day = $service->createEntry(['date' => '2026-11-16', 'start' => '07:00', 'end' => '15:00', 'activity' => 'work', 'orderId' => $order], $tech);
            $this->assertSame(480, $day['totals']['netMinutes'], 'Rapport- und Berichtsstunden werden nicht addiert.');
            $this->assertSame(false, $day['dailyReportTimes'][0]['countsAsActual']);
            $this->assertSame('6.000', $day['workReportTimes'][0]['hours'], 'Rapportzeit als Referenz sichtbar.');
            $actual = $service->actualHoursForOrder($order, $office);
            $this->assertSame(480 + 360, $actual['totalMinutes'], '16.11. aus der Zeiterfassung (8 h), 17.11. aus dem Bericht (6 h); Rapport zählt nie.');
            $this->assertSame(['time_tracking', 'daily_report'], array_column($actual['items'], 'source'));
            $this->assertSame($before, $pdo->query('SELECT id, duration_hours, updated_at FROM daily_report_time_entries ORDER BY work_date')->fetchAll(), 'Berichte bleiben unverändert.');
            $this->assertThrows(AccessDeniedException::class, fn () => $service->actualHoursForOrder($order, $tech));
        });
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-time-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $now = '2026-11-12T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Matzko-Mack',:now,:now)")->execute(['id' => $customer, 'now' => $now]);
            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);
            $released = $orders->createRegie(['customerId' => $customer, 'title' => 'Montage Halle', 'orderDate' => '2026-11-12']);
            $orders->changeStatus($released['id'], 'open', 'in_progress');
            $open = $orders->createRegie(['customerId' => $customer, 'title' => 'Noch nicht freigegeben', 'orderDate' => '2026-11-12']);
            $tech = $this->user($pdo, 'technician', $now);
            $otherTech = $this->user($pdo, 'technician', $now);
            $office = $this->user($pdo, 'office', $now);
            $employee = $this->employee($pdo, $tech['id'], 'Max Monteur', $now);
            $this->employee($pdo, $otherTech['id'], 'Eva Elektrikerin', $now);
            $test([
                'pdo' => $pdo, 'tmp' => $tmp, 'files' => $tmp . '/documents', 'customer' => $customer,
                'service' => new TimeTrackingService(new TimeTrackingRepository($pdo), new AttachmentStore($tmp . '/documents')),
                'order' => $released['id'], 'openOrder' => $open['id'],
                'tech' => $tech, 'otherTech' => $otherTech, 'office' => $office, 'employee' => $employee,
                'noEmployee' => $this->user($pdo, 'technician', $now),
            ]);
        } finally {
            $this->remove($tmp);
        }
    }

    private function employee(PDO $pdo, string $userId, string $name, string $now): string
    {
        $id = Uuid::v4();
        $pdo->prepare('INSERT INTO employees (id, user_id, display_name, hourly_cost_rate, active, created_at, updated_at) VALUES (:id, :user, :name, \'42.0000\', 1, :now, :now)')
            ->execute(['id' => $id, 'user' => $userId, 'name' => $name, 'now' => $now]);
        return $id;
    }

    /** @return array{id:string,role:string} */
    private function user(PDO $pdo, string $role, string $now): array
    {
        $id = Uuid::v4();
        $pdo->prepare('INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:username,\'hash\',:name,:role,1,:now,:now)')
            ->execute(['id' => $id, 'username' => $role . '-' . substr($id, 0, 8), 'name' => ucfirst($role), 'role' => $role, 'now' => $now]);
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
