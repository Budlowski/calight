<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\PurchaseOrderRepository;
use App\Security\RolePermissions;
use App\Support\Clock;
use App\Support\ListQuery;
use App\Support\Uuid;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Bestellungen (Phase 3.4.1): interne Bestellungen mit Bestell-PDF und extern bereits
 * aufgegebene Bestellungen in einer gemeinsamen Verwaltung.
 *
 * Fachliche Regeln:
 * - Bestell-, Liefer- und Zahlungsstatus sind unabhängig; jede Änderung wird protokolliert.
 * - Interne Bestellungen werden erst mit „Bestellen“ verbindlich: Lieferanten-Snapshot,
 *   unveränderliches PDF je Fassung. Änderungen danach nur über eine neue Fassung.
 * - Ist-Kosten haben genau eine Quelle: direkt erfasster Betrag ODER Summe der Rechnungen.
 *   Beides gleichzeitig wird abgelehnt; der erwartete Bestellwert ist nie Ist-Kosten.
 * - Kostenverteilung auf Aufträge über feste Beträge, höchstens bis zu den Ist-Kosten.
 * - Beträge, Preise und Rechnungen nur mit costs.read (serverseitig gefiltert).
 */
final readonly class PurchaseOrderService
{
    public const STATUS_LABELS = ['draft' => 'Entwurf', 'ordered' => 'Bestellt', 'cancelled' => 'Storniert'];
    public const DELIVERY_LABELS = ['pending' => 'Ausstehend', 'partial' => 'Teilgeliefert', 'delivered' => 'Geliefert'];
    public const PAYMENT_LABELS = ['open' => 'Offen', 'partial' => 'Teilbezahlt', 'paid' => 'Bezahlt'];
    public const DOCUMENT_KINDS = [
        'own_order' => 'Eigene Bestellung',
        'supplier_confirmation' => 'Bestellbestätigung',
        'order_confirmation' => 'Auftragsbestätigung',
        'delivery_note' => 'Lieferschein des Lieferanten',
        'invoice' => 'Rechnung des Lieferanten',
        'other' => 'Sonstiger Anhang',
    ];
    /** Wie bei Berichtsanhängen: Typ wird am Inhalt erkannt; keine ausführbaren Dateien. */
    public const ATTACHMENT_TYPES = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/gif' => 'gif', 'image/heic' => 'heic', 'image/heif' => 'heif',
    ];
    public const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;
    private const MAX_DOCUMENTS = 50;
    private const MAX_ITEMS = 200;
    private const FOLDER = 'purchase-orders';

    public function __construct(
        private PDO $pdo,
        private PurchaseOrderRepository $orders,
        private NumberRangeRepository $numbers,
        private AttachmentStore $store,
        private PurchaseOrderPdfService $pdf,
    ) {
    }

    // -------------------------------------------------------------------------
    // Lesen
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $parameters @param array<string,mixed> $actor @return array<string,mixed> */
    public function page(array $parameters, array $actor): array
    {
        $this->require($actor, 'purchases.read');
        $query = ListQuery::fromArray($parameters, PurchaseOrderRepository::LIST_SORTS, 'updated', PurchaseOrderRepository::LIST_FILTERS);
        $page = $this->orders->page($query, $this->canCosts($actor));
        $page['items'] = array_map(fn (array $row): array => $this->summary($row, $actor), $page['items']);
        return $page;
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function forOrder(string $orderId, array $actor): array
    {
        $this->require($actor, 'purchases.read');
        return array_map(fn (array $row): array => $this->summary($row, $actor), $this->orders->forOrder($orderId));
    }

    /** Auswahllisten für Filter und Anlegen. @param array<string,mixed> $actor @return array<string,mixed> */
    public function referenceData(array $actor): array
    {
        $this->require($actor, 'purchases.read');
        $requests = $this->can($actor, 'purchases.write') ? $this->orders->openMaterialRequests() : [];
        return [
            'suppliers' => array_map(static fn (array $row): array => ['id' => $row['id'], 'number' => $row['supplier_number'], 'name' => $row['name']], $this->orders->activeSuppliers()),
            'orders' => array_map(static fn (array $row): array => ['id' => $row['id'], 'orderNumber' => $row['order_number'], 'title' => $row['title']], $this->orders->orders()),
            'materialRequests' => array_map(static fn (array $row): array => [
                'id' => $row['id'], 'orderId' => $row['order_id'], 'orderNumber' => $row['order_number'], 'orderTitle' => $row['order_title'],
                'description' => $row['description'], 'notes' => $row['notes'], 'neededBy' => $row['needed_by'], 'createdAt' => $row['created_at'],
                'alreadyOrdered' => (int) $row['order_count'] > 0,
            ], $requests),
            'statusLabels' => self::STATUS_LABELS,
            'deliveryLabels' => self::DELIVERY_LABELS,
            'paymentLabels' => self::PAYMENT_LABELS,
            'documentKinds' => array_diff_key(self::DOCUMENT_KINDS, ['own_order' => true]),
            'canWrite' => $this->can($actor, 'purchases.write'),
            'canCosts' => $this->canCosts($actor),
        ];
    }

    /** Artikelsuche für Bestellpositionen; Einkaufspreise nur mit costs.read. @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function catalog(string $search, ?string $supplierId, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        if (mb_strlen(trim($search)) < 2) {
            return [];
        }
        $costs = $this->canCosts($actor);
        return array_map(static fn (array $row): array => [
            'id' => $row['id'], 'articleNumber' => $row['article_number'], 'name' => $row['name'], 'unit' => $row['unit'],
            'supplierArticleNumber' => (string) ($row['supplier_article_number'] ?? ''),
            'fromSupplier' => $supplierId !== null && $row['supplier_id'] === $supplierId,
            // Einkaufspreis unverändert (bis 4 Nachkommastellen) als Vorschlag für die Position.
            'costPrice' => $costs ? (string) $row['cost_price'] : null,
        ], $this->orders->catalog($search, $supplierId));
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function detail(string $id, array $actor): array
    {
        $this->require($actor, 'purchases.read');
        $row = $this->find($id);
        $costs = $this->canCosts($actor);
        $write = $this->can($actor, 'purchases.write');
        $items = $this->orders->items($id);
        $invoices = $costs ? $this->orders->invoices($id) : [];
        $documents = array_values(array_filter($this->orders->documents($id), static fn (array $document): bool => $costs || $document['kind'] !== 'invoice'));
        $result = $this->summary($row, $actor) + [
            'version' => (int) $row['version'],
            'title' => $row['title'],
            'notes' => $row['notes'],
            'deliveryAddress' => $row['delivery_address'],
            'expectedDeliveryDate' => $row['expected_delivery_date'],
            'revision' => (int) $row['revision'],
            'issuedAt' => $row['issued_at'],
            'issuedByName' => $row['issued_by_name'],
            'cancelledAt' => $row['cancelled_at'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'supplierSnapshot' => $row['supplier_snapshot'] !== null ? json_decode((string) $row['supplier_snapshot'], true) : null,
            'items' => array_map(fn (array $item): array => $this->item($item, $costs), $items),
            'materialRequests' => array_map(static fn (array $request): array => [
                'id' => $request['id'], 'description' => $request['description'], 'notes' => $request['notes'],
                'status' => $request['status'], 'neededBy' => $request['needed_by'], 'orderNumber' => $request['order_number'],
            ], $this->orders->materialRequests($id)),
            'documents' => array_map(static fn (array $document): array => [
                'id' => $document['id'], 'kind' => $document['kind'], 'kindLabel' => self::DOCUMENT_KINDS[$document['kind']] ?? $document['kind'],
                'revision' => $document['revision'] !== null ? (int) $document['revision'] : null, 'name' => $document['original_name'],
                'mimeType' => $document['mime_type'], 'size' => (int) $document['size_bytes'], 'uploadedByName' => $document['uploaded_by_name'],
                'createdAt' => $document['created_at'], 'canDelete' => $write && $document['kind'] !== 'own_order',
            ], $documents),
            'history' => array_map(fn (array $entry): array => [
                'field' => $entry['field'], 'oldValue' => $entry['old_value'], 'newValue' => $entry['new_value'],
                'label' => $this->historyLabel($entry), 'note' => $entry['note'], 'userName' => $entry['user_name'], 'createdAt' => $entry['created_at'],
            ], $this->orders->history($id)),
            'permissions' => [
                'edit' => $write && $row['status'] !== 'cancelled',
                'editItems' => $write && $row['status'] === 'draft',
                'issue' => $write && $row['status'] === 'draft',
                'newRevision' => $write && $row['origin'] === 'internal' && $row['status'] === 'ordered',
                'cancel' => $write && $row['status'] !== 'cancelled',
                'delete' => $write && $this->deletable($row),
                'changeDelivery' => $write && $row['status'] !== 'draft',
                'changePayment' => $write && $row['status'] !== 'draft',
                'documents' => $write,
                'costs' => $write && $costs,
            ],
        ];
        if ($costs) {
            $result['invoices'] = array_map(static fn (array $invoice): array => [
                'id' => $invoice['id'], 'invoiceNumber' => $invoice['invoice_number'], 'invoiceDate' => $invoice['invoice_date'],
                'netAmount' => $invoice['net_amount'], 'vatAmount' => $invoice['vat_amount'], 'grossAmount' => $invoice['gross_amount'],
                'documentId' => $invoice['document_id'], 'notes' => $invoice['notes'],
            ], $invoices);
            $result['directActualNetTotal'] = $row['actual_net_total'];
            $result['allocations'] = array_map(static fn (array $allocation): array => [
                'orderId' => $allocation['order_id'], 'orderNumber' => $allocation['order_number'], 'orderTitle' => $allocation['order_title'], 'amount' => $allocation['amount'],
            ], $this->orders->allocations($id));
            $allocated = $this->sum(array_column($result['allocations'], 'amount'));
            $result['allocatedTotal'] = $result['allocations'] === [] ? null : $allocated;
            $actual = $this->actualCost($row);
            $result['unallocatedTotal'] = $result['allocations'] === [] || $actual === null ? null : Decimal::money(BigDecimal::of($actual)->minus($allocated));
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Anlegen und Bearbeiten
    // -------------------------------------------------------------------------

    /**
     * Interne Bestellung als Entwurf – leer oder aus Materialanforderungen.
     *
     * @param array<string,mixed> $input supplierId, orderId?, materialRequestIds?, title?, deliveryAddress?, notes?
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function createInternal(array $input, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $supplier = $this->supplier($input['supplierId'] ?? '');
        $orderId = $this->optionalOrder($input['orderId'] ?? '');
        $requestIds = array_values(array_unique(array_filter(array_map('strval', is_array($input['materialRequestIds'] ?? null) ? $input['materialRequestIds'] : []))));
        $requests = [];
        foreach ($requestIds as $requestId) {
            $request = $this->orders->materialRequest($requestId) ?? throw new OrderValidationException(['Die Materialanforderung wurde nicht gefunden.']);
            $requests[] = $request;
        }
        // Ohne ausdrückliche Auswahl übernimmt die Bestellung den gemeinsamen Auftrag der Anforderungen.
        if ($orderId === null && $requests !== []) {
            $requestOrders = array_values(array_unique(array_column($requests, 'order_id')));
            $orderId = count($requestOrders) === 1 ? (string) $requestOrders[0] : null;
        }
        $id = Uuid::v4();
        $now = Clock::now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $number = $this->numbers->allocate('purchase_order', 'purchase_order', $id, Uuid::v4(), new DateTimeImmutable($now));
            $this->orders->insert([
                'id' => $id, 'order_number' => $number, 'origin' => 'internal', 'status' => 'draft',
                'supplier_id' => $supplier['id'], 'order_id' => $orderId,
                'title' => $this->text($input['title'] ?? '', 200, 'Bezeichnung'),
                'delivery_address' => $this->text($input['deliveryAddress'] ?? '', 1000, 'Lieferanschrift'),
                'notes' => $this->text($input['notes'] ?? '', 5000, 'Bemerkung'),
                'created_by_user_id' => (string) $actor['id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            // Materialanforderungen schlagen Positionen vor; Menge ergänzt das Büro (Freitext enthält oft schon die Menge).
            $items = [];
            foreach ($requests as $index => $request) {
                $items[] = [
                    'id' => Uuid::v4(), 'position' => $index + 1, 'cost_item_id' => null, 'article_number' => '',
                    'description' => mb_substr((string) $request['description'], 0, 500), 'quantity' => null, 'unit' => '',
                    'unit_price' => null, 'line_total' => null, 'notes' => mb_substr((string) $request['notes'], 0, 1000),
                ];
                $this->orders->linkMaterialRequest($id, (string) $request['id']);
            }
            $this->orders->replaceItems($id, $items);
            $this->orders->addHistory($id, Uuid::v4(), 'status', null, 'draft', $requests !== [] ? 'Aus ' . count($requests) . ' Materialanforderung(en) angelegt.' : '', (string) $actor['id'], $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /**
     * Externe, bereits aufgegebene Bestellung: sofort „Bestellt“, ohne Pflicht zu Positionen.
     *
     * @param array<string,mixed> $input supplierId, externalReference?, orderDate?, expectedNetTotal?, orderId?, title?, notes?
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function createExternal(array $input, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $supplier = $this->supplier($input['supplierId'] ?? '');
        $orderId = $this->optionalOrder($input['orderId'] ?? '');
        $orderDate = $this->date($input['orderDate'] ?? '', 'Bestelldatum') ?? $this->today();
        $expected = $this->canCosts($actor) ? $this->money($input['expectedNetTotal'] ?? '', 'Bestellwert') : null;
        $id = Uuid::v4();
        $now = Clock::now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $number = $this->numbers->allocate('purchase_order', 'purchase_order', $id, Uuid::v4(), new DateTimeImmutable($now));
            $this->orders->insert([
                'id' => $id, 'order_number' => $number, 'origin' => 'external', 'status' => 'ordered',
                'supplier_id' => $supplier['id'], 'supplier_snapshot' => $this->snapshot($supplier), 'order_id' => $orderId,
                'external_reference' => $this->text($input['externalReference'] ?? '', 100, 'Externe Bestellnummer'),
                'title' => $this->text($input['title'] ?? '', 200, 'Bezeichnung'),
                'notes' => $this->text($input['notes'] ?? '', 5000, 'Bemerkung'),
                'order_date' => $orderDate, 'expected_net_total' => $expected,
                'created_by_user_id' => (string) $actor['id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->orders->addHistory($id, Uuid::v4(), 'status', null, 'ordered', 'Externe Bestellung erfasst.', (string) $actor['id'], $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /**
     * Speichert Kopfdaten und – nur im Entwurf – die Positionen.
     * Nach dem Bestellen bleiben Lieferant und Positionen unverändert (neue Fassung nötig).
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function update(string $id, array $input, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $row = $this->find($id);
        if ($row['status'] === 'cancelled') {
            throw new OrderValidationException(['Stornierte Bestellungen können nicht mehr bearbeitet werden.']);
        }
        $draft = $row['status'] === 'draft';
        $fields = [
            'title' => $this->text($input['title'] ?? $row['title'], 200, 'Bezeichnung'),
            'external_reference' => $this->text($input['externalReference'] ?? $row['external_reference'], 100, 'Externe Bestellnummer'),
            // Nicht übermittelte Felder bleiben unverändert.
            'expected_delivery_date' => array_key_exists('expectedDeliveryDate', $input) ? $this->date($input['expectedDeliveryDate'], 'Liefertermin') : $row['expected_delivery_date'],
            'order_id' => array_key_exists('orderId', $input) ? $this->optionalOrder($input['orderId']) : $row['order_id'],
            'notes' => $this->text($input['notes'] ?? $row['notes'], 5000, 'Bemerkung'),
        ];
        if ($draft) {
            // Inhalt der Bestellung gegenüber dem Lieferanten: nur vor dem (erneuten) Bestellen.
            $fields['supplier_id'] = $this->supplier($input['supplierId'] ?? $row['supplier_id'])['id'];
            $fields['delivery_address'] = $this->text($input['deliveryAddress'] ?? $row['delivery_address'], 1000, 'Lieferanschrift');
        } elseif ($row['origin'] === 'external') {
            $fields['order_date'] = array_key_exists('orderDate', $input) ? ($this->date($input['orderDate'], 'Bestelldatum') ?? $row['order_date']) : $row['order_date'];
        }
        $items = null;
        if ($draft && array_key_exists('items', $input)) {
            $items = $this->validateItems($input['items'], $this->canCosts($actor));
            if (!$this->canCosts($actor)) {
                // Ohne Kostenrecht bleiben vorhandene Preise unverändert erhalten.
                $items = $this->keepPrices($items, $this->orders->items($id));
            }
        }
        if ($this->canCosts($actor) && array_key_exists('expectedNetTotal', $input)) {
            $fields['expected_net_total'] = $this->money($input['expectedNetTotal'], 'Erwarteter Bestellwert');
        }
        if ($items !== null) {
            // Bei Positionen mit Preisen ergibt sich der erwartete Bestellwert aus deren Summe.
            $priced = array_filter($items, static fn (array $item): bool => $item['line_total'] !== null);
            if ($priced !== []) {
                $fields['expected_net_total'] = $this->sum(array_column($priced, 'line_total'));
            }
        }
        $now = Clock::now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->orders->update($id, $this->version($input), $fields, $now)) {
                throw $this->conflict();
            }
            if ($items !== null) {
                $this->orders->replaceItems($id, $items);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /** Löscht nur nie bestellte interne Entwürfe ohne Rechnungen. @param array<string,mixed> $actor */
    public function delete(string $id, int $version, array $actor): void
    {
        $this->require($actor, 'purchases.write');
        $row = $this->find($id);
        if (!$this->deletable($row)) {
            throw new OrderValidationException(['Nur nie bestellte Entwürfe können gelöscht werden. Bestellungen bitte stornieren.']);
        }
        if ((int) $row['version'] !== $version) {
            throw $this->conflict();
        }
        $documents = $this->orders->documents($id);
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach ($documents as $document) {
                $this->orders->deleteDocument((string) $document['id']);
            }
            $this->orders->delete($id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        foreach ($documents as $document) {
            $this->discard((string) $document['storage_path']);
        }
    }

    // -------------------------------------------------------------------------
    // Statusübergänge
    // -------------------------------------------------------------------------

    /**
     * Löst eine interne Bestellung aus: Snapshot, Fassung + 1, unveränderliches PDF.
     *
     * @param array<string,mixed> $input version, orderDate?
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function issue(string $id, array $input, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $row = $this->find($id);
        if ($row['status'] !== 'draft') {
            throw new OrderValidationException(['Nur Entwürfe können bestellt werden.']);
        }
        $items = $this->orders->items($id);
        $invalid = array_filter($items, fn (array $item): bool => !$this->validForIssue($item));
        if ($items === [] || $invalid !== []) {
            throw new OrderValidationException([$items === []
                ? 'Bitte mindestens eine Position erfassen, bevor die Bestellung ausgegeben wird.'
                : 'Jede Position braucht eine Bezeichnung und eine Menge größer 0 (Position ' . implode(', ', array_column($invalid, 'position')) . ').']);
        }
        $supplier = $this->supplier($row['supplier_id']);
        $now = Clock::now();
        $revision = (int) $row['revision'] + 1;
        $orderDate = $this->date($input['orderDate'] ?? '', 'Bestelldatum') ?? $this->today();
        $issued = [...$row, 'supplier_snapshot' => $this->snapshot($supplier), 'revision' => $revision, 'order_date' => $orderDate,
            'order_reference' => $this->jobReference($row),
            'items_total' => $this->itemsTotal($items)];
        $pdf = $this->pdf->render($issued, $items, false);
        $documentId = Uuid::v4();
        $path = $this->store->storeContents($pdf, self::FOLDER . '/' . $id, $documentId, 'pdf');
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->orders->update($id, $this->version($input), [
                'status' => 'ordered', 'supplier_snapshot' => $issued['supplier_snapshot'], 'revision' => $revision,
                'order_date' => $orderDate, 'issued_at' => $now, 'issued_by_user_id' => (string) $actor['id'],
            ], $now)) {
                throw $this->conflict();
            }
            $this->orders->insertDocument([
                'id' => $documentId, 'purchase_order_id' => $id, 'kind' => 'own_order', 'revision' => $revision,
                'original_name' => 'Bestellung_' . $row['order_number'] . ($revision > 1 ? '_Fassung' . $revision : '') . '.pdf',
                'mime_type' => 'application/pdf', 'size_bytes' => strlen($pdf), 'sha256' => hash('sha256', $pdf),
                'storage_path' => $path, 'uploaded_by_user_id' => (string) $actor['id'], 'created_at' => $now,
            ]);
            $this->orders->addHistory($id, Uuid::v4(), 'status', 'draft', 'ordered', $revision > 1 ? 'Fassung ' . $revision . ' bestellt.' : 'Bestellung ausgegeben.', (string) $actor['id'], $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            $this->discard($path);
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /**
     * Öffnet eine ausgegebene interne Bestellung für eine neue Fassung. Die bisher
     * ausgegebenen PDFs bleiben unverändert archiviert.
     *
     * @param array<string,mixed> $input version, note?
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function newRevision(string $id, array $input, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $row = $this->find($id);
        if ($row['origin'] !== 'internal' || $row['status'] !== 'ordered') {
            throw new OrderValidationException(['Eine neue Fassung ist nur für ausgegebene interne Bestellungen möglich.']);
        }
        $note = $this->text($input['note'] ?? '', 500, 'Bemerkung');
        $now = Clock::now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->orders->update($id, $this->version($input), ['status' => 'draft'], $now)) {
                throw $this->conflict();
            }
            $this->orders->addHistory($id, Uuid::v4(), 'revision', (string) $row['revision'], (string) ((int) $row['revision'] + 1),
                $note !== '' ? $note : 'Neue Fassung zur Änderung geöffnet.', (string) $actor['id'], $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /** Storniert; Dokumente, Rechnungen und Kosten bleiben erhalten. @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function cancel(string $id, array $input, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $row = $this->find($id);
        if ($row['status'] === 'cancelled') {
            throw new OrderValidationException(['Die Bestellung ist bereits storniert.']);
        }
        $note = $this->text($input['note'] ?? '', 500, 'Begründung');
        $now = Clock::now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->orders->update($id, $this->version($input), ['status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by_user_id' => (string) $actor['id']], $now)) {
                throw $this->conflict();
            }
            $this->orders->addHistory($id, Uuid::v4(), 'status', (string) $row['status'], 'cancelled', $note, (string) $actor['id'], $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /** @param array<string,mixed> $input version, status, note? @param array<string,mixed> $actor @return array<string,mixed> */
    public function setDeliveryStatus(string $id, array $input, array $actor): array
    {
        return $this->setTrackingStatus($id, $input, $actor, 'delivery', 'delivery_status', self::DELIVERY_LABELS);
    }

    /** @param array<string,mixed> $input version, status, note? @param array<string,mixed> $actor @return array<string,mixed> */
    public function setPaymentStatus(string $id, array $input, array $actor): array
    {
        return $this->setTrackingStatus($id, $input, $actor, 'payment', 'payment_status', self::PAYMENT_LABELS);
    }

    // -------------------------------------------------------------------------
    // Dokumente
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function addDocument(string $id, string $sourcePath, string $originalName, string $kind, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $row = $this->find($id);
        if (!array_key_exists($kind, self::DOCUMENT_KINDS) || $kind === 'own_order') {
            throw new OrderValidationException(['Bitte eine gültige Dokumentart wählen.']);
        }
        if ($kind === 'invoice') {
            $this->require($actor, 'costs.read');
        }
        if (count($this->orders->documents($id)) >= self::MAX_DOCUMENTS) {
            throw new OrderValidationException(['Eine Bestellung kann höchstens ' . self::MAX_DOCUMENTS . ' Dokumente enthalten.']);
        }
        $size = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;
        if ($size <= 0) {
            throw new OrderValidationException(['Die Datei ist leer oder fehlt.']);
        }
        if ($size > self::MAX_ATTACHMENT_BYTES) {
            throw new OrderValidationException(['Dokumente dürfen höchstens 15 MB groß sein.']);
        }
        // Der Typ wird am Inhalt erkannt, nicht an Dateiname oder Browserangabe.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        $extension = self::ATTACHMENT_TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new OrderValidationException(['Dieser Dateityp wird nicht unterstützt. Erlaubt sind PDF und Bilder (JPG, PNG, WebP, GIF, HEIC).']);
        }
        $documentId = Uuid::v4();
        $sha256 = (string) hash_file('sha256', $sourcePath);
        $path = $this->store->store($sourcePath, self::FOLDER . '/' . $row['id'], $documentId, $extension);
        try {
            $this->orders->insertDocument([
                'id' => $documentId, 'purchase_order_id' => $id, 'kind' => $kind, 'revision' => null,
                'original_name' => $this->fileName($originalName, $extension), 'mime_type' => $mime, 'size_bytes' => $size,
                'sha256' => $sha256, 'storage_path' => $path, 'uploaded_by_user_id' => (string) $actor['id'], 'created_at' => Clock::now(),
            ]);
        } catch (Throwable $exception) {
            $this->discard($path);
            throw $exception;
        }
        // Bewusst keine automatische Statusänderung durch den Upload.
        return $this->detail($id, $actor);
    }

    /** @param array<string,mixed> $actor @return array{contents:string,name:string,mimeType:string,inline:bool} */
    public function documentFile(string $id, string $documentId, array $actor): array
    {
        $this->require($actor, 'purchases.read');
        $this->find($id);
        $document = $this->orders->document($id, $documentId) ?? throw new OrderValidationException(['Das Dokument wurde nicht gefunden.']);
        if ($document['kind'] === 'invoice') {
            $this->require($actor, 'costs.read');
        }
        return [
            'contents' => $this->store->read((string) $document['storage_path']),
            'name' => (string) $document['original_name'],
            'mimeType' => (string) $document['mime_type'],
            'inline' => in_array($document['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true),
        ];
    }

    /** Entfernt hochgeladene Belege; eigene Bestell-PDFs sind unveränderlich. @param array<string,mixed> $actor @return array<string,mixed> */
    public function deleteDocument(string $id, string $documentId, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $this->find($id);
        $document = $this->orders->document($id, $documentId) ?? throw new OrderValidationException(['Das Dokument wurde nicht gefunden.']);
        if ($document['kind'] === 'own_order') {
            throw new OrderValidationException(['Ausgegebene Bestell-PDFs sind archiviert und können nicht gelöscht werden.']);
        }
        if ($document['kind'] === 'invoice') {
            $this->require($actor, 'costs.read');
        }
        $this->orders->deleteDocument($documentId);
        $this->discard((string) $document['storage_path']);
        return $this->detail($id, $actor);
    }

    /**
     * PDF der Bestellung: Entwürfe als Vorschau mit Wasserzeichen (nicht archiviert),
     * sonst die zuletzt ausgegebene, archivierte Fassung.
     *
     * @param array<string,mixed> $actor
     * @return array{pdf:string,fileName:string}
     */
    public function pdf(string $id, array $actor): array
    {
        $this->require($actor, 'purchases.read');
        $row = $this->find($id);
        if ($row['origin'] === 'external') {
            throw new OrderValidationException(['Für extern aufgegebene Bestellungen erzeugt Calight keinen eigenen Bestellbeleg.']);
        }
        if ($row['status'] === 'draft') {
            $items = $this->orders->items($id);
            $preview = [...$row, 'supplier_snapshot' => $this->snapshot($this->supplier($row['supplier_id'])), 'revision' => (int) $row['revision'] + 1,
                'order_date' => $row['order_date'] ?? $this->today(),
                'order_reference' => $this->jobReference($row),
                'items_total' => $this->itemsTotal($items)];
            // Ohne Kostenrecht keine Preise in der Vorschau.
            if (!$this->canCosts($actor)) {
                $items = array_map(static fn (array $item): array => [...$item, 'unit_price' => null, 'line_total' => null], $items);
                $preview['items_total'] = null;
            }
            return ['pdf' => $this->pdf->render($preview, $items, true), 'fileName' => 'Bestellung_' . $row['order_number'] . '_Vorschau.pdf'];
        }
        $documents = array_values(array_filter($this->orders->documents($id), static fn (array $document): bool => $document['kind'] === 'own_order'));
        if ($documents === []) {
            throw new OrderValidationException(['Für diese Bestellung wurde kein Bestell-PDF ausgegeben.']);
        }
        usort($documents, static fn (array $a, array $b): int => (int) $b['revision'] <=> (int) $a['revision']);
        if (!$this->canCosts($actor)) {
            throw new AccessDeniedException('costs.read');
        }
        return ['pdf' => $this->store->read((string) $documents[0]['storage_path']), 'fileName' => (string) $documents[0]['original_name']];
    }

    // -------------------------------------------------------------------------
    // Rechnungen, Ist-Kosten, Kostenverteilung (nur mit costs.read)
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $input invoiceNumber, invoiceDate, netAmount, vatAmount?, grossAmount?, documentId?, notes? @param array<string,mixed> $actor @return array<string,mixed> */
    public function addInvoice(string $id, array $input, array $actor): array
    {
        $this->requireCosts($actor);
        $row = $this->find($id);
        if ($row['status'] === 'draft') {
            throw new OrderValidationException(['Rechnungen können erst nach dem Bestellen erfasst werden.']);
        }
        if ($row['actual_net_total'] !== null) {
            throw new OrderValidationException(['Für diese Bestellung ist bereits ein tatsächlicher Betrag direkt erfasst. Bitte diesen zuerst entfernen, damit Kosten nicht doppelt gezählt werden.']);
        }
        $number = $this->text($input['invoiceNumber'] ?? '', 100, 'Rechnungsnummer');
        $date = $this->date($input['invoiceDate'] ?? '', 'Rechnungsdatum');
        $net = $this->money($input['netAmount'] ?? '', 'Nettobetrag');
        if ($number === '' || $date === null || $net === null) {
            throw new OrderValidationException(['Bitte Rechnungsnummer, Rechnungsdatum und Nettobetrag angeben.']);
        }
        $vat = $this->money($input['vatAmount'] ?? '', 'Umsatzsteuer');
        $gross = $this->money($input['grossAmount'] ?? '', 'Bruttobetrag');
        if ($vat !== null && $gross === null) {
            $gross = Decimal::money(BigDecimal::of($net)->plus($vat));
        }
        if ($vat !== null && $gross !== null && !BigDecimal::of($net)->plus($vat)->isEqualTo($gross)) {
            throw new OrderValidationException(['Nettobetrag und Umsatzsteuer ergeben nicht den Bruttobetrag.']);
        }
        $documentId = trim((string) ($input['documentId'] ?? ''));
        if ($documentId !== '' && $this->orders->document($id, $documentId) === null) {
            throw new OrderValidationException(['Das zugeordnete Dokument gehört nicht zu dieser Bestellung.']);
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->orders->update($id, $this->version($input), [], Clock::now())) {
                throw $this->conflict();
            }
            $this->orders->insertInvoice([
                'id' => Uuid::v4(), 'purchase_order_id' => $id, 'invoice_number' => $number, 'invoice_date' => $date,
                'net_amount' => $net, 'vat_amount' => $vat, 'gross_amount' => $gross, 'document_id' => $documentId !== '' ? $documentId : null,
                'notes' => $this->text($input['notes'] ?? '', 1000, 'Bemerkung'), 'created_by_user_id' => (string) $actor['id'], 'created_at' => Clock::now(),
            ]);
            $this->assertAllocationsCovered($id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /** @param array<string,mixed> $input version @param array<string,mixed> $actor @return array<string,mixed> */
    public function deleteInvoice(string $id, string $invoiceId, array $input, array $actor): array
    {
        $this->requireCosts($actor);
        $this->find($id);
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->orders->update($id, $this->version($input), [], Clock::now())) {
                throw $this->conflict();
            }
            if (!$this->orders->deleteInvoice($id, $invoiceId)) {
                throw new OrderValidationException(['Die Rechnung wurde nicht gefunden.']);
            }
            $this->assertAllocationsCovered($id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /**
     * Direkt erfasster tatsächlicher Nettobetrag (einfache externe Käufe) – nur ohne Rechnungen.
     * Leerer Wert entfernt den Betrag.
     *
     * @param array<string,mixed> $input version, amount
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function setActualCost(string $id, array $input, array $actor): array
    {
        $this->requireCosts($actor);
        $row = $this->find($id);
        if ($row['status'] === 'draft') {
            throw new OrderValidationException(['Tatsächliche Kosten können erst nach dem Bestellen erfasst werden.']);
        }
        $amount = $this->money($input['amount'] ?? '', 'Tatsächlicher Nettobetrag');
        if ($amount !== null && $this->orders->invoices($id) !== []) {
            throw new OrderValidationException(['Es sind bereits Lieferantenrechnungen erfasst; deren Summe ist der tatsächliche Betrag. Ein zusätzlicher direkter Betrag würde Kosten doppelt zählen.']);
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->orders->update($id, $this->version($input), ['actual_net_total' => $amount], Clock::now())) {
                throw $this->conflict();
            }
            $this->assertAllocationsCovered($id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /**
     * Kostenverteilung auf mehrere Aufträge mit festen Nettobeträgen. Eine leere Liste
     * entfernt die Verteilung (dann gilt wieder der Auftragsbezug der Bestellung).
     *
     * @param array<string,mixed> $input version, allocations: list<{orderId, amount}>
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function saveAllocations(string $id, array $input, array $actor): array
    {
        $this->requireCosts($actor);
        $row = $this->find($id);
        $raw = $input['allocations'] ?? [];
        if (!is_array($raw) || count($raw) > 50) {
            throw new OrderValidationException(['Die Kostenverteilung muss als Liste mit höchstens 50 Einträgen übergeben werden.']);
        }
        $allocations = [];
        foreach (array_values(array_filter($raw, 'is_array')) as $index => $entry) {
            $orderId = $this->optionalOrder($entry['orderId'] ?? '') ?? throw new OrderValidationException(['Zeile ' . ($index + 1) . ': Bitte einen Auftrag wählen.']);
            $amount = $this->money($entry['amount'] ?? '', 'Zeile ' . ($index + 1) . ': Betrag');
            if ($amount === null || !BigDecimal::of($amount)->isPositive()) {
                throw new OrderValidationException(['Zeile ' . ($index + 1) . ': Bitte einen Betrag größer 0 angeben.']);
            }
            if (isset($allocations[$orderId])) {
                throw new OrderValidationException(['Jeder Auftrag darf in der Kostenverteilung nur einmal vorkommen.']);
            }
            $allocations[$orderId] = ['id' => Uuid::v4(), 'order_id' => $orderId, 'amount' => $amount];
        }
        if ($allocations !== []) {
            $actual = $this->actualCost($row);
            if ($actual === null) {
                throw new OrderValidationException(['Bitte zuerst die tatsächlichen Kosten erfassen (Rechnung oder direkter Betrag).']);
            }
            if (BigDecimal::of($this->sum(array_column($allocations, 'amount')))->isGreaterThan($actual)) {
                throw new OrderValidationException(['Die verteilten Beträge übersteigen die tatsächlichen Kosten der Bestellung.']);
            }
        }
        $now = Clock::now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->orders->update($id, $this->version($input), [], $now)) {
                throw $this->conflict();
            }
            $this->orders->replaceAllocations($id, array_values($allocations), $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /**
     * Beschaffungskosten je Auftrag – Grundlage für die Nachkalkulation (Phase 3.5).
     *
     * Regel: Hat eine Bestellung eine Kostenverteilung, zählt für den Auftrag genau der dort
     * zugeordnete Betrag. Sonst zählen die vollen Ist-Kosten für den Auftrag der Bestellung.
     * Bestellungen ohne bekannte Ist-Kosten erscheinen mit amount = null. Entwürfe zählen nicht.
     *
     * @param array<string,mixed> $actor
     * @return array{items:list<array{purchaseOrderId:string,orderNumber:string,amount:?string,source:string}>,total:string}
     */
    public function costsByOrder(string $orderId, array $actor): array
    {
        $this->requireCosts($actor);
        $this->require($actor, 'purchases.read');
        $items = [];
        foreach ($this->orders->forCostEvaluation($orderId) as $row) {
            if ((int) $row['allocation_count'] > 0) {
                if ($row['allocated_amount'] === null) {
                    continue; // Verteilung ohne diesen Auftrag: keine Kosten für ihn.
                }
                $items[] = ['purchaseOrderId' => (string) $row['id'], 'orderNumber' => (string) $row['order_number'], 'amount' => (string) $row['allocated_amount'], 'source' => 'allocation'];
                continue;
            }
            $items[] = ['purchaseOrderId' => (string) $row['id'], 'orderNumber' => (string) $row['order_number'], 'amount' => $this->actualCost($row), 'source' => 'order'];
        }
        return ['items' => $items, 'total' => $this->sum(array_filter(array_column($items, 'amount'), static fn (?string $amount): bool => $amount !== null))];
    }

    // -------------------------------------------------------------------------
    // Hilfen
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $row @param array<string,mixed> $actor @return array<string,mixed> */
    private function summary(array $row, array $actor): array
    {
        $costs = $this->canCosts($actor);
        $actual = $this->actualCost($row);
        $snapshot = $row['supplier_snapshot'] !== null ? json_decode((string) $row['supplier_snapshot'], true) : null;
        return [
            'id' => $row['id'],
            'orderNumber' => $row['order_number'],
            'origin' => $row['origin'],
            'status' => $row['status'],
            'statusLabel' => self::STATUS_LABELS[$row['status']] ?? $row['status'],
            'deliveryStatus' => $row['delivery_status'],
            'deliveryLabel' => self::DELIVERY_LABELS[$row['delivery_status']] ?? $row['delivery_status'],
            'paymentStatus' => $row['payment_status'],
            'paymentLabel' => self::PAYMENT_LABELS[$row['payment_status']] ?? $row['payment_status'],
            'supplierId' => $row['supplier_id'],
            // Ausgegebene Bestellungen zeigen den Lieferanten wie bestellt.
            'supplierName' => is_array($snapshot) ? (string) $snapshot['name'] : (string) $row['supplier_name'],
            'supplierNumber' => is_array($snapshot) ? (string) ($snapshot['number'] ?? '') : (string) ($row['supplier_number'] ?? ''),
            'externalReference' => $row['external_reference'],
            'title' => $row['title'],
            'orderDate' => $row['order_date'],
            'orderId' => $row['order_id'],
            'jobNumber' => $row['order_id'] !== null ? $row['job_number'] : null,
            'jobTitle' => $row['order_id'] !== null ? $row['job_title'] : null,
            'itemCount' => (int) ($row['item_count'] ?? 0),
            'creatorName' => $row['creator_name'],
            'expectedNetTotal' => $costs ? $row['expected_net_total'] : null,
            'actualNetTotal' => $costs ? $actual : null,
            'costSource' => $costs ? ($row['actual_net_total'] !== null ? 'direct' : ($actual !== null ? 'invoices' : null)) : null,
        ];
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function item(array $item, bool $costs): array
    {
        return [
            'id' => $item['id'], 'position' => (int) $item['position'], 'costItemId' => $item['cost_item_id'], 'articleNumber' => $item['article_number'],
            'description' => $item['description'], 'quantity' => $item['quantity'], 'unit' => $item['unit'], 'notes' => $item['notes'],
            'unitPrice' => $costs ? $item['unit_price'] : null, 'lineTotal' => $costs ? $item['line_total'] : null,
        ];
    }

    /**
     * Ist-Kosten: direkt erfasster Betrag oder Summe der Rechnungen (nie beides).
     *
     * @param array<string,mixed> $row
     */
    private function actualCost(array $row): ?string
    {
        if ($row['actual_net_total'] !== null) {
            return (string) $row['actual_net_total'];
        }
        $amounts = array_filter(explode('|', (string) ($row['invoice_amounts'] ?? '')), static fn (string $value): bool => $value !== '');
        return $amounts === [] ? null : $this->sum($amounts);
    }

    /** Nach Änderungen an Ist-Kosten dürfen bestehende Verteilungen sie nicht übersteigen. */
    private function assertAllocationsCovered(string $id): void
    {
        $allocations = $this->orders->allocations($id);
        if ($allocations === []) {
            return;
        }
        $actual = $this->actualCost($this->find($id));
        if ($actual === null || BigDecimal::of($this->sum(array_column($allocations, 'amount')))->isGreaterThan($actual)) {
            throw new OrderValidationException(['Die Kostenverteilung übersteigt danach die tatsächlichen Kosten. Bitte zuerst die Verteilung anpassen.']);
        }
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @param array<string,string> $labels @return array<string,mixed> */
    private function setTrackingStatus(string $id, array $input, array $actor, string $field, string $column, array $labels): array
    {
        $this->require($actor, 'purchases.write');
        $row = $this->find($id);
        if ($row['status'] === 'draft') {
            throw new OrderValidationException(['Liefer- und Zahlungsstatus sind erst nach dem Bestellen verfügbar.']);
        }
        $status = (string) ($input['status'] ?? '');
        if (!array_key_exists($status, $labels)) {
            throw new OrderValidationException(['Ungültiger Status.']);
        }
        if ($status === $row[$column]) {
            throw new OrderValidationException(['Der Status ist bereits gesetzt.']);
        }
        $note = $this->text($input['note'] ?? '', 500, 'Bemerkung');
        $now = Clock::now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->orders->update($id, $this->version($input), [$column => $status], $now)) {
                throw $this->conflict();
            }
            $this->orders->addHistory($id, Uuid::v4(), $field, (string) $row[$column], $status, $note, (string) $actor['id'], $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /** @return list<array<string,mixed>> */
    private function validateItems(mixed $raw, bool $costs): array
    {
        if (!is_array($raw)) {
            throw new OrderValidationException(['Positionen müssen als Liste übergeben werden.']);
        }
        $rows = array_values(array_filter($raw, 'is_array'));
        if (count($rows) > self::MAX_ITEMS) {
            throw new OrderValidationException(['Eine Bestellung kann höchstens ' . self::MAX_ITEMS . ' Positionen enthalten.']);
        }
        $items = [];
        foreach ($rows as $index => $row) {
            $label = 'Position ' . ($index + 1);
            $description = $this->text($row['description'] ?? '', 500, $label . ': Bezeichnung');
            if ($description === '') {
                throw new OrderValidationException([$label . ': Bitte eine Bezeichnung angeben.']);
            }
            $quantity = $this->decimal($row['quantity'] ?? '', Decimal::QUANTITY_SCALE, $label . ': Menge');
            if ($quantity !== null && !BigDecimal::of($quantity)->isPositive()) {
                throw new OrderValidationException([$label . ': Die Menge muss größer 0 sein.']);
            }
            $price = $costs ? $this->decimal($row['unitPrice'] ?? '', Decimal::COST_SCALE, $label . ': Einzelpreis') : null;
            if ($price !== null && BigDecimal::of($price)->isNegative()) {
                throw new OrderValidationException([$label . ': Der Einzelpreis darf nicht negativ sein.']);
            }
            $costItemId = trim((string) ($row['costItemId'] ?? ''));
            $costItem = $costItemId !== '' ? $this->orders->costItem($costItemId) : null;
            if ($costItemId !== '' && $costItem === null) {
                throw new OrderValidationException([$label . ': Der Stammartikel wurde nicht gefunden.']);
            }
            $items[] = [
                'id' => Uuid::v4(), 'position' => $index + 1, 'cost_item_id' => $costItem['id'] ?? null,
                'article_number' => $this->text($row['articleNumber'] ?? ($costItem['supplier_article_number'] ?? $costItem['article_number'] ?? ''), 100, $label . ': Artikelnummer'),
                'description' => $description, 'quantity' => $quantity,
                'unit' => $this->text($row['unit'] ?? '', 20, $label . ': Einheit'),
                'unit_price' => $price,
                // Menge × Einzelpreis, kaufmännisch auf Cent gerundet.
                'line_total' => $price !== null && $quantity !== null ? Decimal::money(BigDecimal::of($quantity)->multipliedBy($price)) : null,
                'notes' => $this->text($row['notes'] ?? '', 1000, $label . ': Bemerkung'),
            ];
        }
        return $items;
    }

    /**
     * Ohne Kostenrecht übermittelte Positionen behalten vorhandene Preise (gleiche Position und Bezeichnung).
     *
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $existing
     * @return list<array<string,mixed>>
     */
    private function keepPrices(array $items, array $existing): array
    {
        $byPosition = array_column($existing, null, 'position');
        return array_map(function (array $item) use ($byPosition): array {
            $old = $byPosition[$item['position']] ?? null;
            if ($old === null || $old['description'] !== $item['description'] || $old['unit_price'] === null) {
                return $item;
            }
            return [...$item, 'unit_price' => $old['unit_price'], 'line_total' => $item['quantity'] !== null
                ? Decimal::money(BigDecimal::of((string) $item['quantity'])->multipliedBy((string) $old['unit_price'])) : null];
        }, $items);
    }

    /** @param array<string,mixed> $item */
    private function validForIssue(array $item): bool
    {
        return trim((string) $item['description']) !== '' && $item['quantity'] !== null && BigDecimal::of((string) $item['quantity'])->isPositive();
    }

    /** @param list<array<string,mixed>> $items */
    private function itemsTotal(array $items): ?string
    {
        $totals = array_filter(array_column($items, 'line_total'), static fn (?string $value): bool => $value !== null);
        return $totals === [] ? null : $this->sum($totals);
    }

    /** Auftragsbezug für das PDF: Nummer und Bezeichnung. @param array<string,mixed> $row */
    private function jobReference(array $row): string
    {
        return $row['order_id'] !== null ? trim($row['job_number'] . ' · ' . $row['job_title']) : '';
    }

    /** @param array<string,mixed> $row */
    private function deletable(array $row): bool
    {
        return $row['origin'] === 'internal' && $row['status'] === 'draft' && (int) $row['revision'] === 0;
    }

    /** @param array<string,mixed> $entry */
    private function historyLabel(array $entry): string
    {
        $labels = match ($entry['field']) {
            'status' => self::STATUS_LABELS,
            'delivery' => self::DELIVERY_LABELS,
            'payment' => self::PAYMENT_LABELS,
            default => [],
        };
        $prefix = ['status' => 'Bestellstatus', 'delivery' => 'Lieferstatus', 'payment' => 'Zahlungsstatus', 'revision' => 'Neue Fassung'][$entry['field']] ?? $entry['field'];
        if ($entry['field'] === 'revision') {
            return 'Neue Fassung ' . $entry['new_value'] . ' geöffnet';
        }
        $from = $entry['old_value'] !== null ? ($labels[$entry['old_value']] ?? $entry['old_value']) . ' → ' : '';
        return $prefix . ': ' . $from . ($labels[$entry['new_value']] ?? $entry['new_value']);
    }

    /** @return array<string,mixed> */
    private function find(string $id): array
    {
        $row = $this->orders->find($id);
        if ($row === null) {
            throw new OrderValidationException(['Die Bestellung wurde nicht gefunden.']);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function supplier(mixed $id): array
    {
        $id = trim((string) $id);
        if ($id === '') {
            throw new OrderValidationException(['Bitte einen Lieferanten auswählen.']);
        }
        return $this->orders->supplier($id) ?? throw new OrderValidationException(['Der Lieferant wurde nicht gefunden.']);
    }

    /** @param array<string,mixed> $supplier */
    private function snapshot(array $supplier): string
    {
        return json_encode(['id' => $supplier['id'], 'number' => (string) ($supplier['supplier_number'] ?? ''), 'name' => (string) $supplier['name'],
            'contact' => (string) ($supplier['contact'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function optionalOrder(mixed $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }
        return $this->orders->order($id) !== null ? $id : throw new OrderValidationException(['Der Auftrag wurde nicht gefunden.']);
    }

    private function text(mixed $value, int $max, string $label): string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) > $max) {
            throw new OrderValidationException([$label . ' darf höchstens ' . $max . ' Zeichen lang sein.']);
        }
        return $text;
    }

    private function date(mixed $value, string $label): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new OrderValidationException([$label . ' ist kein gültiges Datum.']);
        }
        return $value;
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d');
    }

    /** Geldbetrag (deutsches Komma erlaubt), auf Cent; leer = null. */
    private function money(mixed $value, string $label): ?string
    {
        $parsed = $this->decimal($value, Decimal::MONEY_SCALE, $label);
        return $parsed === null ? null : Decimal::money(BigDecimal::of($parsed));
    }

    private function decimal(mixed $value, int $scale, string $label): ?string
    {
        $text = str_replace(',', '.', trim((string) $value));
        if ($text === '') {
            return null;
        }
        try {
            return (string) Decimal::parse($text, $scale, $label);
        } catch (InvalidArgumentException $exception) {
            throw new OrderValidationException([$label . ': bitte eine gültige Zahl mit höchstens ' . $scale . ' Nachkommastellen angeben.']);
        }
    }

    /** @param iterable<string> $values */
    private function sum(iterable $values): string
    {
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus((string) $value);
        }
        return Decimal::money($sum);
    }

    private function fileName(string $name, string $extension): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', pathinfo($name, PATHINFO_FILENAME)));
        return mb_substr($base !== '' ? $base : 'Dokument', 0, 120) . '.' . $extension;
    }

    /** @param array<string,mixed> $input */
    private function version(array $input): int
    {
        $version = filter_var($input['version'] ?? null, FILTER_VALIDATE_INT);
        if ($version === false || $version < 1) {
            throw new OrderValidationException(['Bitte die aktuelle Version mitsenden.']);
        }
        return $version;
    }

    private function conflict(): OrderValidationException
    {
        return new OrderValidationException(['Die Bestellung wurde zwischenzeitlich geändert. Bitte neu laden.']);
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function discard(string $path): void
    {
        try {
            $this->store->delete($path);
        } catch (Throwable) {
            // Verwaiste Datei ist unkritisch.
        }
    }

    /** @param array<string,mixed> $actor */
    private function require(array $actor, string $permission): void
    {
        if (!$this->can($actor, $permission)) {
            throw new AccessDeniedException($permission);
        }
    }

    /** @param array<string,mixed> $actor */
    private function requireCosts(array $actor): void
    {
        $this->require($actor, 'purchases.write');
        $this->require($actor, 'costs.read');
    }

    /** @param array<string,mixed> $actor */
    private function canCosts(array $actor): bool
    {
        return $this->can($actor, 'costs.read');
    }

    /** @param array<string,mixed> $actor */
    private function can(array $actor, string $permission): bool
    {
        return RolePermissions::allows((string) ($actor['role'] ?? ''), $permission);
    }
}
