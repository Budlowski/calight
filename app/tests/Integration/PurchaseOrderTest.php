<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\MasterDataService;
use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\PurchaseOrderPdfService;
use App\Application\PurchaseOrderService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\MasterData\MasterDataValidationException;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\MasterDataRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\PurchaseOrderRepository;
use App\Security\AuthorizationPolicy;
use App\Security\RolePermissions;
use App\Support\Uuid;
use App\Tests\TestCase;
use PDO;

/** Phase 3.4.1 und 3.4.1.1: Bestellverwaltung als Vorgang. */
final class PurchaseOrderTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    public function testPermissionsKeepPurchasesWithTheOffice(): void
    {
        $this->assertTrue(RolePermissions::allows('office', 'purchases.read') && RolePermissions::allows('office', 'purchases.write'));
        $this->assertTrue(!RolePermissions::allows('technician', 'purchases.read'), 'Techniker sehen keine Bestellungen.');
        $policy = static fn (string $method, string $path): array => AuthorizationPolicy::required(new Request($method, $path, [], [], [], ''));
        $this->assertSame(['purchases.read'], $policy('GET', '/purchase-orders'));
        $this->assertSame(['purchases.read'], $policy('GET', '/api/purchase-orders/x/documents/y'));
        $this->assertSame(['purchases.write'], $policy('POST', '/api/purchase-orders/x/issue'));
        $this->assertSame(['purchases.write'], $policy('POST', '/api/purchase-orders/suppliers'));
        $this->assertSame(['purchases.write'], $policy('POST', '/api/purchase-orders/x/confirmations'));
        $this->assertSame(['purchases.write'], $policy('POST', '/api/purchase-orders/x/deliveries'));
        $this->assertSame(['purchases.write', 'costs.read'], $policy('POST', '/api/purchase-orders/x/invoices'));
        $this->assertSame(['purchases.write', 'costs.read'], $policy('POST', '/api/purchase-orders/x/payments'));
        $this->assertSame(['purchases.write', 'costs.read'], $policy('PUT', '/api/purchase-orders/x/actual-cost'));
        $this->assertSame(['purchases.write', 'costs.read'], $policy('PUT', '/api/purchase-orders/x/allocations'));
    }

    public function testNewSupplierWithAddressAndCustomerNumberIsUsedForAnOrder(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'masterData' => $masterData, 'office' => $office, 'pdo' => $pdo] = $env;

            // Schnellanlage: nur der Name ist Pflicht.
            $minimal = $masterData->createSupplier(['name' => 'Baumarkt Nord'], true);
            $this->assertSame('', $minimal['street']);
            $this->assertThrows(MasterDataValidationException::class, fn () => $masterData->createSupplier(['name' => ''], true));
            $this->assertThrows(MasterDataValidationException::class, fn () => $masterData->createSupplier(['name' => 'baumarkt nord'], true), 'Keine Doppelanlage gleichen Namens.');

            $supplier = $masterData->createSupplier([
                'name' => 'Kabelwerk Ost GmbH', 'street' => 'Hafenstraße', 'houseNumber' => '12a', 'postalCode' => '04109',
                'city' => 'Leipzig', 'country' => 'Deutschland', 'customerNumber' => 'KD-778899',
            ], true);
            $this->assertSame('KD-778899', $supplier['customerNumber']);
            $this->assertSame('12a', $supplier['houseNumber']);

            // Älterer JSON-Import ohne Anschriftsfelder leert vorhandene Werte nicht.
            $masterData->importScope('suppliers', ['format' => 'calight-master-data', 'schemaVersion' => 1, 'scope' => 'suppliers', 'items' => [
                ['id' => $supplier['id'], 'name' => 'Kabelwerk Ost GmbH', 'contact' => 'Herr Krause', 'active' => true],
            ]]);
            $this->assertSame('Leipzig', (string) $pdo->query('SELECT city FROM suppliers WHERE id = ' . $pdo->quote($supplier['id']))->fetchColumn());

            // Bestellung mit dem neuen Lieferanten: Anschrift und Kundennummer im Snapshot und im PDF.
            $draft = $service->createInternal(['supplierId' => $supplier['id']], $office);
            $ready = $service->update($draft['id'], ['version' => $draft['version'], 'items' => [['description' => 'NYM-J 5x2,5', 'quantity' => '100', 'unit' => 'm']]], $office);
            $issued = $service->issue($ready['id'], ['version' => $ready['version']], $office);
            $this->assertSame('KD-778899', $issued['supplierSnapshot']['customerNumber']);
            $this->assertSame('04109', $issued['supplierSnapshot']['postalCode']);
            $pdf = $service->pdf($issued['id'], $office)['pdf'];
            $this->assertTrue(str_contains($pdf, 'KD-778899') && str_contains($pdf, 'Leipzig'), 'Kundennummer und Anschrift stehen auf dem Bestell-PDF.');

            // Spätere Stammdatenänderung ändert die ausgegebene Bestellung nicht.
            $masterData->updateSupplier($supplier['id'], ['name' => 'Kabelwerk Ost GmbH', 'city' => 'Halle', 'customerNumber' => 'NEU']);
            $after = $service->detail($issued['id'], $office);
            $this->assertSame('Leipzig', $after['supplierSnapshot']['city']);
            $this->assertSame('KD-778899', $after['supplierSnapshot']['customerNumber']);
            $this->assertSame($pdf, $service->pdf($issued['id'], $office)['pdf'], 'Archiviertes Bestell-PDF bleibt byte-identisch.');
            // Neue Bestellungen verwenden dagegen die aktuellen Stammdaten.
            $next = $service->createInternal(['supplierId' => $supplier['id']], $office);
            $next = $service->update($next['id'], ['version' => $next['version'], 'items' => [['description' => 'Kabel', 'quantity' => '1']]], $office);
            $this->assertSame('NEU', $service->issue($next['id'], ['version' => $next['version']], $office)['supplierSnapshot']['customerNumber']);
        });
    }

    public function testInternalOrderWithPositionsIsIssuedAsImmutablePdfAndRevised(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'technician' => $technician, 'supplier' => $supplier, 'item' => $item, 'regie' => $regie, 'pdo' => $pdo, 'documents' => $documents] = $env;

            $this->assertThrows(AccessDeniedException::class, fn () => $service->createInternal(['supplierId' => $supplier], $technician));
            $this->assertThrows(OrderValidationException::class, fn () => $service->createInternal([], $office), 'Lieferant ist Pflicht.');

            $draft = $service->createInternal(['supplierId' => $supplier, 'orderId' => $regie['id'], 'title' => 'Material Werkhalle'], $office);
            $this->assertSame('draft', $draft['status']);
            $this->assertTrue(!$draft['placed']);
            $this->assertTrue(str_starts_with((string) $draft['orderNumber'], 'BE'), 'Bestellnummer aus dem Nummernkreis „Bestellungen“.');
            $second = $service->createInternal(['supplierId' => $supplier], $office);
            $this->assertTrue($second['orderNumber'] !== $draft['orderNumber'], 'Bestellnummern sind eindeutig.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->issue($draft['id'], ['version' => $draft['version']], $office), 'Ohne Position kein Bestell-PDF.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->addDelivery($draft['id'], ['version' => $draft['version'], 'kind' => 'complete', 'deliveryDate' => '2026-11-20'], null, $office), 'Vorgänge erst nach dem Bestellen.');

            $saved = $service->update($draft['id'], ['version' => $draft['version'], 'items' => [
                ['description' => 'Kabelkanal 40x60', 'quantity' => '12', 'unit' => 'm', 'unitPrice' => '3,4567', 'costItemId' => $item],
                ['description' => 'Abzweigdose', 'quantity' => '20', 'unit' => 'Stk.'],
            ]], $office);
            $this->assertSame('41.48', $saved['items'][0]['lineTotal'], '12 × 3,4567 € = 41,4804 € → 41,48 €.');
            $this->assertSame('ART-2', $saved['items'][0]['articleNumber'], 'Lieferanten-Artikelnummer aus dem Stamm.');
            $this->assertSame('41.48', $saved['expectedNetTotal'], 'Bestellwert aus den Positionspreisen.');
            $this->assertSame('edit', $saved['history'][0]['field'], 'Änderungen werden protokolliert.');

            $issued = $service->issue($saved['id'], ['version' => $saved['version']], $office);
            $this->assertSame('ordered', $issued['status']);
            $this->assertSame(1, $issued['revision']);
            $first = $service->pdf($issued['id'], $office);
            $this->assertTrue(str_starts_with($first['pdf'], '%PDF') && str_contains($first['pdf'], 'Kabelkanal 40x60') && !str_contains($first['pdf'], 'ENTWURF'));

            $pdo->prepare("UPDATE suppliers SET name = 'Umbenannt GmbH' WHERE id = :id")->execute(['id' => $supplier]);
            $this->assertSame($first['pdf'], $service->pdf($issued['id'], $office)['pdf'], 'Ausgegebenes PDF bleibt unverändert archiviert.');

            $locked = $service->update($issued['id'], ['version' => $issued['version'], 'items' => [['description' => 'Anders', 'quantity' => '1']]], $office);
            $this->assertSame('Kabelkanal 40x60', $locked['items'][0]['description'], 'Positionen ausgegebener Bestellungen bleiben unverändert.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->delete($locked['id'], $locked['version'], $office));

            $reopened = $service->newRevision($locked['id'], ['version' => $locked['version'], 'note' => 'Menge erhöht'], $office);
            $this->assertTrue($reopened['placed'] && $reopened['permissions']['events'], 'Vorgänge bleiben während einer neuen Fassung möglich.');
            $changed = $service->update($reopened['id'], ['version' => $reopened['version'], 'items' => [
                ['description' => 'Kabelkanal 40x60', 'quantity' => '24', 'unit' => 'm', 'unitPrice' => '3.4567'],
            ]], $office);
            $revised = $service->issue($changed['id'], ['version' => $changed['version']], $office);
            $own = array_values(array_filter($revised['documents'], static fn (array $document): bool => $document['kind'] === 'own_order'));
            $this->assertSame(2, count($own), 'Beide Fassungen bleiben archiviert.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->deleteDocument($revised['id'], $own[0]['id'], $office));
            foreach ($pdo->query('SELECT storage_path FROM purchase_order_documents')->fetchAll(PDO::FETCH_COLUMN) as $path) {
                $this->assertTrue(is_file($documents . '/' . $path) && str_starts_with((string) $path, 'purchase-orders/'));
            }

            $service->delete($second['id'], $second['version'], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $service->detail($second['id'], $office));
        });
    }

    public function testMaterialRequestsAreNoLongerTakenOverButExistingLinksStay(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'regie' => $regie, 'pdo' => $pdo] = $env;
            $request = $this->materialRequest($pdo, $regie['id'], $office['id'], '20 m NYM-J 3x1,5');
            $this->assertTrue(!array_key_exists('materialRequests', $service->referenceData($office)), 'Keine Übernahme mehr angeboten.');
            $order = $service->createInternal(['supplierId' => $supplier, 'materialRequestIds' => [$request]], $office);
            $this->assertSame([], $order['items'], 'Materialanforderungen erzeugen keine Positionen mehr.');
            $this->assertSame([], $order['materialRequests']);

            // Bestandsverknüpfung aus Phase 3.4.1 bleibt lesbar.
            $pdo->prepare('INSERT INTO purchase_order_material_requests (purchase_order_id, material_request_id) VALUES (:order, :request)')
                ->execute(['order' => $order['id'], 'request' => $request]);
            $this->assertSame('20 m NYM-J 3x1,5', $service->detail($order['id'], $office)['materialRequests'][0]['description']);
        });
    }

    public function testConfirmationsKeepHistoryAndDoNotChangeOriginalValue(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'tmp' => $tmp] = $env;
            $order = $service->createExternal(['supplierId' => $supplier, 'expectedNetTotal' => '1000.00'], $office);

            $first = $service->addConfirmation($order['id'], ['version' => $order['version'], 'confirmationNumber' => 'AB-1', 'confirmationDate' => '2026-11-16',
                'confirmedNetTotal' => '980,00', 'confirmedDeliveryDate' => '2026-11-30'], $this->upload($tmp, 'AB-1.pdf'), $office);
            $this->assertSame('980.00', $first['confirmedNetTotal']);
            $this->assertSame('1000.00', $first['expectedNetTotal'], 'Ursprünglicher Bestellwert bleibt unverändert.');
            $this->assertSame(null, $first['actualNetTotal'], 'Bestätigter Wert ist keine Ist-Kosten.');
            $this->assertTrue($first['confirmations'][0]['current'] && $first['confirmations'][0]['document'] !== null);
            $this->assertSame('order_confirmation', $first['documents'][0]['kind']);
            $this->assertTrue(!$first['documents'][0]['canDelete'], 'Belege eines Vorgangs bleiben erhalten.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->deleteDocument($first['id'], $first['documents'][0]['id'], $office));

            // Korrigierte AB wird aktuell, die frühere bleibt erhalten.
            $corrected = $service->addConfirmation($first['id'], ['version' => $first['version'], 'confirmationNumber' => 'AB-1a', 'confirmationDate' => '2026-11-18', 'confirmedNetTotal' => '1010.00'], null, $office);
            $this->assertSame(2, count($corrected['confirmations']));
            $this->assertSame('1010.00', $corrected['confirmedNetTotal']);
            $this->assertSame(1, count(array_filter($corrected['confirmations'], static fn (array $entry): bool => $entry['current'])));

            // Weitere AB ohne Aktualisierung, dann ausdrücklich die erste wieder als aktuell markieren.
            $extra = $service->addConfirmation($corrected['id'], ['version' => $corrected['version'], 'confirmationDate' => '2026-11-19', 'confirmedNetTotal' => '5.00', 'makeCurrent' => '0'], null, $office);
            $this->assertSame('1010.00', $extra['confirmedNetTotal']);
            $old = array_values(array_filter($extra['confirmations'], static fn (array $entry): bool => $entry['number'] === 'AB-1'))[0];
            $back = $service->markCurrentConfirmation($extra['id'], $old['id'], ['version' => $extra['version']], $office);
            $this->assertSame('980.00', $back['confirmedNetTotal']);
            $this->assertSame('980.00', $service->page(['q' => $back['orderNumber']], $office)['items'][0]['confirmedNetTotal'], 'Liste zeigt die aktuelle AB.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->addConfirmation($back['id'], ['version' => $back['version']], null, $office), 'Datum ist Pflicht.');
        });
    }

    public function testDeliveriesReturnsAndCorrectionsDeriveTheDeliveryStatus(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'tmp' => $tmp] = $env;
            $order = $service->createExternal(['supplierId' => $supplier], $office);
            $this->assertSame('pending', $order['deliveryStatus']);

            $partial = $service->addDelivery($order['id'], ['version' => $order['version'], 'kind' => 'partial', 'deliveryDate' => '2026-11-17', 'deliveryNoteNumber' => 'LS-1'], $this->upload($tmp, 'LS-1.pdf'), $office);
            $this->assertSame('partial', $partial['deliveryStatus']);
            $this->assertSame('delivery_note', $partial['documents'][0]['kind']);
            $second = $service->addDelivery($partial['id'], ['version' => $partial['version'], 'kind' => 'partial', 'deliveryDate' => '2026-11-18'], null, $office);
            $complete = $service->addDelivery($second['id'], ['version' => $second['version'], 'kind' => 'complete', 'deliveryDate' => '2026-11-19'], null, $office);
            $this->assertSame('delivered', $complete['deliveryStatus'], 'Vollständige Lieferung ohne Mengenabgleich.');
            $this->assertSame(3, count($complete['deliveries']));

            // Rücksendung: Status ausdrücklich zurück auf teilgeliefert; Kosten unverändert.
            $this->assertThrows(OrderValidationException::class, fn () => $service->addDelivery($complete['id'], ['version' => $complete['version'], 'kind' => 'return', 'deliveryDate' => '2026-11-20'], null, $office), 'Status nach Rücksendung muss gewählt werden.');
            $returned = $service->addDelivery($complete['id'], ['version' => $complete['version'], 'kind' => 'return', 'deliveryDate' => '2026-11-20', 'resultingStatus' => 'partial', 'notes' => '2 Leuchten defekt'], null, $office);
            $this->assertSame('partial', $returned['deliveryStatus']);
            $this->assertSame(null, $returned['actualNetTotal'], 'Rücksendung verändert keine Kosten.');
            $this->assertSame('Lieferstatus: Geliefert → Teilgeliefert', $returned['history'][0]['label']);

            // Lieferkorrektur nach Fehlerfassung.
            $corrected = $service->addDelivery($returned['id'], ['version' => $returned['version'], 'kind' => 'correction', 'deliveryDate' => '2026-11-21', 'resultingStatus' => 'delivered'], null, $office);
            $this->assertSame('delivered', $corrected['deliveryStatus']);
            $this->assertThrows(OrderValidationException::class, fn () => $service->addDelivery($corrected['id'], ['version' => $corrected['version'] - 1, 'kind' => 'partial', 'deliveryDate' => '2026-11-21'], null, $office), 'Konfliktschutz über die Version.');
            $this->assertSame(1, $service->page(['delivery' => 'delivered'], $office)['total']);
        });
    }

    public function testPartialAndFinalInvoicesAreNeverCountedTwice(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier] = $env;
            $invoice = fn (array $order, array $data): array => $service->addInvoice($order['id'], ['version' => $order['version'], 'invoiceDate' => '2026-11-20'] + $data, null, $office);

            // Variante 1: Schlussrechnung als Gesamtrechnung einschließlich der Teilrechnungen.
            $total = $service->createExternal(['supplierId' => $supplier, 'expectedNetTotal' => '600.00'], $office);
            $total = $invoice($total, ['kind' => 'partial', 'invoiceNumber' => 'T-1', 'netAmount' => '300.00', 'vatAmount' => '57.00']);
            $total = $invoice($total, ['kind' => 'partial', 'invoiceNumber' => 'T-2', 'netAmount' => '200.00']);
            $this->assertSame('500.00', $total['actualNetTotal']);
            $this->assertThrows(OrderValidationException::class, fn () => $invoice($total, ['kind' => 'final', 'invoiceNumber' => 'S-1', 'netAmount' => '620.00']), 'Erfassungsart der Schlussrechnung ist Pflicht.');
            $total = $invoice($total, ['kind' => 'final', 'finalMode' => 'total', 'invoiceNumber' => 'S-1', 'netAmount' => '620.00']);
            $this->assertSame('620.00', $total['actualNetTotal'], 'Teilrechnungen werden von der Gesamtrechnung abgezogen.');
            $this->assertSame('120.00', $total['invoices'][2]['effect']);
            $this->assertSame('600.00', $total['expectedNetTotal']);
            $this->assertThrows(OrderValidationException::class, fn () => $invoice($total, ['kind' => 'partial', 'invoiceNumber' => 'T-3', 'netAmount' => '10.00']), 'Nach Gesamt-Schlussrechnung keine weiteren Teilrechnungen.');
            $this->assertThrows(OrderValidationException::class, fn () => $invoice($total, ['kind' => 'final', 'finalMode' => 'total', 'invoiceNumber' => 'S-2', 'netAmount' => '1.00']));

            // Variante 2: Schlussrechnung über den Restbetrag.
            $rest = $service->createExternal(['supplierId' => $supplier], $office);
            $rest = $invoice($rest, ['kind' => 'partial', 'invoiceNumber' => 'T-1', 'netAmount' => '300.00']);
            $rest = $invoice($rest, ['kind' => 'final', 'finalMode' => 'additional', 'invoiceNumber' => 'S-1', 'netAmount' => '120.00']);
            $this->assertSame('420.00', $rest['actualNetTotal']);

            // Eingaben: positive Beträge, Brutto = Netto + USt., Belegnummer Pflicht.
            $this->assertThrows(OrderValidationException::class, fn () => $invoice($rest, ['kind' => 'invoice', 'invoiceNumber' => 'X', 'netAmount' => '-5']));
            $this->assertThrows(OrderValidationException::class, fn () => $invoice($rest, ['kind' => 'invoice', 'invoiceNumber' => 'X', 'netAmount' => '10.00', 'vatAmount' => '1.90', 'grossAmount' => '99.00']));
            $this->assertThrows(OrderValidationException::class, fn () => $invoice($rest, ['kind' => 'invoice', 'netAmount' => '10.00']));
        });
    }

    public function testCreditNotesAndReversalsCorrectCostsWithoutDeleting(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'tmp' => $tmp] = $env;
            $reverse = fn (array $order, string $related, string $amount): array => $service->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'reversal',
                'invoiceDate' => '2026-11-23', 'netAmount' => $amount, 'relatedInvoiceId' => $related], null, $office);
            $order = $service->createExternal(['supplierId' => $supplier], $office);
            $order = $service->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'invoice', 'invoiceNumber' => 'R-1', 'invoiceDate' => '2026-11-20',
                'netAmount' => '400.00', 'vatAmount' => '76.00'], $this->upload($tmp, 'R-1.pdf'), $office);
            $this->assertSame('400.00', $order['actualNetTotal']);
            $this->assertSame('invoice', $order['documents'][0]['kind']);
            $original = $order['invoices'][0]['id'];

            // Gutschrift (z. B. nach Rücksendung) mindert die Kosten.
            $credited = $service->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'credit', 'invoiceNumber' => 'G-1', 'invoiceDate' => '2026-11-22',
                'netAmount' => '50.00', 'relatedInvoiceId' => $original], null, $office);
            $this->assertSame('350.00', $credited['actualNetTotal']);
            $this->assertSame('-50.00', $credited['invoices'][1]['effect']);
            $credit = $credited['invoices'][1]['id'];

            // Teilstorno der Rechnung; Original bleibt erhalten.
            $this->assertThrows(OrderValidationException::class, fn () => $service->addInvoice($credited['id'], ['version' => $credited['version'], 'kind' => 'reversal', 'invoiceDate' => '2026-11-23', 'netAmount' => '10.00'], null, $office), 'Storno braucht den Originalbeleg.');
            $this->assertThrows(OrderValidationException::class, fn () => $reverse($credited, $original, '400.01'), 'Nicht mehr als der Originalbetrag.');
            $partly = $reverse($credited, $original, '100.00');
            $this->assertSame('250.00', $partly['actualNetTotal']);
            $this->assertSame('300.00', $partly['invoices'][0]['remaining']);

            // Vollständiges Storno würde zusammen mit der Gutschrift negative Kosten ergeben: abgelehnt.
            $this->assertThrows(OrderValidationException::class, fn () => $reverse($partly, $original, '300.00'));
            // Erst die Gutschrift stornieren, dann die Rechnung vollständig.
            $creditReversed = $reverse($partly, $credit, '50.00');
            $this->assertSame('300.00', $creditReversed['actualNetTotal']);
            $none = $reverse($creditReversed, $original, '300.00');
            $this->assertSame('0.00', $none['actualNetTotal']);
            $this->assertSame(5, count($none['invoices']), 'Alle Belege bleiben erhalten (Rechnung, Gutschrift, drei Stornobelege).');
            $this->assertSame('0.00', $none['invoices'][0]['remaining']);
            $this->assertThrows(OrderValidationException::class, fn () => $reverse($none, $original, '1.00'), 'Bereits vollständig storniert.');
            $this->assertThrows(OrderValidationException::class, fn () => $reverse($none, $none['invoices'][2]['id'], '1.00'), 'Stornobelege selbst sind nicht stornierbar.');
        });
    }

    public function testDirectCostCanBeReplacedByInvoicesOnlyExplicitly(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier] = $env;
            $order = $service->createExternal(['supplierId' => $supplier], $office);
            $direct = $service->setActualCost($order['id'], ['version' => $order['version'], 'amount' => '89,90'], $office);
            $this->assertSame('89.90', $direct['actualNetTotal']);
            $this->assertSame('direct', $direct['costSource']);

            $input = ['version' => $direct['version'], 'kind' => 'invoice', 'invoiceNumber' => 'R-9', 'invoiceDate' => '2026-11-20', 'netAmount' => '92.00'];
            $this->assertThrows(OrderValidationException::class, fn () => $service->addInvoice($direct['id'], $input, null, $office), 'Umstellung nur ausdrücklich.');
            $switched = $service->addInvoice($direct['id'], $input + ['replaceDirectCost' => true], null, $office);
            $this->assertSame('92.00', $switched['actualNetTotal'], 'Kein Aufaddieren von direktem Betrag und Beleg.');
            $this->assertSame(null, $switched['directActualNetTotal']);
            $this->assertSame('invoices', $switched['costSource']);
            $this->assertSame('cost', $switched['history'][1]['field'], 'Umstellung wird protokolliert.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->setActualCost($switched['id'], ['version' => $switched['version'], 'amount' => '10.00'], $office), 'Direkter Betrag neben Belegen wird abgelehnt.');
        });
    }

    public function testPaymentsAndRefundsAreIndependentOfCosts(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier] = $env;
            $order = $service->createExternal(['supplierId' => $supplier], $office);
            $order = $service->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'invoice', 'invoiceNumber' => 'R-1', 'invoiceDate' => '2026-11-20',
                'netAmount' => '100.00', 'vatAmount' => '19.00'], null, $office);

            // Onlinekauf: bezahlt, aber noch nicht geliefert.
            $partial = $service->addPayment($order['id'], ['version' => $order['version'], 'kind' => 'payment', 'paymentDate' => '2026-11-20', 'amount' => '50.00'], $office);
            $this->assertSame('partial', $partial['paymentStatus']);
            $paid = $service->addPayment($partial['id'], ['version' => $partial['version'], 'kind' => 'payment', 'paymentDate' => '2026-11-21', 'amount' => '69.00', 'complete' => true], $office);
            $this->assertSame('paid', $paid['paymentStatus']);
            $this->assertSame('pending', $paid['deliveryStatus'], 'Bezahlt und noch nicht geliefert ist möglich.');
            $this->assertSame('119.00', $paid['paidTotal']);
            $this->assertSame('0.00', $paid['openGrossTotal']);
            $this->assertSame('100.00', $paid['actualNetTotal'], 'Zahlungen verändern die Ist-Kosten nicht.');

            // Gutschrift ist kein Geldrückfluss; die Rückerstattung wird eigens vermerkt.
            $credit = $service->addInvoice($paid['id'], ['version' => $paid['version'], 'kind' => 'credit', 'invoiceNumber' => 'G-1', 'invoiceDate' => '2026-11-25', 'netAmount' => '20.00', 'grossAmount' => '23.80'], null, $office);
            $this->assertSame('80.00', $credit['actualNetTotal']);
            $this->assertSame('paid', $credit['paymentStatus']);
            $this->assertSame('-23.80', $credit['openGrossTotal'], 'Guthaben beim Lieferanten wird sichtbar.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->addPayment($credit['id'], ['version' => $credit['version'], 'kind' => 'refund', 'paymentDate' => '2026-11-26', 'amount' => '23.80'], $office), 'Status nach Rückerstattung wählen.');
            $refund = $service->addPayment($credit['id'], ['version' => $credit['version'], 'kind' => 'refund', 'paymentDate' => '2026-11-26', 'amount' => '23.80', 'resultingStatus' => 'paid'], $office);
            $this->assertSame('95.20', $refund['paidTotal']);
            $this->assertSame('0.00', $refund['openGrossTotal']);
            $this->assertSame('80.00', $refund['actualNetTotal']);
            $this->assertThrows(OrderValidationException::class, fn () => $service->addPayment($refund['id'], ['version' => $refund['version'], 'kind' => 'payment', 'paymentDate' => '2026-11-26', 'amount' => '0'], $office));
            $correction = $service->addPayment($refund['id'], ['version' => $refund['version'], 'kind' => 'correction', 'paymentDate' => '2026-11-27', 'resultingStatus' => 'open'], $office);
            $this->assertSame('open', $correction['paymentStatus']);
            $this->assertSame(null, $correction['payments'][0]['amount']);
        });
    }

    public function testCancelledOrderKeepsCostsAndAllowsLaterCredits(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'regie' => $regie] = $env;
            $order = $service->createExternal(['supplierId' => $supplier, 'orderId' => $regie['id']], $office);
            $order = $service->addDelivery($order['id'], ['version' => $order['version'], 'kind' => 'partial', 'deliveryDate' => '2026-11-18'], null, $office);
            $order = $service->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'partial', 'invoiceNumber' => 'T-1', 'invoiceDate' => '2026-11-18', 'netAmount' => '250.00'], null, $office);

            $cancelled = $service->cancel($order['id'], ['version' => $order['version'], 'note' => 'Rest nicht mehr lieferbar'], $office);
            $this->assertSame('cancelled', $cancelled['status']);
            $this->assertSame('250.00', $cancelled['actualNetTotal'], 'Stornierung setzt Ist-Kosten nicht auf null.');
            $this->assertSame('partial', $cancelled['deliveryStatus']);
            $this->assertSame('Rest nicht mehr lieferbar', $cancelled['history'][0]['note']);
            $this->assertTrue(!$cancelled['permissions']['newRevision'] && !$cancelled['permissions']['edit'], 'Keine neuen Fassungen nach Stornierung.');
            $this->assertSame('250.00', $service->costsByOrder($regie['id'], $office)['total'], 'Stornierte Bestellungen bleiben in der Nachkalkulation.');

            $credited = $service->addInvoice($cancelled['id'], ['version' => $cancelled['version'], 'kind' => 'credit', 'invoiceNumber' => 'G-1', 'invoiceDate' => '2026-11-25', 'netAmount' => '50.00'], null, $office);
            $this->assertSame('200.00', $credited['actualNetTotal']);
            $this->assertSame('200.00', $service->costsByOrder($regie['id'], $office)['total']);
            $this->assertSame(1, $service->page(['status' => 'cancelled'], $office)['total']);
        });
    }

    public function testAllocationsAreRevalidatedWhenCostsChange(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'regie' => $regie, 'second' => $secondOrder] = $env;
            $single = $service->createExternal(['supplierId' => $supplier, 'orderId' => $regie['id']], $office);
            $service->setActualCost($single['id'], ['version' => $single['version'], 'amount' => '120.00'], $office);
            $this->assertSame('120.00', $service->costsByOrder($regie['id'], $office)['total']);
            $this->assertSame('order', $service->costsByOrder($regie['id'], $office)['items'][0]['source']);

            $shared = $service->createExternal(['supplierId' => $supplier], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $service->saveAllocations($shared['id'], ['version' => $shared['version'], 'allocations' => [['orderId' => $regie['id'], 'amount' => '10']]], $office), 'Verteilung erst mit bekannten Ist-Kosten.');
            $shared = $service->addInvoice($shared['id'], ['version' => $shared['version'], 'kind' => 'invoice', 'invoiceNumber' => 'S-1', 'invoiceDate' => '2026-11-18', 'netAmount' => '300.00'], null, $office);
            $this->assertThrows(OrderValidationException::class, fn () => $service->saveAllocations($shared['id'], ['version' => $shared['version'], 'allocations' => [
                ['orderId' => $regie['id'], 'amount' => '200'], ['orderId' => $secondOrder['id'], 'amount' => '150'],
            ]], $office), 'Summe darf die Ist-Kosten nicht überschreiten.');
            $split = $service->saveAllocations($shared['id'], ['version' => $shared['version'], 'allocations' => [
                ['orderId' => $regie['id'], 'amount' => '180,00'], ['orderId' => $secondOrder['id'], 'amount' => '100.00'],
            ]], $office);
            $this->assertSame('20.00', $split['unallocatedTotal']);
            $this->assertSame('300.00', $service->costsByOrder($regie['id'], $office)['total'], '120 € direkt + 180 € aus der Verteilung.');

            // Kleine Gutschrift bleibt innerhalb der Verteilung.
            $credit = $service->addInvoice($split['id'], ['version' => $split['version'], 'kind' => 'credit', 'invoiceNumber' => 'G-1', 'invoiceDate' => '2026-11-20', 'netAmount' => '20.00'], null, $office);
            $this->assertSame('280.00', $credit['actualNetTotal']);
            $this->assertSame('0.00', $credit['unallocatedTotal']);
            $this->assertTrue(!$credit['allocationExceeded']);

            // Eine echte Gutschrift wird immer erfasst; die Verteilung wird dann sichtbar ungültig, nie still gekürzt.
            $over = $service->addInvoice($credit['id'], ['version' => $credit['version'], 'kind' => 'credit', 'invoiceNumber' => 'G-2', 'invoiceDate' => '2026-11-21', 'netAmount' => '30.00'], null, $office);
            $this->assertSame('250.00', $over['actualNetTotal']);
            $this->assertTrue($over['allocationExceeded'], 'Überverteilung wird erkannt.');
            $this->assertSame('-30.00', $over['unallocatedTotal']);
            $this->assertSame('180.00', $over['allocations'][0]['amount'], 'Verteilung bleibt unverändert, bis sie korrigiert wird.');
            $this->assertTrue(in_array('exceeded', array_column($over['history'], 'newValue'), true), 'Ungültige Verteilung wird protokolliert.');
            $flagged = array_values(array_filter($service->costsByOrder($regie['id'], $office)['items'], static fn (array $item): bool => $item['source'] === 'allocation'));
            $this->assertTrue($flagged[0]['allocationExceeded'], 'costsByOrder kennzeichnet die ungültige Verteilung.');

            // Korrektur: Summe wieder höchstens Ist-Kosten.
            $this->assertThrows(OrderValidationException::class, fn () => $service->saveAllocations($over['id'], ['version' => $over['version'], 'allocations' => [
                ['orderId' => $regie['id'], 'amount' => '180.00'], ['orderId' => $secondOrder['id'], 'amount' => '100.00'],
            ]], $office), 'Gespeicherte Verteilungen dürfen die Ist-Kosten nie übersteigen.');
            $fixed = $service->saveAllocations($over['id'], ['version' => $over['version'], 'allocations' => [
                ['orderId' => $regie['id'], 'amount' => '150.00'], ['orderId' => $secondOrder['id'], 'amount' => '100.00'],
            ]], $office);
            $this->assertTrue(!$fixed['allocationExceeded']);
            $this->assertSame('270.00', $service->costsByOrder($regie['id'], $office)['total'], '120 € + 150 € aus der Verteilung.');
        });
    }

    public function testCaseEAllocationBecomesInvalidAfterCreditAndCanBeCorrected(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'regie' => $regie] = $env;
            $order = $service->createExternal(['supplierId' => $supplier], $office);
            $order = $service->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'invoice', 'invoiceNumber' => 'R-1', 'invoiceDate' => '2026-11-20', 'netAmount' => '500.00'], null, $office);
            $order = $service->saveAllocations($order['id'], ['version' => $order['version'], 'allocations' => [['orderId' => $regie['id'], 'amount' => '400.00']]], $office);
            $order = $service->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'credit', 'invoiceNumber' => 'G-1', 'invoiceDate' => '2026-11-21', 'netAmount' => '200.00'], null, $office);
            $this->assertSame('300.00', $order['actualNetTotal']);
            $this->assertTrue($order['allocationExceeded']);
            $order = $service->saveAllocations($order['id'], ['version' => $order['version'], 'allocations' => [['orderId' => $regie['id'], 'amount' => '300.00']]], $office);
            $this->assertTrue(!$order['allocationExceeded']);
            $this->assertSame('300.00', $service->costsByOrder($regie['id'], $office)['total']);
        });
    }

    public function testCaseDCancelledOrderKeepsInvoicedCostsUntilCounterDocument(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'regie' => $regie] = $env;
            $order = $service->createExternal(['supplierId' => $supplier, 'orderId' => $regie['id']], $office);
            $order = $service->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'invoice', 'invoiceNumber' => 'R-1', 'invoiceDate' => '2026-11-20', 'netAmount' => '200.00'], null, $office);
            $order = $service->cancel($order['id'], ['version' => $order['version']], $office);
            $this->assertSame('200.00', $order['actualNetTotal']);
            $order = $service->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'reversal', 'invoiceDate' => '2026-11-22', 'netAmount' => '200.00', 'relatedInvoiceId' => $order['invoices'][0]['id']], null, $office);
            $this->assertSame('0.00', $order['actualNetTotal'], 'Erst der Gegenbeleg neutralisiert die Kosten.');
            $this->assertSame('0.00', $service->costsByOrder($regie['id'], $office)['total']);
        });
    }

    public function testLegacyOrdersFromMigration0029KeepWorking(): void
    {
        $tmp = sys_get_temp_dir() . '/calight-purchase-legacy-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/migrations', 0770, true);
        try {
            // Datenbank auf dem Stand von Migration 0029 mit Bestandsdaten.
            foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
                if (strcmp(basename($file), '0030') < 0) {
                    copy($file, $tmp . '/migrations/' . basename($file));
                }
            }
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, $tmp . '/migrations'))->migrate();
            $now = '2026-11-12T08:00:00Z';
            $user = $this->user($pdo, 'office', $now);
            $supplier = Uuid::v4();
            $pdo->prepare("INSERT INTO suppliers(id,name,contact,active,created_at,updated_at) VALUES(:id,'Altlieferant','Weg 1',1,:now,:now)")->execute(['id' => $supplier, 'now' => $now]);
            $order = Uuid::v4();
            $pdo->prepare("INSERT INTO purchase_orders(id,order_number,origin,status,delivery_status,payment_status,supplier_id,supplier_snapshot,expected_net_total,created_by_user_id,created_at,updated_at)
                VALUES(:id,'BE2026110001','external','ordered','partial','paid',:supplier,:snapshot,'500.00',:user,:now,:now)")
                ->execute(['id' => $order, 'supplier' => $supplier, 'snapshot' => '{"name":"Altlieferant","number":"","contact":"Weg 1"}', 'user' => $user['id'], 'now' => $now]);
            foreach (['200.00', '150.55'] as $index => $amount) {
                $pdo->prepare("INSERT INTO purchase_order_invoices(id,purchase_order_id,invoice_number,invoice_date,net_amount,created_by_user_id,created_at) VALUES(:id,:order,:number,'2026-11-12',:amount,:user,:now)")
                    ->execute(['id' => Uuid::v4(), 'order' => $order, 'number' => 'R-' . $index, 'amount' => $amount, 'user' => $user['id'], 'now' => $now]);
            }
            $pdo->prepare("INSERT INTO purchase_order_history(id,purchase_order_id,field,old_value,new_value,note,user_id,created_at) VALUES(:id,:order,'status',NULL,'ordered','',:user,:now)")
                ->execute(['id' => Uuid::v4(), 'order' => $order, 'user' => $user['id'], 'now' => $now]);

            // Upgrade auf den aktuellen Stand.
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);
            $service = new PurchaseOrderService($pdo, new PurchaseOrderRepository($pdo), new NumberRangeRepository($pdo), new AttachmentStore($tmp),
                new PurchaseOrderPdfService(new DocumentSettingsRepository($pdo)));
            $detail = $service->detail($order, $user);
            $this->assertSame('350.55', $detail['actualNetTotal'], 'Bestandsrechnungen gelten als normale Rechnungen (exakte Summe).');
            $this->assertSame('invoice', $detail['invoices'][0]['kind']);
            $this->assertSame('partial', $detail['deliveryStatus']);
            $this->assertSame('correction', $detail['deliveries'][0]['kind'], 'Bisheriger Lieferstatus als Korrekturereignis übernommen.');
            $this->assertSame('paid', $detail['payments'][0]['resultingStatus']);
            $this->assertSame('500.00', $detail['expectedNetTotal']);
            $this->assertSame('ordered', $detail['history'][0]['newValue'], 'Statusverlauf bleibt erhalten.');
            $next = $service->addDelivery($order, ['version' => $detail['version'], 'kind' => 'complete', 'deliveryDate' => '2026-11-20'], null, $user);
            $this->assertSame('delivered', $next['deliveryStatus']);
        } finally {
            $this->remove($tmp);
        }
    }

    public function testAmountsAndInvoiceDocumentsAreProtected(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'technician' => $technician, 'tmp' => $tmp] = $env;
            $order = $service->createExternal(['supplierId' => $supplier, 'expectedNetTotal' => '50.00'], $office);
            $order = $service->addInvoice($order['id'], ['version' => $order['version'], 'kind' => 'invoice', 'invoiceNumber' => 'R-1', 'invoiceDate' => '2026-11-20', 'netAmount' => '50.00'], $this->upload($tmp, 'R-1.pdf'), $office);
            $this->assertThrows(AccessDeniedException::class, fn () => $service->detail($order['id'], $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->page([], $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->documentFile($order['id'], $order['documents'][0]['id'], $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->costsByOrder('x', $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->addPayment($order['id'], ['version' => $order['version']], $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->addInvoice($order['id'], ['version' => $order['version']], null, $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->addDelivery($order['id'], ['version' => $order['version']], null, $technician));
            $this->assertSame(self::PDF, $service->documentFile($order['id'], $order['documents'][0]['id'], $office)['contents']);

            // Der Dateityp wird am Inhalt geprüft; ein abgelehnter Upload legt keinen Vorgang an.
            $script = $tmp . '/tool.sh';
            file_put_contents($script, "#!/bin/sh\necho hi\n");
            $this->assertThrows(OrderValidationException::class, fn () => $service->addDelivery($order['id'], ['version' => $order['version'], 'kind' => 'partial', 'deliveryDate' => '2026-11-20'], ['path' => $script, 'name' => 'ls.pdf'], $office));
            $this->assertSame(0, count($service->detail($order['id'], $office)['deliveries']));
        });
    }

    /** @return array{path:string,name:string} */
    private function upload(string $tmp, string $name): array
    {
        $path = $tmp . '/' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, self::PDF);
        return ['path' => $path, 'name' => $name];
    }

    private function materialRequest(PDO $pdo, string $orderId, string $userId, string $description): string
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO material_requests(id, customer_id, order_id, description, notes, status, created_by_user_id, version, created_at, updated_at)
            VALUES(:id, (SELECT customer_id FROM orders WHERE id = :order), :order, :description, '', 'open', :user, 1, '2026-11-12T08:00:00Z', '2026-11-12T08:00:00Z')")
            ->execute(['id' => $id, 'order' => $orderId, 'description' => $description, 'user' => $userId]);
        return $id;
    }

    /** @param callable(array<string,mixed>):void $test */
    private function withEnvironment(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-purchase-orders-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0770, true);
        mkdir($tmp . '/documents', 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate(); self::useReleasedOrderVisibility($pdo);
            $now = '2026-11-12T08:00:00Z';
            $customer = Uuid::v4();
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'K1','company','Matzko-Mack',:now,:now)")
                ->execute(['id' => $customer, 'now' => $now]);
            $supplier = Uuid::v4();
            $pdo->prepare("INSERT INTO suppliers(id,supplier_number,name,contact,active,created_at,updated_at) VALUES(:id,'70001','Elektrogroßhandel Süd','Industriestraße 5\n72108 Rottenburg',1,:now,:now)")
                ->execute(['id' => $supplier, 'now' => $now]);
            $category = (string) $pdo->query('SELECT id FROM master_categories LIMIT 1')->fetchColumn();
            if ($category === '') {
                $category = Uuid::v4();
                $pdo->prepare("INSERT INTO master_categories(id,name,created_at,updated_at) VALUES(:id,'Material',:now,:now)")->execute(['id' => $category, 'now' => $now]);
            }
            $item = Uuid::v4();
            $pdo->prepare("INSERT INTO cost_items(id,article_number,name,description,category_id,calculation_type,unit,cost_price,markup_percent,supplier_id,supplier_article_number,active,created_at,updated_at)
                VALUES(:id,'ART-1','Kabelkanal 40x60','',:category,'material','m','3.4567','20.00',:supplier,'ART-2',1,:now,:now)")
                ->execute(['id' => $item, 'category' => $category, 'supplier' => $supplier, 'now' => $now]);

            $offers = new OfferRepository($pdo);
            $validator = new OfferValidator();
            $calculator = new OfferCalculator(new PriceCalculator());
            $numbers = new NumberRangeRepository($pdo);
            $offerService = new OfferService($pdo, new ProjectRepository($pdo), $offers, new OfferJsonStore($tmp . '/offers', $validator), $calculator, $numbers,
                new OfferPdfService($pdo, new DocumentSettingsRepository($pdo), $validator, $calculator));
            $orders = new OrderService($pdo, new OrderRepository($pdo), $offers, $offerService, $numbers);

            $test([
                'pdo' => $pdo,
                'tmp' => $tmp,
                'documents' => $tmp . '/documents',
                'service' => new PurchaseOrderService($pdo, new PurchaseOrderRepository($pdo), $numbers, new AttachmentStore($tmp . '/documents'),
                    new PurchaseOrderPdfService(new DocumentSettingsRepository($pdo))),
                'masterData' => new MasterDataService(new MasterDataRepository($pdo), new PriceCalculator(), $numbers),
                'supplier' => $supplier,
                'item' => $item,
                'regie' => $orders->createRegie(['customerId' => $customer, 'title' => 'Elektroinstallation Werkhalle', 'orderDate' => '2026-11-12']),
                'second' => $orders->createRegie(['customerId' => $customer, 'title' => 'Wartung Verwaltung', 'orderDate' => '2026-11-12']),
                'office' => $this->user($pdo, 'office', $now),
                'technician' => $this->user($pdo, 'technician', $now),
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
