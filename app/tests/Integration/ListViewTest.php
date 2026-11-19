<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\CustomerProjectService;
use App\Application\DailyReportService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\CustomerProjectRepository;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Support\ListQuery;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Serverseitige Pagination, Suche, Filter und Sortierung der fünf Übersichten (Phase 3.2.1.3). */
final class ListViewTest extends TestCase
{
    public function testListQueryFallsBackToDefaultsForInvalidParameters(): void
    {
        $sorts = ['updated' => 'desc', 'name' => 'asc'];
        $query = ListQuery::fromArray(
            ['sort' => 'DROP TABLE', 'dir' => 'sideways', 'page' => '-3', 'per_page' => '7', 'q' => ['array'], 'status' => '  draft ', 'unknown' => 'x'],
            $sorts,
            'updated',
            ['status'],
        );
        $this->assertSame('updated', $query->sort);
        $this->assertSame('desc', $query->direction);
        $this->assertSame(1, $query->page);
        $this->assertSame(25, $query->perPage);
        $this->assertSame('', $query->search);
        $this->assertSame(['status' => 'draft'], $query->filters);

        $named = ListQuery::fromArray(['sort' => 'name', 'page' => '4', 'per_page' => '50', 'q' => '  KNX   Müller '], $sorts, 'updated');
        $this->assertSame('asc', $named->direction, 'Ohne Richtung gilt die natürliche Richtung des Feldes.');
        $this->assertSame(4, $named->page);
        $this->assertSame(50, $named->perPage);
        $this->assertSame(['KNX', 'Müller'], $named->searchTerms());
    }

    public function testCustomerPagesSearchFiltersAndStableSorting(): void
    {
        $this->withDatabase(function (PDO $pdo): void {
            $service = new CustomerProjectService(new CustomerProjectRepository($pdo), new NumberRangeRepository($pdo));
            // 30 gleichzeitig geänderte Kunden: die Reihenfolge muss trotzdem stabil sein.
            for ($index = 1; $index <= 30; $index++) {
                $this->customer($pdo, sprintf('K%03d', $index), $index === 7 ? 'Elektro Müller GmbH' : 'Firma ' . $index, '2026-11-01T10:00:00Z', $index !== 30);
            }
            $this->customer($pdo, 'P001', '', '2026-11-02T10:00:00Z', true, 'private');

            $first = $service->customerPage([]);
            $this->assertSame(30, $first['total'], 'Archivierte Kunden sind standardmäßig ausgeblendet.');
            $this->assertSame(2, $first['pageCount']);
            $this->assertSame(25, count($first['items']));
            $this->assertSame('P001', $first['items'][0]['customerNumber'], 'Standard: zuletzt geändert zuerst.');

            $last = $service->customerPage(['page' => '2']);
            $this->assertSame(5, count($last['items']));
            $allIds = array_merge(array_column($first['items'], 'id'), array_column($last['items'], 'id'));
            $this->assertSame(30, count(array_unique($allIds)), 'Seiten überschneiden sich nicht und lassen nichts aus.');
            $this->assertSame($first['items'], $service->customerPage([])['items'], 'Gleiche Werte werden deterministisch sortiert.');

            $beyond = $service->customerPage(['page' => '99', 'per_page' => '10']);
            $this->assertSame(3, $beyond['page'], 'Seiten hinter dem Ende werden auf die letzte Seite begrenzt.');
            $this->assertSame(10, $beyond['perPage']);

            $byNumber = $service->customerPage(['sort' => 'number', 'dir' => 'desc', 'per_page' => '10']);
            $this->assertSame('P001', $byNumber['items'][0]['customerNumber']);
            $this->assertSame('K029', $byNumber['items'][1]['customerNumber']);
            $ascending = $service->customerPage(['sort' => 'number', 'dir' => 'asc', 'per_page' => '10']);
            $this->assertSame('K001', $ascending['items'][0]['customerNumber']);

            $search = $service->customerPage(['q' => 'MÜLLER elektro']);
            $this->assertSame(1, $search['total'], 'Suche ignoriert Groß-/Kleinschreibung auch bei Umlauten.');
            $this->assertSame('Elektro Müller GmbH', $search['items'][0]['displayName']);

            $this->assertSame(0, $service->customerPage(['q' => '100%_'])['total'], 'LIKE-Platzhalter in der Suche werden maskiert.');
            $this->assertSame([], $service->customerPage(['q' => 'gibt es nicht'])['items']);
            $this->assertSame(1, $service->customerPage(['type' => 'private'])['total']);
            $this->assertSame(31, $service->customerPage(['archived' => '1'])['total']);
        });
    }

    public function testOffersListOnlyLatestRevisionWithCachedTitlesAndTotals(): void
    {
        $this->withDatabase(function (PDO $pdo, string $tmp): void {
            [$offerService] = $this->services($pdo, $tmp);
            $customer = $this->customer($pdo, 'K1', 'Kunde', '2026-11-01T10:00:00Z');

            $original = $offerService->createDraft(null, 'KNX Steuerung', $customer);
            $etag = $this->setPrice($offerService, $original, '250.00');
            $offerService->submitOffer($original, $etag, null, '2026-11-09', true);
            $revision = $offerService->createRevision($original)['id'];
            $cheap = $offerService->createDraft(null, 'Kleinauftrag', $customer);
            $this->setPrice($offerService, $cheap, '20.00');

            $page = $offerService->page([]);
            $this->assertSame(2, $page['total'], 'Je Revisionskette erscheint nur die neueste Revision.');
            $ids = array_column($page['items'], 'id');
            $this->assertTrue(in_array($revision, $ids, true) && !in_array($original, $ids, true));

            $byNet = $offerService->page(['sort' => 'net', 'dir' => 'desc']);
            $this->assertSame('250.00', $byNet['items'][0]['netTotal'], 'Die Revision übernimmt Inhalt und Summe des Vorgängers.');
            $this->assertSame('20.00', $byNet['items'][1]['netTotal']);

            $byNumber = $offerService->page(['sort' => 'number', 'dir' => 'desc']);
            $this->assertSame($cheap, $byNumber['items'][1]['id'], 'Entwürfe ohne Nummer stehen in beiden Richtungen am Ende.');
            $this->assertTrue($byNumber['items'][0]['offerNumber'] !== null);

            // Der Listen-Cache folgt Änderungen am Entwurf ohne gesonderten Schreibpfad.
            $this->setPrice($offerService, $cheap, '999.00', 'Umbenannt');
            $updated = $offerService->page(['q' => 'umbenannt']);
            $this->assertSame(1, $updated['total']);
            $this->assertSame('999.00', $updated['items'][0]['netTotal']);

            $this->assertSame(1, $offerService->page(['status' => 'draft', 'q' => 'knx'])['total'], 'Filter und Suche werden kombiniert.');
            $this->assertSame(0, $offerService->page(['type' => 'change_order'])['total']);
            $this->assertSame(2, count($offerService->overview()));
        });
    }

    public function testOrdersHideOpenOrdersForTechniciansAndSortByContractTotal(): void
    {
        $this->withDatabase(function (PDO $pdo, string $tmp): void {
            [$offerService, $orderService] = $this->services($pdo, $tmp);
            $customer = $this->customer($pdo, 'K1', 'Kunde', '2026-11-01T10:00:00Z');

            $offer = $offerService->createDraft(null, 'Angebot', $customer);
            $etag = $this->setPrice($offerService, $offer, '300.00');
            $offerService->submitOffer($offer, $etag, null, '2026-11-09', true);
            $offerService->changeStatus($offer, 'submitted', 'commissioned');
            $offerOrder = $orderService->createFromOffer($offer);
            $regie = $orderService->createRegie(['customerId' => $customer, 'title' => 'Störung', 'orderDate' => '2026-11-10']);
            $orderService->changeStatus($offerOrder['id'], 'open', 'in_progress');

            $all = $orderService->page(['sort' => 'total', 'dir' => 'asc'], false);
            $this->assertSame(2, $all['total']);
            $this->assertSame('300.00', $all['items'][0]['contractNetTotal']);
            $this->assertSame(null, $all['items'][1]['contractNetTotal'], 'Regieaufträge ohne Summe stehen am Ende.');

            $technician = $orderService->page([], true);
            $this->assertSame(1, $technician['total'], 'Techniker sehen keine offenen Aufträge.');
            $this->assertSame($offerOrder['id'], $technician['items'][0]['id']);
            $this->assertSame(1, $orderService->page(['type' => 'regie'], false)['total']);
            $this->assertSame($regie['id'], $orderService->page(['q' => 'störung'], false)['items'][0]['id']);
        });
    }

    public function testDailyReportsFilterByEmployeeAndProtectCosts(): void
    {
        $this->withDatabase(function (PDO $pdo, string $tmp): void {
            [, $orderService] = $this->services($pdo, $tmp);
            $customer = $this->customer($pdo, 'K1', 'Kunde', '2026-11-01T10:00:00Z');
            $released = $orderService->createRegie(['customerId' => $customer, 'title' => 'Freigegeben', 'orderDate' => '2026-11-10']);
            $orderService->changeStatus($released['id'], 'open', 'in_progress');
            $open = $orderService->createRegie(['customerId' => $customer, 'title' => 'Offen', 'orderDate' => '2026-11-10']);

            $now = '2026-11-10T08:00:00Z';
            $user = Uuid::v4();
            $pdo->prepare("INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,'tech','hash','Techniker','technician',1,:now,:now)")
                ->execute(['id' => $user, 'now' => $now]);
            $anna = $this->employee($pdo, 'Anna', $now);
            $ben = $this->employee($pdo, 'Ben', $now);
            // Zwei Berichte am selben Tag zum selben Auftrag sind zulässig.
            $first = $this->report($pdo, $released['id'], '2026-11-10', $user, '2026-11-10T09:00:00Z', [[$anna, '2.500', '100.00']]);
            $second = $this->report($pdo, $released['id'], '2026-11-10', $user, '2026-11-10T12:00:00Z', [[$ben, '1.000', '40.00'], [$anna, '0.250', '10.00']]);
            $this->report($pdo, $open['id'], '2026-11-11', $user, '2026-11-11T09:00:00Z', [[$ben, '8.000', '320.00']]);

            $service = new DailyReportService($pdo, new DailyReportRepository($pdo), new EmployeeRepository($pdo), new OrderRepository($pdo));
            $office = ['id' => Uuid::v4(), 'role' => 'office'];
            $technician = ['id' => $user, 'role' => 'technician'];

            $officePage = $service->page([], $office);
            $this->assertSame(3, $officePage['total']);
            $this->assertSame('2026-11-11', $officePage['items'][0]['report_date'], 'Standard: Berichtsdatum absteigend.');
            $this->assertSame($second, $officePage['items'][1]['id'], 'Gleicher Tag: später angelegter Bericht zuerst.');
            $this->assertSame('1.250', $officePage['items'][1]['total_hours']);
            $this->assertSame('50.00', $officePage['items'][1]['total_labor_cost']);

            $technicianPage = $service->page([], $technician);
            $this->assertSame(2, $technicianPage['total'], 'Berichte offener Aufträge bleiben für Techniker verborgen.');
            $this->assertTrue(!isset($technicianPage['items'][0]['total_labor_cost']), 'Ohne costs.read keine Kosten.');

            $this->assertSame(2, $service->page(['employee' => $anna], $office)['total']);
            $this->assertSame(1, $service->page(['employee' => $ben, 'order' => $released['id']], $office)['total']);
            $this->assertSame($first, $service->page(['sort' => 'hours', 'dir' => 'desc', 'order' => $released['id']], $office)['items'][0]['id']);
            $this->assertSame(0, $service->page(['date' => 'kein-datum', 'q' => 'nichts'], $office)['total']);
            $this->assertSame(3, $service->page(['date' => 'kein-datum'], $office)['total'], 'Ungültige Datumsfilter werden ignoriert.');
        });
    }

    public function testProjectsSearchCustomerNameAndFilterStatus(): void
    {
        $this->withDatabase(function (PDO $pdo): void {
            $service = new CustomerProjectService(new CustomerProjectRepository($pdo), new NumberRangeRepository($pdo));
            $customer = $this->customer($pdo, 'K1', 'Bauherr Schmidt', '2026-11-01T10:00:00Z');
            $active = $service->createProject(['name' => 'Neubau', 'customerId' => $customer, 'status' => 'active', 'autoProjectNumber' => true]);
            $service->createProject(['name' => 'Altbau', 'status' => 'draft', 'autoProjectNumber' => true]);

            $this->assertSame(2, $service->projectPage([])['total']);
            $this->assertSame($active['id'], $service->projectPage(['q' => 'schmidt'])['items'][0]['id']);
            $this->assertSame(1, $service->projectPage(['status' => 'draft'])['total']);
            $byCustomer = $service->projectPage(['sort' => 'customer', 'dir' => 'desc']);
            $this->assertSame('Bauherr Schmidt', $byCustomer['items'][0]['customerName'], 'Projekte ohne Kunde stehen am Ende.');
        });
    }

    /** @param callable(PDO,string):void $test */
    private function withDatabase(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-lists-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $test($pdo, $tmp);
        } finally {
            $this->remove($tmp);
        }
    }

    /** @return array{0:OfferService,1:OrderService} */
    private function services(PDO $pdo, string $tmp): array
    {
        $offers = new OfferRepository($pdo);
        $validator = new OfferValidator();
        $calculator = new OfferCalculator(new PriceCalculator());
        $numbers = new NumberRangeRepository($pdo);
        $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
            new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
        return [$offerService, new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers)];
    }

    private function setPrice(OfferService $service, string $offerId, string $price, ?string $title = null): string
    {
        $loaded = $service->loadDraft($offerId);
        $document = $loaded['offer'];
        if ($title !== null) {
            $document['title'] = $title;
        }
        $document['nodes'] = [[
            'id' => Uuid::v4(), 'type' => 'position', 'positionType' => 'normal', 'shortText' => 'Leistung', 'longText' => '',
            'quantity' => '1.000', 'unit' => 'Stk.', 'manualUnitPrice' => $price, 'components' => [],
        ]];
        return $service->saveDraft($offerId, $document, $loaded['etag']);
    }

    private function customer(PDO $pdo, string $number, string $company, string $updatedAt, bool $active = true, string $type = 'company'): string
    {
        $id = Uuid::v4();
        $pdo->prepare('INSERT INTO customers(id,customer_number,customer_type,company_name,first_name,last_name,active,created_at,updated_at)
            VALUES(:id,:number,:type,:company,:first,:last,:active,:at,:at)')
            ->execute(['id' => $id, 'number' => $number, 'type' => $type, 'company' => $company, 'first' => $type === 'private' ? 'Paula' : '',
                'last' => $type === 'private' ? 'Privat' : '', 'active' => $active ? 1 : 0, 'at' => $updatedAt]);
        return $id;
    }

    private function employee(PDO $pdo, string $name, string $now): string
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO employees(id,display_name,hourly_cost_rate,active,created_at,updated_at) VALUES(:id,:name,'40.0000',1,:now,:now)")
            ->execute(['id' => $id, 'name' => $name, 'now' => $now]);
        return $id;
    }

    /** @param list<array{0:string,1:string,2:string}> $entries Mitarbeiter, Stunden, Kosten */
    private function report(PDO $pdo, string $orderId, string $date, string $userId, string $createdAt, array $entries): string
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO daily_reports(id,customer_id,order_id,labor_counts_for_order,report_date,title,activities,notes,created_by_user_id,version,created_at,updated_at)
            VALUES(:id,(SELECT customer_id FROM orders WHERE id=:order),:order,1,:date,'','Montage','',:user,1,:at,:at)")
            ->execute(['id' => $id, 'order' => $orderId, 'date' => $date, 'user' => $userId, 'at' => $createdAt]);
        $entry = $pdo->prepare("INSERT INTO daily_report_time_entries(id,report_id,employee_id,work_date,duration_hours,description,target_kind,
            target_basis_type,target_basis_id,target_node_id,hourly_cost_rate_snapshot,labor_cost,created_at,updated_at)
            VALUES(:id,:report,:employee,:date,:hours,'','order',NULL,NULL,NULL,'40.0000',:cost,:at,:at)");
        foreach ($entries as [$employee, $hours, $cost]) {
            $entry->execute(['id' => Uuid::v4(), 'report' => $id, 'employee' => $employee, 'date' => $date, 'hours' => $hours, 'cost' => $cost, 'at' => $createdAt]);
        }
        return $id;
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
