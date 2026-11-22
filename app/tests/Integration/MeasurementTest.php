<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DocumentArchive;
use App\Application\MeasurementPdfService;
use App\Application\MeasurementService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\GeneratedDocumentRepository;
use App\Infrastructure\Persistence\MeasurementRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Security\AuthorizationPolicy;
use App\Security\RolePermissions;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Phase 3.3: Aufmaße auf Basis verbindlicher Auftragspositionen. */
final class MeasurementTest extends TestCase
{
    public function testPermissionsMatrix(): void
    {
        $this->assertTrue(RolePermissions::allows('office', 'measurements.complete'));
        $this->assertTrue(RolePermissions::allows('technician', 'measurements.write'));
        $this->assertTrue(!RolePermissions::allows('technician', 'measurements.complete'));
        $this->assertSame(['measurements.read', 'measurements.complete'], AuthorizationPolicy::required(new Request('POST', '/api/measurements/x/complete', [], [], [], '')));
        $this->assertSame(['measurements.write'], AuthorizationPolicy::required(new Request('PUT', '/api/measurements/x', [], [], [], '')));
        $this->assertSame(['measurements.read'], AuthorizationPolicy::required(new Request('GET', '/measurements', [], [], [], '')));
    }

    public function testPartialMeasurementsProgressPricesAndAdjustments(): void
    {
        $this->withOrder(function (array $env): void {
            ['service' => $service, 'office' => $office, 'order' => $order, 'keys' => $keys, 'orders' => $orders, 'customer' => $customer] = $env;

            $first = $service->create(['orderId' => $order['id'], 'measurementDate' => '2026-11-10'], $office);
            $this->assertSame('draft', $first['status']);
            $this->assertSame(null, $first['measurementNumber'], 'Entwürfe verbrauchen keine Aufmaßnummer.');
            $this->assertSame(['order', 'addendum'], array_column($first['sources'], 'type'), 'Nur angenommene Nachträge sind verbindliche Leistungen.');
            $this->assertSame(1, count(array_filter($first['sources'], static fn (array $source): bool => $source['type'] === 'addendum')));
            $json = json_encode($first, JSON_THROW_ON_ERROR);
            $this->assertTrue(!str_contains($json, 'costPrice') && !str_contains($json, 'totalCosts') && !str_contains($json, 'materialCosts'), 'Keine internen Kosten im Aufmaß.');

            $first = $this->measure($service, $first, [$keys['meter'] => '30'], $office);
            $this->assertSame('75.00', $first['totals'][0]['itemsTotal']);
            $this->assertSame('-7.50', $first['totals'][0]['adjustmentAmount'], 'Der Angebotsnachlass wirkt anteilig auf Normalpositionen.');
            $this->assertSame('67.50', $first['totals'][0]['netTotal']);
            $completed = $service->complete($first['id'], ['version' => $first['version']], $office);
            $this->assertTrue($completed['completed']);
            $this->assertTrue(str_starts_with((string) $completed['measurement']['measurementNumber'], 'AM'));
            $this->assertSame('67.50', $completed['measurement']['netTotal']);

            // Zweites Teilaufmaß: zusätzliche Menge, Bedarfsposition ohne Nachlass.
            $second = $service->create(['orderId' => $order['id'], 'measurementDate' => '2026-11-11'], $office);
            $meter = $this->position($second, $keys['meter']);
            $this->assertSame('30.000', $meter['previousQuantity'], 'Bereits abgeschlossene Mengen werden als „bisher“ angezeigt.');
            $second = $this->measure($service, $second, [$keys['meter'] => '45', $keys['demand'] => '3'], $office);
            $this->assertSame('157.50', $second['totals'][0]['itemsTotal']);
            $this->assertSame('-11.25', $second['totals'][0]['adjustmentAmount']);
            $this->assertSame('146.25', $second['totals'][0]['netTotal']);
            $this->assertSame('75.000', $this->position($second, $keys['meter'])['totalQuantity']);
            $this->assertSame('25.000', $this->position($second, $keys['meter'])['openQuantity']);
            $service->complete($second['id'], ['version' => $second['version']], $office);

            $third = $service->create(['orderId' => $order['id'], 'measurementDate' => '2026-11-12'], $office);
            $third = $this->measure($service, $third, [$keys['meter'] => '25', $keys['addendum'] => '5'], $office);
            $byType = array_column($third['totals'], null, 'sourceType');
            $this->assertSame('56.25', $byType['order']['netTotal']);
            $this->assertSame('200.00', $byType['addendum']['netTotal'], 'Nachtragspositionen werden zum Nachtragspreis bewertet.');
            $this->assertSame([], $third['overruns']);
            $service->complete($third['id'], ['version' => $third['version']], $office);

            // 30 + 45 + 25 m ergeben exakt den vereinbarten Positionswert nach Nachlass (225,00 €).
            $overview = $service->forOrder($order['id'], $office);
            $this->assertSame(['positions' => 4, 'started' => 3, 'complete' => 2, 'overrun' => 0], $overview['progress']);
            $this->assertSame('67.50', $overview['measurements'][2]['netTotal']);
            $this->assertSame('470.00', $overview['completedNetTotal'], '67,50 + 146,25 + 56,25 + 200,00 (Nachtrag).');

            // Historische Sicht: das erste Aufmaß zeigt nur vorher abgeschlossene Mengen als „bisher“.
            $this->assertSame('0.000', $this->position($service->detail($first['id'], $office), $keys['meter'])['previousQuantity']);

            // Rundung: 0,333 m × 2,50 € = 0,8325 € → 0,83 €.
            $rounding = $service->create(['orderId' => $order['id']], $office);
            $rounding = $this->measure($service, $rounding, [$keys['meter'] => '0,333'], $office);
            $this->assertSame('0.83', $this->position($rounding, $keys['meter'])['lineTotal']);
            $service->delete($rounding['id'], $rounding['version'], $office);

            // Direkter Regieauftrag ohne angenommenen Nachtrag hat keine messbaren Leistungen.
            $regie = $orders->createRegie(['customerId' => $customer, 'title' => 'Störung', 'orderDate' => '2026-11-10']);
            $this->assertThrows(OrderValidationException::class, fn () => $service->create(['orderId' => $regie['id']], $office));
            $this->assertSame(false, $service->forOrder($regie['id'], $office)['measurable']);
        });
    }

    public function testOverrunsNeedConfirmationAndCompletedMeasurementsAreImmutable(): void
    {
        $this->withOrder(function (array $env): void {
            ['service' => $service, 'office' => $office, 'order' => $order, 'keys' => $keys] = $env;
            $full = $service->create(['orderId' => $order['id']], $office);
            $full = $this->measure($service, $full, [$keys['meter'] => '100'], $office);
            $service->complete($full['id'], ['version' => $full['version']], $office);

            $extra = $service->create(['orderId' => $order['id']], $office);
            $extra = $this->measure($service, $extra, [$keys['meter'] => '2,5'], $office);
            $this->assertSame(1, count($extra['overruns']), 'Überschreitungen sind schon im Entwurf sichtbar.');
            $this->assertTrue($this->position($extra, $keys['meter'])['overrun']);

            $refused = $service->complete($extra['id'], ['version' => $extra['version']], $office);
            $this->assertSame(false, $refused['completed'], 'Ohne Bestätigung wird nicht abgeschlossen.');
            $this->assertSame('2.500', $refused['overruns'][0]['excess']);
            $this->assertSame('draft', $service->detail($extra['id'], $office)['status']);

            $accepted = $service->complete($extra['id'], ['version' => $extra['version'], 'confirmOverrun' => true], $office);
            $this->assertTrue($accepted['completed']);
            $this->assertTrue($this->position($accepted['measurement'], $keys['meter'])['overrunConfirmed']);

            $done = $accepted['measurement'];
            $this->assertThrows(OrderValidationException::class, fn () => $service->update($done['id'], ['version' => $done['version'], 'title' => 'x', 'measurementDate' => '2026-11-10', 'items' => []], $office));
            $this->assertThrows(OrderValidationException::class, fn () => $service->delete($done['id'], $done['version'], $office));

            // Negative Mengen nur in Korrekturaufmaßen; Korrekturen dürfen nicht unter null führen.
            $plain = $service->create(['orderId' => $order['id']], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $this->measure($service, $plain, [$keys['meter'] => '-1'], $office));
            $correction = $service->create(['correctionOf' => $done['id']], $office);
            $this->assertSame($done['id'], $correction['correctionOfId']);
            $tooMuch = $this->measure($service, $correction, [$keys['meter'] => '-200'], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $service->complete($tooMuch['id'], ['version' => $tooMuch['version']], $office));
            $fixed = $this->measure($service, $service->detail($correction['id'], $office), [$keys['meter'] => '-2,5'], $office);
            $this->assertSame('-6.25', $this->position($fixed, $keys['meter'])['lineTotal']);
            $this->assertTrue($service->complete($fixed['id'], ['version' => $fixed['version']], $office)['completed']);
            $this->assertSame('100.000', $this->position($service->detail($plain['id'], $office), $keys['meter'])['previousQuantity']);
        });
    }

    public function testConcurrentDraftsAndVersionConflicts(): void
    {
        $this->withOrder(function (array $env): void {
            ['service' => $service, 'office' => $office, 'order' => $order, 'keys' => $keys] = $env;
            // Zwei Benutzer erfassen parallel dieselbe Bedarfsposition (vereinbart 10).
            $a = $this->measure($service, $service->create(['orderId' => $order['id']], $office), [$keys['demand'] => '7'], $office);
            $b = $this->measure($service, $service->create(['orderId' => $order['id']], $office), [$keys['demand'] => '7'], $office);
            $this->assertSame([], $b['overruns'], 'Entwürfe zählen nicht als aufgemessen.');
            $this->assertTrue($service->complete($a['id'], ['version' => $a['version']], $office)['completed']);
            $second = $service->complete($b['id'], ['version' => $b['version']], $office);
            $this->assertSame(false, $second['completed'], 'Der zweite Abschluss erkennt die zwischenzeitlich abgeschlossene Menge.');
            $this->assertSame('14.000', $second['overruns'][0]['total']);

            $this->assertThrows(OrderValidationException::class, fn () => $service->update($b['id'], ['version' => $b['version'] - 1, 'title' => 'alt', 'measurementDate' => '2026-11-10', 'items' => []], $office));
            $this->assertThrows(OrderValidationException::class, fn () => $service->complete($b['id'], ['version' => $b['version'] - 1, 'confirmOverrun' => true], $office));
            $this->assertThrows(OrderValidationException::class, fn () => $service->complete($a['id'], ['version' => $a['version'] + 1], $office));
            $this->assertThrows(OrderValidationException::class, fn () => $this->measure($service, $b, ['order:fremd:knoten' => '1'], $office));
        });
    }

    public function testTechniciansMeasureOnlyReleasedOrdersAndCannotComplete(): void
    {
        $this->withOrder(function (array $env): void {
            ['service' => $service, 'office' => $office, 'technician' => $technician, 'order' => $order, 'keys' => $keys, 'orders' => $orders] = $env;
            $this->assertThrows(AccessDeniedException::class, fn () => $service->create(['orderId' => $order['id']], $technician));
            $orders->changeStatus($order['id'], 'open', 'in_progress');

            $own = $this->measure($service, $service->create(['orderId' => $order['id']], $technician), [$keys['meter'] => '10'], $technician);
            $this->assertTrue($own['canEdit'] && !$own['canComplete']);
            $this->assertThrows(AccessDeniedException::class, fn () => $service->complete($own['id'], ['version' => $own['version']], $technician));

            $foreign = $service->create(['orderId' => $order['id']], $office);
            $this->assertThrows(AccessDeniedException::class, fn () => $this->measure($service, $foreign, [$keys['meter'] => '1'], $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->delete($foreign['id'], $foreign['version'], $technician));

            $done = $service->complete($own['id'], ['version' => $own['version']], $office)['measurement'];
            $this->assertThrows(AccessDeniedException::class, fn () => $service->create(['correctionOf' => $done['id']], $technician));
            $this->assertSame(2, $service->page([], $technician)['total']);
            $this->assertSame(1, $service->page(['status' => 'completed'], $office)['total']);
        });
    }

    public function testMeasurementPdfShowsProgressAndArchivesCompletedState(): void
    {
        $this->withOrder(function (array $env): void {
            ['service' => $service, 'pdf' => $pdfService, 'office' => $office, 'order' => $order, 'keys' => $keys, 'pdo' => $pdo] = $env;
            $first = $this->measure($service, $service->create(['orderId' => $order['id']], $office), [$keys['meter'] => '30'], $office);
            $preview = $pdfService->pdf($first['id'], $office);
            $this->assertTrue(str_contains($preview['pdf'], '(ENTWURF) Tj') && $preview['fileName'] === 'Aufmass_Entwurf.pdf', 'Entwürfe nur als Vorschau.');
            $completed = $service->complete($first['id'], ['version' => $first['version']], $office)['measurement'];

            $second = $this->measure($service, $service->create(['orderId' => $order['id']], $office), [$keys['meter'] => '75', $keys['addendum'] => '5'], $office);
            $done = $service->complete($second['id'], ['version' => $second['version'], 'confirmOverrun' => true], $office)['measurement'];
            $document = $pdfService->pdf($done['id'], $office);
            $pdf = $document['pdf'];
            $this->assertTrue(str_contains($pdf, (string) $done['measurementNumber']) && !str_contains($pdf, 'ENTWURF'));
            $this->assertTrue(str_contains($pdf, 'NYM-J 3x1,5') && str_contains($pdf, 'Steckdose'), 'Positionen aus Auftragsgrundlage und Nachtrag.');
            $this->assertTrue(str_contains($pdf, '(30) Tj') && str_contains($pdf, '(75) Tj') && str_contains($pdf, '(105) Tj'), 'Bisher, dieses Aufmaß und Gesamt.');
            $this->assertTrue(str_contains($pdf, 'Mehrmenge 5 m \\(best'), 'Mehrmengen werden ausgewiesen.');
            $this->assertTrue(!str_contains($pdf, 'Abzweigdose'), 'Nur Positionen mit Menge in diesem Aufmaß.');
            $this->assertSame($pdf, $pdfService->pdf($done['id'], $office)['pdf'], 'Abgeschlossene Aufmaße werden archiviert und unverändert erneut ausgeliefert.');
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM generated_documents WHERE document_type = 'measurement'")->fetchColumn());

            $correction = $this->measure($service, $service->create(['correctionOf' => $completed['id']], $office), [$keys['meter'] => '-5'], $office);
            $corrected = $service->complete($correction['id'], ['version' => $correction['version']], $office)['measurement'];
            $correctionPdf = $pdfService->pdf($corrected['id'], $office)['pdf'];
            $this->assertTrue(str_contains($correctionPdf, 'Korrekturaufma') && str_contains($correctionPdf, (string) $completed['measurementNumber']), 'Korrekturaufmaß mit Bezug zum korrigierten Aufmaß.');
            $this->assertTrue(str_contains($correctionPdf, '(-5) Tj'));
        });
    }

    /**
     * Auftrag aus Angebot (10 % Nachlass): Titel mit 100 m Leitung zu 2,50 € und Bedarfsposition
     * (10 Stk. zu 15,00 €), Alternativtitel; dazu ein angenommener und ein nur abgegebener Nachtrag.
     *
     * @param callable(array<string,mixed>):void $test
     */
    private function withOrder(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-measurements-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);
            $now = '2026-11-10T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Kunde',:now,:now)")
                ->execute(['id' => $customer, 'now' => $now]);
            $office = $this->user($pdo, 'office', $now);
            $technician = $this->user($pdo, 'technician', $now);

            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);

            $meterId = Uuid::v4();
            $demandId = Uuid::v4();
            $offerId = $offerService->createDraft(null, 'Elektroinstallation', $customer);
            $etag = $this->saveNodes($offerService, $offerId, [
                ['id' => Uuid::v4(), 'type' => 'title', 'name' => 'Leitungen', 'description' => '', 'children' => [
                    $this->positionNode($meterId, 'NYM-J 3x1,5', '100.000', 'm', '2.50'),
                    $this->positionNode($demandId, 'Abzweigdose', '10.000', 'Stk.', '15.00', 'bedarf'),
                ]],
                ['id' => Uuid::v4(), 'type' => 'title', 'titleType' => 'alternative', 'name' => 'Alternative', 'description' => '', 'children' => [
                    $this->positionNode(Uuid::v4(), 'Aufputz statt Unterputz', '1.000', 'psch', '99.00'),
                ]],
            ], ['type' => 'discount', 'mode' => 'percentage', 'value' => '10.00', 'label' => 'Nachlass']);
            $offerService->submitOffer($offerId, $etag, null, '2026-11-09', true);
            $offerService->changeStatus($offerId, 'submitted', 'commissioned');
            $order = $orders->createFromOffer($offerId);

            $addendumNode = Uuid::v4();
            $changeOrder = $orders->createChangeOrder($order['id'], 'Zusätzliche Steckdosen');
            $changeEtag = $this->saveNodes($offerService, $changeOrder['id'], [$this->positionNode($addendumNode, 'Steckdose', '5.000', 'Stk.', '40.00')]);
            $offerService->submitOffer($changeOrder['id'], $changeEtag, null, '2026-11-10', true);
            $orders->acceptChangeOrder($changeOrder['id'], 'submitted');
            $pending = $orders->createChangeOrder($order['id'], 'Noch nicht angenommen');
            $pendingEtag = $this->saveNodes($offerService, $pending['id'], [$this->positionNode(Uuid::v4(), 'Unverbindlich', '1.000', 'Stk.', '10.00')]);
            $offerService->submitOffer($pending['id'], $pendingEtag, null, '2026-11-10', true);

            $addendumId = (string) $pdo->query('SELECT id FROM order_addenda')->fetchColumn();
            $measurementService = new MeasurementService($pdo, new MeasurementRepository($pdo), new OrderRepository($pdo), $numbers);
            $test([
                'pdo' => $pdo,
                'pdf' => new MeasurementPdfService($measurementService, new MeasurementRepository($pdo), new DocumentSettingsRepository($pdo),
                    new DocumentArchive(new GeneratedDocumentRepository($pdo), new AttachmentStore($tmp . '/documents'))),
                'service' => $measurementService,
                'orders' => $orders,
                'order' => $order,
                'office' => $office,
                'technician' => $technician,
                'customer' => $customer,
                'keys' => [
                    'meter' => 'order:' . $order['id'] . ':' . $meterId,
                    'demand' => 'order:' . $order['id'] . ':' . $demandId,
                    'addendum' => 'addendum:' . $addendumId . ':' . $addendumNode,
                ],
            ]);
        } finally {
            $this->remove($tmp);
        }
    }

    /** @param array<string,mixed> $measurement @param array<string,string> $quantities @param array<string,mixed> $actor @return array<string,mixed> */
    private function measure(MeasurementService $service, array $measurement, array $quantities, array $actor): array
    {
        $items = [];
        foreach ($quantities as $key => $quantity) {
            $items[] = ['key' => $key, 'quantity' => $quantity];
        }
        return $service->update($measurement['id'], [
            'version' => $measurement['version'],
            'title' => $measurement['title'],
            'measurementDate' => $measurement['measurementDate'],
            'notes' => '',
            'items' => $items,
        ], $actor);
    }

    /** Positionsknoten für ein Angebotsdokument mit manuellem Verkaufspreis. @return array<string,mixed> */
    private function positionNode(string $id, string $shortText, string $quantity, string $unit, string $price, string $type = 'normal'): array
    {
        return ['id' => $id, 'type' => 'position', 'positionType' => $type, 'shortText' => $shortText, 'longText' => '',
            'quantity' => $quantity, 'unit' => $unit, 'manualUnitPrice' => $price, 'components' => []];
    }

    /** Sucht eine Position im Aufmaß-Positionsbaum. @param array<string,mixed> $measurement @return array<string,mixed> */
    private function position(array $measurement, string $key): array
    {
        $find = static function (array $nodes) use (&$find, $key): ?array {
            foreach ($nodes as $node) {
                if ($node['kind'] === 'title' && ($found = $find($node['children'])) !== null) {
                    return $found;
                }
                if ($node['kind'] === 'position' && $node['key'] === $key) {
                    return $node;
                }
            }
            return null;
        };
        foreach ($measurement['sources'] as $source) {
            if (($found = $find($source['nodes'])) !== null) {
                return $found;
            }
        }
        throw new \RuntimeException('Position nicht gefunden: ' . $key);
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
