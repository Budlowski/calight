<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DailyReportService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\TimeTrackingService;
use App\Application\OperationalSettingsService;
use App\Application\WorkReportService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Document\DocumentValidationException;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Infrastructure\Persistence\WorkReportRepository;
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

    public function testWorkLocationIsStoredWithAssignmentKind(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech] = $env;
            $day = $service->createEntry(['date' => '2026-11-18', 'start' => '07:00', 'end' => '11:00', 'workLocation' => '  Baustelle Leipzig ', 'assignmentKind' => 'external'], $tech);
            $entry = $day['entries'][0];
            $this->assertSame(['Baustelle Leipzig', 'external'], [$entry['workLocation'], $entry['assignmentKind']], 'Einsatzort getrimmt, Einsatzart unverändert.');
            $day = $service->updateEntry($entry['id'], ['version' => $entry['version'], 'date' => '2026-11-18', 'start' => '07:00', 'end' => '11:00', 'workLocation' => '', 'assignmentKind' => ''], $tech);
            $this->assertSame(['', null], [$day['entries'][0]['workLocation'], $day['entries'][0]['assignmentKind']], 'Ohne Angabe bleibt beides leer.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-11-18', 'start' => '12:00', 'end' => '13:00', 'workLocation' => str_repeat('x', 201)], $tech));
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
            // Ruhezeit-Hinweise liefert die Prüfung der Freigabe (TimeApprovalService::check, TimeApprovalTest).
            $next = $service->createEntry(['date' => '2026-11-17', 'start' => '08:00', 'end' => '09:00', 'activity' => 'work'], $tech);

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
            $day = $service->saveDetails(['date' => '2026-11-18', 'travelCategory' => 'arrival', 'overnight' => true, 'location' => 'Leipzig', 'absence' => '13:30', 'mealBreakfast' => false], $tech);
            $this->assertSame(['arrival', 810, '14.00'], [$day['details']['travelCategory'], $day['details']['absenceMinutes'], $day['travel']['allowance']]);
            $this->assertTrue(!array_key_exists('assignmentKind', $day['details']), 'Einsatzart nicht mehr je Tag.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveDetails(['date' => '2026-11-18', 'absence' => '25:00'], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveDetails(['date' => '2026-11-18', 'travelCategory' => 'holiday'], $tech));
            $day = $service->saveTrip(['date' => '2026-11-18', 'kilometers' => '182,5', 'route' => 'Werkstatt – Leipzig', 'purpose' => 'Anreise', 'orderId' => $order], $tech);
            $this->assertSame(['182.5', '54.75'], [$day['travel']['privateKm'], $day['travel']['kmAmount']]);
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveTrip(['date' => '2026-11-18', 'kilometers' => '-3', 'purpose' => 'X'], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveTrip(['date' => '2026-11-18', 'kilometers' => '3'], $tech), 'Fahrtzweck Pflicht.');

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

    public function testCalendarSplitIsIdenticalInAllViewsReportAndOrderHours(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'office' => $office, 'employee' => $employee, 'order' => $order, 'pdo' => $pdo, 'customer' => $customer] = $env;
            // Monatswechsel: 30.11. 22:00 – 01.12. 02:00, 60 min Reise → je 120 min, Reise 30/30.
            $service->createEntry(['date' => '2026-11-30', 'start' => '22:00', 'end' => '02:00', 'travelMinutes' => '60', 'activity' => 'work', 'orderId' => $order], $tech);
            $service->createEntry(['date' => '2026-12-01', 'start' => '08:00', 'end' => '12:00', 'activity' => 'work', 'orderId' => $order], $tech);
            // Jahreswechsel: 31.12. 22:00 – 01.01. 02:30, 30 min Pause, 60 min Reise → 107/133.
            $service->createEntry(['date' => '2026-12-31', 'start' => '22:00', 'end' => '02:30', 'breakMinutes' => '30', 'travelMinutes' => '60', 'activity' => 'work', 'orderId' => $order], $tech);
            $total = 240 + 240 + 240;

            $days = array_sum(array_map(fn (string $date): int => $service->day($employee, $date, $office)['totals']['netMinutes'], ['2026-11-30', '2026-12-01', '2026-12-31', '2027-01-01']));
            $months = [['2026-11-01', '2026-11-30', 120], ['2026-12-01', '2026-12-31', 467], ['2027-01-01', '2027-01-31', 133]];
            $monthSum = 0;
            $reportSum = 0;
            $orderSum = 0;
            foreach ($months as [$from, $to, $expected]) {
                $month = $service->range($employee, $from, $to, $office)['totals']['netMinutes'];
                $report = $service->report($from, $to, $employee, null, $office);
                $actual = $service->actualHoursForOrder($order, $office, $from, $to);
                $this->assertSame($expected, $month, "Monat $from");
                $this->assertSame($expected, $report['totals']['netMinutes'], "Büroauswertung $from nach Kalendertag");
                $this->assertSame($expected, $report['byOrder'][0]['netMinutes']);
                $this->assertSame($expected, $actual['totals']['netMinutes'], "Ist-Stunden $from nach Kalendertag");
                $monthSum += $month;
                $reportSum += $report['totals']['netMinutes'];
                $orderSum += $actual['totals']['netMinutes'];
            }
            $weekSum = 0;
            for ($monday = new \DateTimeImmutable('2026-11-30'); $monday->format('Y-m-d') <= '2026-12-28'; $monday = $monday->modify('+7 days')) {
                $weekSum += $service->range($employee, $monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d'), $office)['totals']['netMinutes'];
            }
            $this->assertSame([$total, $total, $total, $total, $total], [$days, $weekSum, $monthSum, $reportSum, $orderSum], 'Tag, Woche, Monat, Auswertung und Auftrag summieren gleich.');

            $actual = $service->actualHoursForOrder($order, $office);
            $this->assertSame($total, $actual['totals']['netMinutes'], 'Auftragssumme unabhängig von der Aufteilung.');
            $this->assertSame(30 + 30 + 27 + 33, $actual['totals']['travelMinutes']);
            $this->assertSame($total - 120, $actual['totals']['workMinutes']);
            $this->assertTrue(!array_key_exists('status', $actual) && !array_key_exists('pending', $actual), 'Keine Altfall- oder Quellenwahlangaben mehr.');
            $this->assertSame(['2026-11-30', '2026-12-01'], array_column(array_filter($actual['items'], static fn (array $item): bool => $item['sourceDate'] === '2026-11-30'), 'date'));
            $this->assertSame('42.0000', $actual['items'][0]['hourlyCostRate'], 'Kostensatz-Snapshot mit costs.read.');
            $this->assertSame($total, $actual['byEmployee'][0]['netMinutes']);
            $december = $service->report('2026-12-01', '2026-12-31', null, null, $office);
            $carried = array_values(array_filter($december['rows'], static fn (array $row): bool => $row['date'] === '2026-11-30'))[0];
            $this->assertSame([240, 120], [$carried['netMinutes'], $carried['periodNetMinutes']], 'Zeile zeigt Block und Anteil im Zeitraum.');
        });
    }

    public function testWorkReportHoursAreBookedOnceAndCorrectionsReplaceThem(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'office' => $office, 'employee' => $employee, 'colleagueEmployee' => $eva, 'order' => $order,
                'customer' => $customer, 'pdo' => $pdo, 'workReports' => $reports] = $env;
            $bookings = static fn (): array => $pdo->query("SELECT t.id, t.employee_id, t.work_date, t.net_minutes, t.start_time, t.order_id, l.report_id FROM time_entries t
                JOIN work_report_labor l ON l.id = t.work_report_labor_id ORDER BY t.work_date, t.net_minutes")->fetchAll();
            $save = static fn (array $report, array $labor) => $reports->update($report['id'], ['version' => $report['version'], 'description' => 'Montage', 'labor' => $labor, 'materials' => []], $office);

            // Mehrtägig, mehrere Mitarbeiter, mehrere Positionen je Mitarbeiter und Tag, andere Person ohne Mitarbeiter.
            $draft = $reports->create(['customerId' => $customer, 'orderId' => $order, 'workDate' => '2026-11-18'], $office);
            $draft = $save($draft, [
                ['workDate' => '2026-11-18', 'employeeId' => $employee, 'hours' => '6'],
                ['workDate' => '2026-11-18', 'employeeId' => $employee, 'hours' => '2'],
                ['workDate' => '2026-11-19', 'employeeId' => $employee, 'hours' => '5'],
                ['workDate' => '2026-11-18', 'employeeId' => $eva, 'hours' => '6,5'],
                ['workDate' => '2026-11-18', 'workerName' => 'Aushilfe Ben', 'hours' => '3'],
            ]);
            $this->assertThrows(OrderValidationException::class, fn () => $save($draft, [['workDate' => '2026-11-18', 'employeeId' => $employee, 'hours' => '0,333']]), 'Nur ganze Minuten.');
            $this->assertSame([120, 360, 390, 300], array_map('intval', array_column($bookings(), 'net_minutes')), 'Je Position mit Mitarbeiter genau eine Buchung, gleiche Dauer (Aushilfe ohne Mitarbeiter: keine).');
            $day = $service->day($employee, '2026-11-18', $tech);
            $this->assertSame([480, 'work_report', true, true], [$day['totals']['netMinutes'], $day['entries'][0]['sourceKind'], $day['entries'][0]['reportDraft'], $day['entries'][0]['durationFixed']]);
            $this->assertSame($order, $day['entries'][0]['orderId'], 'Auftrag aus dem Rapport.');
            $this->assertSame(300, $service->day($employee, '2026-11-19', $tech)['totals']['netMinutes'], 'Leistungsdatum der Position.');
            $this->assertSame(390, $service->day($eva, '2026-11-18', $office)['totals']['netMinutes'], 'Richtiger Mitarbeiter.');

            // Erneutes Speichern mit denselben Positionen: dieselben Buchungen (stabile IDs), keine Doppelung.
            $before = $bookings();
            $sixHours = array_values(array_filter($day['entries'], static fn (array $entry): bool => $entry['netMinutes'] === 360))[0];
            $this->assertThrows(DocumentValidationException::class, fn () => $service->updateEntry($sixHours['id'], ['version' => $sixHours['version'], 'start' => '08:00', 'end' => '15:30', 'breakMinutes' => '30'], $tech), '7 h passen nicht zu 6 h laut Rapport.');
            $timed = $service->updateEntry($sixHours['id'], ['version' => $sixHours['version'], 'start' => '08:00', 'end' => '14:30', 'breakMinutes' => '30', 'travelMinutes' => '60', 'assignmentKind' => 'external'], $tech);
            $this->assertSame([480, 60], [$timed['totals']['netMinutes'], $timed['totals']['travelMinutes']], 'Reisezeit ist Teil der Berichtsstunden, nicht zusätzlich.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->deleteEntry($sixHours['id'], ['version' => $sixHours['version'] + 1], $tech), 'Berichtsbuchungen nur über den Bericht.');
            $draft = $reports->detail($draft['id'], $office);
            $draft = $save($draft, array_map(static fn (array $row): array => ['id' => $row['id'], 'workDate' => $row['workDate'], 'employeeId' => $row['employeeId'] ?? '', 'workerName' => $row['employeeId'] ? '' : $row['workerName'], 'hours' => $row['hours']], $draft['labor']));
            $this->assertSame(array_column($before, 'id'), array_column($bookings(), 'id'));
            $this->assertSame('08:00', $pdo->query('SELECT start_time FROM time_entries WHERE id = ' . $pdo->quote($sixHours['id']))->fetchColumn(), 'Ergänzte Uhrzeiten bleiben beim erneuten Speichern.');

            // Einreichen erzeugt keine zweite Buchung; Uhrzeiten ändern den Nachweis nicht.
            $submitted = $reports->submit($draft['id'], ['version' => $draft['version']], $office);
            $this->assertSame(array_column($before, 'id'), array_column($bookings(), 'id'));
            $hash = $submitted['submittedSha256'];
            $entry = $service->day($employee, '2026-11-18', $tech)['entries'];
            $sixHours = array_values(array_filter($entry, static fn (array $item): bool => $item['netMinutes'] === 360))[0];
            $this->assertTrue(!$sixHours['reportDraft']);
            $service->updateEntry($sixHours['id'], ['version' => $sixHours['version'], 'start' => '07:00', 'end' => '13:30', 'breakMinutes' => '30'], $tech);
            $this->assertSame($hash, $reports->detail($submitted['id'], $office)['submittedSha256'], 'Uhrzeiten verändern weder Prüfsumme noch Rapport.');
            $this->assertSame('6.000', $reports->detail($submitted['id'], $office)['labor'][0]['hours']);
            $actual = $service->actualHoursForOrder($order, $office);
            $this->assertSame([1170, 1170], [$actual['totals']['netMinutes'], $actual['bySource']['work_report']], 'Rapportstunden sind Ist-Stunden (6+2+5+6,5 h).');

            // Korrektur: Entwurf ersetzt noch nichts.
            $correction = $reports->create(['correctionOf' => $submitted['id']], $office);
            $this->assertSame(array_column($before, 'id'), array_column($bookings(), 'id'), 'Korrekturentwurf hat keine Wirkung.');
            $rows = array_map(static fn (array $row): array => ['id' => $row['id'], 'workDate' => $row['workDate'], 'employeeId' => $row['employeeId'] ?? '', 'workerName' => $row['employeeId'] ? '' : $row['workerName'], 'hours' => $row['hours']], $correction['labor']);
            // 6 h → 5 h (Uhrzeiten passen nicht mehr), 2 h unverändert, 19.11. entfällt, Eva → Max am 20.11.
            $rows[0]['hours'] = '5';
            unset($rows[2]);
            $rows[3]['employeeId'] = $employee;
            $rows[3]['workDate'] = '2026-11-20';
            $correction = $save($correction, array_values($rows));
            // Konkurrierende zweite Korrektur desselben Rapports.
            $competitor = $reports->create(['correctionOf' => $submitted['id']], $office);
            $competitor = $save($competitor, [['workDate' => '2026-11-18', 'employeeId' => $employee, 'hours' => '1']]);
            $replaced = $reports->submit($correction['id'], ['version' => $correction['version']], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $reports->submit($competitor['id'], ['version' => $competitor['version']], $office), 'Nur eine gültige Fassung je Kette.');
            $this->assertSame('draft', $reports->detail($competitor['id'], $office)['status']);
            $original = $reports->detail($submitted['id'], $office);
            $this->assertSame([false, $replaced['reportNumber'], $hash], [$original['valid'], $original['supersededByNumber'], $original['submittedSha256']], 'Original bleibt erhalten, zählt aber nicht.');
            $this->assertThrows(OrderValidationException::class, fn () => $reports->create(['correctionOf' => $submitted['id']], $office), 'Nur die gültige Fassung wird korrigiert.');
            $now = $bookings();
            $this->assertSame([$replaced['id']], array_values(array_unique(array_column($now, 'report_id'))), 'Nur Buchungen der gültigen Fassung.');
            $this->assertSame([['2026-11-18', 120], ['2026-11-18', 300], ['2026-11-20', 390]], array_map(static fn (array $row): array => [$row['work_date'], (int) $row['net_minutes']], $now));
            $this->assertSame([$employee], array_values(array_unique(array_column($now, 'employee_id'))), 'Mitarbeiterwechsel übernommen.');
            $this->assertSame([0, 0, 0], [$service->day($employee, '2026-11-19', $office)['totals']['netMinutes'], $service->day($eva, '2026-11-18', $office)['totals']['netMinutes'],
                count(array_filter($now, static fn (array $row): bool => (int) $row['net_minutes'] === 300 && $row['start_time'] !== null))], 'Entfallene Positionen weg; geänderte Dauer ohne erfundene Uhrzeiten.');
            $this->assertSame(810, $service->actualHoursForOrder($order, $office)['totals']['netMinutes'], 'Nur die gültige Fassung zählt (5 + 2 + 6,5 h).');

            // Zweite Korrektur in der Kette: nur noch 5 h insgesamt.
            $third = $reports->create(['correctionOf' => $replaced['id']], $office);
            $third = $save($third, [['workDate' => '2026-11-18', 'employeeId' => $employee, 'hours' => '5']]);
            $reports->submit($third['id'], ['version' => $third['version']], $office);
            $this->assertSame(300, $service->actualHoursForOrder($order, $office)['totals']['netMinutes'], 'R001 8 h → R002 → R003 5 h: zählt 5 h, nicht die Summe.');
            // Verworfener Korrekturentwurf ohne Wirkung.
            $discard = $reports->create(['correctionOf' => $third['id']], $office);
            $reports->delete($discard['id'], $discard['version'], $office);
            $this->assertSame(300, $service->actualHoursForOrder($order, $office)['totals']['netMinutes']);
        });
    }

    public function testDailyReportHoursAreBookedAndFollowCorrectionsAndOrderAssignment(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'otherTech' => $otherTech, 'office' => $office, 'employee' => $employee, 'order' => $order, 'customer' => $customer,
                'pdo' => $pdo, 'reports' => $reports, 'orders' => $orders, 'workReports' => $workReports] = $env;
            $report = $reports->create(['customerId' => $customer, 'reportDate' => '2026-11-16', 'activities' => 'Besichtigung',
                'entries' => [['employeeId' => $employee, 'durationHours' => '7', 'targetKey' => 'order', 'description' => 'Aufnahme']]], $tech);
            $day = $service->day($employee, '2026-11-16', $tech);
            $this->assertSame([420, 'daily_report', null, 'Aufnahme'], [$day['totals']['netMinutes'], $day['entries'][0]['sourceKind'], $day['entries'][0]['orderId'], $day['entries'][0]['reportActivity']], 'Ohne Auftrag in der Zeiterfassung.');
            $booking = $day['entries'][0];
            $service->updateEntry($booking['id'], ['version' => $booking['version'], 'start' => '07:00', 'end' => '14:30', 'breakMinutes' => '30'], $tech);

            // Büro korrigiert 7 → 6 h: Zeiterfassung sofort 6 h, keine Differenzbuchung, Uhrzeiten passen nicht mehr.
            $this->assertThrows(AccessDeniedException::class, fn () => $reports->update($report['id'], ['version' => $report['version'], 'reportDate' => '2026-11-16', 'activities' => 'x',
                'entries' => [['id' => $report['entries'][0]['id'], 'employeeId' => $employee, 'durationHours' => '6', 'targetKey' => 'order']]], $otherTech), 'Fremder Monteur korrigiert nicht.');
            $corrected = $reports->update($report['id'], ['version' => $report['version'], 'reportDate' => '2026-11-16', 'activities' => 'Besichtigung',
                'entries' => [['id' => $report['entries'][0]['id'], 'employeeId' => $employee, 'durationHours' => '6', 'targetKey' => 'order', 'description' => 'Aufnahme']]], $office);
            $day = $service->day($employee, '2026-11-16', $tech);
            $this->assertSame([360, 1, null, $booking['id']], [$day['totals']['netMinutes'], count($day['entries']), $day['entries'][0]['start'], $day['entries'][0]['id']]);
            $this->assertSame((string) $office['id'], $pdo->query('SELECT updated_by_user_id FROM daily_report_time_entries')->fetchColumn(), 'Korrektur nachvollziehbar.');
            $requests = (int) $pdo->query('SELECT COUNT(*) FROM material_requests')->fetchColumn();

            // Nachträgliche Zuordnung: Stunden folgen dem Auftrag, keine neue Buchung, kein separater Übernahmeschritt.
            $this->assertThrows(AccessDeniedException::class, fn () => $reports->assignOrder($report['id'], ['version' => $corrected['version'], 'orderId' => $order], $tech));
            $assigned = $reports->assignOrder($report['id'], ['version' => $corrected['version'], 'orderId' => $order], $office);
            $this->assertSame([$order, 1], [$service->day($employee, '2026-11-16', $tech)['entries'][0]['orderId'], count($service->day($employee, '2026-11-16', $tech)['entries'])]);
            $this->assertSame(360, $service->actualHoursForOrder($order, $office)['bySource']['daily_report']);
            // Falsche Zuordnung korrigieren: anderer Auftrag bzw. wieder ohne Auftrag.
            $other = $orders->createRegie(['customerId' => $customer, 'title' => 'Zweiter Auftrag', 'orderDate' => '2026-11-16']);
            $moved = $reports->assignOrder($report['id'], ['version' => $assigned['version'], 'orderId' => $other['id']], $office);
            $this->assertSame([0, 360], [$service->actualHoursForOrder($order, $office)['totals']['netMinutes'], $service->actualHoursForOrder($other['id'], $office)['totals']['netMinutes']]);
            $removed = $reports->assignOrder($report['id'], ['version' => $moved['version'], 'orderId' => ''], $office);
            $this->assertSame([null, 360], [$service->day($employee, '2026-11-16', $tech)['entries'][0]['orderId'], $service->day($employee, '2026-11-16', $tech)['totals']['netMinutes']]);
            $this->assertSame([[null, $order], [$order, $other['id']], [$other['id'], null]], array_map(static fn (array $event): array => [$event['previous_order_id'], $event['order_id']], $removed['orderEvents']), 'Zuordnungen protokolliert.');
            $this->assertSame($requests, (int) $pdo->query('SELECT COUNT(*) FROM material_requests')->fetchColumn(), 'Keine Material- oder Bestellbuchung.');
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM time_entries WHERE source_kind = 'daily_report'")->fetchColumn());

            // Alle drei Herkünfte für einen Auftrag: Rapport 5 h + Bautagesbericht 3 h + manuell 1 h = 9 h.
            $reports->create(['orderId' => $order, 'reportDate' => '2026-11-17', 'activities' => 'Montage', 'entries' => [['employeeId' => $employee, 'durationHours' => '3', 'targetKey' => 'order']]], $tech);
            $work = $workReports->create(['orderId' => $order, 'workDate' => '2026-11-17'], $tech);
            $work = $workReports->update($work['id'], ['version' => $work['version'], 'description' => 'Regie', 'labor' => [['workDate' => '2026-11-17', 'employeeId' => $employee, 'hours' => '5']], 'materials' => []], $tech);
            $workReports->submit($work['id'], ['version' => $work['version']], $tech);
            $service->createEntry(['date' => '2026-11-17', 'start' => '16:00', 'end' => '17:00', 'activity' => 'preparation', 'orderId' => $order], $tech);
            $service->createEntry(['date' => '2026-11-17', 'start' => '17:00', 'end' => '18:00', 'activity' => 'internal'], $tech);
            $actual = $service->actualHoursForOrder($order, $office);
            $this->assertSame([540, ['work_report' => 300, 'daily_report' => 180, 'manual' => 60]], [$actual['totals']['netMinutes'], $actual['bySource']], 'Jede Stunde genau einmal; allgemeine Zeit ohne Auftrag zählt nicht zum Auftrag.');
            $range = $service->range($employee, '2026-11-16', '2026-11-22', $tech);
            $month = $service->range($employee, '2026-11-01', '2026-11-30', $tech);
            $this->assertSame([960, 960, ['work_report' => 300, 'daily_report' => 540, 'manual' => 120]], [$range['totals']['netMinutes'], $month['totals']['netMinutes'], $range['totals']['bySource']], 'Woche und Monat enthalten alle Herkünfte.');
            // Überschneidung mit ergänzten Uhrzeiten wird abgelehnt (auch zwischen Herkünften).
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-11-17', 'start' => '16:30', 'end' => '17:30', 'activity' => 'other'], $tech));
        });
    }

    public function testTimesOptionalSettingControlsCompletenessWithoutChangingHours(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'tech' => $tech, 'office' => $office, 'admin' => $admin, 'employee' => $employee, 'customer' => $customer, 'reports' => $reports, 'settings' => $settings] = $env;
            $this->assertThrows(AccessDeniedException::class, fn () => $settings->all($office), 'Nur Administratoren.');
            $this->assertThrows(AccessDeniedException::class, fn () => $settings->save(['time_tracking.times_optional' => false], $tech));
            $this->assertSame(['operations.manage'], AuthorizationPolicy::required(new Request('PUT', '/api/operational-settings', [], [], [], '')));
            $this->assertSame([true, true], [$settings->all($admin)['settings'][0]['value'], $settings->all($admin)['settings'][0]['default']]);
            $this->assertThrows(DocumentValidationException::class, fn () => $settings->save(['time_tracking.times_optional' => 'nein'], $admin));
            $this->assertThrows(DocumentValidationException::class, fn () => $settings->save(['unbekannt' => true], $admin));

            // Manuelle Zeiterfassung immer mit Uhrzeiten (unabhängig von der Einstellung).
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-11-16', 'durationMinutes' => '90', 'activity' => 'internal'], $tech), 'Keine reine Dauer.');
            // Aktiviert: Berichtsstunden ohne Uhrzeiten sind vollständig.
            $manual = $service->createEntry(['date' => '2026-11-16', 'start' => '13:00', 'end' => '14:30', 'activity' => 'internal', 'notes' => 'Angebotsbearbeitung'], $tech);
            $reports->create(['customerId' => $customer, 'reportDate' => '2026-11-16', 'activities' => 'Termin', 'entries' => [['employeeId' => $employee, 'durationHours' => '2', 'targetKey' => 'order']]], $tech);
            $day = $service->day($employee, '2026-11-16', $tech);
            $this->assertSame([210, 0, [false, false]], [$day['totals']['netMinutes'], $day['totals']['incompleteMinutes'], array_column($day['entries'], 'timesMissing')]);
            $this->assertSame(90, $manual['entries'][0]['netMinutes']);

            // Deaktiviert: nichts geht verloren, Stunden zählen weiter, sind aber „Uhrzeiten fehlen“.
            $settings->save(['time_tracking.times_optional' => false], $admin);
            $day = $service->day($employee, '2026-11-16', $tech);
            $missing = array_column($day['entries'], 'timesMissing');
            sort($missing);
            $this->assertSame([210, 120, [false, true]], [$day['totals']['netMinutes'], $day['totals']['incompleteMinutes'], $missing], 'Nur die Berichtsstunden ohne Uhrzeiten.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-11-17', 'durationMinutes' => '60', 'activity' => 'internal'], $tech), 'Manuell nur mit Uhrzeiten.');
            $report = $reports->create(['customerId' => $customer, 'reportDate' => '2026-11-17', 'activities' => 'Termin', 'entries' => [['employeeId' => $employee, 'durationHours' => '1', 'targetKey' => 'order']]], $tech);
            $this->assertSame(60, $service->range($employee, '2026-11-17', '2026-11-17', $tech)['totals']['incompleteMinutes'], 'Berichte bleiben unkompliziert; Buchung zunächst unvollständig.');
            $entry = $service->day($employee, '2026-11-17', $tech)['entries'][0];
            $completed = $service->updateEntry($entry['id'], ['version' => $entry['version'], 'start' => '08:00', 'end' => '09:00'], $tech);
            $this->assertSame([60, 0], [$completed['totals']['netMinutes'], $completed['totals']['incompleteMinutes']], 'Nach Ergänzung vollständig.');
            $this->assertSame(1, count($reports->detail($report['id'], $tech)['entries']));
            $settings->save(['time_tracking.times_optional' => true], $admin);
            $this->assertSame(0, $service->day($employee, '2026-11-16', $tech)['totals']['incompleteMinutes'], 'Bewertung nach aktueller Regel, Daten unverändert.');
        });
    }

    public function testCleanupMigrationRemovesLegacyStateAndSourceChoices(): void
    {
        // Stand vor 0037 mit früherer Quellenwahl und Altrapporten; danach 0037 und die Bereinigung 0038.
        $tmp = sys_get_temp_dir() . '/calight-time-central-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/migrations', 0770, true);
        try {
            foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
                if (strcmp(basename($file), '0037') < 0) {
                    copy($file, $tmp . '/migrations/' . basename($file));
                }
            }
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, $tmp . '/migrations'))->migrate();
            $now = '2026-11-12T08:00:00Z';
            $office = $this->user($pdo, 'office', $now);
            $employee = $this->employee($pdo, $office['id'], 'Max', $now);
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Kunde',:now,:now)")->execute(['id' => $customer, 'now' => $now]);
            $order = Uuid::v4();
            $pdo->prepare("INSERT INTO orders(id,order_number,customer_id,order_type,title,order_date,status,created_at,updated_at) VALUES(:id,'A1',:customer,'regie','A','2026-01-01','in_progress',:now,:now)")->execute(['id' => $order, 'customer' => $customer, 'now' => $now]);
            $manual = $pdo->prepare("INSERT INTO time_entries (id, employee_id, work_date, start_time, end_time, net_minutes, activity, order_id, created_by_user_id, created_at, updated_by_user_id, updated_at)
                VALUES (:id, :employee, :date, '08:00', '12:00', 240, 'work', :order, :user, :now, :user, :now)");
            foreach (['m5' => '2026-01-05', 'm6' => '2026-01-06', 'm7' => '2026-01-07'] as $id => $date) {
                $manual->execute(['id' => $id, 'employee' => $employee, 'date' => $date, 'order' => $order, 'user' => $office['id'], 'now' => $now]);
            }
            $report = $pdo->prepare("INSERT INTO daily_reports (id, customer_id, order_id, labor_counts_for_order, report_date, activities, created_by_user_id, created_at, updated_at) VALUES (:id, :customer, :order, :counts, :date, 'x', :user, :now, :now)");
            $line = $pdo->prepare("INSERT INTO daily_report_time_entries (id, report_id, employee_id, work_date, duration_hours, hourly_cost_rate_snapshot, labor_cost, created_at, updated_at) VALUES (:id, :report, :employee, :date, :hours, '40.0000', '0.00', :now, :now)");
            foreach ([['r5', 'd5', '2026-01-05', $order, 1, '2.000'], ['r6', 'd6', '2026-01-06', $order, 1, '1.500'], ['r7', 'd7', '2026-01-07', $order, 1, '1.000'],
                ['r8', 'd8', '2026-01-08', $order, 0, '3.000'], ['r9', 'd9', '2026-01-09', null, 0, '4.000']] as [$reportId, $lineId, $date, $reportOrder, $counts, $hours]) {
                $report->execute(['id' => $reportId, 'customer' => $customer, 'order' => $reportOrder, 'counts' => $counts, 'date' => $date, 'user' => $office['id'], 'now' => $now]);
                $line->execute(['id' => $lineId, 'report' => $reportId, 'employee' => $employee, 'date' => $date, 'hours' => $hours, 'now' => $now]);
            }
            $choice = $pdo->prepare("INSERT INTO time_source_choices (employee_id, work_date, order_id, source, chosen_by_user_id, chosen_at) VALUES (:employee, :date, :order, :source, :user, :now)");
            $choice->execute(['employee' => $employee, 'date' => '2026-01-06', 'order' => $order, 'source' => 'time_tracking', 'user' => $office['id'], 'now' => $now]);
            $choice->execute(['employee' => $employee, 'date' => '2026-01-07', 'order' => $order, 'source' => 'daily_report', 'user' => $office['id'], 'now' => $now]);
            $work = $pdo->prepare("INSERT INTO work_reports (id, customer_id, order_id, report_number, work_date, status, correction_of_id, created_by_user_id, created_at, updated_at, submitted_by_user_id, submitted_at, submitted_sha256)
                VALUES (:id, :customer, :order, :number, '2026-01-05', :status, :correction, :user, :now, :now, :submitter, :submitted, :hash)");
            foreach ([['w1', 'RP1', 'submitted', null], ['w2', 'RP2', 'submitted', 'w1'], ['w3', null, 'draft', null], ['w4', null, 'draft', 'w2']] as [$id, $number, $status, $correction]) {
                $submitted = $status === 'draft' ? null : '2026-01-0' . substr($id, 1) . 'T10:00:00Z';
                $work->execute(['id' => $id, 'customer' => $customer, 'order' => $order, 'number' => $number, 'status' => $status, 'correction' => $correction, 'user' => $office['id'], 'now' => $now,
                    'submitter' => $status === 'draft' ? null : $office['id'], 'submitted' => $submitted, 'hash' => $status === 'draft' ? null : 'hash-' . $id]);
                $pdo->prepare("INSERT INTO work_report_labor (id, report_id, employee_id, worker_name, hours, sort_order) VALUES (:id, :report, :employee, 'Max', '8.000', 0)")
                    ->execute(['id' => 'l' . substr($id, 1), 'report' => $id, 'employee' => $employee]);
            }
            $hashes = $pdo->query('SELECT id, submitted_sha256 FROM work_reports ORDER BY id')->fetchAll();

            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);

            // Quellenwahl-Tabellen und Altfallspalten sind entfernt.
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
            $this->assertTrue(!in_array('time_source_choices', $tables, true) && !in_array('time_source_choice_events', $tables, true));
            $columns = array_column($pdo->query('PRAGMA table_info(time_entries)')->fetchAll(), 'name');
            $this->assertSame([], array_values(array_filter($columns, static fn (string $column): bool => str_starts_with($column, 'legacy_'))));
            foreach (['source_kind', 'daily_report_entry_id', 'work_report_labor_id', 'start_time', 'end_time', 'travel_minutes', 'assignment_kind'] as $column) {
                $this->assertTrue(in_array($column, $columns, true), $column . ' bleibt Teil der zentralen Stundenbasis.');
            }
            // Alle Buchungen bleiben erhalten und zählen regulär genau einmal; ersetzte Fassung und Korrekturentwurf ohne Buchung.
            $this->assertSame(10, (int) $pdo->query('SELECT COUNT(*) FROM time_entries')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM time_entries WHERE work_report_labor_id IN ('l1', 'l4')")->fetchColumn());
            $this->assertSame('w2', $pdo->query("SELECT superseded_by_id FROM work_reports WHERE id = 'w1'")->fetchColumn(), 'Korrekturkette bleibt.');
            $this->assertSame($hashes, $pdo->query('SELECT id, submitted_sha256 FROM work_reports ORDER BY id')->fetchAll(), 'Prüfsummen unverändert.');
            $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM operational_settings WHERE setting_key = 'time_tracking.times_optional'")->fetchColumn());
            $this->assertSame(['idx_time_entries_cost_center', 'idx_time_entries_employee_date', 'idx_time_entries_order'], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'time_entries' AND name LIKE 'idx_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN));

            // Auftrags-Ist-Stunden ausschließlich aus time_entries: 3 × 4 h manuell + 2 + 1,5 + 1 + 3 h Bautagesbericht + 8 + 8 h Rapport (w2, w3).
            $service = new TimeTrackingService(new TimeTrackingRepository($pdo), new AttachmentStore($tmp));
            $actual = $service->actualHoursForOrder($order, $office);
            $this->assertSame([720 + 120 + 90 + 60 + 180 + 960, ['work_report' => 960, 'daily_report' => 450, 'manual' => 720]], [$actual['totals']['netMinutes'], $actual['bySource']]);
            $this->assertSame(240, $service->day($employee, '2026-01-09', $office)['totals']['netMinutes'], 'Bericht ohne Auftrag zählt als allgemeine Zeit.');
            // Bestehende Buchungen bleiben bearbeitbar; die Datenbank prüft die Nettozeit weiterhin.
            $entry = $service->day($employee, '2026-01-05', $office)['entries'];
            $report = array_values(array_filter($entry, static fn (array $item): bool => $item['sourceKind'] === 'daily_report'))[0];
            $this->assertSame(120, $service->updateEntry($report['id'], ['version' => $report['version'], 'start' => '13:00', 'end' => '15:00'], $office)['entries'][1]['netMinutes']);
        } finally {
            $this->remove($tmp);
        }
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
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);

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
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);
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
            $colleagueEmployee = $this->employee($pdo, $otherTech['id'], 'Eva Elektrikerin', $now);
            $test([
                'pdo' => $pdo, 'tmp' => $tmp, 'files' => $tmp . '/documents', 'customer' => $customer,
                'service' => new TimeTrackingService(new TimeTrackingRepository($pdo), new AttachmentStore($tmp . '/documents')),
                'order' => $released['id'], 'openOrder' => $open['id'],
                'tech' => $tech, 'otherTech' => $otherTech, 'office' => $office, 'employee' => $employee,
                'noEmployee' => $this->user($pdo, 'technician', $now),
                'admin' => $this->user($pdo, 'admin', $now), 'colleagueEmployee' => $colleagueEmployee, 'orders' => $orders,
                'reports' => new DailyReportService($pdo, new DailyReportRepository($pdo), new EmployeeRepository($pdo), new OrderRepository($pdo)),
                'workReports' => new WorkReportService($pdo, new WorkReportRepository($pdo), new OrderRepository($pdo), $numbers, new PriceCalculator(), null, $orders),
                'settings' => new OperationalSettingsService($pdo),
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
