<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DailyReportService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OperationalSettingsService;
use App\Application\OrderService;
use App\Application\TimeApprovalService;
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
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\TimeApprovalRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Infrastructure\Persistence\WorkReportRepository;
use App\Security\AuthorizationPolicy;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;
use PDOException;

/** Einreichung, Festschreibung, Wiederöffnung, Sperren über alle Module, Prüfungen und Nachweise (Phase A). */
final class TimeApprovalTest extends TestCase
{
    public function testWeekSubmissionApprovalStatementAndRevision(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'approvals' => $approvals, 'tech' => $tech, 'otherTech' => $otherTech, 'office' => $office, 'admin' => $admin, 'employee' => $employee,
                'order' => $order, 'pdo' => $pdo, 'settings' => $settings, 'files' => $files] = $env;
            $this->assertSame(['timesheet.own'], AuthorizationPolicy::required(new Request('POST', '/api/time-tracking/submissions', [], [], [], '')));
            $service->createEntry(['date' => '2026-10-05', 'start' => '08:00', 'end' => '12:00', 'activity' => 'work', 'orderId' => $order], $tech);
            $service->createEntry(['date' => '2026-10-06', 'start' => '07:00', 'end' => '15:30', 'breakMinutes' => '30', 'activity' => 'work', 'orderId' => $order], $tech);

            // Zusammenfassung vor dem Einreichen: Stunden, Hinweise (leere Werktage), keine Sperrfehler.
            $period = $approvals->period(null, 'week', '2026-10-07', $tech);
            $this->assertSame(['2026-W41', '2026-10-05', '2026-10-11', 'open', true, 720], [$period['period']['key'], $period['period']['from'], $period['period']['to'], $period['status'], $period['canSubmit'], $period['totals']['netMinutes']]);
            $this->assertSame([], $period['errors']);
            $this->assertSame(['empty_day', 'empty_day', 'empty_day'], array_column($period['warnings'], 'code'), 'Leere Werktage Mi–Fr als Warnung.');

            // Einreichen sperrt alle Tage der Woche, auch arbeitsfreie; wiederholte Anfrage wird abgelehnt.
            $submitted = $approvals->submit(['type' => 'week', 'date' => '2026-10-05'], $tech);
            $this->assertSame(['submitted', ['submitted' => 7]], [$submitted['status'], $submitted['statusCounts']]);
            $this->assertThrows(DocumentValidationException::class, fn () => $approvals->submit(['type' => 'week', 'date' => '2026-10-05'], $tech), 'Keine doppelte Einreichung.');
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM time_submissions')->fetchColumn());

            // Gesperrt für alle – auch Büro und Administrator, auch direkt in der Datenbank.
            $day = $service->day($employee, '2026-10-07', $tech);
            $this->assertSame(['submitted', false], [$day['lockStatus'], $day['canEdit']]);
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-10-10', 'durationMinutes' => '60', 'activity' => 'internal'], $tech), 'Auch arbeitsfreie Tage gesperrt.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['employeeId' => $employee, 'date' => '2026-10-07', 'durationMinutes' => '60', 'activity' => 'internal'], $office));
            $entry = $service->day($employee, '2026-10-05', $office)['entries'][0];
            $this->assertThrows(DocumentValidationException::class, fn () => $service->updateEntry($entry['id'], ['version' => $entry['version'], 'date' => '2026-10-05', 'start' => '08:00', 'end' => '11:00', 'activity' => 'work'], $admin));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->deleteEntry($entry['id'], ['version' => $entry['version']], $office));
            $this->assertThrows(DocumentValidationException::class, fn () => $service->saveDetails(['employeeId' => $employee, 'date' => '2026-10-05', 'location' => 'Halle'], $office));
            $this->assertThrows(PDOException::class, fn () => $pdo->exec("UPDATE time_entries SET net_minutes = 1 WHERE id = " . $pdo->quote($entry['id'])), 'Datenbank-Trigger.');

            // Büro: Freigaben nur mit timesheet.manage; Warnungen müssen bestätigt werden.
            $this->assertThrows(AccessDeniedException::class, fn () => $approvals->pending($tech));
            $pending = $approvals->pending($office)['items'];
            $this->assertSame([1, 3], [count($pending), count($pending[0]['warnings'])]);
            $this->assertThrows(AccessDeniedException::class, fn () => $approvals->approve($pending[0]['id'], ['version' => $pending[0]['version'], 'acknowledgeWarnings' => true], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $approvals->approve($pending[0]['id'], ['version' => $pending[0]['version']], $office), 'Warnungen bestätigen.');
            $approvals->approve($pending[0]['id'], ['version' => $pending[0]['version'], 'acknowledgeWarnings' => true, 'comment' => 'Urlaub Mi–Fr'], $office);
            $this->assertThrows(DocumentValidationException::class, fn () => $approvals->approve($pending[0]['id'], ['version' => $pending[0]['version'], 'acknowledgeWarnings' => true], $office), 'Wiederholte Festschreibung abgelehnt.');

            // Nachweis: archiviert, unverändert abrufbar, nur für den Mitarbeiter und das Büro.
            $statements = $approvals->period(null, 'week', '2026-10-05', $tech)['statements'];
            $this->assertSame([['week', 1, true]], array_map(static fn (array $item): array => [$item['type'], $item['revision'], $item['current']], $statements));
            $first = $approvals->statementPdf($statements[0]['id'], $tech);
            $this->assertTrue(str_starts_with($first['pdf'], '%PDF') && str_contains($first['fileName'], '2026-W41_Rev1'));
            $this->assertTrue(str_contains($first['pdf'], '(Stundennachweis) Tj') && !str_contains($first['pdf'], '42,00'), 'Keine Kostensätze.');
            $this->assertThrows(AccessDeniedException::class, fn () => $approvals->statementPdf($statements[0]['id'], $otherTech));
            $this->assertSame($first['pdf'], $approvals->statementPdf($statements[0]['id'], $office)['pdf'], 'Büro erhält dieselbe Datei.');
            $snapshot = (string) $pdo->query('SELECT snapshot_json FROM time_statements')->fetchColumn();
            $this->assertSame('Urlaub Mi–Fr', json_decode($snapshot, true)['submissions'][0]['comment']);
            $settings->save(['time_limits.regular_daily_hours' => '7'], $admin);
            $this->assertSame($snapshot, (string) $pdo->query('SELECT snapshot_json FROM time_statements')->fetchColumn(), 'Einstellungen ändern archivierte Nachweise nicht.');
            $this->assertThrows(PDOException::class, fn () => $pdo->exec("UPDATE time_statements SET snapshot_json = '{}'"), 'Nachweis unveränderlich.');
            $this->assertThrows(PDOException::class, fn () => $pdo->exec('DELETE FROM time_statements'));

            // Wiederöffnen: Begründung Pflicht, nur Büro; der Nachweis wird ersetzt, die Datei bleibt.
            $this->assertThrows(AccessDeniedException::class, fn () => $approvals->reopen(['employeeId' => $employee, 'from' => '2026-10-06', 'to' => '2026-10-06', 'reason' => 'x'], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $approvals->reopen(['employeeId' => $employee, 'from' => '2026-10-06', 'to' => '2026-10-06', 'reason' => ' '], $office));
            $approvals->reopen(['employeeId' => $employee, 'from' => '2026-10-06', 'to' => '2026-10-06', 'reason' => 'Pause fehlt'], $office);
            $this->assertSame(['reopened', true], [$service->day($employee, '2026-10-06', $tech)['lockStatus'], $service->day($employee, '2026-10-06', $tech)['canEdit']]);
            $this->assertSame(false, $approvals->period(null, 'week', '2026-10-05', $tech)['statements'][0]['current'], 'Bisheriger Nachweis nicht mehr aktuell.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-10-05', 'durationMinutes' => '30', 'activity' => 'internal'], $tech), 'Andere Tage bleiben festgeschrieben.');
            $fix = $service->day($employee, '2026-10-06', $tech)['entries'][0];
            $service->updateEntry($fix['id'], ['version' => $fix['version'], 'date' => '2026-10-06', 'start' => '07:00', 'end' => '16:00', 'breakMinutes' => '60', 'activity' => 'work', 'orderId' => $order], $tech);

            // Erneut einreichen (nur der geöffnete Tag) und festschreiben: neue Revision, alte Datei unverändert.
            $again = $approvals->submit(['type' => 'week', 'date' => '2026-10-05'], $tech);
            $this->assertSame(['approved' => 6, 'submitted' => 1], $again['statusCounts']);
            $pending = $approvals->pending($office)['items'];
            $approvals->approve($pending[0]['id'], ['version' => $pending[0]['version'], 'acknowledgeWarnings' => true], $office);
            $statements = $approvals->period(null, 'week', '2026-10-05', $tech)['statements'];
            $this->assertSame([[1, false], [2, true]], array_map(static fn (array $item): array => [$item['revision'], $item['current']], $statements));
            $this->assertSame(hash('sha256', $first['pdf']), hash('sha256', $approvals->statementPdf($statements[0]['id'], $tech)['pdf']), 'Revision 1 bleibt unverändert.');
            $this->assertSame(2, count(glob($files . '/time-statements/' . $employee . '/*.pdf') ?: []));
            $this->assertSame(['submit', 'approve', 'reopen', 'submit', 'approve'], array_column($approvals->period(null, 'week', '2026-10-05', $tech)['events'], 'action'));
            $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        });
    }

    public function testMonthFromWeeksMonthBoundariesAndRhythm(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'approvals' => $approvals, 'tech' => $tech, 'office' => $office, 'admin' => $admin, 'employee' => $employee, 'order' => $order, 'settings' => $settings, 'pdo' => $pdo] = $env;
            $service->createEntry(['date' => '2026-10-01', 'start' => '08:00', 'end' => '12:00', 'activity' => 'work', 'orderId' => $order], $tech);
            // KW 40 reicht vom 28.09. bis 04.10., KW 44 vom 26.10. bis 01.11.
            $this->assertSame(['2026-W40', '2026-09-28', '2026-10-04'], array_values(array_intersect_key(TimeApprovalService::periodFor('week', '2026-10-01'), array_flip(['key', 'from', 'to']))));
            $this->assertSame(['2020-W53', '2020-12-28', '2021-01-03'], array_values(array_intersect_key(TimeApprovalService::periodFor('week', '2021-01-01'), array_flip(['key', 'from', 'to']))), 'ISO-Woche über den Jahreswechsel.');
            $approveAll = function () use ($approvals, $office): void {
                foreach ($approvals->pending($office)['items'] as $item) {
                    $approvals->approve($item['id'], ['version' => $item['version'], 'acknowledgeWarnings' => true], $office);
                }
            };
            foreach (['2026-10-01', '2026-10-05', '2026-10-12', '2026-10-19'] as $date) {
                $approvals->submit(['type' => 'week', 'date' => $date], $tech);
            }
            $approveAll();
            $this->assertSame([], array_column(array_filter($approvals->period(null, 'month', '2026-10-01', $tech)['statements'], static fn (array $item): bool => $item['type'] === 'month'), 'id'), 'Monat noch nicht vollständig.');
            // Gemischter Monat: festgeschrieben, offen.
            $month = $approvals->period(null, 'month', '2026-10-01', $tech);
            $this->assertSame(['mixed', 25, 6], [$month['status'], $month['statusCounts']['approved'], $month['statusCounts']['open']]);
            $approvals->submit(['type' => 'week', 'date' => '2026-10-26'], $tech);
            $approveAll();
            // Vollständig über Wochen festgeschrieben: Monatsnachweis ohne erneute Freigabe.
            $month = $approvals->period(null, 'month', '2026-10-15', $tech);
            $monthly = array_values(array_filter($month['statements'], static fn (array $item): bool => $item['type'] === 'month'));
            $this->assertSame(['approved', 1, 1, true], [$month['status'], count($monthly), $monthly[0]['revision'], $monthly[0]['current']]);
            $this->assertSame([], array_filter($approvals->period(null, 'month', '2026-09-15', $tech)['statements'], static fn (array $item): bool => $item['type'] === 'month'), 'September unvollständig: kein Monatsnachweis.');
            $this->assertThrows(DocumentValidationException::class, fn () => $approvals->submit(['type' => 'month', 'date' => '2026-10-01'], $tech), 'Nichts mehr offen.');
            $this->assertSame(240, json_decode((string) $pdo->query("SELECT snapshot_json FROM time_statements WHERE period_type = 'month'")->fetchColumn(), true)['totals']['netMinutes'], 'Keine doppelte Zählung.');

            // Wiederöffnen eines Tages ersetzt Wochen- und Monatsnachweis.
            $approvals->reopen(['employeeId' => $employee, 'from' => '2026-10-01', 'to' => '2026-10-01', 'reason' => 'Auftrag falsch'], $office);
            $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM time_statements WHERE status = 'superseded'")->fetchColumn());
            // Monatseinreichung des geöffneten Tages: neue Revision für Monat und Woche.
            $approvals->submit(['type' => 'month', 'date' => '2026-10-01'], $tech);
            $approveAll();
            $this->assertSame([['month', 2], ['week', 2]], $pdo->query("SELECT period_type, revision FROM time_statements WHERE status = 'current' AND (period_key = '2026-10' OR period_key = '2026-W40') ORDER BY period_type")->fetchAll(PDO::FETCH_NUM));

            // Rhythmus und nicht abgeschlossene Zeiträume.
            $settings->save(['time_tracking.submission_rhythm' => 'week'], $admin);
            $this->assertSame(['rhythm'], array_column(array_filter($approvals->period(null, 'month', '2026-09-01', $tech)['errors'], static fn (array $error): bool => $error['code'] === 'rhythm'), 'code'));
            $this->assertThrows(DocumentValidationException::class, fn () => $approvals->submit(['type' => 'month', 'date' => '2026-09-01'], $tech));
            $this->assertTrue(in_array('unfinished', array_column($approvals->period(null, 'week', '2099-01-05', $tech)['errors'], 'code'), true));
            $this->assertThrows(DocumentValidationException::class, fn () => $approvals->submit(['type' => 'week', 'date' => '2099-01-05'], $tech));
            $this->assertThrows(DocumentValidationException::class, fn () => $settings->save(['time_tracking.submission_rhythm' => 'daily'], $admin));
        });
    }

    public function testLocksApplyAcrossModulesIncludingCorrections(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'approvals' => $approvals, 'tech' => $tech, 'office' => $office, 'employee' => $employee, 'colleagueEmployee' => $eva, 'otherTech' => $otherTech,
                'order' => $order, 'customer' => $customer, 'reports' => $reports, 'workReports' => $workReports, 'pdo' => $pdo] = $env;
            $approveAll = function () use ($approvals, $office): void {
                foreach ($approvals->pending($office)['items'] as $item) {
                    $approvals->approve($item['id'], ['version' => $item['version'], 'acknowledgeWarnings' => true], $office);
                }
            };
            // Tagesbericht (KW 42) und eingereichter Rapport (KW 43) vor der Sperre.
            $report = $reports->create(['orderId' => $order, 'reportDate' => '2026-10-13', 'activities' => 'Montage', 'entries' => [['employeeId' => $employee, 'durationHours' => '3', 'targetKey' => 'order']]], $tech);
            $work = $workReports->create(['customerId' => $customer, 'orderId' => $order, 'workDate' => '2026-10-20'], $office);
            $work = $workReports->update($work['id'], ['version' => $work['version'], 'description' => 'Regie', 'labor' => [['workDate' => '2026-10-20', 'employeeId' => $employee, 'hours' => '2']], 'materials' => []], $office);
            $work = $workReports->submit($work['id'], ['version' => $work['version']], $office);
            // Normaler Rapportentwurf mit Stunden ist ein Sperrfehler; Korrekturentwurf nur ein Hinweis.
            $draft = $workReports->create(['customerId' => $customer, 'orderId' => $order, 'workDate' => '2026-10-21'], $office);
            $draft = $workReports->update($draft['id'], ['version' => $draft['version'], 'description' => 'Regie', 'labor' => [['workDate' => '2026-10-21', 'employeeId' => $employee, 'hours' => '1']], 'materials' => []], $office);
            $this->assertSame(['draft_report'], array_column($approvals->period(null, 'week', '2026-10-21', $tech)['errors'], 'code'));
            $this->assertThrows(DocumentValidationException::class, fn () => $approvals->submit(['type' => 'week', 'date' => '2026-10-21'], $tech));
            $workReports->delete($draft['id'], $workReports->detail($draft['id'], $office)['version'], $office);
            $correction = $workReports->create(['correctionOf' => $work['id']], $office);
            $this->assertTrue(in_array('correction_draft', array_column($approvals->period(null, 'week', '2026-10-21', $tech)['warnings'], 'code'), true), 'Offener Korrekturentwurf als Hinweis.');
            $this->assertSame(120, $approvals->period(null, 'week', '2026-10-21', $tech)['totals']['netMinutes'], 'Korrekturentwurf zählt nicht zusätzlich.');

            foreach (['2026-10-13', '2026-10-20'] as $date) {
                $approvals->submit(['type' => 'week', 'date' => $date], $tech);
            }
            $approveAll();

            // Tagesbericht: Stunden, Löschung und Zuordnung gesperrt; reine Beschreibung bleibt änderbar.
            $report = $reports->detail($report['id'], $tech);
            $entries = [['id' => $report['entries'][0]['id'], 'employeeId' => $employee, 'durationHours' => '3', 'targetKey' => 'order']];
            $base = ['reportDate' => '2026-10-13', 'title' => '', 'notes' => '', 'entries' => $entries];
            $report = $reports->update($report['id'], ['version' => $report['version'], 'activities' => 'Montage abgeschlossen'] + $base, $tech);
            $this->assertThrows(PDOException::class, fn () => $reports->update($report['id'], ['version' => $report['version'], 'activities' => 'x', 'entries' => [['id' => $entries[0]['id'], 'employeeId' => $employee, 'durationHours' => '4', 'targetKey' => 'order']]] + $base, $tech), 'Dauer.');
            $this->assertThrows(PDOException::class, fn () => $reports->update($report['id'], ['version' => $report['version'], 'activities' => 'x', 'entries' => []] + $base, $tech), 'Position löschen.');
            $this->assertThrows(PDOException::class, fn () => $reports->update($report['id'], ['version' => $report['version'], 'activities' => 'x', 'entries' => [['id' => $entries[0]['id'], 'employeeId' => $employee, 'durationHours' => '3', 'targetKey' => 'order', 'workDate' => '2026-10-27']]] + ['reportDate' => '2026-10-27'] + $base, $tech), 'Verschiebung aus gesperrtem Tag.');
            $this->assertThrows(PDOException::class, fn () => $reports->assignOrder($report['id'], ['version' => $report['version']], $office), 'Kostenziel.');
            $this->assertThrows(PDOException::class, fn () => $reports->delete($report['id'], $report['version'], $office), 'Bericht löschen.');
            $this->assertThrows(PDOException::class, fn () => $reports->create(['orderId' => $order, 'reportDate' => '2026-10-14', 'activities' => 'Nachtrag', 'entries' => [['employeeId' => $employee, 'durationHours' => '1', 'targetKey' => 'order']]], $tech), 'Neue Berichtsstunden.');
            $service->createEntry(['employeeId' => $eva, 'date' => '2026-10-14', 'durationMinutes' => '60', 'activity' => 'internal'], $office);

            // Rapporte: neue Stunden und Korrekturrapport (alte und neue Fassung) gesperrt.
            $new = $workReports->create(['customerId' => $customer, 'orderId' => $order, 'workDate' => '2026-10-22'], $office);
            $this->assertThrows(PDOException::class, fn () => $workReports->update($new['id'], ['version' => $new['version'], 'description' => 'Regie', 'labor' => [['workDate' => '2026-10-22', 'employeeId' => $employee, 'hours' => '1']], 'materials' => []], $office));
            $correction = $workReports->detail($correction['id'], $office);
            $this->assertThrows(PDOException::class, fn () => $workReports->submit($correction['id'], ['version' => $correction['version']], $office), 'Korrektur ersetzt gesperrte Stunden nicht.');
            $this->assertSame(120, (int) $pdo->query("SELECT SUM(net_minutes) FROM time_entries WHERE source_kind = 'work_report'")->fetchColumn(), 'Gültige Fassung bleibt maßgeblich.');

            // Über Mitternacht: Buchung des offenen Vortags reicht in einen gesperrten Tag.
            $evaUser = $otherTech;
            $service->createEntry(['date' => '2026-10-04', 'start' => '22:00', 'end' => '02:00', 'activity' => 'work', 'orderId' => $order], $evaUser);
            $approvals->submit(['type' => 'week', 'date' => '2026-10-05', 'employeeId' => $eva], $office);
            $night = $service->day($eva, '2026-10-04', $office)['entries'][0];
            $this->assertThrows(DocumentValidationException::class, fn () => $service->updateEntry($night['id'], ['version' => $night['version'], 'date' => '2026-10-04', 'start' => '22:00', 'end' => '01:00', 'activity' => 'work'], $evaUser), 'Folgetag gesperrt.');
            $this->assertThrows(DocumentValidationException::class, fn () => $service->createEntry(['date' => '2026-10-04', 'start' => '23:30', 'end' => '00:30', 'activity' => 'work'], $evaUser));
            $service->createEntry(['date' => '2026-10-04', 'start' => '10:00', 'end' => '12:00', 'activity' => 'work'], $evaUser);
        });
    }

    public function testBlockingErrorsWarningsAndConfigurableLimits(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'approvals' => $approvals, 'tech' => $tech, 'admin' => $admin, 'employee' => $employee, 'order' => $order, 'settings' => $settings, 'pdo' => $pdo,
                'reports' => $reports] = $env;
            $codes = static fn (array $items, ?string $date = null): array => array_values(array_column(array_filter($items, static fn (array $item): bool => $date === null || $item['date'] === $date), 'code'));
            $add = static fn (string $date, string $start, string $end, int $break = 0, ?string $orderId = null) => $service->createEntry(['date' => $date, 'start' => $start, 'end' => $end, 'breakMinutes' => (string) $break, 'activity' => 'work', 'orderId' => $orderId ?? $order], $tech);
            $add('2026-11-02', '06:00', '17:45', 45);          // Mo: 11 h → Höchstarbeitszeit (Pause ausreichend)
            $add('2026-11-03', '07:00', '16:45', 30);          // Di: 9:15 h → regulär überschritten, Pause 30 < 45
            $add('2026-11-04', '07:00', '14:00');              // Mi: 7 h am Stück (Lücke bis 22 Uhr zählt als Pause)
            $add('2026-11-04', '22:00', '23:00');              // Mi spät …
            $add('2026-11-05', '06:00', '08:00', 0, '');      // … Do früh: 7 h Ruhezeit, ohne Zuordnung
            $add('2026-11-08', '10:00', '12:00');              // So
            $week = $approvals->period(null, 'week', '2026-11-02', $tech);
            $this->assertSame([], $week['errors']);
            $this->assertSame(['max_daily'], $codes($week['warnings'], '2026-11-02'));
            $this->assertSame(['regular_daily', 'breaks'], $codes($week['warnings'], '2026-11-03'));
            $this->assertSame(['continuous'], $codes($week['warnings'], '2026-11-04'));
            $this->assertSame(['no_target', 'rest'], $codes($week['warnings'], '2026-11-05'));
            $this->assertSame(['empty_day'], $codes($week['warnings'], '2026-11-06'));
            $this->assertSame(['sunday'], $codes($week['warnings'], '2026-11-08'));

            // Grenzwerte sind global einstellbar; Arbeitszeitwarnungen abschaltbar.
            $settings->save(['time_limits.max_daily_hours' => '12', 'time_limits.break_minimum_2_minutes' => '30', 'time_limits.min_rest_hours' => '6'], $admin);
            $week = $approvals->period(null, 'week', '2026-11-02', $tech);
            $this->assertSame(['regular_daily'], $codes($week['warnings'], '2026-11-02'));
            $this->assertSame(['regular_daily'], $codes($week['warnings'], '2026-11-03'));
            $this->assertSame(['no_target'], $codes($week['warnings'], '2026-11-05'));
            $this->assertThrows(DocumentValidationException::class, fn () => $settings->save(['time_limits.max_daily_hours' => '25'], $admin));
            $this->assertThrows(DocumentValidationException::class, fn () => $settings->save(['time_limits.break_threshold_2_hours' => '5'], $admin), 'Zweite Stufe nicht vor der ersten.');
            $settings->save(['time_tracking.warnings_enabled' => false], $admin);
            $this->assertSame(['no_target', 'empty_day'], $codes($approvals->period(null, 'week', '2026-11-02', $tech)['warnings']));

            // Feiertage (bundesweit) und Sperrfehler: Überschneidung, fehlende Uhrzeiten, unpassende Berichtsbuchung.
            $this->assertSame(['Karfreitag', 'Ostermontag', 'Christi Himmelfahrt', 'Pfingstmontag', 'Tag der Deutschen Einheit', null],
                array_map(TimeApprovalService::holiday(...), ['2026-04-03', '2026-04-06', '2026-05-14', '2026-05-25', '2026-10-03', '2026-10-02']));
            $settings->save(['time_tracking.warnings_enabled' => true], $admin);
            $pdo->prepare("INSERT INTO time_entries (id, employee_id, work_date, source_kind, start_time, end_time, net_minutes, activity, created_by_user_id, created_at, updated_by_user_id, updated_at)
                VALUES ('overlap', :employee, '2026-11-02', 'manual', '12:00', '13:00', 60, 'work', :user, 't', :user, 't')")->execute(['employee' => $employee, 'user' => $tech['id']]);
            $report = $reports->create(['orderId' => $order, 'reportDate' => '2026-11-06', 'activities' => 'Montage', 'entries' => [['employeeId' => $employee, 'durationHours' => '2', 'targetKey' => 'order']]], $tech);
            $settings->save(['time_tracking.times_optional' => false], $admin);
            $week = $approvals->period(null, 'week', '2026-11-02', $tech);
            $this->assertSame(['overlap', 'times_missing'], $codes($week['errors']));
            $this->assertSame(false, $week['canSubmit']);
            $settings->save(['time_tracking.times_optional' => true], $admin);
            $pdo->exec("DELETE FROM time_entries WHERE id = 'overlap'");
            $pdo->prepare('UPDATE time_entries SET net_minutes = 100 WHERE daily_report_entry_id = :id')->execute(['id' => $reports->detail($report['id'], $tech)['entries'][0]['id']]);
            $this->assertSame(['booking'], $codes($approvals->period(null, 'week', '2026-11-02', $tech)['errors']), 'Inkonsistente Berichtsbuchung.');
            $this->assertSame(['untimed'], $codes($approvals->period(null, 'week', '2026-11-02', $tech)['warnings'], '2026-11-06'), 'Ohne Uhrzeit: nur Hinweis, keine Pausenprüfung.');
        });
    }

    public function testMigrationSchemaAndIntegrity(): void
    {
        $this->withEnvironment(function (array $env): void {
            $pdo = $env['pdo'];
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('time_submissions', 'time_day_locks', 'time_lock_events', 'time_statements') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame(['time_day_locks', 'time_lock_events', 'time_statements', 'time_submissions'], $tables);
            $triggers = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name LIKE '%lock%'")->fetchColumn();
            $this->assertSame(9, (int) $triggers, 'Sperr-Trigger für Buchungen, Tagesangaben und Auslagen.');
            $this->assertThrows(PDOException::class, fn () => $pdo->exec("INSERT INTO time_day_locks (employee_id, work_date, status, updated_by_user_id, updated_at) VALUES ('x', '2026-01-01', 'approved', 'y', 't')"), 'Festgeschrieben nur mit Einreichung.');
            $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        });
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-approvals-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $now = '2026-09-01T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Matzko-Mack',:now,:now)")->execute(['id' => $customer, 'now' => $now]);
            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);
            $released = $orders->createRegie(['customerId' => $customer, 'title' => 'Montage Halle', 'orderDate' => '2026-09-01']);
            $orders->changeStatus($released['id'], 'open', 'in_progress');
            $tech = $this->user($pdo, 'technician', $now);
            $otherTech = $this->user($pdo, 'technician', $now);
            $office = $this->user($pdo, 'office', $now);
            $employee = $this->employee($pdo, $tech['id'], 'Max Monteur', $now);
            $colleague = $this->employee($pdo, $otherTech['id'], 'Eva Elektrikerin', $now);
            $store = new AttachmentStore($tmp . '/documents');
            $service = new TimeTrackingService(new TimeTrackingRepository($pdo), $store);
            $settings = new OperationalSettingsService($pdo);
            $test([
                'pdo' => $pdo, 'files' => $tmp . '/documents', 'customer' => $customer, 'order' => $released['id'],
                'service' => $service, 'settings' => $settings,
                'approvals' => new TimeApprovalService(new TimeApprovalRepository($pdo), new TimeTrackingRepository($pdo), $service, $settings, new DocumentSettingsRepository($pdo), $store),
                'tech' => $tech, 'otherTech' => $otherTech, 'office' => $office, 'admin' => $this->user($pdo, 'admin', $now),
                'employee' => $employee, 'colleagueEmployee' => $colleague,
                'reports' => new DailyReportService($pdo, new DailyReportRepository($pdo), new EmployeeRepository($pdo), new OrderRepository($pdo)),
                'workReports' => new WorkReportService($pdo, new WorkReportRepository($pdo), new OrderRepository($pdo), $numbers, new PriceCalculator(), null, $orders),
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
