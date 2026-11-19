<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\Order\OrderValidationException;
use App\Domain\Purchase\PurchaseCostCalculator;
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
 * Bestellungen (Phase 3.4.1, vorgangsorientiert seit 3.4.1.1): interne Bestellungen mit
 * Bestell-PDF und extern bereits aufgegebene Bestellungen in einer gemeinsamen Verwaltung.
 *
 * Fachliche Regeln:
 * - Interne Bestellungen werden erst mit „Bestellen“ verbindlich: Lieferanten-Snapshot,
 *   unveränderliches PDF je Fassung. Änderungen danach nur über eine neue Fassung.
 * - Nach dem Bestellen folgen unabhängige, unveränderliche Ereignisse in beliebiger Reihenfolge:
 *   Auftragsbestätigungen, Lieferungen/Rücksendungen, Lieferantenbelege und Zahlungsvermerke.
 *   Liefer- und Zahlungsstatus ergeben sich aus dem zuletzt erfassten Ereignis.
 * - Drei Beträge bleiben getrennt: ursprünglicher Bestellwert (expected_net_total), aktuell
 *   bestätigter Wert (aktuelle AB) und tatsächliche Nettokosten.
 * - Ist-Kosten haben genau eine Quelle: direkt erfasster Betrag ODER Kostenwirkung der Belege
 *   (PurchaseCostCalculator). Die Umstellung auf Belege ersetzt den direkten Betrag ausdrücklich.
 * - Belege werden nie gelöscht; Korrekturen über Gutschrift oder Stornobeleg.
 * - Kostenverteilung auf Aufträge über feste Beträge, höchstens bis zu den Ist-Kosten.
 * - Beträge, Preise und Belege nur mit costs.read (serverseitig gefiltert).
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
    public const DELIVERY_KINDS = ['partial' => 'Teillieferung', 'complete' => 'Vollständige Lieferung', 'return' => 'Rücksendung', 'correction' => 'Lieferkorrektur'];
    public const PAYMENT_KINDS = ['payment' => 'Zahlung', 'refund' => 'Rückerstattung', 'correction' => 'Statuskorrektur'];
    public const INVOICE_KINDS = ['invoice' => 'Rechnung', 'partial' => 'Teilrechnung', 'final' => 'Schlussrechnung', 'credit' => 'Gutschrift', 'reversal' => 'Stornobeleg'];
    public const FINAL_MODES = ['additional' => 'Zusätzlicher Rechnungsbetrag', 'total' => 'Gesamtrechnung einschließlich bisheriger Teilrechnungen'];
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

    /** Auswahllisten für Filter, Anlegen und Vorgangsdialoge. @param array<string,mixed> $actor @return array<string,mixed> */
    public function referenceData(array $actor): array
    {
        $this->require($actor, 'purchases.read');
        return [
            'suppliers' => array_map(static fn (array $row): array => ['id' => $row['id'], 'number' => $row['supplier_number'], 'name' => $row['name']], $this->orders->activeSuppliers()),
            'orders' => array_map(static fn (array $row): array => ['id' => $row['id'], 'orderNumber' => $row['order_number'], 'title' => $row['title']], $this->orders->orders()),
            'statusLabels' => self::STATUS_LABELS,
            'deliveryLabels' => self::DELIVERY_LABELS,
            'paymentLabels' => self::PAYMENT_LABELS,
            'deliveryKinds' => self::DELIVERY_KINDS,
            'paymentKinds' => self::PAYMENT_KINDS,
            'invoiceKinds' => self::INVOICE_KINDS,
            'finalModes' => self::FINAL_MODES,
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
        $placed = $this->placed($row);
        $items = $this->orders->items($id);
        $documents = array_values(array_filter($this->orders->documents($id), static fn (array $document): bool => $costs || $document['kind'] !== 'invoice'));
        $documentNames = array_column($documents, 'original_name', 'id');
        // Verweis auf den Beleg eines Vorgangs (ohne Kostenrecht keine Rechnungsbelege).
        $documentRef = static fn (?string $documentId): ?array => $documentId !== null && isset($documentNames[$documentId])
            ? ['id' => $documentId, 'name' => $documentNames[$documentId]] : null;
        $payments = $this->orders->payments($id);
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
            // Bestandsverknüpfungen aus Phase 3.4.1; neue Übernahmen gibt es nicht mehr.
            'materialRequests' => array_map(static fn (array $request): array => [
                'id' => $request['id'], 'description' => $request['description'], 'status' => $request['status'], 'orderNumber' => $request['order_number'],
            ], $this->orders->materialRequests($id)),
            'confirmations' => array_map(static fn (array $confirmation): array => [
                'id' => $confirmation['id'], 'number' => $confirmation['confirmation_number'], 'date' => $confirmation['confirmation_date'],
                'confirmedNetTotal' => $costs ? $confirmation['confirmed_net_total'] : null,
                'confirmedDeliveryDate' => $confirmation['confirmed_delivery_date'], 'notes' => $confirmation['notes'],
                'current' => (bool) $confirmation['is_current'], 'document' => $documentRef($confirmation['document_id']),
                'createdByName' => $confirmation['created_by_name'], 'createdAt' => $confirmation['created_at'],
            ], $this->orders->confirmations($id)),
            'deliveries' => array_map(static fn (array $delivery): array => [
                'id' => $delivery['id'], 'kind' => $delivery['kind'], 'kindLabel' => self::DELIVERY_KINDS[$delivery['kind']] ?? $delivery['kind'],
                'date' => $delivery['delivery_date'], 'deliveryNoteNumber' => $delivery['delivery_note_number'], 'notes' => $delivery['notes'],
                'resultingStatus' => $delivery['resulting_status'], 'resultingLabel' => self::DELIVERY_LABELS[$delivery['resulting_status']] ?? '',
                'document' => $documentRef($delivery['document_id']), 'createdByName' => $delivery['created_by_name'], 'createdAt' => $delivery['created_at'],
            ], $this->orders->deliveries($id)),
            'payments' => array_map(static fn (array $payment): array => [
                'id' => $payment['id'], 'kind' => $payment['kind'], 'kindLabel' => self::PAYMENT_KINDS[$payment['kind']] ?? $payment['kind'],
                'date' => $payment['payment_date'], 'amount' => $costs ? $payment['amount'] : null, 'notes' => $payment['notes'],
                'resultingStatus' => $payment['resulting_status'], 'resultingLabel' => self::PAYMENT_LABELS[$payment['resulting_status']] ?? '',
                'createdByName' => $payment['created_by_name'], 'createdAt' => $payment['created_at'],
            ], $payments),
            'documents' => array_map(fn (array $document): array => [
                'id' => $document['id'], 'kind' => $document['kind'], 'kindLabel' => self::DOCUMENT_KINDS[$document['kind']] ?? $document['kind'],
                'revision' => $document['revision'] !== null ? (int) $document['revision'] : null, 'name' => $document['original_name'],
                'mimeType' => $document['mime_type'], 'size' => (int) $document['size_bytes'], 'uploadedByName' => $document['uploaded_by_name'],
                'createdAt' => $document['created_at'],
                // Eigene Bestell-PDFs und Belege von Vorgängen bleiben erhalten.
                'canDelete' => $write && $document['kind'] !== 'own_order' && !$this->orders->documentInUse((string) $document['id']),
            ], $documents),
            // Kosteneinträge im Verlauf nur mit Kostenrecht.
            'history' => array_values(array_map(fn (array $entry): array => [
                'field' => $entry['field'], 'oldValue' => $entry['old_value'], 'newValue' => $entry['new_value'],
                'label' => $this->historyLabel($entry), 'note' => $entry['note'], 'userName' => $entry['user_name'], 'createdAt' => $entry['created_at'],
            ], array_filter($this->orders->history($id), static fn (array $entry): bool => $costs || !in_array($entry['field'], ['invoice', 'cost', 'allocation'], true)))),
            'permissions' => [
                'edit' => $write && $row['status'] !== 'cancelled',
                'editItems' => $write && $row['status'] === 'draft',
                'issue' => $write && $row['status'] === 'draft',
                'newRevision' => $write && $row['origin'] === 'internal' && $row['status'] === 'ordered',
                'cancel' => $write && $row['status'] !== 'cancelled',
                'delete' => $write && $this->deletable($row),
                // Vorgänge bleiben auch nach einer Stornierung möglich (z. B. Gutschrift nach Rücksendung).
                'events' => $write && $placed,
                'documents' => $write,
                'costs' => $write && $costs && $placed,
            ],
        ];
        if ($costs) {
            $invoices = $this->orders->invoices($id);
            $calculation = PurchaseCostCalculator::calculate($this->costDocuments($invoices));
            $numbers = array_column($invoices, 'invoice_number', 'id');
            $result['invoices'] = array_map(static fn (array $invoice): array => [
                'id' => $invoice['id'], 'kind' => $invoice['kind'], 'kindLabel' => self::INVOICE_KINDS[$invoice['kind']] ?? $invoice['kind'],
                'finalMode' => $invoice['final_mode'], 'finalModeLabel' => $invoice['final_mode'] !== null ? (self::FINAL_MODES[$invoice['final_mode']] ?? '') : null,
                'invoiceNumber' => $invoice['invoice_number'], 'invoiceDate' => $invoice['invoice_date'],
                'netAmount' => $invoice['net_amount'], 'vatAmount' => $invoice['vat_amount'], 'grossAmount' => $invoice['gross_amount'],
                'relatedInvoiceId' => $invoice['related_invoice_id'],
                'relatedNumber' => $invoice['related_invoice_id'] !== null ? ($numbers[$invoice['related_invoice_id']] ?? '') : null,
                'effect' => $calculation['effects'][$invoice['id']] ?? null,
                'remaining' => $calculation['remaining'][$invoice['id']] ?? null,
                'document' => $documentRef($invoice['document_id']), 'notes' => $invoice['notes'],
                'createdByName' => $invoice['created_by_name'], 'createdAt' => $invoice['created_at'],
            ], $invoices);
            $result['invoicedNetTotal'] = $calculation['total'];
            $result['invoicedGrossTotal'] = $calculation['grossTotal'];
            $result['hasTotalFinal'] = $calculation['activeTotalFinalId'] !== null;
            $result['directActualNetTotal'] = $row['actual_net_total'];
            $paid = BigDecimal::zero();
            foreach ($payments as $payment) {
                if ($payment['amount'] !== null) {
                    $paid = $payment['kind'] === 'refund' ? $paid->minus((string) $payment['amount']) : $paid->plus((string) $payment['amount']);
                }
            }
            $result['paidTotal'] = Decimal::money($paid);
            // Offener Betrag nur, wenn alle Belege einen Bruttobetrag haben (Zahlungen sind brutto).
            $result['openGrossTotal'] = $calculation['grossTotal'] !== null ? Decimal::money(BigDecimal::of($calculation['grossTotal'])->minus($paid)) : null;
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
     * Interne Bestellung als Entwurf. Positionen folgen auf der Detailseite (manuell oder aus
     * dem Artikelstamm). Eine Übernahme aus Materialanforderungen gibt es bewusst nicht mehr.
     *
     * @param array<string,mixed> $input supplierId, orderId?, title?, deliveryAddress?, notes?
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function createInternal(array $input, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $supplier = $this->supplier($input['supplierId'] ?? '');
        $orderId = $this->optionalOrder($input['orderId'] ?? '');
        $id = Uuid::v4();
        $now = Clock::now();
        $this->transaction(function () use ($id, $now, $supplier, $orderId, $input, $actor): void {
            $number = $this->numbers->allocate('purchase_order', 'purchase_order', $id, Uuid::v4(), new DateTimeImmutable($now));
            $this->orders->insert([
                'id' => $id, 'order_number' => $number, 'origin' => 'internal', 'status' => 'draft',
                'supplier_id' => $supplier['id'], 'order_id' => $orderId,
                'title' => $this->text($input['title'] ?? '', 200, 'Bezeichnung'),
                'delivery_address' => $this->text($input['deliveryAddress'] ?? '', 1000, 'Lieferanschrift'),
                'notes' => $this->text($input['notes'] ?? '', 5000, 'Bemerkung'),
                'created_by_user_id' => (string) $actor['id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->orders->addHistory($id, Uuid::v4(), 'status', null, 'draft', '', (string) $actor['id'], $now);
        });
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
        $changed = $this->changedFields($row, $fields, $items);
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->orders->update($id, $this->version($input), $fields, $now)) {
                throw $this->conflict();
            }
            if ($items !== null) {
                $this->orders->replaceItems($id, $items);
            }
            // Nachvollziehbarkeit: welche Angaben geändert wurden (ohne Beträge im Text).
            if ($changed !== []) {
                $this->orders->addHistory($id, Uuid::v4(), 'edit', null, 'updated', implode(', ', $changed), (string) $actor['id'], $now);
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

    // -------------------------------------------------------------------------
    // Vorgänge: Auftragsbestätigungen, Lieferungen, Zahlungen
    // -------------------------------------------------------------------------

    /**
     * Auftragsbestätigung (AB) erfassen. Mehrere Fassungen sind möglich; vorherige bleiben
     * erhalten. Die erste AB wird automatisch aktuell, weitere auf Wunsch (Standard: ja).
     *
     * @param array<string,mixed> $input version, confirmationNumber?, confirmationDate, confirmedNetTotal?, confirmedDeliveryDate?, notes?, makeCurrent?
     * @param array{path:string,name:string}|null $upload
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function addConfirmation(string $id, array $input, ?array $upload, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $row = $this->placedOrder($id);
        $date = $this->date($input['confirmationDate'] ?? '', 'Bestätigungsdatum') ?? throw new OrderValidationException(['Bitte das Bestätigungsdatum angeben.']);
        $net = $this->canCosts($actor) ? $this->money($input['confirmedNetTotal'] ?? '', 'Bestätigter Nettobetrag') : null;
        if ($net !== null && BigDecimal::of($net)->isNegative()) {
            throw new OrderValidationException(['Der bestätigte Nettobetrag darf nicht negativ sein.']);
        }
        $existing = $this->orders->confirmations($id);
        $makeCurrent = $existing === [] || filter_var($input['makeCurrent'] ?? true, FILTER_VALIDATE_BOOL);
        $number = $this->text($input['confirmationNumber'] ?? '', 100, 'AB-Nummer');
        $confirmationId = Uuid::v4();
        $now = Clock::now();
        $this->withEvent($id, $input, $upload, 'order_confirmation', $actor, function (?string $documentId) use ($id, $input, $confirmationId, $number, $date, $net, $makeCurrent, $existing, $actor, $now): void {
            $this->orders->insertConfirmation([
                'id' => $confirmationId, 'purchase_order_id' => $id, 'confirmation_number' => $number, 'confirmation_date' => $date,
                'confirmed_net_total' => $net, 'confirmed_delivery_date' => $this->date($input['confirmedDeliveryDate'] ?? '', 'Bestätigter Liefertermin'),
                'document_id' => $documentId, 'notes' => $this->text($input['notes'] ?? '', 1000, 'Bemerkung'), 'is_current' => 0,
                'created_by_user_id' => (string) $actor['id'], 'created_at' => $now,
            ]);
            if ($makeCurrent) {
                $this->orders->markCurrentConfirmation($id, $confirmationId);
            }
            $label = $number !== '' ? 'AB ' . $number : 'AB vom ' . (new DateTimeImmutable($date))->format('d.m.Y');
            $this->orders->addHistory($id, Uuid::v4(), 'confirmation', null, $existing === [] ? 'added' : 'corrected',
                $label . ($makeCurrent ? ' (aktuell)' : ''), (string) $actor['id'], $now);
        });
        return $this->detail($id, $actor);
    }

    /** Eine frühere oder korrigierte AB ausdrücklich als aktuell markieren. @param array<string,mixed> $input version @param array<string,mixed> $actor @return array<string,mixed> */
    public function markCurrentConfirmation(string $id, string $confirmationId, array $input, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $this->placedOrder($id);
        $confirmation = null;
        foreach ($this->orders->confirmations($id) as $candidate) {
            if ($candidate['id'] === $confirmationId) {
                $confirmation = $candidate;
            }
        }
        if ($confirmation === null) {
            throw new OrderValidationException(['Die Auftragsbestätigung wurde nicht gefunden.']);
        }
        if ((bool) $confirmation['is_current']) {
            throw new OrderValidationException(['Diese Auftragsbestätigung ist bereits aktuell.']);
        }
        $now = Clock::now();
        $this->transaction(function () use ($id, $input, $confirmationId, $confirmation, $actor, $now): void {
            if (!$this->orders->update($id, $this->version($input), [], $now)) {
                throw $this->conflict();
            }
            $this->orders->markCurrentConfirmation($id, $confirmationId);
            $label = $confirmation['confirmation_number'] !== '' ? 'AB ' . $confirmation['confirmation_number'] : 'AB vom ' . (new DateTimeImmutable((string) $confirmation['confirmation_date']))->format('d.m.Y');
            $this->orders->addHistory($id, Uuid::v4(), 'confirmation', null, 'current', $label, (string) $actor['id'], $now);
        });
        return $this->detail($id, $actor);
    }

    /**
     * Lieferung, Teillieferung, Rücksendung oder Lieferkorrektur. Der Lieferstatus ergibt sich
     * aus dem Ereignis: Teillieferung → teilgeliefert, vollständige Lieferung → geliefert
     * (ohne Mengenabgleich je Position); bei Rücksendung und Korrektur wird er ausdrücklich gewählt.
     * Kosten ändern sich dadurch nie – eine Gutschrift ist ein eigener Beleg.
     *
     * @param array<string,mixed> $input version, kind, deliveryDate, deliveryNoteNumber?, notes?, resultingStatus?
     * @param array{path:string,name:string}|null $upload
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function addDelivery(string $id, array $input, ?array $upload, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $row = $this->placedOrder($id);
        $kind = (string) ($input['kind'] ?? '');
        if (!array_key_exists($kind, self::DELIVERY_KINDS)) {
            throw new OrderValidationException(['Bitte die Art der Lieferung wählen.']);
        }
        $date = $this->date($input['deliveryDate'] ?? '', 'Lieferdatum') ?? throw new OrderValidationException(['Bitte das Lieferdatum angeben.']);
        $status = match ($kind) {
            'partial' => 'partial',
            'complete' => 'delivered',
            default => $this->choice($input['resultingStatus'] ?? '', self::DELIVERY_LABELS, 'Bitte den Lieferstatus nach der ' . self::DELIVERY_KINDS[$kind] . ' wählen.'),
        };
        $noteNumber = $this->text($input['deliveryNoteNumber'] ?? '', 100, 'Lieferscheinnummer');
        $notes = $this->text($input['notes'] ?? '', 1000, 'Bemerkung');
        $now = Clock::now();
        $this->withEvent($id, $input, $upload, 'delivery_note', $actor, function (?string $documentId) use ($id, $row, $kind, $date, $status, $noteNumber, $notes, $actor, $now): void {
            $this->orders->insertDelivery([
                'id' => Uuid::v4(), 'purchase_order_id' => $id, 'kind' => $kind, 'delivery_date' => $date,
                'delivery_note_number' => $noteNumber, 'document_id' => $documentId, 'notes' => $notes,
                'resulting_status' => $status, 'created_by_user_id' => (string) $actor['id'], 'created_at' => $now,
            ]);
            $this->orders->addHistory($id, Uuid::v4(), 'delivery', (string) $row['delivery_status'], $status,
                trim(self::DELIVERY_KINDS[$kind] . ' vom ' . (new DateTimeImmutable($date))->format('d.m.Y') . ($noteNumber !== '' ? ' · LS ' . $noteNumber : '')), (string) $actor['id'], $now);
        }, ['delivery_status' => $status]);
        return $this->detail($id, $actor);
    }

    /**
     * Zahlungsvermerk: Zahlung, Rückerstattung oder reine Statuskorrektur. Zahlungen verändern
     * die Ist-Kosten nicht. Zahlung: „vollständig bezahlt“ → bezahlt, sonst teilbezahlt;
     * Rückerstattung und Korrektur nennen den Zahlungsstatus danach ausdrücklich.
     *
     * @param array<string,mixed> $input version, kind, paymentDate, amount?, complete?, resultingStatus?, notes?
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function addPayment(string $id, array $input, array $actor): array
    {
        $this->requireCosts($actor);
        $row = $this->placedOrder($id);
        $kind = (string) ($input['kind'] ?? '');
        if (!array_key_exists($kind, self::PAYMENT_KINDS)) {
            throw new OrderValidationException(['Bitte die Art des Zahlungsvermerks wählen.']);
        }
        $date = $this->date($input['paymentDate'] ?? '', 'Zahlungsdatum') ?? throw new OrderValidationException(['Bitte das Datum angeben.']);
        $amount = null;
        if ($kind !== 'correction') {
            $amount = $this->money($input['amount'] ?? '', 'Betrag');
            if ($amount === null || !BigDecimal::of($amount)->isPositive()) {
                throw new OrderValidationException(['Bitte einen Betrag größer 0 angeben.']);
            }
        }
        $status = $kind === 'payment'
            ? (filter_var($input['complete'] ?? false, FILTER_VALIDATE_BOOL) ? 'paid' : 'partial')
            : $this->choice($input['resultingStatus'] ?? '', self::PAYMENT_LABELS, 'Bitte den Zahlungsstatus danach wählen.');
        $notes = $this->text($input['notes'] ?? '', 1000, 'Bemerkung');
        $now = Clock::now();
        $this->transaction(function () use ($id, $input, $row, $kind, $date, $amount, $status, $notes, $actor, $now): void {
            if (!$this->orders->update($id, $this->version($input), ['payment_status' => $status], $now)) {
                throw $this->conflict();
            }
            $this->orders->insertPayment([
                'id' => Uuid::v4(), 'purchase_order_id' => $id, 'kind' => $kind, 'payment_date' => $date, 'amount' => $amount,
                'notes' => $notes, 'resulting_status' => $status, 'created_by_user_id' => (string) $actor['id'], 'created_at' => $now,
            ]);
            // Keine Beträge im Verlaufstext (Verlauf ist auch ohne Kostenrecht sichtbar).
            $this->orders->addHistory($id, Uuid::v4(), 'payment', (string) $row['payment_status'], $status,
                self::PAYMENT_KINDS[$kind] . ' vom ' . (new DateTimeImmutable($date))->format('d.m.Y'), (string) $actor['id'], $now);
        });
        return $this->detail($id, $actor);
    }

    // -------------------------------------------------------------------------
    // Dokumente
    // -------------------------------------------------------------------------

    /** Sonstige Dokumente hochladen (Belege zu AB, Lieferung, Rechnung entstehen mit dem Vorgang). @param array<string,mixed> $actor @return array<string,mixed> */
    public function addDocument(string $id, string $sourcePath, string $originalName, string $kind, array $actor): array
    {
        $this->require($actor, 'purchases.write');
        $this->find($id);
        if (!array_key_exists($kind, self::DOCUMENT_KINDS) || $kind === 'own_order') {
            throw new OrderValidationException(['Bitte eine gültige Dokumentart wählen.']);
        }
        if ($kind === 'invoice') {
            $this->require($actor, 'costs.read');
        }
        $document = $this->acceptUpload($id, $sourcePath, $originalName, $kind, $actor);
        try {
            $this->orders->insertDocument($document);
        } catch (Throwable $exception) {
            $this->discard((string) $document['storage_path']);
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
        if ($this->orders->documentInUse($documentId)) {
            throw new OrderValidationException(['Das Dokument ist Beleg einer Auftragsbestätigung, Lieferung oder Rechnung und bleibt erhalten.']);
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

    /**
     * Lieferantenbeleg erfassen: Rechnung, Teilrechnung, Schlussrechnung, Gutschrift oder Stornobeleg.
     * Beträge werden positiv erfasst; die Kostenwirkung berechnet PurchaseCostCalculator.
     * Belege werden nie gelöscht – eine Rechnung wird über einen Stornobeleg neutralisiert.
     *
     * Ist bereits ein direkter Ist-Betrag erfasst, ersetzt der Beleg ihn nur mit
     * replaceDirectCost = true (kontrollierte Umstellung, keine Doppelzählung).
     *
     * @param array<string,mixed> $input version, kind, invoiceNumber, invoiceDate, netAmount, vatAmount?, grossAmount?,
     *   finalMode?, relatedInvoiceId?, documentId?, notes?, replaceDirectCost?
     * @param array{path:string,name:string}|null $upload
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function addInvoice(string $id, array $input, ?array $upload, array $actor): array
    {
        $this->requireCosts($actor);
        $row = $this->placedOrder($id, 'Belege können erst nach dem Bestellen erfasst werden.');
        $kind = (string) ($input['kind'] ?? 'invoice');
        if (!array_key_exists($kind, self::INVOICE_KINDS)) {
            throw new OrderValidationException(['Bitte eine gültige Belegart wählen.']);
        }
        $number = $this->text($input['invoiceNumber'] ?? '', 100, 'Belegnummer');
        $date = $this->date($input['invoiceDate'] ?? '', 'Belegdatum');
        $net = $this->money($input['netAmount'] ?? '', 'Nettobetrag');
        if (($number === '' && $kind !== 'reversal') || $date === null || $net === null) {
            throw new OrderValidationException([$kind === 'reversal' ? 'Bitte Belegdatum und Nettobetrag angeben.' : 'Bitte Belegnummer, Belegdatum und Nettobetrag angeben.']);
        }
        if (!BigDecimal::of($net)->isPositive()) {
            throw new OrderValidationException(['Bitte den Nettobetrag positiv angeben – Gutschriften und Stornos mindern die Kosten automatisch.']);
        }
        $vat = $this->money($input['vatAmount'] ?? '', 'Umsatzsteuer');
        $gross = $this->money($input['grossAmount'] ?? '', 'Bruttobetrag');
        if ($vat !== null && $gross === null) {
            $gross = Decimal::money(BigDecimal::of($net)->plus($vat));
        }
        if ($vat !== null && $gross !== null && !BigDecimal::of($net)->plus($vat)->isEqualTo($gross)) {
            throw new OrderValidationException(['Nettobetrag und Umsatzsteuer ergeben nicht den Bruttobetrag.']);
        }
        if ($gross !== null && BigDecimal::of($gross)->isNegative()) {
            throw new OrderValidationException(['Der Bruttobetrag darf nicht negativ sein.']);
        }
        $finalMode = null;
        if ($kind === 'final') {
            $finalMode = (string) ($input['finalMode'] ?? '');
            if (!array_key_exists($finalMode, self::FINAL_MODES)) {
                throw new OrderValidationException(['Bitte angeben, ob die Schlussrechnung einen zusätzlichen Betrag oder den Gesamtbetrag einschließlich der Teilrechnungen ausweist.']);
            }
        }

        $invoices = $this->orders->invoices($id);
        $byId = array_column($invoices, null, 'id');
        $current = PurchaseCostCalculator::calculate($this->costDocuments($invoices));
        $relatedId = trim((string) ($input['relatedInvoiceId'] ?? ''));
        $related = $relatedId !== '' ? ($byId[$relatedId] ?? throw new OrderValidationException(['Der Originalbeleg gehört nicht zu dieser Bestellung.'])) : null;
        if ($kind === 'reversal' && $related === null) {
            throw new OrderValidationException(['Ein Stornobeleg braucht den zugehörigen Originalbeleg.']);
        }
        if ($related !== null && !in_array($kind, ['reversal', 'credit'], true)) {
            $related = null; // Bezug nur bei Storno und Gutschrift.
        }
        if ($related !== null && $related['kind'] === 'reversal') {
            throw new OrderValidationException(['Ein Stornobeleg kann nicht selbst storniert oder gutgeschrieben werden.']);
        }
        if ($kind === 'reversal' && BigDecimal::of($net)->isGreaterThan($current['remaining'][$related['id']] ?? '0')) {
            throw new OrderValidationException(['Der Stornobetrag übersteigt den noch nicht stornierten Betrag des Originalbelegs (' . $current['remaining'][$related['id']] . ' €).']);
        }
        if ($current['activeTotalFinalId'] !== null && ($kind === 'partial' || ($kind === 'final' && $finalMode === 'total'))) {
            throw new OrderValidationException([$kind === 'partial'
                ? 'Es gibt bereits eine Gesamt-Schlussrechnung. Weitere Teilrechnungen würden doppelt zählen – bitte die Schlussrechnung zuerst stornieren.'
                : 'Es gibt bereits eine Gesamt-Schlussrechnung. Bitte diese zuerst stornieren.']);
        }
        $invoiceId = Uuid::v4();
        $record = [
            'id' => $invoiceId, 'purchase_order_id' => $id, 'kind' => $kind, 'final_mode' => $finalMode,
            'related_invoice_id' => $related['id'] ?? null, 'invoice_number' => $number, 'invoice_date' => $date,
            'net_amount' => $net, 'vat_amount' => $vat, 'gross_amount' => $gross,
            'notes' => $this->text($input['notes'] ?? '', 1000, 'Bemerkung'),
            'created_by_user_id' => (string) $actor['id'], 'created_at' => Clock::now(),
        ];
        $after = PurchaseCostCalculator::calculate($this->costDocuments([...$invoices, $record]));
        if (BigDecimal::of((string) $after['total'])->isNegative()) {
            throw new OrderValidationException(['Gutschriften und Stornos würden die Kosten unter 0 senken. Bitte die Beträge prüfen.']);
        }
        $replaceDirect = $row['actual_net_total'] !== null;
        if ($replaceDirect && !filter_var($input['replaceDirectCost'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw new OrderValidationException(['Für diese Bestellung sind die Ist-Kosten direkt erfasst. Bitte die Umstellung auf Belege ausdrücklich bestätigen, damit Kosten nicht doppelt zählen.']);
        }
        $existingDocument = trim((string) ($input['documentId'] ?? ''));
        if ($upload === null && $existingDocument !== '' && $this->orders->document($id, $existingDocument) === null) {
            throw new OrderValidationException(['Das zugeordnete Dokument gehört nicht zu dieser Bestellung.']);
        }
        $fields = ['invoiced_net_total' => $after['total']] + ($replaceDirect ? ['actual_net_total' => null] : []);
        $now = (string) $record['created_at'];
        $this->withEvent($id, $input, $upload, 'invoice', $actor, function (?string $documentId) use ($id, $record, $existingDocument, $replaceDirect, $kind, $number, $related, $actor, $now): void {
            $this->orders->insertInvoice([...$record, 'document_id' => $documentId ?? ($existingDocument !== '' ? $existingDocument : null)]);
            if ($replaceDirect) {
                $this->orders->addHistory($id, Uuid::v4(), 'cost', 'direct', 'invoices', 'Direkt erfasste Ist-Kosten durch Belege ersetzt.', (string) $actor['id'], $now);
            }
            $this->orders->addHistory($id, Uuid::v4(), 'invoice', null, $kind,
                trim(($number !== '' ? 'Nr. ' . $number : '') . ($related !== null ? ' zu ' . $related['invoice_number'] : '')), (string) $actor['id'], $now);
            $this->assertAllocationsCovered($id);
        }, $fields);
        return $this->detail($id, $actor);
    }

    /**
     * Direkt erfasster tatsächlicher Nettobetrag (einfache Käufe ohne Belegerfassung) – nur
     * solange keine Belege erfasst sind. Leerer Wert entfernt den Betrag.
     *
     * @param array<string,mixed> $input version, amount
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function setActualCost(string $id, array $input, array $actor): array
    {
        $this->requireCosts($actor);
        $row = $this->placedOrder($id, 'Tatsächliche Kosten können erst nach dem Bestellen erfasst werden.');
        $amount = $this->money($input['amount'] ?? '', 'Tatsächlicher Nettobetrag');
        if ($amount !== null && BigDecimal::of($amount)->isNegative()) {
            throw new OrderValidationException(['Der tatsächliche Nettobetrag darf nicht negativ sein.']);
        }
        if ($this->orders->invoices($id) !== []) {
            throw new OrderValidationException(['Es sind bereits Lieferantenbelege erfasst; deren Kostenwirkung ist der tatsächliche Betrag. Ein direkter Betrag würde Kosten doppelt zählen.']);
        }
        if ($amount === $row['actual_net_total']) {
            return $this->detail($id, $actor);
        }
        $now = Clock::now();
        $this->transaction(function () use ($id, $input, $amount, $row, $actor, $now): void {
            if (!$this->orders->update($id, $this->version($input), ['actual_net_total' => $amount], $now)) {
                throw $this->conflict();
            }
            $this->orders->addHistory($id, Uuid::v4(), 'cost', $row['actual_net_total'] !== null ? 'direct' : null, $amount !== null ? 'direct' : 'none',
                $amount === null ? 'Direkt erfasste Ist-Kosten entfernt.' : ($row['actual_net_total'] === null ? 'Ist-Kosten direkt erfasst.' : 'Direkt erfasste Ist-Kosten geändert.'), (string) $actor['id'], $now);
            $this->assertAllocationsCovered($id);
        });
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
        $row = $this->placedOrder($id, 'Die Kostenverteilung ist erst nach dem Bestellen möglich.');
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
        $this->transaction(function () use ($id, $input, $allocations, $actor, $now): void {
            if (!$this->orders->update($id, $this->version($input), [], $now)) {
                throw $this->conflict();
            }
            $this->orders->replaceAllocations($id, array_values($allocations), $now);
            $this->orders->addHistory($id, Uuid::v4(), 'allocation', null, $allocations === [] ? 'removed' : 'saved',
                $allocations === [] ? 'Kostenverteilung entfernt.' : 'Kostenverteilung auf ' . count($allocations) . ' Auftrag/Aufträge gespeichert.', (string) $actor['id'], $now);
        });
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
            'placed' => $this->placed($row),
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
            // Drei getrennte Beträge: ursprünglich bestellt, aktuell bestätigt, tatsächlich berechnet.
            'expectedNetTotal' => $costs ? $row['expected_net_total'] : null,
            'confirmedNetTotal' => $costs ? ($row['confirmed_net_total'] ?? null) : null,
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
     * Ist-Kosten: direkt erfasster Betrag oder Kostenwirkung der Belege (nie beides).
     * invoiced_net_total wird bei jeder Belegerfassung über PurchaseCostCalculator neu gesetzt.
     *
     * @param array<string,mixed> $row
     */
    private function actualCost(array $row): ?string
    {
        if ($row['actual_net_total'] !== null) {
            return (string) $row['actual_net_total'];
        }
        return $row['invoiced_net_total'] !== null ? (string) $row['invoiced_net_total'] : null;
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

    /**
     * Gemeinsamer Ablauf für Vorgänge mit optionalem Beleg: Datei prüfen und ablegen, dann in
     * einer Transaktion Version prüfen, Dokument und Vorgang speichern. Fehler räumen die Datei auf.
     *
     * @param array<string,mixed> $input
     * @param array{path:string,name:string}|null $upload
     * @param array<string,mixed> $actor
     * @param callable(?string):void $store erhält die ID des neuen Dokuments (oder null)
     * @param array<string,mixed> $fields zusätzliche Spalten der Bestellung (z. B. abgeleiteter Status)
     */
    private function withEvent(string $id, array $input, ?array $upload, string $documentKind, array $actor, callable $store, array $fields = []): void
    {
        $document = $upload !== null ? $this->acceptUpload($id, $upload['path'], $upload['name'], $documentKind, $actor) : null;
        $now = Clock::now();
        try {
            $this->transaction(function () use ($id, $input, $fields, $document, $store, $now): void {
                if (!$this->orders->update($id, $this->version($input), $fields, $now)) {
                    throw $this->conflict();
                }
                if ($document !== null) {
                    $this->orders->insertDocument($document);
                }
                $store($document['id'] ?? null);
            });
        } catch (Throwable $exception) {
            if ($document !== null) {
                $this->discard((string) $document['storage_path']);
            }
            throw $exception;
        }
    }

    /**
     * Prüft eine hochgeladene Datei (Typ am Inhalt, Größe, Anzahl) und legt sie ab.
     * Liefert die Dokumentzeile; das Einfügen in die Datenbank übernimmt der Aufrufer.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function acceptUpload(string $id, string $sourcePath, string $originalName, string $kind, array $actor): array
    {
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
        $path = $this->store->store($sourcePath, self::FOLDER . '/' . $id, $documentId, $extension);
        return [
            'id' => $documentId, 'purchase_order_id' => $id, 'kind' => $kind, 'revision' => null,
            'original_name' => $this->fileName($originalName, $extension), 'mime_type' => $mime, 'size_bytes' => $size,
            'sha256' => $sha256, 'storage_path' => $path, 'uploaded_by_user_id' => (string) $actor['id'], 'created_at' => Clock::now(),
        ];
    }

    /** @param callable():void $operation */
    private function transaction(callable $operation): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $operation();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    /** Bereits bestellt (auch storniert oder für eine neue Fassung geöffnet)? @param array<string,mixed> $row */
    private function placed(array $row): bool
    {
        return $row['status'] !== 'draft' || (int) $row['revision'] > 0;
    }

    /** @return array<string,mixed> */
    private function placedOrder(string $id, string $message = 'Vorgänge können erst nach dem Bestellen erfasst werden.'): array
    {
        $row = $this->find($id);
        if (!$this->placed($row)) {
            throw new OrderValidationException([$message]);
        }
        return $row;
    }

    /** @param array<string,string> $allowed */
    private function choice(mixed $value, array $allowed, string $message): string
    {
        $value = (string) $value;
        return array_key_exists($value, $allowed) ? $value : throw new OrderValidationException([$message]);
    }

    /**
     * @param list<array<string,mixed>> $invoices
     * @return list<array{id:string,kind:string,final_mode:?string,related_invoice_id:?string,net_amount:string,gross_amount:?string}>
     */
    private function costDocuments(array $invoices): array
    {
        return array_map(static fn (array $invoice): array => [
            'id' => (string) $invoice['id'], 'kind' => (string) $invoice['kind'], 'final_mode' => $invoice['final_mode'] ?? null,
            'related_invoice_id' => $invoice['related_invoice_id'] ?? null, 'net_amount' => (string) $invoice['net_amount'],
            'gross_amount' => $invoice['gross_amount'] ?? null,
        ], $invoices);
    }

    /**
     * Bezeichnungen der geänderten Angaben für den Verlauf (keine Beträge im Text).
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $fields
     * @param list<array<string,mixed>>|null $items
     * @return list<string>
     */
    private function changedFields(array $row, array $fields, ?array $items): array
    {
        $labels = [
            'title' => 'Bezeichnung', 'external_reference' => 'Externe Bestellnummer', 'expected_delivery_date' => 'Liefertermin',
            'order_id' => 'Auftrag', 'notes' => 'Bemerkung', 'supplier_id' => 'Lieferant', 'delivery_address' => 'Lieferanschrift',
            'order_date' => 'Bestelldatum', 'expected_net_total' => 'Bestellwert',
        ];
        $changed = [];
        foreach ($labels as $column => $label) {
            if (array_key_exists($column, $fields) && (string) ($fields[$column] ?? '') !== (string) ($row[$column] ?? '')) {
                $changed[] = $label;
            }
        }
        if ($items !== null) {
            $changed[] = 'Positionen';
        }
        return $changed;
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
        $new = (string) $entry['new_value'];
        $old = $entry['old_value'];
        return match ($entry['field']) {
            'status' => 'Bestellstatus: ' . ($old !== null ? (self::STATUS_LABELS[$old] ?? $old) . ' → ' : '') . (self::STATUS_LABELS[$new] ?? $new),
            'delivery' => 'Lieferstatus: ' . ($old !== null ? (self::DELIVERY_LABELS[$old] ?? $old) . ' → ' : '') . (self::DELIVERY_LABELS[$new] ?? $new),
            'payment' => 'Zahlungsstatus: ' . ($old !== null ? (self::PAYMENT_LABELS[$old] ?? $old) . ' → ' : '') . (self::PAYMENT_LABELS[$new] ?? $new),
            'revision' => 'Neue Fassung ' . $new . ' geöffnet',
            'confirmation' => match ($new) {
                'current' => 'Auftragsbestätigung als aktuell markiert',
                'corrected' => 'Weitere Auftragsbestätigung erfasst',
                default => 'Auftragsbestätigung erfasst',
            },
            'invoice' => (self::INVOICE_KINDS[$new] ?? 'Beleg') . ' erfasst',
            'cost' => 'Ist-Kosten',
            'allocation' => 'Kostenverteilung',
            'edit' => 'Angaben geändert',
            default => (string) $entry['field'],
        };
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

    /** Lieferanten- und Adressdaten zum Zeitpunkt der Bestellung. @param array<string,mixed> $supplier */
    private function snapshot(array $supplier): string
    {
        return json_encode([
            'id' => $supplier['id'], 'number' => (string) ($supplier['supplier_number'] ?? ''), 'name' => (string) $supplier['name'],
            'contact' => (string) ($supplier['contact'] ?? ''),
            'street' => (string) ($supplier['street'] ?? ''), 'houseNumber' => (string) ($supplier['house_number'] ?? ''),
            'postalCode' => (string) ($supplier['postal_code'] ?? ''), 'city' => (string) ($supplier['city'] ?? ''),
            'country' => (string) ($supplier['country'] ?? ''), 'customerNumber' => (string) ($supplier['customer_number'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
