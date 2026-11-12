<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\WorkReportService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\WorkReportRepository;
use App\Security\AuthorizationPolicy;
use App\Security\RolePermissions;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Phase 3.4: Rapporte mit Leistungsnachweis, kaufmännischer Bearbeitung und Kundenbestätigung. */
final class WorkReportTest extends TestCase
{
    public function testPermissionsKeepPricesWithTheOffice(): void
    {
        $this->assertTrue(RolePermissions::allows('technician', 'workreports.write'));
        $this->assertTrue(!RolePermissions::allows('technician', 'workreports.process'));
        $this->assertTrue(RolePermissions::allows('office', 'workreports.process'));
        $policy = static fn (string $method, string $path): array => AuthorizationPolicy::required(new Request($method, $path, [], [], [], ''));
        $this->assertSame(['workreports.read', 'workreports.process'], $policy('PUT', '/api/work-reports/x/billing'));
        $this->assertSame(['workreports.read', 'workreports.process'], $policy('POST', '/api/work-reports/x/process'));
        $this->assertSame(['workreports.read', 'workreports.process'], $policy('GET', '/api/work-reports/catalog'));
        $this->assertSame(['workreports.write'], $policy('POST', '/api/work-reports/x/submit'));
        $this->assertSame(['workreports.read'], $policy('GET', '/work-reports'));
    }

    public function testTechnicianRecordsAndSubmitsImmutableProofWithoutPrices(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'technician' => $technician, 'regie' => $regie, 'offerOrder' => $offerOrder, 'orders' => $orders, 'labor' => $laborItem, 'anna' => $anna] = $env;

            $this->assertThrows(AccessDeniedException::class, fn () => $service->create(['orderId' => $regie['id']], $technician));
            $orders->changeStatus($regie['id'], 'open', 'in_progress');

            $draft = $service->create(['orderId' => $regie['id'], 'workDate' => '2026-11-12'], $technician);
            $this->assertSame('draft', $draft['status']);
            $this->assertSame(null, $draft['reportNumber'], 'Entwürfe verbrauchen keine Rapportnummer.');
            $this->assertTrue(!array_key_exists('billing', $draft) && !array_key_exists('netTotal', $draft), 'Monteure erhalten keine Preisfelder.');

            $this->assertThrows(OrderValidationException::class, fn () => $this->saveProof($service, $draft, ['labor' => [['employeeId' => $anna, 'hours' => '0']]], $technician));
            $this->assertThrows(OrderValidationException::class, fn () => $this->saveProof($service, $draft, ['materials' => [['quantity' => '1', 'unit' => 'Stk.', 'description' => ' ']]], $technician));
            $this->assertThrows(OrderValidationException::class, fn () => $this->saveProof($service, $draft, ['workDateTo' => '2026-11-11'], $technician));
            $this->assertThrows(OrderValidationException::class, fn () => $service->submit($draft['id'], ['version' => $draft['version']], $technician), 'Ohne Beschreibung und Leistungen nicht einreichbar.');

            $saved = $this->saveProof($service, $draft, [
                'description' => 'Heizungsregelung geprüft, Fühler getauscht',
                'contactPerson' => 'Herr Mack',
                'labor' => [
                    ['employeeId' => $anna, 'hours' => '3,5', 'laborItemId' => $laborItem, 'activity' => 'Fehlersuche'],
                    ['workerName' => 'Aushilfe Ben', 'hours' => '1'],
                ],
                'materials' => [
                    ['quantity' => '1', 'unit' => 'Stk.', 'description' => 'Temperaturfühler PT1000'],
                    ['quantity' => '5', 'unit' => 'm', 'description' => 'Steuerleitung'],
                    ['quantity' => '1', 'unit' => 'psch', 'description' => 'Anfahrt'],
                ],
            ], $technician);
            $this->assertSame('3.500', $saved['labor'][0]['hours']);
            $this->assertSame('Anna Monteur', $saved['labor'][0]['workerName'], 'Mitarbeitername kommt aus dem Stamm.');
            $this->assertSame('Monteurstunde', $saved['labor'][0]['laborItemName']);
            $this->assertSame('4.500', $saved['totalHours']);
            $json = json_encode($saved, JSON_THROW_ON_ERROR);
            $this->assertTrue(!str_contains($json, 'Price') && !str_contains($json, 'cost'), 'Keine Preise oder Kosten in der Monteuransicht.');

            $submitted = $service->submit($saved['id'], ['version' => $saved['version']], $technician);
            $this->assertSame('submitted', $submitted['status']);
            $this->assertTrue(str_starts_with((string) $submitted['reportNumber'], 'RP'));
            $this->assertSame(1, preg_match('/^[a-f0-9]{64}$/', (string) $submitted['submittedSha256']));
            $this->assertThrows(OrderValidationException::class, fn () => $this->saveProof($service, $submitted, ['description' => 'überschrieben'], $technician));
            $this->assertThrows(OrderValidationException::class, fn () => $service->delete($submitted['id'], $submitted['version'], $office));

            // Rapporte sind auch zu Angebotsaufträgen möglich; mehrere je Auftrag.
            $orders->changeStatus($offerOrder['id'], 'open', 'in_progress');
            $second = $service->create(['orderId' => $regie['id']], $office);
            $service->create(['orderId' => $offerOrder['id']], $technician);
            $this->assertSame(2, count($service->forOrder($regie['id'], $office)));
            $this->assertSame(3, $service->page([], $office)['total']);
            $this->assertSame(1, $service->page(['status' => 'submitted'], $technician)['total']);
            $this->assertThrows(AccessDeniedException::class, fn () => $this->saveProof($service, $second, ['description' => 'fremd'], $technician), 'Fremde Entwürfe sind für Monteure gesperrt.');
            $this->assertSame(3, $service->page(['sort' => 'total'], $technician)['total'], 'Sortierung nach Beträgen fällt für Monteure ohne Fehler zurück.');
        });
    }

    public function testOfficeBillingIsSeparateFromProofAndUsesSalesPrices(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'technician' => $technician, 'regie' => $regie, 'orders' => $orders, 'labor' => $laborItem, 'material' => $materialItem, 'anna' => $anna, 'pdo' => $pdo] = $env;
            $orders->changeStatus($regie['id'], 'open', 'in_progress');
            $report = $this->submittedReport($service, $regie['id'], $technician, $anna, $laborItem);

            $this->assertThrows(AccessDeniedException::class, fn () => $service->catalog('Monteur', $technician));
            $catalog = $service->catalog('monteur', $office);
            $this->assertSame('85.00', $catalog[0]['salesPrice'], 'Verkaufspreis aus Einkaufspreis und Zuschlag.');
            $this->assertTrue(!array_key_exists('cost_price', $catalog[0]) && !array_key_exists('costPrice', $catalog[0]), 'Keine Einkaufspreise im Katalog.');

            $this->assertThrows(AccessDeniedException::class, fn () => $service->saveBilling($report['id'], ['version' => $report['version'], 'items' => []], $technician));
            $billed = $service->saveBilling($report['id'], ['version' => $report['version'], 'items' => [
                ['type' => 'labor', 'description' => 'Monteurstunden', 'quantity' => '3,5', 'unit' => 'h', 'unitPrice' => '85.00', 'costItemId' => $laborItem],
                ['type' => 'material', 'description' => 'Temperaturfühler PT1000', 'quantity' => '1', 'unit' => 'Stk.', 'unitPrice' => '42.00', 'costItemId' => $materialItem],
                ['type' => 'material', 'description' => 'Steuerleitung', 'quantity' => '5', 'unit' => 'm', 'unitPrice' => '2,50'],
                ['type' => 'travel', 'description' => 'Anfahrt', 'quantity' => '1', 'unit' => 'psch', 'unitPrice' => '35.00'],
                ['type' => 'other', 'description' => 'Kleinmaterial', 'quantity' => '0,333', 'unit' => 'psch', 'unitPrice' => '2.50'],
            ]], $office);
            $this->assertSame('297.50', $billed['billing'][0]['lineTotal']);
            $this->assertSame('0.83', $billed['billing'][4]['lineTotal'], '0,333 × 2,50 € = 0,8325 € → 0,83 €.');
            $this->assertSame('387.83', $billed['billingTotal']);
            $this->assertSame('3.500', $billed['labor'][0]['hours'], 'Der Leistungsnachweis bleibt unverändert.');
            $this->assertSame($report['submittedSha256'], $billed['submittedSha256']);
            $this->assertThrows(OrderValidationException::class, fn () => $service->saveBilling($report['id'], ['version' => $report['version'], 'items' => []], $office), 'Veraltete Version wird abgelehnt.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->saveBilling($report['id'], ['version' => $billed['version'], 'items' => [['type' => 'unbekannt', 'description' => 'x', 'quantity' => '1', 'unit' => '', 'unitPrice' => '1']]], $office));

            $technicianView = $service->detail($report['id'], $technician);
            $this->assertTrue(!array_key_exists('billing', $technicianView) && !array_key_exists('netTotal', $technicianView), 'Auch die API liefert Monteuren keine Preise.');

            $processed = $service->process($report['id'], ['version' => $billed['version']], $office);
            $this->assertSame('processed', $processed['status']);
            $this->assertSame('387.83', $processed['netTotal']);
            $this->assertThrows(OrderValidationException::class, fn () => $service->saveBilling($report['id'], ['version' => $processed['version'], 'items' => []], $office));
            $reopened = $service->reopen($report['id'], ['version' => $processed['version']], $office);
            $this->assertSame('submitted', $reopened['status']);
            $this->assertSame(null, $reopened['netTotal']);
            $this->assertSame(5, count($reopened['billing']), 'Wiederöffnen behält die Positionen.');

            // Spätere Stammdatenänderungen ändern bestehende Positionen nicht.
            $pdo->exec("UPDATE cost_items SET cost_price = '99.0000' WHERE id = " . $pdo->quote($laborItem));
            $this->assertSame('85.00', $service->detail($report['id'], $office)['billing'][0]['unitPrice']);

            $empty = $this->submittedReport($service, $regie['id'], $technician, $anna, $laborItem);
            $this->assertThrows(OrderValidationException::class, fn () => $service->process($empty['id'], ['version' => $empty['version']], $office), 'Ohne Positionen kein Abschluss.');
        });
    }

    public function testConfirmationIsBoundToSubmittedProofAndCorrectionsKeepOriginal(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'technician' => $technician, 'colleague' => $colleague, 'regie' => $regie, 'orders' => $orders, 'labor' => $laborItem, 'anna' => $anna, 'pdo' => $pdo] = $env;
            $orders->changeStatus($regie['id'], 'open', 'in_progress');

            $draft = $service->create(['orderId' => $regie['id']], $technician);
            $this->assertThrows(OrderValidationException::class, fn () => $service->confirm($draft['id'], ['version' => $draft['version'], 'confirmedByName' => 'Herr Mack'], $technician));

            $report = $this->submittedReport($service, $regie['id'], $technician, $anna, $laborItem);
            $this->assertThrows(AccessDeniedException::class, fn () => $service->confirm($report['id'], ['version' => $report['version'], 'confirmedByName' => 'Herr Mack'], $colleague));
            $this->assertThrows(OrderValidationException::class, fn () => $service->confirm($report['id'], ['version' => $report['version'], 'confirmedByName' => ' '], $technician));

            $confirmed = $service->confirm($report['id'], ['version' => $report['version'], 'confirmedByName' => 'Herr Mack', 'method' => 'on_site'], $technician);
            $this->assertSame('confirmed', $confirmed['confirmationStatus']);
            $this->assertSame('Herr Mack', $confirmed['confirmedByName']);
            $this->assertSame('submitted', $confirmed['status'], 'Die Bestätigung ändert den Bearbeitungsstatus nicht.');
            $hashes = $pdo->query('SELECT submitted_sha256, confirmation_sha256 FROM work_reports WHERE id = ' . $pdo->quote($report['id']))->fetch();
            $this->assertSame($hashes['submitted_sha256'], $hashes['confirmation_sha256'], 'Bestätigung gilt genau für den eingereichten Stand.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->confirm($report['id'], ['version' => $confirmed['version'], 'confirmedByName' => 'Frau Mack'], $office));

            $this->assertThrows(AccessDeniedException::class, fn () => $service->create(['correctionOf' => $report['id']], $colleague));
            $correction = $service->create(['correctionOf' => $report['id']], $technician);
            $this->assertSame('draft', $correction['status']);
            $this->assertSame($report['id'], $correction['correctionOfId']);
            $this->assertSame($confirmed['reportNumber'], $correction['correctedNumber']);
            $this->assertSame('none', $correction['confirmationStatus'], 'Die Bestätigung wird nicht auf die Korrektur übertragen.');
            $this->assertSame($report['labor'], $correction['labor'], 'Die Korrektur startet mit dem eingereichten Stand.');

            $this->saveProof($service, $correction, ['description' => 'Korrigiert', 'labor' => [['employeeId' => $anna, 'hours' => '2']]], $technician);
            $original = $service->detail($report['id'], $office);
            $this->assertSame('3.500', $original['labor'][0]['hours'], 'Das Original bleibt unverändert.');
            $this->assertSame('confirmed', $original['confirmationStatus']);
            $this->assertSame(1, $original['correctionCount']);
            $this->assertSame($report['submittedSha256'], $original['submittedSha256']);
        });
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function submittedReport(WorkReportService $service, string $orderId, array $actor, string $employee, string $laborItem): array
    {
        $draft = $service->create(['orderId' => $orderId, 'workDate' => '2026-11-12'], $actor);
        $saved = $this->saveProof($service, $draft, [
            'description' => 'Regiearbeiten',
            'labor' => [['employeeId' => $employee, 'hours' => '3.5', 'laborItemId' => $laborItem]],
            'materials' => [['quantity' => '1', 'unit' => 'Stk.', 'description' => 'Temperaturfühler']],
        ], $actor);
        return $service->submit($saved['id'], ['version' => $saved['version']], $actor);
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $changes @param array<string,mixed> $actor @return array<string,mixed> */
    private function saveProof(WorkReportService $service, array $report, array $changes, array $actor): array
    {
        return $service->update($report['id'], [
            'version' => $report['version'],
            'workDate' => $report['workDate'],
            'workDateTo' => '',
            'contactPerson' => '',
            'description' => '',
            'notes' => '',
            'labor' => [],
            'materials' => [],
            ...$changes,
        ], $actor);
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-work-reports-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $now = '2026-11-12T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Matzko-Mack',:now,:now)")
                ->execute(['id' => $customer, 'now' => $now]);
            $laborItem = $this->costItem($pdo, 'Monteurstunde', 'labor', 'h', '50.0000', '70.00', $now);
            $materialItem = $this->costItem($pdo, 'Temperaturfühler PT1000', 'material', 'Stk.', '30.0000', '40.00', $now);
            $anna = Uuid::v4();
            $pdo->prepare("INSERT INTO employees(id,display_name,hourly_cost_rate,active,created_at,updated_at) VALUES(:id,'Anna Monteur','40.0000',1,:now,:now)")
                ->execute(['id' => $anna, 'now' => $now]);

            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);

            $offerId = $offerService->createDraft(null, 'Wartung', $customer);
            $loaded = $offerService->loadDraft($offerId);
            $document = $loaded['offer'];
            $document['nodes'] = [['id' => Uuid::v4(), 'type' => 'position', 'positionType' => 'normal', 'shortText' => 'Wartung', 'longText' => '',
                'quantity' => '1.000', 'unit' => 'psch', 'manualUnitPrice' => '100.00', 'components' => []]];
            $etag = $offerService->saveDraft($offerId, $document, $loaded['etag']);
            $offerService->submitOffer($offerId, $etag, null, '2026-11-10', true);
            $offerService->changeStatus($offerId, 'submitted', 'commissioned');

            $test([
                'pdo' => $pdo,
                'service' => new WorkReportService($pdo, new WorkReportRepository($pdo), new OrderRepository($pdo), $numbers),
                'orders' => $orders,
                'regie' => $orders->createRegie(['customerId' => $customer, 'title' => 'Störung Heizung', 'orderDate' => '2026-11-12']),
                'offerOrder' => $orders->createFromOffer($offerId),
                'office' => $this->user($pdo, 'office', $now),
                'technician' => $this->user($pdo, 'technician', $now),
                'colleague' => $this->user($pdo, 'technician', $now),
                'labor' => $laborItem,
                'material' => $materialItem,
                'anna' => $anna,
            ]);
        } finally {
            $this->remove($tmp);
        }
    }

    private function costItem(PDO $pdo, string $name, string $type, string $unit, string $cost, string $markup, string $now): string
    {
        $id = Uuid::v4();
        $pdo->prepare('INSERT INTO cost_items(id,name,calculation_type,unit,cost_price,markup_percent,created_at,updated_at) VALUES(:id,:name,:type,:unit,:cost,:markup,:now,:now)')
            ->execute(['id' => $id, 'name' => $name, 'type' => $type, 'unit' => $unit, 'cost' => $cost, 'markup' => $markup, 'now' => $now]);
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
