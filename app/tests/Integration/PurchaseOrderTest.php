<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Application\OrderService;
use App\Application\PurchaseOrderPdfService;
use App\Application\PurchaseOrderService;
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

/** Phase 3.4.1: Bestellverwaltung. */
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
        $this->assertSame(['purchases.write', 'costs.read'], $policy('POST', '/api/purchase-orders/x/invoices'));
        $this->assertSame(['purchases.write', 'costs.read'], $policy('DELETE', '/api/purchase-orders/x/invoices/y'));
        $this->assertSame(['purchases.write', 'costs.read'], $policy('PUT', '/api/purchase-orders/x/actual-cost'));
        $this->assertSame(['purchases.write', 'costs.read'], $policy('PUT', '/api/purchase-orders/x/allocations'));
    }

    public function testInternalOrderWithPositionsIsIssuedAsImmutablePdfAndRevised(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'technician' => $technician, 'supplier' => $supplier, 'item' => $item, 'regie' => $regie, 'pdo' => $pdo, 'documents' => $documents] = $env;

            $this->assertThrows(AccessDeniedException::class, fn () => $service->createInternal(['supplierId' => $supplier], $technician));
            $this->assertThrows(OrderValidationException::class, fn () => $service->createInternal([], $office), 'Lieferant ist Pflicht.');

            $draft = $service->createInternal(['supplierId' => $supplier, 'orderId' => $regie['id'], 'title' => 'Material Werkhalle'], $office);
            $this->assertSame('draft', $draft['status']);
            $this->assertSame('internal', $draft['origin']);
            $this->assertTrue(str_starts_with((string) $draft['orderNumber'], 'BE'), 'Bestellnummer aus dem Nummernkreis „Bestellungen“.');
            $second = $service->createInternal(['supplierId' => $supplier], $office);
            $this->assertTrue($second['orderNumber'] !== $draft['orderNumber'], 'Bestellnummern sind eindeutig.');
            $this->assertSame([], $draft['items'], 'Bestellung ohne Positionen kann gespeichert werden.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->issue($draft['id'], ['version' => $draft['version']], $office), 'Ohne Position kein Bestell-PDF.');

            $saved = $service->update($draft['id'], ['version' => $draft['version'], 'items' => [
                ['description' => 'Kabelkanal 40x60', 'quantity' => '12', 'unit' => 'm', 'unitPrice' => '3,4567', 'costItemId' => $item],
                ['description' => 'Abzweigdose', 'quantity' => '', 'unit' => 'Stk.'],
            ]], $office);
            $this->assertSame('41.48', $saved['items'][0]['lineTotal'], '12 × 3,4567 € = 41,4804 € → 41,48 €.');
            $this->assertSame('ART-2', $saved['items'][0]['articleNumber'], 'Lieferanten-Artikelnummer aus dem Stamm.');
            $this->assertSame('41.48', $saved['expectedNetTotal'], 'Erwarteter Wert aus den Positionspreisen.');
            $this->assertSame($regie['id'], $saved['orderId'], 'Nicht übermittelte Felder bleiben unverändert.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->issue($saved['id'], ['version' => $saved['version']], $office), 'Position ohne Menge ist nicht bestellbar.');

            $ready = $service->update($saved['id'], ['version' => $saved['version'], 'deliveryAddress' => 'Baustelle Werk 2', 'items' => [
                ['description' => 'Kabelkanal 40x60', 'quantity' => '12', 'unit' => 'm', 'unitPrice' => '3.4567', 'costItemId' => $item],
                ['description' => 'Abzweigdose', 'quantity' => '20', 'unit' => 'Stk.'],
            ]], $office);
            $issued = $service->issue($ready['id'], ['version' => $ready['version']], $office);
            $this->assertSame('ordered', $issued['status']);
            $this->assertSame(1, $issued['revision']);
            $this->assertTrue($issued['orderDate'] !== null && $issued['issuedAt'] !== null);
            $this->assertSame('Elektrogroßhandel Süd', $issued['supplierSnapshot']['name'], 'Lieferant als Snapshot festgeschrieben.');
            $this->assertSame(1, count(array_filter($issued['documents'], static fn (array $document): bool => $document['kind'] === 'own_order')));
            $first = $service->pdf($issued['id'], $office);
            $this->assertTrue(str_starts_with($first['pdf'], '%PDF') && str_contains($first['pdf'], 'Kabelkanal 40x60') && !str_contains($first['pdf'], 'ENTWURF'));
            $this->assertTrue(str_contains($first['pdf'], (string) $issued['orderNumber']));

            // Spätere Stammdatenänderungen verändern den Beleg nicht.
            $pdo->prepare("UPDATE suppliers SET name = 'Umbenannt GmbH' WHERE id = :id")->execute(['id' => $supplier]);
            $this->assertSame($first['pdf'], $service->pdf($issued['id'], $office)['pdf'], 'Ausgegebenes PDF bleibt unverändert archiviert.');
            $this->assertSame('Elektrogroßhandel Süd', $service->detail($issued['id'], $office)['supplierName']);

            // Nach dem Bestellen keine stillen Änderungen am Inhalt.
            $locked = $service->update($issued['id'], ['version' => $issued['version'], 'items' => [['description' => 'Anders', 'quantity' => '1']]], $office);
            $this->assertSame('Kabelkanal 40x60', $locked['items'][0]['description'], 'Positionen ausgegebener Bestellungen bleiben unverändert.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->delete($locked['id'], $locked['version'], $office), 'Ausgegebene Bestellungen werden storniert, nicht gelöscht.');

            // Änderung über eine neue Fassung.
            $reopened = $service->newRevision($locked['id'], ['version' => $locked['version'], 'note' => 'Menge erhöht'], $office);
            $this->assertSame('draft', $reopened['status']);
            $this->assertTrue(!$reopened['permissions']['delete'], 'Bereits ausgegebene Bestellungen sind nicht löschbar.');
            $changed = $service->update($reopened['id'], ['version' => $reopened['version'], 'items' => [
                ['description' => 'Kabelkanal 40x60', 'quantity' => '24', 'unit' => 'm', 'unitPrice' => '3.4567'],
            ]], $office);
            $revised = $service->issue($changed['id'], ['version' => $changed['version']], $office);
            $this->assertSame(2, $revised['revision']);
            $own = array_values(array_filter($revised['documents'], static fn (array $document): bool => $document['kind'] === 'own_order'));
            $this->assertSame(2, count($own), 'Beide Fassungen bleiben archiviert.');
            $this->assertTrue($service->pdf($revised['id'], $office)['pdf'] !== $first['pdf'], 'PDF liefert die neueste Fassung.');
            $firstFile = $service->documentFile($revised['id'], $own[0]['revision'] === 1 ? $own[0]['id'] : $own[1]['id'], $office);
            $this->assertSame($first['pdf'], $firstFile['contents'], 'Fassung 1 ist unverändert abrufbar.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->deleteDocument($revised['id'], $own[0]['id'], $office), 'Eigene Bestell-PDFs sind nicht löschbar.');
            foreach ($pdo->query('SELECT storage_path FROM purchase_order_documents')->fetchAll(PDO::FETCH_COLUMN) as $path) {
                $this->assertTrue(is_file($documents . '/' . $path) && str_starts_with((string) $path, 'purchase-orders/'), 'Ablage unter APP_DOCUMENTS_PATH/purchase-orders.');
            }

            // Nie ausgegebener Entwurf ist löschbar.
            $service->delete($second['id'], $second['version'], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $service->detail($second['id'], $office));
        });
    }

    public function testMaterialRequestsSuggestPositionsAndStayOpen(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'regie' => $regie, 'pdo' => $pdo] = $env;
            $first = $this->materialRequest($pdo, $regie['id'], $office['id'], '20 m NYM-J 3x1,5', 'für Halle 4');
            $second = $this->materialRequest($pdo, $regie['id'], $office['id'], '5 Abzweigdosen', '');

            $reference = $service->referenceData($office);
            $this->assertSame(2, count($reference['materialRequests']));
            $order = $service->createInternal(['supplierId' => $supplier, 'materialRequestIds' => [$first, $second]], $office);
            $this->assertSame(2, count($order['items']));
            $this->assertSame('20 m NYM-J 3x1,5', $order['items'][0]['description']);
            $this->assertSame('für Halle 4', $order['items'][0]['notes']);
            $this->assertSame(null, $order['items'][0]['quantity'], 'Menge ergänzt das Büro vor dem Bestellen.');
            $this->assertSame($regie['id'], $order['orderId'], 'Gemeinsamer Auftrag der Anforderungen wird übernommen.');
            $this->assertSame(2, count($order['materialRequests']), 'Bezug zur Materialanforderung bleibt erhalten.');
            $this->assertSame('open', (string) $pdo->query("SELECT status FROM material_requests WHERE id = " . $pdo->quote($first))->fetchColumn(), 'Anforderungen werden nicht automatisch erledigt.');
            $this->assertTrue($service->referenceData($office)['materialRequests'][0]['alreadyOrdered'], 'Bereits übernommene Anforderungen sind gekennzeichnet.');

            // Positionen frei bearbeiten und ergänzen.
            $edited = $service->update($order['id'], ['version' => $order['version'], 'items' => [
                ['description' => 'NYM-J 3x1,5', 'quantity' => '20', 'unit' => 'm'],
                ['description' => 'Abzweigdose', 'quantity' => '5', 'unit' => 'Stk.'],
                ['description' => 'Wago-Klemmen', 'quantity' => '50', 'unit' => 'Stk.'],
            ]], $office);
            $this->assertSame(3, count($edited['items']));
            $this->assertSame(null, $edited['expectedNetTotal'], 'Ohne Preise kein erwarteter Wert.');
        });
    }

    public function testExternalOrderWithoutPositionsDocumentsAndTrackingStatus(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'regie' => $regie, 'tmp' => $tmp] = $env;
            $external = $service->createExternal(['supplierId' => $supplier, 'externalReference' => 'WEB-4711', 'orderDate' => '2026-11-15', 'expectedNetTotal' => '199,90'], $office);
            $this->assertSame('ordered', $external['status'], 'Externe Bestellungen sind sofort „Bestellt“.');
            $this->assertSame('external', $external['origin']);
            $this->assertSame([], $external['items'], 'Keine Pflicht zur Positionserfassung.');
            $this->assertSame('199.90', $external['expectedNetTotal']);
            $this->assertSame(null, $external['actualNetTotal'], 'Erwarteter Wert zählt nicht als Ist-Kosten.');
            $this->assertSame('WEB-4711', $external['externalReference']);
            $this->assertThrows(OrderValidationException::class, fn () => $service->pdf($external['id'], $office), 'Kein künstlicher Calight-Bestellbeleg.');

            // Nachträgliche Auftragszuordnung.
            $assigned = $service->update($external['id'], ['version' => $external['version'], 'orderId' => $regie['id']], $office);
            $this->assertSame($regie['id'], $assigned['orderId']);

            // Bestellbestätigung hochladen – ohne automatische Statusänderung.
            $file = $tmp . '/bestaetigung.pdf';
            file_put_contents($file, self::PDF);
            $withDocument = $service->addDocument($assigned['id'], $file, '../../Bestätigung WEB-4711.pdf', 'supplier_confirmation', $office);
            $this->assertSame(1, count($withDocument['documents']));
            $this->assertSame('Bestätigung WEB-4711.pdf', $withDocument['documents'][0]['name'], 'Sicherer Dateiname ohne Pfadanteile.');
            $this->assertSame('pending', $withDocument['deliveryStatus']);
            $this->assertSame('open', $withDocument['paymentStatus']);
            $this->assertSame(self::PDF, $service->documentFile($withDocument['id'], $withDocument['documents'][0]['id'], $office)['contents']);
            $script = $tmp . '/tool.sh';
            file_put_contents($script, "#!/bin/sh\necho hi\n");
            $this->assertThrows(OrderValidationException::class, fn () => $service->addDocument($withDocument['id'], $script, 'tool.pdf', 'other', $office), 'Typ wird am Inhalt erkannt.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->addDocument($withDocument['id'], $file, 'x.pdf', 'own_order', $office));

            // Liefer- und Zahlungsstatus unabhängig, mit Verlauf.
            $partial = $service->setDeliveryStatus($withDocument['id'], ['version' => $withDocument['version'], 'status' => 'partial', 'note' => 'Erste Lieferung'], $office);
            $paid = $service->setPaymentStatus($partial['id'], ['version' => $partial['version'], 'status' => 'paid'], $office);
            $this->assertSame('partial', $paid['deliveryStatus']);
            $this->assertSame('paid', $paid['paymentStatus'], 'Teilgeliefert und bezahlt gleichzeitig möglich.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->setPaymentStatus($paid['id'], ['version' => $paid['version'], 'status' => 'unknown'], $office));
            $this->assertThrows(OrderValidationException::class, fn () => $service->setPaymentStatus($paid['id'], ['version' => $paid['version'] - 1, 'status' => 'open'], $office), 'Konfliktschutz über die Version.');
            $this->assertSame('Zahlungsstatus: Offen → Bezahlt', $paid['history'][0]['label']);
            $this->assertSame('Erste Lieferung', $paid['history'][1]['note']);
            $this->assertSame(3, count($paid['history']));

            // Stornieren behält Dokumente.
            $cancelled = $service->cancel($paid['id'], ['version' => $paid['version'], 'note' => 'Doppelt bestellt'], $office);
            $this->assertSame('cancelled', $cancelled['status']);
            $this->assertSame(1, count($cancelled['documents']), 'Dokumente bleiben bei Stornierung erhalten.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->update($cancelled['id'], ['version' => $cancelled['version'], 'title' => 'x'], $office));
            $this->assertThrows(OrderValidationException::class, fn () => $service->cancel($cancelled['id'], ['version' => $cancelled['version']], $office));

            // Liste mit Filtern.
            $this->assertSame(1, $service->page(['origin' => 'external'], $office)['total']);
            $this->assertSame(1, $service->page(['payment' => 'paid'], $office)['total']);
            $this->assertSame(1, $service->page(['q' => 'web-4711'], $office)['total']);
            $this->assertSame(0, $service->page(['delivery' => 'delivered'], $office)['total']);
        });
    }

    public function testInvoicesAndDirectActualCostNeverAddUp(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'tmp' => $tmp] = $env;
            $order = $service->createExternal(['supplierId' => $supplier, 'expectedNetTotal' => '500.00'], $office);

            // Mehrere Rechnungen (Teillieferungen) ergeben die Ist-Kosten.
            $one = $service->addInvoice($order['id'], ['version' => $order['version'], 'invoiceNumber' => 'R-1', 'invoiceDate' => '2026-11-16', 'netAmount' => '200,00', 'vatAmount' => '38,00'], $office);
            $this->assertSame('238.00', $one['invoices'][0]['grossAmount'], 'Brutto aus Netto und Umsatzsteuer.');
            $two = $service->addInvoice($one['id'], ['version' => $one['version'], 'invoiceNumber' => 'R-2', 'invoiceDate' => '2026-11-20', 'netAmount' => '150.55'], $office);
            $this->assertSame('350.55', $two['actualNetTotal']);
            $this->assertSame('invoices', $two['costSource']);
            $this->assertSame('500.00', $two['expectedNetTotal'], 'Erwarteter Wert bleibt getrennt.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->addInvoice($two['id'], ['version' => $two['version'], 'invoiceNumber' => 'R-3', 'invoiceDate' => '2026-11-20', 'netAmount' => '10.00', 'vatAmount' => '1.90', 'grossAmount' => '99.00'], $office), 'Brutto muss Netto + USt. entsprechen.');

            // Direkter Betrag zusätzlich zu Rechnungen wird abgelehnt (keine Doppelzählung).
            $this->assertThrows(OrderValidationException::class, fn () => $service->setActualCost($two['id'], ['version' => $two['version'], 'amount' => '350.55'], $office));

            // Einfacher externer Kauf: direkter Betrag ohne Rechnung – dann keine Rechnungen zusätzlich.
            $simple = $service->createExternal(['supplierId' => $supplier], $office);
            $direct = $service->setActualCost($simple['id'], ['version' => $simple['version'], 'amount' => '89,90'], $office);
            $this->assertSame('89.90', $direct['actualNetTotal']);
            $this->assertSame('direct', $direct['costSource']);
            $this->assertThrows(OrderValidationException::class, fn () => $service->addInvoice($direct['id'], ['version' => $direct['version'], 'invoiceNumber' => 'R-9', 'invoiceDate' => '2026-11-20', 'netAmount' => '89.90'], $office));
            $cleared = $service->setActualCost($direct['id'], ['version' => $direct['version'], 'amount' => ''], $office);
            $this->assertSame(null, $cleared['actualNetTotal']);

            // Rechnung entfernen; Rechnungsbelege nur mit Kostenrecht.
            $removed = $service->deleteInvoice($two['id'], $two['invoices'][1]['id'], ['version' => $two['version']], $office);
            $this->assertSame('200.00', $removed['actualNetTotal']);
            $file = $tmp . '/rechnung.pdf';
            file_put_contents($file, self::PDF);
            $withInvoiceFile = $service->addDocument($removed['id'], $file, 'Rechnung R-1.pdf', 'invoice', $office);
            $this->assertSame('invoice', $withInvoiceFile['documents'][0]['kind']);

            // Entwürfe haben noch keine Kosten.
            $draft = $service->createInternal(['supplierId' => $supplier], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $service->addInvoice($draft['id'], ['version' => $draft['version'], 'invoiceNumber' => 'X', 'invoiceDate' => '2026-11-20', 'netAmount' => '1.00'], $office));
        });
    }

    public function testCostsAreAssignedToOrdersAndSplitWithoutExceedingActualCost(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'regie' => $regie, 'second' => $secondOrder] = $env;

            // Standardfall: volle Ist-Kosten gehen an den Auftrag der Bestellung.
            $single = $service->createExternal(['supplierId' => $supplier, 'orderId' => $regie['id']], $office);
            $single = $service->setActualCost($single['id'], ['version' => $single['version'], 'amount' => '120.00'], $office);
            $costs = $service->costsByOrder($regie['id'], $office);
            $this->assertSame('120.00', $costs['total']);
            $this->assertSame('order', $costs['items'][0]['source']);

            // Bestellung ohne Auftrag, später verteilt auf zwei Aufträge.
            $shared = $service->createExternal(['supplierId' => $supplier], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $service->saveAllocations($shared['id'], ['version' => $shared['version'], 'allocations' => [['orderId' => $regie['id'], 'amount' => '10']]], $office), 'Verteilung erst mit bekannten Ist-Kosten.');
            $shared = $service->addInvoice($shared['id'], ['version' => $shared['version'], 'invoiceNumber' => 'S-1', 'invoiceDate' => '2026-11-18', 'netAmount' => '300.00'], $office);
            $this->assertThrows(OrderValidationException::class, fn () => $service->saveAllocations($shared['id'], ['version' => $shared['version'], 'allocations' => [
                ['orderId' => $regie['id'], 'amount' => '200'], ['orderId' => $secondOrder['id'], 'amount' => '150'],
            ]], $office), 'Summe darf die Ist-Kosten nicht überschreiten.');
            $this->assertThrows(OrderValidationException::class, fn () => $service->saveAllocations($shared['id'], ['version' => $shared['version'], 'allocations' => [
                ['orderId' => $regie['id'], 'amount' => '100'], ['orderId' => $regie['id'], 'amount' => '50'],
            ]], $office), 'Jeder Auftrag nur einmal.');
            $split = $service->saveAllocations($shared['id'], ['version' => $shared['version'], 'allocations' => [
                ['orderId' => $regie['id'], 'amount' => '180,00'], ['orderId' => $secondOrder['id'], 'amount' => '100.00'],
            ]], $office);
            $this->assertSame('280.00', $split['allocatedTotal']);
            $this->assertSame('20.00', $split['unallocatedTotal'], 'Nicht zugeordneter Rest bleibt sichtbar.');
            $this->assertSame('300.00', $service->costsByOrder($regie['id'], $office)['total'], '120,00 € direkt + 180,00 € aus der Verteilung.');
            $this->assertSame('100.00', $service->costsByOrder($secondOrder['id'], $office)['total']);

            // Rechnung entfernen, die die Verteilung decken muss: abgelehnt.
            $this->assertThrows(OrderValidationException::class, fn () => $service->deleteInvoice($split['id'], $split['invoices'][0]['id'], ['version' => $split['version']], $office));

            // Verteilung mit eigenem Auftragsbezug: es zählen nur die verteilten Beträge.
            $withOrder = $service->update($split['id'], ['version' => $split['version'], 'orderId' => $secondOrder['id']], $office);
            $this->assertSame('100.00', $service->costsByOrder($secondOrder['id'], $office)['total'], 'Mit Verteilung keine zusätzliche volle Zuordnung.');
            $removed = $service->saveAllocations($withOrder['id'], ['version' => $withOrder['version'], 'allocations' => []], $office);
            $this->assertSame(null, $removed['unallocatedTotal']);
            $this->assertSame('300.00', $service->costsByOrder($secondOrder['id'], $office)['total'], 'Ohne Verteilung volle Kosten an den Auftrag der Bestellung.');
            $this->assertSame('120.00', $service->costsByOrder($regie['id'], $office)['total']);

            // Stornierte Bestellungen behalten bereits entstandene Kosten.
            $service->cancel($single['id'], ['version' => $single['version']], $office);
            $this->assertSame('120.00', $service->costsByOrder($regie['id'], $office)['total']);
        });
    }

    public function testAmountsAreHiddenWithoutCostPermission(): void
    {
        $this->withEnvironment(function (array $env): void {
            ['service' => $service, 'office' => $office, 'supplier' => $supplier, 'technician' => $technician] = $env;
            $order = $service->createExternal(['supplierId' => $supplier, 'expectedNetTotal' => '50.00'], $office);
            $this->assertThrows(AccessDeniedException::class, fn () => $service->detail($order['id'], $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->page([], $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->documentFile($order['id'], 'x', $technician));
            $this->assertThrows(AccessDeniedException::class, fn () => $service->costsByOrder('x', $technician));
            // Eine Rolle mit Bestellrecht, aber ohne Kostenrecht, erhält keine Beträge (Filterlogik).
            $detail = $service->detail($order['id'], $office);
            $this->assertTrue(array_key_exists('invoices', $detail) && $detail['expectedNetTotal'] === '50.00');
        });
    }

    private function materialRequest(PDO $pdo, string $orderId, string $userId, string $description, string $notes): string
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO material_requests(id, order_id, description, notes, status, created_by_user_id, version, created_at, updated_at)
            VALUES(:id, :order, :description, :notes, 'open', :user, 1, '2026-11-12T08:00:00Z', '2026-11-12T08:00:00Z')")
            ->execute(['id' => $id, 'order' => $orderId, 'description' => $description, 'notes' => $notes, 'user' => $userId]);
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
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
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
