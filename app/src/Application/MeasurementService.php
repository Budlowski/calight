<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\Order\OrderBasisPositions;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Persistence\MeasurementRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Security\RolePermissions;
use App\Support\Clock;
use App\Support\ListQuery;
use App\Support\Uuid;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Aufmaße: zusätzliche ausgeführte Mengen verbindlicher Auftragspositionen.
 *
 * Bewertet wird ausschließlich mit den verbindlichen Einzelpreisen der
 * Auftragsgrundlage bzw. angenommener Nachträge; es findet keine erneute
 * Kalkulation statt. Mengen eines Aufmaßes sind Zusatzmengen, der Fortschritt
 * ergibt sich aus der Summe abgeschlossener Aufmaße.
 */
final readonly class MeasurementService
{
    private const SOURCE_ORDER = 'order';
    private const SOURCE_ADDENDUM = 'addendum';

    public function __construct(
        private PDO $pdo,
        private MeasurementRepository $measurements,
        private OrderRepository $orders,
        private NumberRangeRepository $numbers,
        private OrderBasisPositions $basis = new OrderBasisPositions(),
    ) {
    }

    /**
     * @param array<string,mixed> $parameters URL-Parameter (q, status, order, sort, dir, page, per_page)
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function page(array $parameters, array $actor): array
    {
        $query = ListQuery::fromArray($parameters, MeasurementRepository::LIST_SORTS, 'updated', MeasurementRepository::LIST_FILTERS);
        $page = $this->measurements->page($query, $this->isTechnician($actor));
        $page['items'] = array_map(fn (array $row): array => $this->summary($row, $actor), $page['items']);
        return $page;
    }

    /**
     * Aufmaße eines Auftrags mit Gesamtfortschritt (für die Auftragsdetailseite).
     *
     * @param array<string,mixed> $actor
     * @return array{measurements:list<array<string,mixed>>,measurable:bool,completedNetTotal:string,progress:array{positions:int,started:int,complete:int,overrun:int}}
     */
    public function forOrder(string $orderId, array $actor): array
    {
        $order = $this->accessibleOrder($orderId, $actor);
        $positions = $this->positionMap($this->sources($order));
        $completed = $this->sumByKey($this->measurements->completedQuantities($orderId));
        $progress = ['positions' => count($positions), 'started' => 0, 'complete' => 0, 'overrun' => 0];
        foreach ($positions as $key => $position) {
            $total = $completed[$key] ?? BigDecimal::zero();
            $agreed = BigDecimal::of($position['agreedQuantity']);
            if ($total->isGreaterThan(0)) {
                $progress['started']++;
            }
            if ($agreed->isGreaterThan(0) && $total->isGreaterThanOrEqualTo($agreed)) {
                $progress['complete']++;
            }
            if ($total->isGreaterThan($agreed)) {
                $progress['overrun']++;
            }
        }
        $rows = $this->measurements->forOrder($orderId);
        $net = BigDecimal::zero();
        foreach ($rows as $row) {
            if ($row['status'] === 'completed') {
                $net = $net->plus((string) $row['net_total']);
            }
        }
        return [
            'measurements' => array_map(fn (array $row): array => $this->summary($row, $actor), $rows),
            'measurable' => $positions !== [],
            'completedNetTotal' => Decimal::money($net),
            'progress' => $progress,
        ];
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function create(array $input, array $actor): array
    {
        $this->requireWrite($actor);
        $correctionOf = trim((string) ($input['correctionOf'] ?? ''));
        $orderId = trim((string) ($input['orderId'] ?? ''));
        $corrected = null;
        if ($correctionOf !== '') {
            // Korrekturen abgeschlossener Aufmaße sind dem Abschlussrecht vorbehalten.
            if (!$this->can($actor, 'measurements.complete')) {
                throw new AccessDeniedException('measurements.complete');
            }
            $corrected = $this->measurements->find($correctionOf);
            if ($corrected === null || $corrected['status'] !== 'completed') {
                throw new OrderValidationException(['Korrigiert werden können nur abgeschlossene Aufmaße.']);
            }
            $orderId = (string) $corrected['order_id'];
        }
        $order = $this->accessibleOrder($orderId, $actor);
        if ($this->positionMap($this->sources($order)) === []) {
            throw new OrderValidationException(['Für diesen Auftrag gibt es keine verbindlichen Leistungspositionen. Direkte Regieaufträge werden über Rapporte abgerechnet; Aufmaße sind erst mit angenommenen Nachträgen möglich.']);
        }
        $date = trim((string) ($input['measurementDate'] ?? '')) ?: date('Y-m-d');
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = $corrected !== null ? 'Korrektur zu ' . $corrected['measurement_number'] : 'Aufmaß vom ' . (new DateTimeImmutable($this->validDate($date)))->format('d.m.Y');
        }
        $this->validateHeader($title, $date, (string) ($input['notes'] ?? ''));
        $id = Uuid::v4();
        $now = Clock::now();
        $this->measurements->insert([
            'id' => $id,
            'order_id' => $orderId,
            'title' => $title,
            'measurement_date' => $date,
            'notes' => trim((string) ($input['notes'] ?? '')),
            'correction_of_id' => $corrected['id'] ?? null,
            'created_by_user_id' => (string) $actor['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->detail($id, $actor);
    }

    /**
     * Vollständige Ansicht: Positionsbaum je Quelle mit Mengenfortschritt und Summen.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function detail(string $id, array $actor): array
    {
        $measurement = $this->accessibleMeasurement($id, $actor);
        $order = $this->orders->find((string) $measurement['order_id']);
        $sources = $this->sources((array) $order);
        $completed = $measurement['status'] === 'completed';
        $items = $this->measurements->items($id);
        $current = [];
        foreach ($items as $item) {
            $current[$this->itemKey($item)] = $item;
        }
        $previous = $this->sumByKey($this->measurements->completedQuantities(
            (string) $measurement['order_id'],
            $id,
            $completed ? ['at' => (string) $measurement['completed_at'], 'number' => (string) $measurement['measurement_number']] : null,
        ));

        $resultSources = [];
        foreach ($sources as $source) {
            $resultSources[] = [
                'type' => $source['type'],
                'id' => $source['id'],
                'label' => $source['label'],
                'adjustment' => $source['adjustment'],
                'nodes' => $this->progressTree($source['nodes'], $current, $previous),
            ];
        }
        // Positionen, die in der aktuellen Grundlage nicht mehr vorkommen, bleiben über den Snapshot sichtbar.
        $known = $this->positionMap($sources);
        $orphans = array_values(array_filter($items, fn (array $item): bool => !isset($known[$this->itemKey($item)])));

        $result = $this->summary($measurement, $actor);
        $result['notes'] = $measurement['notes'];
        $result['sources'] = $resultSources;
        $result['orphanItems'] = array_map(fn (array $item): array => $this->mapItem($item), $orphans);
        $result['totals'] = $completed
            ? array_map(static fn (array $row): array => [
                'sourceType' => $row['source_type'], 'sourceId' => $row['source_id'], 'label' => $row['label'],
                'itemsTotal' => $row['items_total'], 'adjustableTotal' => $row['adjustable_total'],
                'adjustmentAmount' => $row['adjustment_amount'], 'netTotal' => $row['net_total'],
            ], $this->measurements->sourceTotals($id))
            : $this->sourceTotals($sources, $items);
        $result['overruns'] = $completed ? [] : $this->overruns($items, $previous);
        return $result;
    }

    /**
     * Speichert Kopfdaten und Mengen eines Entwurfs. Leere Mengen und 0 entfernen die Position.
     *
     * @param array<string,mixed> $input version, title, measurementDate, notes, items: list<{key,quantity}>
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function update(string $id, array $input, array $actor): array
    {
        $measurement = $this->editableDraft($id, $actor);
        $title = trim((string) ($input['title'] ?? ''));
        $date = trim((string) ($input['measurementDate'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $this->validateHeader($title, $date, $notes);
        $order = $this->orders->find((string) $measurement['order_id']);
        $positions = $this->positionMap($this->sources((array) $order));
        $items = $this->validateItems($input['items'] ?? [], $positions, $id, $measurement['correction_of_id'] !== null);

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->measurements->updateDraft($id, $this->version($input), $title, $date, $notes, Clock::now())) {
                throw new OrderValidationException(['Das Aufmaß wurde zwischenzeitlich geändert oder abgeschlossen. Bitte neu laden.']);
            }
            $this->measurements->replaceItems($id, $items);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /**
     * Schließt ein Aufmaß verbindlich ab. Überschreitungen der vereinbarten Menge
     * erfordern eine ausdrückliche Bestätigung (confirmOverrun).
     *
     * @param array<string,mixed> $input version, confirmOverrun
     * @param array<string,mixed> $actor
     * @return array{completed:bool,overruns?:list<array<string,mixed>>,measurement?:array<string,mixed>}
     */
    public function complete(string $id, array $input, array $actor): array
    {
        if (!$this->can($actor, 'measurements.complete')) {
            throw new AccessDeniedException('measurements.complete');
        }
        $version = $this->version($input);
        $confirmed = filter_var($input['confirmOverrun'] ?? false, FILTER_VALIDATE_BOOL);

        // Die Prüfung gegen bereits abgeschlossene Mengen und der Abschluss laufen in
        // derselben Schreibtransaktion: Ein gleichzeitig abgeschlossenes Aufmaß desselben
        // Auftrags wird damit zwingend berücksichtigt.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $measurement = $this->accessibleMeasurement($id, $actor);
            if ($measurement['status'] !== 'draft' || (int) $measurement['version'] !== $version) {
                throw new OrderValidationException(['Das Aufmaß wurde zwischenzeitlich geändert oder abgeschlossen. Bitte neu laden.']);
            }
            $items = $this->measurements->items($id);
            if ($items === []) {
                throw new OrderValidationException(['Ein Aufmaß ohne Mengen kann nicht abgeschlossen werden.']);
            }
            $previous = $this->sumByKey($this->measurements->completedQuantities((string) $measurement['order_id'], $id));
            $this->assertNotBelowZero($items, $previous);
            $overruns = $this->overruns($items, $previous);
            if ($overruns !== [] && !$confirmed) {
                $this->pdo->rollBack();
                return ['completed' => false, 'overruns' => $overruns];
            }
            $this->measurements->markOverrunsConfirmed($id, array_column($overruns, 'key'));

            $order = $this->orders->find((string) $measurement['order_id']);
            $totals = $this->sourceTotals($this->sources((array) $order), $items);
            $net = BigDecimal::zero();
            foreach ($totals as $total) {
                $net = $net->plus($total['netTotal']);
            }
            $now = Clock::now();
            $number = $this->numbers->allocate('measurement', 'measurement', $id, Uuid::v4(), new DateTimeImmutable());
            $stored = array_map(static fn (array $total): array => [
                'source_type' => $total['sourceType'], 'source_id' => $total['sourceId'], 'label' => $total['label'],
                'items_total' => $total['itemsTotal'], 'adjustable_total' => $total['adjustableTotal'],
                'adjustment_amount' => $total['adjustmentAmount'], 'net_total' => $total['netTotal'],
            ], $totals);
            if (!$this->measurements->complete($id, $version, $number, Decimal::money($net), (string) $actor['id'], $now, $stored)) {
                throw new OrderValidationException(['Das Aufmaß wurde zwischenzeitlich geändert oder abgeschlossen. Bitte neu laden.']);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return ['completed' => true, 'measurement' => $this->detail($id, $actor)];
    }

    /** @param array<string,mixed> $actor */
    public function delete(string $id, int $version, array $actor): void
    {
        $this->editableDraft($id, $actor);
        if (!$this->measurements->deleteDraft($id, $version)) {
            throw new OrderValidationException(['Das Aufmaß wurde zwischenzeitlich geändert oder abgeschlossen. Bitte neu laden.']);
        }
    }

    // -------------------------------------------------------------------------
    // Grundlage und Berechnung
    // -------------------------------------------------------------------------

    /**
     * Verbindliche Quellen: ursprüngliche Auftragsgrundlage und angenommene Nachträge.
     * Offene oder abgelehnte Nachträge stehen nie in order_addenda und erscheinen daher nicht.
     *
     * @param array<string,mixed> $order
     * @return list<array<string,mixed>>
     */
    private function sources(array $order): array
    {
        $sources = [];
        if (($order['basis_json'] ?? null) !== null) {
            $label = 'Auftragsgrundlage' . (($order['source_offer_number'] ?? null) ? ' · Angebot ' . $order['source_offer_number'] : '');
            $sources[] = ['type' => self::SOURCE_ORDER, 'id' => (string) $order['id'], 'label' => $label, 'json' => (string) $order['basis_json']];
        }
        foreach ($this->measurements->acceptedAddenda((string) ($order['id'] ?? '')) as $addendum) {
            $label = 'Nachtrag ' . ($addendum['offer_number'] ?? '') . ((int) $addendum['revision_number'] > 0 ? ' · Revision ' . (int) $addendum['revision_number'] : '');
            $sources[] = ['type' => self::SOURCE_ADDENDUM, 'id' => (string) $addendum['id'], 'label' => trim($label), 'json' => (string) $addendum['basis_json']];
        }
        return $this->basis->read($sources);
    }

    /** @param list<array<string,mixed>> $sources @return array<string,array<string,mixed>> */
    private function positionMap(array $sources): array
    {
        $map = [];
        foreach ($sources as $source) {
            foreach ($source['positions'] as $key => $position) {
                $map[$key] = $position;
            }
        }
        return $map;
    }

    /**
     * @param mixed $raw
     * @param array<string,array<string,mixed>> $positions
     * @return list<array<string,mixed>>
     */
    private function validateItems(mixed $raw, array $positions, string $measurementId, bool $correction): array
    {
        if (!is_array($raw)) {
            throw new OrderValidationException(['Aufmaßmengen müssen als Liste übergeben werden.']);
        }
        $items = [];
        $seen = [];
        $order = array_flip(array_keys($positions));
        foreach (array_values($raw) as $entry) {
            $key = is_array($entry) ? (string) ($entry['key'] ?? '') : '';
            $position = $positions[$key] ?? null;
            if ($position === null) {
                throw new OrderValidationException(['Eine Aufmaßposition gehört nicht zu den verbindlichen Leistungen dieses Auftrags.']);
            }
            if (isset($seen[$key])) {
                throw new OrderValidationException(['Position ' . $position['number'] . ' wurde mehrfach übermittelt.']);
            }
            $seen[$key] = true;
            $text = trim(str_replace(',', '.', (string) ($entry['quantity'] ?? '')));
            if ($text === '') {
                continue;
            }
            try {
                $quantity = Decimal::parse($text, Decimal::QUANTITY_SCALE, 'Menge');
            } catch (\InvalidArgumentException $exception) {
                throw new OrderValidationException(['Position ' . $position['number'] . ': ' . $exception->getMessage()]);
            }
            if ($quantity->isZero()) {
                continue;
            }
            if ($quantity->isNegative() && !$correction) {
                throw new OrderValidationException(['Position ' . $position['number'] . ': Negative Mengen sind nur in Korrekturaufmaßen zulässig.']);
            }
            $items[] = [
                'id' => Uuid::v4(),
                'measurement_id' => $measurementId,
                'source_type' => $position['sourceType'],
                'source_id' => $position['sourceId'],
                'node_id' => $position['nodeId'],
                'position_number' => $position['number'],
                'short_text' => $position['shortText'],
                'unit' => $position['unit'],
                'effective_type' => $position['effectiveType'],
                'agreed_quantity' => $position['agreedQuantity'],
                'unit_price' => $position['unitPrice'],
                'quantity' => (string) $quantity->toScale(Decimal::QUANTITY_SCALE, RoundingMode::HalfUp),
                'line_total' => $this->lineTotal($quantity, $position['unitPrice']),
                'overrun_confirmed' => 0,
                'sort_order' => $order[$key],
            ];
        }
        return $items;
    }

    /** Gleiche Rundung wie in der Angebotskalkulation: Menge × Einzelpreis, kaufmännisch auf Cent. */
    private function lineTotal(BigDecimal $quantity, string $unitPrice): string
    {
        return Decimal::money($quantity->multipliedBy($unitPrice));
    }

    /**
     * Summen je Quelldokument. Nachlass/Aufschlag des Angebots bzw. Nachtrags gilt nur für
     * Normalpositionen und wird im Verhältnis zur vereinbarten Normalsumme anteilig angewendet,
     * damit ein vollständig aufgemessener Auftrag (bis auf Rundung) seiner Auftragssumme entspricht.
     *
     * @param list<array<string,mixed>> $sources
     * @param list<array<string,mixed>> $items
     * @return list<array{sourceType:string,sourceId:string,label:string,itemsTotal:string,adjustableTotal:string,adjustmentAmount:string,netTotal:string}>
     */
    private function sourceTotals(array $sources, array $items): array
    {
        $labels = [];
        $adjustments = [];
        foreach ($sources as $source) {
            $sourceKey = $source['type'] . ':' . $source['id'];
            $labels[$sourceKey] = $source['label'];
            $adjustments[$sourceKey] = $source['adjustment'];
        }
        $groups = [];
        foreach ($items as $item) {
            $sourceKey = $item['source_type'] . ':' . $item['source_id'];
            $groups[$sourceKey] ??= ['type' => $item['source_type'], 'id' => $item['source_id'], 'items' => BigDecimal::zero(), 'adjustable' => BigDecimal::zero()];
            $groups[$sourceKey]['items'] = $groups[$sourceKey]['items']->plus($item['line_total']);
            if ($item['effective_type'] === 'normal') {
                $groups[$sourceKey]['adjustable'] = $groups[$sourceKey]['adjustable']->plus($item['line_total']);
            }
        }
        $result = [];
        foreach ($groups as $sourceKey => $group) {
            $adjustment = BigDecimal::zero();
            $base = $adjustments[$sourceKey] ?? null;
            if ($base !== null && !BigDecimal::of($base['subtotal'])->isZero() && !BigDecimal::of($base['signed'])->isZero()) {
                $adjustment = $group['adjustable']->multipliedBy($base['signed'])->dividedBy($base['subtotal'], Decimal::MONEY_SCALE, RoundingMode::HalfUp);
            }
            $result[] = [
                'sourceType' => $group['type'],
                'sourceId' => $group['id'],
                'label' => $labels[$sourceKey] ?? 'Nicht mehr in der Auftragsgrundlage',
                'itemsTotal' => Decimal::money($group['items']),
                'adjustableTotal' => Decimal::money($group['adjustable']),
                'adjustmentAmount' => Decimal::money($adjustment),
                'netTotal' => Decimal::money($group['items']->plus($adjustment)),
            ];
        }
        return $result;
    }

    /**
     * Positionen, deren Gesamtmenge nach diesem Aufmaß die vereinbarte Menge übersteigt.
     *
     * @param list<array<string,mixed>> $items
     * @param array<string,BigDecimal> $previous
     * @return list<array{key:string,number:string,shortText:string,unit:string,agreed:string,total:string,excess:string}>
     */
    private function overruns(array $items, array $previous): array
    {
        $result = [];
        foreach ($items as $item) {
            $key = $this->itemKey($item);
            $quantity = BigDecimal::of($item['quantity']);
            $total = ($previous[$key] ?? BigDecimal::zero())->plus($quantity);
            $agreed = BigDecimal::of($item['agreed_quantity']);
            if ($quantity->isPositive() && $total->isGreaterThan($agreed)) {
                $result[] = [
                    'key' => $key,
                    'number' => $item['position_number'],
                    'shortText' => $item['short_text'],
                    'unit' => $item['unit'],
                    'agreed' => $this->quantity($agreed),
                    'total' => $this->quantity($total),
                    'excess' => $this->quantity($total->minus($agreed)),
                ];
            }
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $items @param array<string,BigDecimal> $previous */
    private function assertNotBelowZero(array $items, array $previous): void
    {
        foreach ($items as $item) {
            $total = ($previous[$this->itemKey($item)] ?? BigDecimal::zero())->plus($item['quantity']);
            if ($total->isNegative()) {
                throw new OrderValidationException(['Position ' . $item['position_number'] . ': Die Korrektur würde die insgesamt aufgemessene Menge unter null senken.']);
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param array<string,array<string,mixed>> $current
     * @param array<string,BigDecimal> $previous
     * @return list<array<string,mixed>>
     */
    private function progressTree(array $nodes, array $current, array $previous): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($node['kind'] === 'title') {
                $node['children'] = $this->progressTree($node['children'], $current, $previous);
                $result[] = $node;
                continue;
            }
            $key = $node['key'];
            $item = $current[$key] ?? null;
            // Abgeschlossene Aufmaße zeigen ihren Snapshot, Entwürfe die aktuelle Grundlage.
            $unitPrice = $item['unit_price'] ?? $node['unitPrice'];
            $agreed = BigDecimal::of($item['agreed_quantity'] ?? $node['agreedQuantity']);
            $before = $previous[$key] ?? BigDecimal::zero();
            $quantity = $item !== null ? BigDecimal::of($item['quantity']) : BigDecimal::zero();
            $total = $before->plus($quantity);
            $result[] = [
                ...$node,
                'unitPrice' => $unitPrice,
                'agreedQuantity' => $this->quantity($agreed),
                'agreedTotal' => $this->lineTotal($agreed, $unitPrice),
                'previousQuantity' => $this->quantity($before),
                'quantity' => $item !== null ? $this->quantity($quantity) : '',
                'lineTotal' => $item['line_total'] ?? '0.00',
                'totalQuantity' => $this->quantity($total),
                'totalValue' => $this->lineTotal($total, $unitPrice),
                'openQuantity' => $this->quantity($agreed->minus($total)),
                'overrun' => $total->isGreaterThan($agreed),
                'overrunConfirmed' => (int) ($item['overrun_confirmed'] ?? 0) === 1,
            ];
        }
        return $result;
    }

    /** @param list<array{source_type:string,source_id:string,node_id:string,quantity:string}> $rows @return array<string,BigDecimal> */
    private function sumByKey(array $rows): array
    {
        $sums = [];
        foreach ($rows as $row) {
            $key = $this->itemKey($row);
            $sums[$key] = ($sums[$key] ?? BigDecimal::zero())->plus($row['quantity']);
        }
        return $sums;
    }

    /** @param array<string,mixed> $item */
    private function itemKey(array $item): string
    {
        return $item['source_type'] . ':' . $item['source_id'] . ':' . $item['node_id'];
    }

    private function quantity(BigDecimal $value): string
    {
        return (string) $value->toScale(Decimal::QUANTITY_SCALE, RoundingMode::HalfUp);
    }

    // -------------------------------------------------------------------------
    // Rechte, Zugriff und Validierung
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function accessibleOrder(string $orderId, array $actor): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new OrderValidationException(['Auftrag wurde nicht gefunden.']);
        }
        if ($this->isTechnician($actor) && $order['status'] === 'open') {
            throw new AccessDeniedException('orders.released.read');
        }
        return $order;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function accessibleMeasurement(string $id, array $actor): array
    {
        $measurement = $this->measurements->find($id);
        if ($measurement === null) {
            throw new OrderValidationException(['Aufmaß wurde nicht gefunden.']);
        }
        if ($this->isTechnician($actor) && $measurement['order_status'] === 'open') {
            throw new AccessDeniedException('orders.released.read');
        }
        return $measurement;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function editableDraft(string $id, array $actor): array
    {
        $this->requireWrite($actor);
        $measurement = $this->accessibleMeasurement($id, $actor);
        if ($measurement['status'] !== 'draft') {
            throw new OrderValidationException(['Abgeschlossene Aufmaße sind unveränderlich. Änderungen bitte über ein Korrekturaufmaß erfassen.']);
        }
        if (!$this->canEditDraft($measurement, $actor)) {
            throw new AccessDeniedException('measurements.write.own');
        }
        return $measurement;
    }

    /** @param array<string,mixed> $measurement @param array<string,mixed> $actor */
    private function canEditDraft(array $measurement, array $actor): bool
    {
        // Monteure bearbeiten eigene Entwürfe; Büro/Admin (Abschlussrecht) alle.
        return $measurement['status'] === 'draft' && $this->can($actor, 'measurements.write')
            && ($this->can($actor, 'measurements.complete') || (string) $measurement['created_by_user_id'] === (string) ($actor['id'] ?? ''));
    }

    private function validateHeader(string $title, string $date, string $notes): void
    {
        $errors = [];
        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = 'Die Bezeichnung ist erforderlich und darf höchstens 200 Zeichen lang sein.';
        }
        if (mb_strlen($notes) > 5000) {
            $errors[] = 'Bemerkungen dürfen höchstens 5.000 Zeichen lang sein.';
        }
        try {
            $this->validDate($date);
        } catch (OrderValidationException $exception) {
            $errors = [...$errors, ...$exception->errors];
        }
        if ($errors !== []) {
            throw new OrderValidationException($errors);
        }
    }

    private function validDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new OrderValidationException(['Das Aufmaßdatum ist ungültig.']);
        }
        return $date;
    }

    /** @param array<string,mixed> $input */
    private function version(array $input): int
    {
        $version = (int) ($input['version'] ?? 0);
        if ($version < 1) {
            throw new OrderValidationException(['Versionsangabe fehlt.']);
        }
        return $version;
    }

    /** @param array<string,mixed> $actor */
    private function requireWrite(array $actor): void
    {
        if (!$this->can($actor, 'measurements.write')) {
            throw new AccessDeniedException('measurements.write');
        }
    }

    /** @param array<string,mixed> $actor */
    private function can(array $actor, string $permission): bool
    {
        return RolePermissions::allows((string) ($actor['role'] ?? ''), $permission);
    }

    /** @param array<string,mixed> $actor */
    private function isTechnician(array $actor): bool
    {
        return ($actor['role'] ?? '') === 'technician';
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $actor @return array<string,mixed> */
    private function summary(array $row, array $actor): array
    {
        return [
            'id' => $row['id'],
            'orderId' => $row['order_id'],
            'orderNumber' => $row['order_number'],
            'orderTitle' => $row['order_title'],
            'customerName' => $row['customer_name'],
            'measurementNumber' => $row['measurement_number'],
            'title' => $row['title'],
            'measurementDate' => $row['measurement_date'],
            'status' => $row['status'],
            'netTotal' => $row['net_total'],
            'itemCount' => (int) $row['item_count'],
            'correctionOfId' => $row['correction_of_id'],
            'correctedNumber' => $row['corrected_number'],
            'creatorName' => $row['creator_name'],
            'completedByName' => $row['completed_by_name'],
            'completedAt' => $row['completed_at'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'version' => (int) $row['version'],
            'canEdit' => $this->canEditDraft($row, $actor),
            'canComplete' => $row['status'] === 'draft' && $this->can($actor, 'measurements.complete'),
            'canCorrect' => $row['status'] === 'completed' && $this->can($actor, 'measurements.complete'),
        ];
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function mapItem(array $item): array
    {
        return [
            'key' => $this->itemKey($item),
            'number' => $item['position_number'],
            'shortText' => $item['short_text'],
            'unit' => $item['unit'],
            'effectiveType' => $item['effective_type'],
            'unitPrice' => $item['unit_price'],
            'agreedQuantity' => $item['agreed_quantity'],
            'quantity' => $item['quantity'],
            'lineTotal' => $item['line_total'],
        ];
    }
}
