<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DocumentArchive;
use App\Application\MaterialRequestService;
use App\Application\MeasurementPdfService;
use App\Application\MeasurementService;
use App\Application\MobileWorkspace;
use App\Application\ModuleService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\GeneratedDocumentRepository;
use App\Infrastructure\Persistence\MaterialRequestRepository;
use App\Infrastructure\Persistence\MeasurementRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Security\AuthorizationPolicy;
use App\Security\ConfidentialDataFilter;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Monteurs-Frontend: mobile Aufmaße und Materialbedarf, preisfreier Aufmaßnachweis. */
final class MobileCaptureTest extends TestCase
{
    public function testRoutesPermissionsAndModules(): void
    {
        $required = static fn (string $method, string $path): array => AuthorizationPolicy::required(new Request($method, $path, [], [], [], ''));
        $this->assertSame(['measurements.read'], $required('GET', '/mobile/measurements'));
        $this->assertSame(['measurements.read'], $required('GET', '/mobile/measurements/x'));
        $this->assertSame(['reports.read'], $required('GET', '/mobile/material-requests'));
        $this->assertSame(['measurements.read'], $required('GET', '/api/measurements/x/proof-pdf'), 'Aufmaßnachweis ohne Preise für alle mit Leserecht.');
        $this->assertSame(['measurements.read', 'commercial.read'], $required('GET', '/api/measurements/x/pdf'), 'Bewertetes Aufmaß nur kaufmännisch.');
        $this->assertSame('procurement', ModuleService::moduleForPath('/mobile/material-requests'));
        $this->assertSame(null, ModuleService::moduleForPath('/mobile/measurements'), 'Aufmaße gehören zum Basissystem.');
    }

    public function testMeasurementListAndOrdersFollowVisibilityWithoutAmounts(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['workspace' => $workspace, 'measurements' => $measurements, 'tech' => $tech, 'other' => $other, 'office' => $office, 'order' => $order, 'hidden' => $hidden, 'key' => $key] = $env;
            $this->assertSame([$order], array_column($workspace->selectableOrders($tech), 'id'), 'Auftragsauswahl nur sichtbare Aufträge.');
            $this->assertSame(2, count($workspace->selectableOrders($office)));
            $draft = $measurements->create(['orderId' => $order], $tech);
            $saved = $measurements->update($draft['id'], ['version' => $draft['version'], 'title' => 'Teilaufmaß', 'measurementDate' => '2026-11-20', 'notes' => '',
                'items' => [['key' => $key, 'quantity' => '10']]], $tech);
            $this->assertThrows(AccessDeniedException::class, fn () => $measurements->create(['orderId' => $hidden], $tech), 'Kein Aufmaß zu nicht sichtbaren Aufträgen.');

            $list = $workspace->measurementList([], $tech);
            $this->assertSame([$saved['id']], array_column($list['items'], 'id'));
            $this->assertTrue(!str_contains(json_encode($list, JSON_THROW_ON_ERROR), 'netTotal') && !str_contains(json_encode($list, JSON_THROW_ON_ERROR), '25.00'), 'Keine Beträge in der mobilen Liste.');
            $this->assertSame('/mobile/measurements/' . $saved['id'], $list['items'][0]['url']);
            $this->assertSame(0, $workspace->measurementList(['status' => 'completed'], $tech)['total']);
            $this->assertSame(0, $workspace->measurementList([], $other)['total'], 'Fremde Monteure sehen das Aufmaß nicht.');
            $this->assertSame(1, $workspace->measurementList(['order' => $order], $office)['total']);

            // API-Antwort für Monteure: Mengen ja, Preise nein (ConfidentialDataFilter wie im Controller).
            $visible = ConfidentialDataFilter::salesValues($measurements->detail($saved['id'], $tech));
            $json = json_encode($visible, JSON_THROW_ON_ERROR);
            $this->assertTrue(!str_contains($json, '"2.50"') && !str_contains($json, '"25.00"') && str_contains($json, '"10.000"'));
        });
    }

    public function testProofPdfHasNoAmountsAndPricedPdfIsUnchanged(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['measurements' => $measurements, 'pdf' => $pdf, 'tech' => $tech, 'office' => $office, 'order' => $order, 'key' => $key, 'other' => $other] = $env;
            $draft = $measurements->create(['orderId' => $order], $tech);
            $saved = $measurements->update($draft['id'], ['version' => $draft['version'], 'title' => 'Teilaufmaß', 'measurementDate' => '2026-11-20', 'notes' => '',
                'items' => [['key' => $key, 'quantity' => '10']]], $tech);
            $proof = $pdf->proof($saved['id'], $tech)['pdf'];
            $priced = $pdf->pdf($saved['id'], $office)['pdf'];
            $this->assertTrue(str_contains($proof, 'Kabel') && str_contains($proof, 'nachweis'), 'Positionen und Titel im Nachweis.');
            $this->assertTrue(!str_contains($proof, '25,00') && !str_contains($proof, 'Summen') && !str_contains($proof, 'Netto'), 'Keine Beträge im Aufmaßnachweis.');
            $this->assertTrue(str_contains($priced, '25,00') && str_contains($priced, 'Summen'), 'Bewertetes Aufmaß unverändert mit Summen.');
            $this->assertThrows(AccessDeniedException::class, fn () => $pdf->proof($saved['id'], $other), 'Nachweis nur mit Zugriff auf das Aufmaß.');

            // Abgeschlossen: beide Fassungen archiviert, getrennt und beim zweiten Abruf unverändert.
            $completed = $measurements->complete($saved['id'], ['version' => $saved['version']], $office);
            $this->assertTrue($completed['completed']);
            $first = $pdf->proof($saved['id'], $tech);
            $this->assertSame($first['pdf'], $pdf->proof($saved['id'], $tech)['pdf']);
            $this->assertTrue(str_starts_with($first['fileName'], 'Aufmassnachweis_') && str_starts_with($pdf->pdf($saved['id'], $office)['fileName'], 'Aufmass_'));
        });
    }

    public function testMaterialRequestsListStatusRulesAndModule(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['workspace' => $workspace, 'materials' => $materials, 'tech' => $tech, 'other' => $other, 'office' => $office, 'order' => $order, 'hidden' => $hidden, 'modules' => $modules, 'admin' => $admin] = $env;
            $request = $materials->create(['orderId' => $order, 'description' => "50 m NYM-J 3x1,5\n10 Schalterdosen", 'notes' => 'Bis Freitag'], $tech);
            $this->assertThrows(AccessDeniedException::class, fn () => $materials->create(['orderId' => $hidden, 'description' => 'x'], $tech));
            $list = $workspace->materialRequestList([], $tech);
            $this->assertSame([$request['id']], array_column($list['items'], 'id'));
            $this->assertSame([true, false], [$list['items'][0]['canEdit'], $list['items'][0]['canManage']], 'Monteur bearbeitet eigene offene, erledigt nicht.');
            $this->assertSame(0, $workspace->materialRequestList([], $other)['total']);
            $this->assertThrows(AccessDeniedException::class, fn () => $materials->setStatus($request['id'], ['status' => 'done', 'version' => $request['version']], $tech));
            $done = $materials->setStatus($request['id'], ['status' => 'done', 'version' => $request['version']], $office);
            $this->assertSame(0, $workspace->materialRequestList([], $tech)['total'], 'Standard: offene Anforderungen.');
            $this->assertSame(1, $workspace->materialRequestList(['status' => 'done'], $tech)['total']);
            $this->assertTrue(!$workspace->materialRequestList(['status' => 'all'], $tech)['items'][0]['canEdit'], 'Erledigte nicht mehr bearbeitbar.');
            $this->assertSame('done', $done['status']);

            $modules->setEnabled('procurement', false, true, $admin);
            $this->assertSame(1, $workspace->materialRequestList(['status' => 'all'], $tech)['total'], 'Bestand bleibt lesbar.');
            $this->assertTrue(!in_array('Materialbedarf', array_column($workspace->quickActions($tech), 'label'), true));
            $this->assertTrue($modules->blockedMessage('POST', '/api/material-requests') !== null);
            $this->assertSame('/mobile/measurements', array_values(array_filter($workspace->quickActions($tech), static fn (array $action): bool => $action['label'] === 'Aufmaß'))[0]['url']);
        });
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-mobile-capture-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Kunde','2026-11-01T08:00:00Z','2026-11-01T08:00:00Z')")
                ->execute(['id' => $customer]);
            $tech = $this->user($pdo, 'technician');
            $other = $this->user($pdo, 'technician');
            $office = $this->user($pdo, 'office');
            $admin = $this->user($pdo, 'admin');
            $techEmployee = $this->employee($pdo, $tech['id']);
            $this->employee($pdo, $other['id']);

            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);
            $offerOrder = function (string $title) use ($offerService, $orders, $customer, &$nodeId): string {
                $nodeId = Uuid::v4();
                $offerId = $offerService->createDraft(null, $title, $customer);
                $loaded = $offerService->loadDraft($offerId);
                $document = $loaded['offer'];
                $document['nodes'] = [['id' => $nodeId, 'type' => 'position', 'positionType' => 'normal', 'shortText' => 'Kabel verlegen', 'longText' => '',
                    'quantity' => '100.000', 'unit' => 'm', 'manualUnitPrice' => '2.50', 'components' => []]];
                $etag = $offerService->saveDraft($offerId, $document, $loaded['etag']);
                $offerService->submitOffer($offerId, $etag, null, '2026-11-01', true);
                $offerService->changeStatus($offerId, 'submitted', 'commissioned');
                $order = $orders->createFromOffer($offerId);
                $orders->changeStatus($order['id'], 'open', 'in_progress');
                return (string) $order['id'];
            };
            $hidden = $offerOrder('Nicht zugeordnet');
            $order = $offerOrder('Elektroinstallation');
            $orders->assignEmployee($order, $techEmployee, true, $office);

            $store = new AttachmentStore($tmp . '/documents');
            $modules = new ModuleService($pdo);
            $measurementRepository = new MeasurementRepository($pdo);
            $measurements = new MeasurementService($pdo, $measurementRepository, new OrderRepository($pdo), $numbers);
            $materials = new MaterialRequestService(new MaterialRequestRepository($pdo), new OrderRepository($pdo), new DailyReportRepository($pdo), $modules);
            $test([
                'pdo' => $pdo, 'order' => $order, 'hidden' => $hidden, 'key' => 'order:' . $order . ':' . $nodeId,
                'tech' => $tech, 'other' => $other, 'office' => $office, 'admin' => $admin, 'modules' => $modules,
                'measurements' => $measurements, 'materials' => $materials,
                'pdf' => new MeasurementPdfService($measurements, $measurementRepository, new DocumentSettingsRepository($pdo), new DocumentArchive(new GeneratedDocumentRepository($pdo), $store)),
                'workspace' => new MobileWorkspace($modules, $orders, $measurements, null, null, $materials),
            ]);
        } finally {
            $this->remove($tmp);
        }
    }

    private function employee(PDO $pdo, string $userId): string
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO employees (id, user_id, display_name, hourly_cost_rate, active, created_at, updated_at) VALUES (:id, :user, :name, '42.0000', 1, '2026-11-01T08:00:00Z', '2026-11-01T08:00:00Z')")
            ->execute(['id' => $id, 'user' => $userId, 'name' => 'Mitarbeiter ' . substr($id, 0, 4)]);
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
