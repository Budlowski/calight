<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\CostCenterService;
use App\Application\CostTargets;
use App\Application\DailyReportPdfService;
use App\Application\DailyReportService;
use App\Application\MaterialRequestService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\PurchaseOrderPdfService;
use App\Application\PurchaseOrderService;
use App\Application\TimeTrackingService;
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
use App\Infrastructure\Persistence\CostCenterRepository;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\MaterialRequestRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\PurchaseOrderRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Infrastructure\Persistence\WorkReportRepository;
use App\Security\AuthorizationPolicy;
use App\Security\RolePermissions;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Allgemeine Kostenstellen und gemeinsames Kostenziel „Auftrag / Kostenstelle“. */
final class CostCenterTest extends TestCase
{
    public function testManagementTemplateAndPermissions(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['centers' => $centers, 'admin' => $admin, 'office' => $office, 'tech' => $tech, 'pdo' => $pdo] = $env;
            $this->assertTrue(!RolePermissions::allows('office', 'cost_centers.manage') && RolePermissions::allows('admin', 'cost_centers.manage'));
            $this->assertSame(['cost_centers.manage'], AuthorizationPolicy::required(new Request('POST', '/api/cost-centers', [], [], [], '')));
            $this->assertSame(['master_data.read'], AuthorizationPolicy::required(new Request('GET', '/api/cost-centers', [], [], [], '')));
            $this->assertThrows(AccessDeniedException::class, fn () => $centers->save(null, ['number' => 'K900', 'name' => 'Test'], $office), 'Büro verwaltet keine Kostenstellen.');
            $this->assertThrows(AccessDeniedException::class, fn () => $centers->overview($tech), 'Techniker sehen keine Kostenstellenauswertung.');
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM cost_centers')->fetchColumn(), 'Keine ungefragte Anlage.');

            // Optionale Vorlage, idempotent; vorhandene Nummern bleiben unverändert.
            $own = $centers->save(null, ['number' => 'K100', 'name' => 'Büro (eigene Bezeichnung)'], $admin);
            $first = $centers->importTemplate($admin);
            $this->assertSame([9, ['K100']], [count($first['created']), $first['skipped']]);
            $this->assertSame([], $centers->importTemplate($admin)['created'], 'Erneute Übernahme legt nichts doppelt an.');
            $this->assertSame(10, (int) $pdo->query('SELECT COUNT(*) FROM cost_centers')->fetchColumn());
            $this->assertSame('Büro (eigene Bezeichnung)', $pdo->query("SELECT name FROM cost_centers WHERE number = 'K100'")->fetchColumn());

            // Nummern eindeutig (ohne Groß-/Kleinschreibung), Bearbeiten mit Version, (De-)Aktivieren.
            $this->assertThrows(DocumentValidationException::class, fn () => $centers->save(null, ['number' => 'k110', 'name' => 'Doppelt'], $admin));
            $this->assertThrows(DocumentValidationException::class, fn () => $centers->save(null, ['number' => '', 'name' => 'Ohne Nummer'], $admin));
            $edited = $centers->save($own['id'], ['number' => 'K100', 'name' => 'Verwaltung & Büro', 'description' => 'Allgemein', 'version' => $own['version']], $admin);
            $this->assertSame(['Verwaltung & Büro', 2], [$edited['name'], (int) $edited['version']]);
            $this->assertThrows(DocumentValidationException::class, fn () => $centers->save($own['id'], ['number' => 'K100', 'name' => 'Veraltet', 'version' => 1], $admin));
            $inactive = $centers->save($own['id'], ['number' => 'K100', 'name' => 'Verwaltung & Büro', 'active' => false, 'version' => $edited['version']], $admin);
            $this->assertSame(0, (int) $inactive['active']);
            $active = $centers->save($own['id'], ['number' => 'K100', 'name' => 'Verwaltung & Büro', 'active' => true, 'version' => $inactive['version']], $admin);
            $this->assertSame(1, (int) $active['active'], 'Wiederaktivierung möglich.');
            $overview = $centers->overview($office, '2026');
            $this->assertSame([10, false, true], [count($overview['items']), $overview['canManage'], $overview['canCosts']]);
        });
    }

    public function testCombinedSearchTimeTrackingAndOrderHours(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['centers' => $centers, 'admin' => $admin, 'office' => $office, 'tech' => $tech, 'time' => $time, 'employee' => $employee, 'order' => $order, 'openOrder' => $openOrder,
                'customer' => $customer, 'reports' => $reports, 'workReports' => $workReports, 'pdo' => $pdo] = $env;
            $centers->importTemplate($admin);
            $k120 = (string) $pdo->query("SELECT id FROM cost_centers WHERE number = 'K120'")->fetchColumn();
            $k110 = (string) $pdo->query("SELECT id FROM cost_centers WHERE number = 'K110'")->fetchColumn();
            $k190 = (string) $pdo->query("SELECT id FROM cost_centers WHERE number = 'K190'")->fetchColumn();
            $centers->save($k190, ['number' => 'K190', 'name' => 'Gewährleistung & Kulanz', 'active' => false, 'version' => 1], $admin);

            // Gemeinsame Auswahl: Aufträge (mit Freigaberegeln) und Kostenstellen mit Typ und ID; keine Beträge.
            $targets = $time->referenceData($tech)['costTargets'];
            $byType = static fn (string $type): array => array_values(array_filter($targets, static fn (array $target): bool => $target['type'] === $type));
            $this->assertSame([$order], array_column($byType('order'), 'id'), 'Techniker: nur freigegebene Aufträge.');
            $this->assertTrue(!in_array($openOrder, array_column($targets, 'id'), true));
            $this->assertSame(10, count($byType('cost_center')));
            $this->assertSame(['type', 'id', 'number', 'label', 'active'], array_keys($targets[0]), 'Keine Kostenangaben in der Auswahl.');
            $this->assertSame(false, array_values(array_filter($targets, static fn (array $target): bool => $target['number'] === 'K190'))[0]['active']);
            $this->assertSame(2, count($time->referenceData($office)['costTargets']) - 10, 'Büro sieht auch nicht freigegebene Aufträge.');

            // Manuelle Zeitblöcke: Auftrag oder Kostenstelle – nie beides.
            $time->createEntry(['date' => '2026-11-16', 'start' => '07:00', 'end' => '08:00', 'activity' => 'preparation', 'costCenterId' => $k120], $tech);
            $time->createEntry(['date' => '2026-11-16', 'start' => '08:00', 'end' => '12:00', 'activity' => 'work', 'orderId' => $order], $tech);
            $day = $time->createEntry(['date' => '2026-11-16', 'start' => '13:00', 'end' => '15:00', 'activity' => 'internal', 'costCenterId' => $k110], $tech);
            $this->assertThrows(DocumentValidationException::class, fn () => $time->createEntry(['date' => '2026-11-16', 'start' => '15:00', 'end' => '16:00', 'activity' => 'work', 'orderId' => $order, 'costCenterId' => $k110], $tech), 'Nicht Auftrag und Kostenstelle zugleich.');
            $this->assertThrows(DocumentValidationException::class, fn () => $time->createEntry(['date' => '2026-11-16', 'start' => '15:00', 'end' => '16:00', 'activity' => 'work', 'costCenterId' => $k190], $tech), 'Deaktivierte Kostenstelle nicht für neue Buchungen.');
            $this->assertThrows(DocumentValidationException::class, fn () => $time->createEntry(['date' => '2026-11-16', 'start' => '15:00', 'end' => '16:00', 'activity' => 'work', 'costCenterId' => Uuid::v4()], $tech));
            $this->assertSame(420, $day['totals']['netMinutes'], 'Gesamtstunden unverändert: 7 h.');
            $this->assertSame(['K120', null, 'K110'], array_column($day['entries'], 'costCenterNumber'));
            $this->assertSame(240, $time->actualHoursForOrder($order, $office)['totals']['netMinutes'], 'Auftrags-Ist-Stunden ohne Kostenstellenstunden.');
            $this->assertThrows(\PDOException::class, fn () => $pdo->exec("UPDATE time_entries SET order_id = " . $pdo->quote($order) . " WHERE cost_center_id IS NOT NULL"), 'Datenbank verhindert doppelte Zuordnung.');

            // Bestehende Zuordnung zu einer inzwischen deaktivierten Kostenstelle bleibt bearbeitbar und sichtbar.
            $centers->save($k120, ['number' => 'K120', 'name' => 'Lager & Rüstzeit', 'active' => false, 'version' => 1], $admin);
            $entry = $day['entries'][0];
            $updated = $time->updateEntry($entry['id'], ['version' => $entry['version'], 'date' => '2026-11-16', 'start' => '06:30', 'end' => '08:00', 'activity' => 'preparation', 'costCenterId' => $k120], $tech);
            $this->assertSame(['K120', 90], [$updated['entries'][0]['costCenterNumber'], $updated['entries'][0]['netMinutes']]);
            $report = $time->report('2026-11-01', '2026-11-30', null, null, $office);
            $groups = array_column($report['byOrder'], 'netMinutes', 'costCenterNumber');
            $this->assertSame([90, 120], [$groups['K120'], $groups['K110']], 'Auswertung nach Auftrag / Kostenstelle.');
            $this->assertSame(450, $report['totals']['netMinutes']);
            $usage = array_column($centers->overview($office, '2026')['items'], 'minutes', 'number');
            $this->assertSame([90, 120, 0], [$usage['K120'], $usage['K110'], $usage['K100']], 'Kostenstellenübersicht: Stunden nach Buchungstag.');
            $this->assertSame(0, array_column($centers->overview($office, '2025')['items'], 'minutes', 'number')['K120'], 'Jahresabgrenzung.');

            // Rapport- und Bautagesberichtstunden unverändert: Auftrag des Berichts, keine Kostenstelle.
            $work = $workReports->create(['orderId' => $order, 'workDate' => '2026-11-17'], $tech);
            $work = $workReports->update($work['id'], ['version' => $work['version'], 'description' => 'Regie', 'labor' => [['workDate' => '2026-11-17', 'employeeId' => $employee, 'hours' => '2']], 'materials' => []], $tech);
            $reports->create(['customerId' => $customer, 'reportDate' => '2026-11-17', 'activities' => 'Termin', 'entries' => [['employeeId' => $employee, 'durationHours' => '1', 'targetKey' => 'order']]], $tech);
            $bookings = $pdo->query("SELECT source_kind, order_id, cost_center_id FROM time_entries WHERE source_kind <> 'manual' ORDER BY source_kind")->fetchAll(PDO::FETCH_NUM);
            $this->assertSame([['daily_report', null, null], ['work_report', $order, null]], $bookings, 'Bericht ohne Auftrag bleibt möglich.');
            $reportEntry = array_values(array_filter($time->day($employee, '2026-11-17', $tech)['entries'], static fn (array $item): bool => $item['sourceKind'] === 'work_report'))[0];
            $time->updateEntry($reportEntry['id'], ['version' => $reportEntry['version'], 'start' => '08:00', 'end' => '10:00', 'costCenterId' => $k110], $tech);
            $this->assertSame(null, $pdo->query('SELECT cost_center_id FROM time_entries WHERE id = ' . $pdo->quote($reportEntry['id']))->fetchColumn() ?: null, 'Keine Kostenstellenwahl für Rapportstunden.');
            $this->assertThrows(\PDOException::class, fn () => $pdo->exec("UPDATE time_entries SET cost_center_id = " . $pdo->quote($k110) . " WHERE source_kind = 'work_report'"), 'Datenbank: Rapportstunden nie auf Kostenstellen.');
        });
    }

    public function testPurchaseOrdersAllocationsAndExpenses(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['centers' => $centers, 'admin' => $admin, 'office' => $office, 'tech' => $tech, 'time' => $time, 'purchases' => $purchases, 'supplier' => $supplier, 'order' => $order, 'pdo' => $pdo] = $env;
            $centers->importTemplate($admin);
            $k130 = (string) $pdo->query("SELECT id FROM cost_centers WHERE number = 'K130'")->fetchColumn();
            $k150 = (string) $pdo->query("SELECT id FROM cost_centers WHERE number = 'K150'")->fetchColumn();
            $this->assertSame(12, count($purchases->referenceData($office)['costTargets']), 'Gemeinsames Suchfeld auch in Bestellungen (2 Aufträge + 10 Kostenstellen).');

            // Messgerät auf K130 – keine Auftragskosten.
            $meter = $purchases->createExternal(['supplierId' => $supplier, 'costCenterId' => $k130, 'orderDate' => '2026-11-10'], $office);
            $this->assertSame(['K130', null], [$meter['costCenterNumber'], $meter['orderId']]);
            $this->assertThrows(OrderValidationException::class, fn () => $purchases->createExternal(['supplierId' => $supplier, 'orderId' => $order, 'costCenterId' => $k130], $office));
            $meter = $purchases->setActualCost($meter['id'], ['version' => $meter['version'], 'amount' => '450.00'], $office);
            $this->assertSame('0.00', $purchases->costsByOrder($order, $office)['total'], 'Kostenstellenkosten nicht im Auftrag.');
            // Wechsel des Kostenziels: Auftrag statt Kostenstelle (nur eines übermittelt).
            $switched = $purchases->update($meter['id'], ['version' => $meter['version'], 'orderId' => $order], $office);
            $this->assertSame([$order, null], [$switched['orderId'], $switched['costCenterId']]);
            $back = $purchases->update($switched['id'], ['version' => $switched['version'], 'orderId' => '', 'costCenterId' => $k130], $office);
            $this->assertSame([null, $k130], [$back['orderId'], $back['costCenterId']]);

            // Gemischte Bestellung: Kostenverteilung auf Auftrag und Kostenstelle (vorhandener Mechanismus).
            $mixed = $purchases->createExternal(['supplierId' => $supplier, 'orderId' => $order, 'orderDate' => '2026-11-12'], $office);
            $mixed = $purchases->setActualCost($mixed['id'], ['version' => $mixed['version'], 'amount' => '300.00'], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $purchases->saveAllocations($mixed['id'], ['version' => $mixed['version'], 'allocations' => [['orderId' => $order, 'costCenterId' => $k150, 'amount' => '10']]], $office));
            $this->assertThrows(OrderValidationException::class, fn () => $purchases->saveAllocations($mixed['id'], ['version' => $mixed['version'], 'allocations' => [['amount' => '10']]], $office));
            $split = $purchases->saveAllocations($mixed['id'], ['version' => $mixed['version'], 'allocations' => [['orderId' => $order, 'amount' => '200'], ['costCenterId' => $k150, 'amount' => '100']]], $office);
            $this->assertSame(['K150', '0.00'], [array_values(array_filter(array_column($split['allocations'], 'costCenterNumber')))[0], $split['unallocatedTotal']]);
            $this->assertSame('200.00', $purchases->costsByOrder($order, $office)['total'], 'Auftrag erhält nur seinen Anteil – keine doppelte Kostenaddition.');
            $this->assertThrows(OrderValidationException::class, fn () => $purchases->saveAllocations($split['id'], ['version' => $split['version'], 'allocations' => [['costCenterId' => $k150, 'amount' => '1'], ['costCenterId' => $k150, 'amount' => '2']]], $office));
            // Deaktivierte Kostenstelle: bestehende Verteilung bleibt speicherbar, neue nicht.
            $centers->save($k150, ['number' => 'K150', 'name' => 'IT & Software', 'active' => false, 'version' => 1], $admin);
            $kept = $purchases->saveAllocations($split['id'], ['version' => $split['version'], 'allocations' => [['orderId' => $order, 'amount' => '150'], ['costCenterId' => $k150, 'amount' => '150']]], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $purchases->createExternal(['supplierId' => $supplier, 'costCenterId' => $k150], $office));

            // Auslagen der Zeiterfassung auf eine Kostenstelle.
            $expense = $time->addExpense(['date' => '2026-11-16', 'amount' => '19.90', 'purpose' => 'Werkzeug', 'costCenterId' => $k130], null, $tech);
            $this->assertSame('K130', $expense['expenses'][0]['costCenterNumber']);
            $this->assertThrows(DocumentValidationException::class, fn () => $time->addExpense(['date' => '2026-11-16', 'amount' => '5', 'purpose' => 'x', 'orderId' => $order, 'costCenterId' => $k130], null, $tech));

            // Übersicht: Beträge nur mit costs.read und nur aus vorhandenen Buchungen.
            $items = array_column($centers->overview($office, '2026')['items'], null, 'number');
            $this->assertSame(['450.00', '19.90', '150.00', '0.00'], [$items['K130']['purchaseTotal'], $items['K130']['expenseTotal'], $items['K150']['purchaseTotal'], $items['K100']['purchaseTotal']]);
            $this->assertSame('300.00', $kept['actualNetTotal']);
            // Verwendete Kostenstellen werden nie gelöscht.
            $this->assertThrows(DocumentValidationException::class, fn () => $centers->delete($k130, 1, $admin));
            $unused = (string) $pdo->query("SELECT id FROM cost_centers WHERE number = 'K180'")->fetchColumn();
            $centers->delete($unused, 1, $admin);
            $this->assertSame(9, (int) $pdo->query('SELECT COUNT(*) FROM cost_centers')->fetchColumn());
        });
    }

    public function testDailyReportsUseOrderCostCenterOrNoTarget(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['centers' => $centers, 'admin' => $admin, 'office' => $office, 'tech' => $tech, 'time' => $time, 'reports' => $reports, 'workReports' => $workReports,
                'employee' => $employee, 'order' => $order, 'customer' => $customer, 'pdo' => $pdo, 'materials' => $materials, 'pdf' => $pdf] = $env;
            $centers->importTemplate($admin);
            $k110 = (string) $pdo->query("SELECT id FROM cost_centers WHERE number = 'K110'")->fetchColumn();
            $k120 = (string) $pdo->query("SELECT id FROM cost_centers WHERE number = 'K120'")->fetchColumn();
            $entry = static fn (string $hours): array => [['employeeId' => $employee, 'durationHours' => $hours, 'targetKey' => 'order']];
            $base = static fn (string $date, string $hours): array => ['reportDate' => $date, 'activities' => 'Besichtigung', 'entries' => $entry($hours)];
            $booking = static fn (string $reportId): array => $pdo->query('SELECT b.order_id, b.cost_center_id, b.net_minutes FROM time_entries b JOIN daily_report_time_entries e ON e.id = b.daily_report_entry_id WHERE e.report_id = '
                . $pdo->quote($reportId))->fetchAll(PDO::FETCH_NUM);
            $this->assertTrue(in_array('K110', array_column($reports->referenceData($tech)['costTargets'], 'number'), true), 'Gemeinsames Suchfeld im Bautagesbericht.');

            // Drei Zustände: Auftrag, Kostenstelle beim Kunden, ohne Zuordnung.
            $onOrder = $reports->create(['orderId' => $order] + $base('2026-11-16', '2'), $tech);
            $visit = $reports->create(['customerId' => $customer, 'costCenterId' => $k110] + $base('2026-11-16', '3'), $tech);
            $none = $reports->create(['customerId' => $customer] + $base('2026-11-17', '1'), $tech);
            $this->assertSame([$customer, $k110, null], [$visit['customer_id'], $visit['cost_center_id'], $visit['order_id']], 'Kunde bleibt, Kostenziel ist die Kostenstelle.');
            $this->assertSame([[$order, null, 120]], $booking($onOrder['id']));
            $this->assertSame([[null, $k110, 180]], $booking($visit['id']));
            $this->assertSame([[null, null, 60]], $booking($none['id']));
            $this->assertThrows(DocumentValidationException::class, fn () => $reports->create(['orderId' => $order, 'costCenterId' => $k110] + $base('2026-11-18', '1'), $tech), 'Nie Auftrag und Kostenstelle zugleich.');
            $centers->save($k120, ['number' => 'K120', 'name' => 'Lager & Rüstzeit', 'active' => false, 'version' => 1], $admin);
            $this->assertThrows(DocumentValidationException::class, fn () => $reports->create(['customerId' => $customer, 'costCenterId' => $k120] + $base('2026-11-18', '1'), $tech), 'Deaktivierte Kostenstelle nicht für neue Berichte.');
            $this->assertSame(['K110', 'Vertrieb & Angebote'], [$reports->detail($visit['id'], $tech)['cost_center_number'], $reports->detail($visit['id'], $tech)['cost_center_name']]);
            $this->assertSame(1, $reports->page(['assignment' => 'with_cost_center'], $office)['total']);
            $this->assertSame(1, $reports->page(['assignment' => 'unassigned'], $office)['total']);
            $this->assertSame(1, $reports->page(['q' => 'Vertrieb'], $office)['total'], 'Suche nach Kostenstelle.');

            // Auswertungen: Auftrag nur mit Auftrag, Kostenstelle nur mit Kostenstelle, Gesamtstunden unverändert.
            $this->assertSame(120, $time->actualHoursForOrder($order, $office)['totals']['netMinutes']);
            $this->assertSame(180, array_column($centers->overview($office, '2026')['items'], 'minutes', 'number')['K110']);
            $this->assertSame(300, $time->day($employee, '2026-11-16', $tech)['totals']['netMinutes']);

            // Nachträgliche Wechsel durch das Büro zwischen allen Zuständen – keine neuen Buchungen.
            $this->assertThrows(AccessDeniedException::class, fn () => $reports->assignOrder($none['id'], ['version' => $none['version'], 'costCenterId' => $k110], $tech));
            $count = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM time_entries WHERE source_kind = 'daily_report'")->fetchColumn();
            $before = $count();
            $step = $reports->assignOrder($none['id'], ['version' => $none['version'], 'costCenterId' => $k110], $office);                // ohne → Kostenstelle
            $this->assertSame([[null, $k110, 60]], $booking($none['id']));
            $step = $reports->assignOrder($none['id'], ['version' => $step['version'], 'orderId' => $order], $office);                    // Kostenstelle → Auftrag
            $this->assertSame([[$order, null, 60]], $booking($none['id']));
            $this->assertSame(180, $time->actualHoursForOrder($order, $office)['totals']['netMinutes'], 'Auftrags-Ist-Stunden nach dem Wechsel neu ausgewertet.');
            $step = $reports->assignOrder($none['id'], ['version' => $step['version'], 'costCenterId' => $k110], $office);                // Auftrag → Kostenstelle
            $step = $reports->assignOrder($none['id'], ['version' => $step['version']], $office);                                        // Kostenstelle → ohne
            $this->assertSame([[null, null, 60]], $booking($none['id']));
            $step = $reports->assignOrder($none['id'], ['version' => $step['version'], 'orderId' => $order], $office);                    // ohne → Auftrag
            $step = $reports->assignOrder($none['id'], ['version' => $step['version']], $office);                                        // Auftrag → ohne
            $this->assertSame($before, $count(), 'Keine zusätzlichen Zeitbuchungen.');
            $this->assertSame('1.000', $reports->detail($none['id'], $office)['entries'][0]['duration_hours'], 'Stunden unverändert.');
            $this->assertThrows(DocumentValidationException::class, fn () => $reports->assignOrder($none['id'], ['version' => $step['version'], 'orderId' => $order, 'costCenterId' => $k110], $office));
            $events = array_map(static fn (array $event): array => [$event['previous_order_id'], $event['previous_cost_center_id'], $event['order_id'], $event['cost_center_id']], $step['orderEvents']);
            $this->assertSame([[null, null, null, $k110], [null, $k110, $order, null], [$order, null, null, $k110], [null, $k110, null, null], [null, null, $order, null], [$order, null, null, null]], $events,
                'Eine nachvollziehbare Historie für Auftrag und Kostenstelle.');
            // Historische Zuordnung zu einer inzwischen deaktivierten Kostenstelle bleibt bestehen.
            $centers->save($k110, ['number' => 'K110', 'name' => 'Vertrieb & Angebote', 'active' => false, 'version' => 1], $admin);
            $this->assertSame([[null, $k110, 180]], $booking($visit['id']));
            $this->assertSame('K110', $reports->detail($visit['id'], $office)['cost_center_number']);

            // Materialanforderung aus einem Kostenstellen-Bericht: Kunde, kein künstlicher Auftrag.
            $ordersBefore = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            $request = $materials->create(['dailyReportId' => $visit['id'], 'description' => 'Muster Schalter'], $tech);
            $this->assertSame([null, $customer], [$request['orderId'], $request['customerId']]);
            $this->assertSame($ordersBefore, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());

            // PDF: Kostenstelle statt künstlicher Auftragsnummer.
            $document = $pdf->pdf($visit['id'], $office)['pdf'];
            $this->assertTrue(str_contains($document, '(Kostenstelle) Tj') && !str_contains($document, '(Auftrag) Tj'));

            // Rapporte bleiben ausschließlich auftragsbezogen.
            $draft = $workReports->create(['customerId' => $customer, 'workDate' => '2026-11-18'], $tech);
            $draft = $workReports->update($draft['id'], ['version' => $draft['version'], 'description' => 'Regie', 'labor' => [['workDate' => '2026-11-18', 'employeeId' => $employee, 'hours' => '1']], 'materials' => []], $tech);
            $this->assertThrows(OrderValidationException::class, fn () => $workReports->submit($draft['id'], ['version' => $draft['version'], 'costCenterId' => $k110], $tech), 'Einreichen nur mit Auftrag.');
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM time_entries WHERE source_kind = 'work_report' AND cost_center_id IS NOT NULL")->fetchColumn());
            $this->assertThrows(\PDOException::class, fn () => $pdo->exec("UPDATE time_entries SET cost_center_id = " . $pdo->quote($k110) . " WHERE source_kind = 'work_report'"), 'Datenbank: Rapportstunden nie auf Kostenstellen.');
            $this->assertThrows(\PDOException::class, fn () => $pdo->exec("UPDATE daily_reports SET cost_center_id = " . $pdo->quote($k110) . " WHERE id = " . $pdo->quote($onOrder['id'])), 'Datenbank: Bericht nie mit beiden Zielen.');
            $this->assertThrows(DocumentValidationException::class, fn () => $centers->delete($k110, 2, $admin), 'Vom Bericht verwendete Kostenstelle nicht löschbar.');
        });
    }

    public function testMigrationKeepsExistingAssignments(): void
    {
        $tmp = sys_get_temp_dir() . '/calight-cost-centers-migration-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/migrations', 0770, true);
        try {
            foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
                if (strcmp(basename($file), '0039') < 0) {
                    copy($file, $tmp . '/migrations/' . basename($file));
                }
            }
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, $tmp . '/migrations'))->migrate();
            $now = '2026-11-12T08:00:00Z';
            $user = $this->user($pdo, 'office', $now);
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Kunde',:now,:now)")->execute(['id' => $customer, 'now' => $now]);
            $order = Uuid::v4();
            $pdo->prepare("INSERT INTO orders(id,order_number,customer_id,order_type,title,order_date,status,created_at,updated_at) VALUES(:id,'A1',:customer,'regie','A','2026-01-01','in_progress',:now,:now)")->execute(['id' => $order, 'customer' => $customer, 'now' => $now]);
            $supplier = Uuid::v4();
            $pdo->prepare("INSERT INTO suppliers(id,supplier_number,name,active,created_at,updated_at) VALUES(:id,'7','Lieferant',1,:now,:now)")->execute(['id' => $supplier, 'now' => $now]);
            $purchase = Uuid::v4();
            $pdo->prepare("INSERT INTO purchase_orders(id,order_number,origin,status,supplier_id,order_id,actual_net_total,created_by_user_id,created_at,updated_at) VALUES(:id,'BE1','external','ordered',:supplier,:order,'100.00',:user,:now,:now)")
                ->execute(['id' => $purchase, 'supplier' => $supplier, 'order' => $order, 'user' => $user['id'], 'now' => $now]);
            $pdo->prepare("INSERT INTO purchase_order_allocations(id,purchase_order_id,order_id,amount,created_at) VALUES('a1',:purchase,:order,'60.00',:now)")->execute(['purchase' => $purchase, 'order' => $order, 'now' => $now]);
            $employee = Uuid::v4();
            $pdo->prepare("INSERT INTO employees(id,display_name,hourly_cost_rate,active,created_at,updated_at) VALUES(:id,'Max','40.0000',1,:now,:now)")->execute(['id' => $employee, 'now' => $now]);
            $pdo->prepare("INSERT INTO time_entries(id,employee_id,work_date,start_time,end_time,net_minutes,activity,order_id,created_by_user_id,created_at,updated_by_user_id,updated_at)
                VALUES('t1',:employee,'2026-01-05','08:00','12:00',240,'work',:order,:user,:now,:user,:now)")->execute(['employee' => $employee, 'order' => $order, 'user' => $user['id'], 'now' => $now]);

            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $this->assertSame([['a1', $order, null, '60.00']], $pdo->query('SELECT id, order_id, cost_center_id, amount FROM purchase_order_allocations')->fetchAll(PDO::FETCH_NUM), 'Kostenverteilung erhalten.');
            $this->assertSame([$order, null], $pdo->query("SELECT order_id, cost_center_id FROM purchase_orders WHERE id = " . $pdo->quote($purchase))->fetch(PDO::FETCH_NUM));
            $this->assertSame([$order, null], $pdo->query("SELECT order_id, cost_center_id FROM time_entries WHERE id = 't1'")->fetch(PDO::FETCH_NUM));
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM cost_centers')->fetchColumn(), 'Keine automatische Anlage.');
            $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
            $this->assertThrows(\PDOException::class, fn () => $pdo->exec("INSERT INTO purchase_order_allocations(id,purchase_order_id,amount,created_at) VALUES('a2'," . $pdo->quote($purchase) . ",'1','t')"), 'Je Verteilzeile genau ein Ziel.');
            // 0040: Bautagesberichte und Zeitbuchungen unverändert, neue Spalten leer, Protokoll erweitert.
            $this->assertSame([], array_values(array_diff(['cost_center_id'], array_column($pdo->query('PRAGMA table_info(daily_reports)')->fetchAll(), 'name'))));
            $this->assertSame([], array_values(array_diff(['previous_cost_center_id', 'cost_center_id'], array_column($pdo->query('PRAGMA table_info(daily_report_order_events)')->fetchAll(), 'name'))));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM time_entries')->fetchColumn());
            $this->assertSame(['idx_time_entries_cost_center', 'idx_time_entries_employee_date', 'idx_time_entries_order'], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'time_entries' AND name LIKE 'idx_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            $this->remove($tmp);
        }
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-cost-centers-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $now = '2026-11-12T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Matzko-Mack',:now,:now)")->execute(['id' => $customer, 'now' => $now]);
            $supplier = Uuid::v4();
            $pdo->prepare("INSERT INTO suppliers(id,supplier_number,name,active,created_at,updated_at) VALUES(:id,'70001','Großhandel',1,:now,:now)")->execute(['id' => $supplier, 'now' => $now]);
            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);
            $released = $orders->createRegie(['customerId' => $customer, 'title' => 'Gebäudeautomation Villa', 'orderDate' => '2026-11-12']);
            $orders->changeStatus($released['id'], 'open', 'in_progress');
            $open = $orders->createRegie(['customerId' => $customer, 'title' => 'Noch offen', 'orderDate' => '2026-11-12']);
            $tech = $this->user($pdo, 'technician', $now);
            $employee = Uuid::v4();
            $pdo->prepare("INSERT INTO employees(id,user_id,display_name,hourly_cost_rate,active,created_at,updated_at) VALUES(:id,:user,'Max Monteur','42.0000',1,:now,:now)")
                ->execute(['id' => $employee, 'user' => $tech['id'], 'now' => $now]);
            $test([
                'pdo' => $pdo, 'customer' => $customer, 'supplier' => $supplier, 'order' => $released['id'], 'openOrder' => $open['id'], 'employee' => $employee,
                'admin' => $this->user($pdo, 'admin', $now), 'office' => $this->user($pdo, 'office', $now), 'tech' => $tech,
                'centers' => new CostCenterService($pdo, new CostCenterRepository($pdo), dirname(__DIR__, 2) . '/config/cost-center-template.json'),
                'time' => new TimeTrackingService(new TimeTrackingRepository($pdo), new AttachmentStore($tmp . '/documents'), new CostTargets($pdo)),
                'purchases' => new PurchaseOrderService($pdo, new PurchaseOrderRepository($pdo), $numbers, new AttachmentStore($tmp . '/documents'), new PurchaseOrderPdfService(new DocumentSettingsRepository($pdo))),
                'reports' => new DailyReportService($pdo, new DailyReportRepository($pdo), new EmployeeRepository($pdo), new OrderRepository($pdo)),
                'workReports' => new WorkReportService($pdo, new WorkReportRepository($pdo), new OrderRepository($pdo), $numbers, new PriceCalculator(), null, $orders),
                'materials' => new MaterialRequestService(new MaterialRequestRepository($pdo), new OrderRepository($pdo), new DailyReportRepository($pdo)),
                'pdf' => new DailyReportPdfService(new DailyReportService($pdo, new DailyReportRepository($pdo), new EmployeeRepository($pdo), new OrderRepository($pdo)), new DocumentSettingsRepository($pdo)),
            ]);
        } finally {
            $this->remove($tmp);
        }
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
