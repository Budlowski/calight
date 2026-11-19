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
            // Reine Fahrtblöcke: gesamte Nettozeit als Reisezeit.
            $add(['start' => '07:00', 'end' => '08:00', 'activity' => 'work', 'travelMinutes' => '60', 'orderId' => $order]);
            $add(['start' => '08:00', 'end' => '12:00', 'activity' => 'work', 'orderId' => $order]);
            $add(['start' => '12:30', 'end' => '14:30', 'breakMinutes' => '15', 'activity' => 'work', 'orderId' => $order]);
            $add(['start' => '14:30', 'end' => '15:30', 'activity' => 'work', 'travelMinutes' => '60']);
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
            $this->assertThrows(DocumentValidationException::class, fn () => $add(['start' => '18:00', 'end' => '19:00', 'activity' => 'travel']), 'Separate Reisetätigkeit wird für neue Blöcke nicht mehr angeboten.');
            $this->assertThrows(DocumentValidationException::class, fn () => $add(['start' => '18:00', 'end' => '19:00', 'activity' => 'work', 'travelMinutes' => '61']), 'Reisezeit höchstens Nettozeit.');
            $this->assertThrows(DocumentValidationException::class, fn () => $add(['start' => '18:00', 'end' => '19:00', 'activity' => 'work', 'travelMinutes' => '-1']));
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
            $day = $service->saveDetails(['date' => '2026-11-18', 'overnight' => true, 'travelDay' => 'arrival', 'location' => 'Leipzig', 'absenceStart' => '05:30', 'absenceEnd' => '19:00',
                'privateKm' => '182,5', 'privateKmPurpose' => 'Anreise', 'privateKmOrderId' => $order], $tech);
            $this->assertSame('05:30', $day['details']['absenceStart']);
            $this->assertTrue(!array_key_exists('assignmentKind', $day['details']), 'Einsatzart nicht mehr je Tag.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveDetails(['date' => '2026-11-18', 'absenceStart' => '25:00'], $tech));
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

    public function testDailyReportConflictsAreExplicitAndNeverCountedTwice(): void
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

            // Am 16.11. zusätzlich 2 h Zeiterfassung für denselben Auftrag: Ergänzung oder Korrektur? → Konflikt.
            $day = $service->createEntry(['date' => '2026-11-16', 'start' => '15:00', 'end' => '17:00', 'activity' => 'work', 'orderId' => $order], $tech);
            $this->assertSame(120, $day['totals']['netMinutes'], 'Rapport- und Berichtsstunden werden nicht addiert.');
            $this->assertSame('conflict', $day['dailyReportTimes'][0]['status']);
            $this->assertSame('6.000', $day['workReportTimes'][0]['hours'], 'Rapportzeit als Referenz sichtbar.');
            $actual = $service->actualHoursForOrder($order, $office);
            $this->assertSame(360, $actual['totalMinutes'], 'Nur der 17.11. (Bericht 6 h) ist eindeutig; Rapport zählt nie.');
            $this->assertSame(1, count($actual['conflicts']), 'Konflikt geht nicht unbemerkt in die Ist-Stunden ein.');
            $this->assertSame(450, $actual['conflicts'][0]['reportMinutes']);
            $this->assertSame(120, $actual['conflicts'][0]['trackingMinutes']);

            // Büro wählt die maßgebliche Quelle; nichts wird addiert oder still verworfen.
            $this->assertThrows(AccessDeniedException::class, fn () => $service->chooseSource(['employeeId' => $employee, 'date' => '2026-11-16', 'orderId' => $order, 'source' => 'daily_report'], $tech));
            $chosen = $service->chooseSource(['employeeId' => $employee, 'date' => '2026-11-16', 'orderId' => $order, 'source' => 'daily_report'], $office);
            $this->assertSame('chosen_daily_report', $chosen['dailyReportTimes'][0]['status']);
            $actual = $service->actualHoursForOrder($order, $office);
            $this->assertSame(450 + 360, $actual['totalMinutes']);
            $this->assertSame([], $actual['conflicts']);
            $this->assertSame(['daily_report', 'daily_report'], array_column($actual['items'], 'source'));
            $service->chooseSource(['employeeId' => $employee, 'date' => '2026-11-16', 'orderId' => $order, 'source' => 'time_tracking'], $office);
            $this->assertSame(120 + 360, $service->actualHoursForOrder($order, $office)['totalMinutes']);
            $this->assertSame($before, $pdo->query('SELECT id, duration_hours, updated_at FROM daily_report_time_entries ORDER BY work_date')->fetchAll(), 'Berichte bleiben unverändert.');
            $this->assertThrows(AccessDeniedException::class, fn () => $service->actualHoursForOrder($order, $tech));
        });
    }

    public function testTravelWithinBlockAssignmentPerBlockAndYearBoundary(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'order' => $order] = $env;
            // Beispiel: 07:00–16:00, 30 min Pause, davon 90 min Reise.
            $day = $service->createEntry(['date' => '2026-11-19', 'start' => '07:00', 'end' => '12:00', 'breakMinutes' => '0', 'travelMinutes' => '0', 'activity' => 'work',
                'assignmentKind' => 'regular'], $tech);
            $day = $service->createEntry(['date' => '2026-11-19', 'start' => '12:00', 'end' => '16:00', 'breakMinutes' => '30', 'travelMinutes' => '90', 'activity' => 'work',
                'assignmentKind' => 'external', 'orderId' => $order], $tech);
            $this->assertSame(510, $day['totals']['netMinutes'], 'Nettozeit = Ende − Beginn − Pause.');
            $this->assertSame(90, $day['totals']['travelMinutes'], 'Reisezeit ist Teil der Nettozeit.');
            $this->assertSame(420, $day['totals']['workMinutes'], 'Übrige Arbeitszeit = Nettozeit − Reisezeit.');
            $this->assertSame(['regular', 'external'], array_column($day['entries'], 'assignmentKind'), 'Einsatzart wechselt innerhalb des Tages.');
            $this->assertSame(120, $day['entries'][1]['workMinutes']);

            // Über den Jahreswechsel: 31.12. 22:00 – 01.01. 02:30, 30 min Pause, 60 min Reise.
            $night = $service->createEntry(['date' => '2026-12-31', 'start' => '22:00', 'end' => '02:30', 'breakMinutes' => '30', 'travelMinutes' => '60', 'activity' => 'work'], $tech);
            $this->assertSame(240, $night['entries'][0]['netMinutes']);
            $split = TimeTrackingService::split(['net_minutes' => 240, 'travel_minutes' => 60, 'break_minutes' => 30, 'start_time' => '22:00', 'ends_next_day' => 1]);
            $this->assertSame([['net' => 107, 'travel' => 27], ['net' => 133, 'travel' => 33]], $split, 'Brutto nach Uhrzeit, Pause und Reise anteilig.');
            $range = $service->range(null, '2026-12-31', '2027-01-01', $tech);
            $this->assertSame([107, 133], array_column($range['days'], 'netMinutes'));
            $this->assertSame(240, $range['totals']['netMinutes'], 'Tagessummen ergeben die Gesamtsumme.');
            $january = $service->range(null, '2027-01-01', '2027-01-31', $tech);
            $this->assertSame(133, $january['totals']['netMinutes'], 'Monat Januar enthält nur den Anteil nach Mitternacht.');
            $newYear = $service->day(null, '2027-01-01', $tech);
            $this->assertSame(133, $newYear['totals']['netMinutes']);
            $this->assertSame(1, count($newYear['carriedOver']), 'Fortsetzung vom Vortag sichtbar.');
            $this->assertSame([], $newYear['entries']);
        });
    }

    public function testLegacyTravelEntriesAndDayAssignmentAreMigrated(): void
    {
        $tmp = sys_get_temp_dir() . '/calight-time-legacy-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/migrations', 0770, true);
        try {
            foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
                if (strcmp(basename($file), '0033') < 0) {
                    copy($file, $tmp . '/migrations/' . basename($file));
                }
            }
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, $tmp . '/migrations'))->migrate();
            $now = '2026-11-12T08:00:00Z';
            $tech = $this->user($pdo, 'technician', $now);
            $employee = $this->employee($pdo, $tech['id'], 'Max', $now);
            foreach ([['t1', '07:00', '08:00', 60, 'travel'], ['t2', '08:00', '12:00', 240, 'work']] as [$id, $start, $end, $net, $activity]) {
                $pdo->prepare("INSERT INTO time_entries (id, employee_id, work_date, start_time, end_time, net_minutes, activity, created_by_user_id, created_at, updated_by_user_id, updated_at)
                    VALUES (:id, :employee, '2026-11-16', :start, :end, :net, :activity, :user, :now, :user, :now)")
                    ->execute(['id' => $id, 'employee' => $employee, 'start' => $start, 'end' => $end, 'net' => $net, 'activity' => $activity, 'user' => $tech['id'], 'now' => $now]);
            }
            $pdo->prepare("INSERT INTO time_day_details (employee_id, work_date, assignment_kind, updated_by_user_id, updated_at) VALUES (:employee, '2026-11-16', 'external', :user, :now)")
                ->execute(['employee' => $employee, 'user' => $tech['id'], 'now' => $now]);
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();

            $service = new TimeTrackingService(new TimeTrackingRepository($pdo), new AttachmentStore($tmp));
            $day = $service->day(null, '2026-11-16', $tech);
            $this->assertSame(300, $day['totals']['netMinutes']);
            $this->assertSame(60, $day['totals']['travelMinutes'], 'Bisherige Fahrt zählt genau einmal als Reisezeit.');
            $this->assertSame(['external', 'external'], array_column($day['entries'], 'assignmentKind'), 'Tagesangabe auf Blöcke ohne eigene Angabe übertragen.');
            // Bestandsblock „Fahrt“ bleibt bearbeitbar.
            $legacy = $day['entries'][0];
            $edited = $service->updateEntry($legacy['id'], ['version' => $legacy['version'], 'date' => '2026-11-16', 'start' => '06:45', 'end' => '08:00', 'activity' => 'travel', 'travelMinutes' => '75'], $tech);
            $this->assertSame(75, $edited['totals']['travelMinutes']);
        } finally {
            $this->remove($tmp);
        }
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
