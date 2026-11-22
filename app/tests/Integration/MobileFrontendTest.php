<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\MeasurementService;
use App\Application\MobileWorkspace;
use App\Application\ModuleService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\TimeTrackingService;
use App\Application\UserInterface;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\MeasurementRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Security\AuthorizationPolicy;
use App\Security\RolePermissions;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;
use PDOException;

/** Monteurs-Frontend Phase 1: Oberflächenwahl, Rechte, Modulabhängigkeit, keine kaufmännischen Daten. */
final class MobileFrontendTest extends TestCase
{
    public function testInterfaceSelectionFollowsRoleAndStoredPreferenceButNeverScreenSize(): void
    {
        $technician = ['id' => 't', 'role' => 'technician', 'preferredInterface' => 'office'];
        $office = ['id' => 'o', 'role' => 'office', 'preferredInterface' => null];
        $admin = ['id' => 'a', 'role' => 'admin', 'preferredInterface' => 'mobile'];

        $this->assertSame('mobile', UserInterface::preferred($technician), 'Monteure landen immer mobil, auch mit (unzulässig) gespeicherter Büroauswahl.');
        $this->assertSame('office', UserInterface::preferred($office), 'Büro ohne Einstellung: Büroansicht.');
        $this->assertSame('mobile', UserInterface::preferred($admin), 'Gespeicherte Auswahl gilt für Büro/Administratoren.');
        $this->assertSame('office', UserInterface::preferred(['role' => 'office', 'preferredInterface' => 'tablet']), 'Unbekannte Werte fallen auf den Standard zurück.');
        $this->assertTrue(!UserInterface::canSwitch($technician) && UserInterface::canSwitch($office) && UserInterface::canSwitch($admin));

        $interfaces = new UserInterface();
        $this->assertSame('/mobile/', $interfaces->landingPath($technician, 'calight.example.de'));
        $this->assertSame('/dashboard', $interfaces->landingPath($office, 'calight.example.de'));

        // Optionale Subdomain: dort immer mobil (Port wird ignoriert), sonst unverändert.
        $withHost = new UserInterface('m.calight.example.de');
        $this->assertSame('/mobile/', $withHost->landingPath($office, 'M.Calight.Example.de:8443'));
        $this->assertSame('/dashboard', $withHost->landingPath($office, 'calight.example.de'));
        $this->assertTrue(!$withHost->isMobileHost(''));
    }

    public function testMobileRoutesUseTheExistingPermissionMatrix(): void
    {
        $required = static fn (string $method, string $path): array => AuthorizationPolicy::required(new Request($method, $path, [], [], [], ''));
        $this->assertSame(['dashboard.read'], $required('GET', '/mobile/'));
        $this->assertSame(['dashboard.read'], $required('GET', '/mobile'));
        $this->assertSame(['dashboard.read'], $required('GET', '/mobile/more'));
        $this->assertSame(['orders.read'], $required('GET', '/mobile/orders'));
        $this->assertSame(['orders.read'], $required('GET', '/mobile/orders/abc'));
        $this->assertSame(['timesheet.own'], $required('GET', '/mobile/times'));
        $this->assertSame([], $required('GET', '/mobile/manifest.webmanifest'), 'Manifest wird vom Browser ohne Anmeldung geladen.');
        $this->assertSame([], $required('GET', '/mobile/sw.js'));
        $this->assertSame(['interface.switch'], $required('POST', '/interface'));
        // Bürorouten bleiben unverändert.
        $this->assertSame(['orders.read'], $required('GET', '/orders/abc'));
        $this->assertSame(['dashboard.read'], $required('GET', '/dashboard'));

        $this->assertTrue(!RolePermissions::allows('technician', 'interface.switch'), 'Der Oberflächenwechsel erweitert keine Monteursrechte.');
        $this->assertTrue(RolePermissions::allows('office', 'interface.switch') && RolePermissions::allows('admin', 'interface.switch'));
        $this->assertSame('work_tracking', ModuleService::moduleForPath('/mobile/times'));
        $this->assertSame(null, ModuleService::moduleForPath('/mobile/orders'));
    }

    public function testPreferenceIsStoredPerUserAndValidatedByTheDatabase(): void
    {
        $this->withOrder(function (array $env): void {
            $pdo = $env['pdo'];
            $users = new UserRepository($pdo);
            $this->assertSame(null, $users->findActiveById($env['office']['id'])['preferred_interface'], 'Bestehende Konten behalten den Rollenstandard.');
            $this->assertTrue($users->updatePreferredInterface($env['office']['id'], 'mobile', '2026-11-21T08:00:00Z'));
            $this->assertSame('mobile', $users->findActiveById($env['office']['id'])['preferred_interface']);
            $this->assertSame(null, $users->findActiveById($env['technician']['id'])['preferred_interface'], 'Nur das eigene Konto wird geändert.');
            $this->assertThrows(PDOException::class, fn () => $users->updatePreferredInterface($env['office']['id'], 'desktop', '2026-11-21T08:00:00Z'));
        });
    }

    public function testOrderProjectionsShowExecutionDataWithoutCommercialValues(): void
    {
        $this->withOrder(function (array $env): void {
            $workspace = $env['workspace'];
            $detail = $workspace->order($env['order']['id'], $env['office']);
            $json = json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            foreach (['unitPrice', 'netTotal', 'contractNetTotal', 'costPrice', 'adjustment', '"2.50"', '"40.00"', 'Nachlass'] as $forbidden) {
                $this->assertTrue(!str_contains($json, $forbidden), 'Mobile Auftragsansicht enthält kaufmännische Angabe: ' . $forbidden);
            }
            $this->assertSame(['Hauptstraße 5', '12345 Musterstadt'], $detail['site']['lines']);
            $this->assertSame('Erika Muster', $detail['contacts'][0]['name']);
            $this->assertSame('0170 1234567', $detail['contacts'][0]['mobile']);
            $positions = array_values(array_filter($detail['services'][0]['rows'], static fn (array $row): bool => $row['kind'] === 'position'));
            $this->assertSame('NYM-J 3x1,5', $positions[0]['text']);
            $this->assertSame('100.000', $positions[0]['quantity']);
            $this->assertSame(2, count($detail['services']), 'Auftragsgrundlage und angenommener Nachtrag.');

            $list = $workspace->orders(['q' => 'Elektro'], $env['office']);
            $this->assertSame(1, $list['total']);
            $item = $list['items'][0];
            $this->assertSame(['id', 'number', 'title', 'customerName', 'projectName', 'projectNumber', 'site', 'status', 'statusLabel'], array_keys($item));
            $this->assertSame('Hauptstraße 5 · 12345 Musterstadt', $item['site']);
        });
    }

    public function testTechniciansKeepTheReleaseRuleAndActiveOrdersArePreferred(): void
    {
        $this->withOrder(function (array $env): void {
            $workspace = $env['workspace'];
            $this->assertSame(0, $workspace->orders([], $env['technician'])['total'], 'Offene Aufträge sind für Monteure nicht freigegeben.');
            $this->assertThrows(AccessDeniedException::class, fn () => $workspace->order($env['order']['id'], $env['technician']));
            $this->assertSame(1, $workspace->orders([], $env['office'])['total'], 'Standardfilter „aktiv“ enthält offene Aufträge.');

            $env['orders']->changeStatus($env['order']['id'], 'open', 'in_progress');
            $this->assertSame(1, $workspace->orders([], $env['technician'])['total']);
            $this->assertSame('In Bearbeitung', $workspace->order($env['order']['id'], $env['technician'])['order']['statusLabel']);

            $env['orders']->changeStatus($env['order']['id'], 'in_progress', 'completed');
            $this->assertSame(0, $workspace->orders([], $env['technician'])['total'], 'Abgeschlossene Aufträge sind nicht aktiv.');
            $this->assertSame(1, $workspace->orders(['status' => 'completed'], $env['technician'])['total']);
            $this->assertSame(1, $workspace->orders(['status' => 'all'], $env['technician'])['total']);
            $this->assertSame('active', $workspace->orders(['status' => 'unbekannt'], $env['technician'])['status']);
        });
    }

    public function testNavigationAndQuickActionsFollowModulesAndPermissions(): void
    {
        $this->withOrder(function (array $env): void {
            $workspace = $env['workspace'];
            $labels = static fn (array $actions): array => array_column($actions, 'label');
            $technician = $workspace->features($env['technician']);
            $this->assertTrue($technician['orders'] && $technician['times'] && $technician['measurements']);
            $this->assertTrue(!$technician['documents'] && !$technician['switchInterface'], 'Monteure: keine Dokumentenübersicht, kein Oberflächenwechsel.');
            $this->assertSame(['Arbeitszeit', 'Tagesbericht', 'Rapport', 'Materialbedarf', 'Aufmaß', 'Abwesenheit', 'Reisekosten'], $labels($workspace->quickActions($env['technician'])),
                'Schnellzugriffe nur mit Schreibrecht und aktivem Modul; persönliche Zeiten mit zugeordnetem Mitarbeiter.');
            $this->assertSame('/mobile/times?form=entry', $workspace->quickActions($env['technician'])[0]['url'], 'Zeiterfassung mobil, ohne Büroansicht.');
            $office = $workspace->features($env['office']);
            $this->assertTrue($office['times'] && !$office['timeEntry'], 'Ohne zugeordneten Mitarbeiter keine persönlichen Erfassungsaktionen.');
            $this->assertTrue(!in_array('Arbeitszeit', $labels($workspace->quickActions($env['office'])), true));

            $modules = $env['modules'];
            $modules->setEnabled('work_tracking', false, true, $env['admin']);
            $this->assertTrue(!$workspace->features($env['technician'])['times']);
            $this->assertSame(['Materialbedarf', 'Aufmaß'], $labels($workspace->quickActions($env['technician'])), 'Ohne Arbeitserfassung entfallen Zeiten, Berichte, Abwesenheiten und Reisekosten.');
            $modules->setEnabled('procurement', false, true, $env['admin']);
            $this->assertTrue(!in_array('Materialbedarf', $labels($workspace->quickActions($env['office'])), true));
            $this->assertTrue(!$workspace->features($env['office'])['materialRequests']);
            $detail = $workspace->order($env['order']['id'], $env['office']);
            $this->assertSame(['Aufmaß anlegen'], $labels($detail['actions']), 'Anlegeaktionen nur für aktive Module.');
        });
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withOrder(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-mobile-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);
            $now = '2026-11-20T08:00:00Z';
            $customer = Uuid::v4();
            $contact = Uuid::v4();
            $project = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,phone,created_at,updated_at) VALUES(:id,'K1','company','Kunde GmbH','089 123',:now,:now)")
                ->execute(['id' => $customer, 'now' => $now]);
            $pdo->prepare("INSERT INTO customer_contacts(id,customer_id,first_name,last_name,mobile,created_at,updated_at) VALUES(:id,:customer,'Erika','Muster','0170 1234567',:now,:now)")
                ->execute(['id' => $contact, 'customer' => $customer, 'now' => $now]);
            $pdo->prepare("INSERT INTO projects(id,project_number,name,customer_id,contact_id,site_street,site_house_number,site_postal_code,site_city,status,created_at,updated_at)
                VALUES(:id,'P1','Neubau',:customer,:contact,'Hauptstraße','5','12345','Musterstadt','active',:now,:now)")
                ->execute(['id' => $project, 'customer' => $customer, 'contact' => $contact, 'now' => $now]);
            $admin = $this->user($pdo, 'admin', $now);
            $office = $this->user($pdo, 'office', $now);
            $technician = $this->user($pdo, 'technician', $now);
            $pdo->prepare("INSERT INTO employees (id, user_id, display_name, hourly_cost_rate, active, created_at, updated_at) VALUES (:id, :user, 'Max Monteur', '42.0000', 1, :now, :now)")
                ->execute(['id' => Uuid::v4(), 'user' => $technician['id'], 'now' => $now]);

            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);

            $offerId = $offerService->createDraft($project, 'Elektroinstallation', $customer);
            $etag = $this->saveNodes($offerService, $offerId, [
                ['id' => Uuid::v4(), 'type' => 'title', 'name' => 'Leitungen', 'description' => '', 'children' => [
                    $this->positionNode(Uuid::v4(), 'NYM-J 3x1,5', '100.000', 'm', '2.50'),
                ]],
            ], ['type' => 'discount', 'mode' => 'percentage', 'value' => '10.00', 'label' => 'Nachlass']);
            $offerService->submitOffer($offerId, $etag, null, '2026-11-19', true);
            $offerService->changeStatus($offerId, 'submitted', 'commissioned');
            $order = $orders->createFromOffer($offerId);
            $changeOrder = $orders->createChangeOrder($order['id'], 'Zusätzliche Steckdosen');
            $changeEtag = $this->saveNodes($offerService, $changeOrder['id'], [$this->positionNode(Uuid::v4(), 'Steckdose', '5.000', 'Stk.', '40.00')]);
            $offerService->submitOffer($changeOrder['id'], $changeEtag, null, '2026-11-20', true);
            $orders->acceptChangeOrder($changeOrder['id'], 'submitted');

            $modules = new ModuleService($pdo);
            $measurements = new MeasurementService($pdo, new MeasurementRepository($pdo), new OrderRepository($pdo), $numbers);
            $test([
                'pdo' => $pdo,
                'orders' => $orders,
                'order' => $order,
                'modules' => $modules,
                'workspace' => new MobileWorkspace($modules, $orders, $measurements, timeTracking: new TimeTrackingService(new TimeTrackingRepository($pdo), new AttachmentStore($tmp . '/documents'))),
                'admin' => $admin,
                'office' => $office,
                'technician' => $technician,
            ]);
        } finally {
            $this->remove($tmp);
        }
    }

    /** @return array<string,mixed> */
    private function positionNode(string $id, string $shortText, string $quantity, string $unit, string $price): array
    {
        return ['id' => $id, 'type' => 'position', 'positionType' => 'normal', 'shortText' => $shortText, 'longText' => '',
            'quantity' => $quantity, 'unit' => $unit, 'manualUnitPrice' => $price, 'components' => []];
    }

    /** @param list<array<string,mixed>> $nodes @param array<string,mixed>|null $adjustment */
    private function saveNodes(OfferService $service, string $offerId, array $nodes, ?array $adjustment = null): string
    {
        $loaded = $service->loadDraft($offerId);
        $document = $loaded['offer'];
        $document['nodes'] = $nodes;
        if ($adjustment !== null) {
            $document['offerAdjustment'] = $adjustment;
        }
        return $service->saveDraft($offerId, $document, $loaded['etag']);
    }

    /** @return array{id:string,role:string,preferredInterface:null} */
    private function user(PDO $pdo, string $role, string $now): array
    {
        $id = Uuid::v4();
        $pdo->prepare('INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:username,\'hash\',:name,:role,1,:now,:now)')
            ->execute(['id' => $id, 'username' => $role . '-' . substr($id, 0, 8), 'name' => ucfirst($role), 'role' => $role, 'now' => $now]);
        return ['id' => $id, 'role' => $role, 'preferredInterface' => null];
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
