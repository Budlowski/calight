<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\OrderVisibility;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ReportTimeBookings;
use App\Infrastructure\Persistence\WorkReportRepository;
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
 * Rapporte (Regieberichte): Leistungsnachweis durch den Monteur und davon
 * getrennte kaufmännische Abrechnungspositionen durch das Büro.
 *
 * Ein Rapport gehört zu einem Kunden (Pflicht). Ein Entwurf darf ohne Auftrag
 * begonnen werden; spätestens beim Einreichen wird ein Auftrag desselben Kunden
 * zugeordnet oder ein Regieauftrag angelegt (submit). Historische eingereichte
 * Rapporte ohne Auftrag bleiben gültig; das Büro kann sie nachträglich zuordnen,
 * ohne den Leistungsnachweis zu verändern.
 *
 * Der Leistungsnachweis (Kopf, Stunden, Material) ist ab dem Einreichen
 * unveränderlich und per SHA-256 festgeschrieben.
 *
 * Stundenpositionen mit Mitarbeiter sind tatsächliche Arbeitszeit: Je maßgeblicher Position gibt
 * es genau eine zentrale Zeitbuchung (ReportTimeBookings, gleiche Transaktion). Maßgeblich sind
 * Entwürfe ohne Korrekturbezug und die gültige eingereichte Fassung einer Korrekturkette. Ein
 * Korrekturrapport ersetzt seinen Vorgänger erst beim Einreichen vollständig (superseded_by_id). Preise und Beträge werden nur
 * mit workreports.process ausgeliefert. Rapporte erzeugen keine Ist-Kosten.
 */
final readonly class WorkReportService
{
    public const BILLING_TYPES = ['labor' => 'Arbeitsstunden', 'material' => 'Material', 'travel' => 'Anfahrt/Fahrtkosten', 'external' => 'Fremdleistung', 'flat' => 'Pauschale', 'other' => 'Sonstige Leistung'];
    private const MAX_ROWS = 100;
    /** Auftragsstatus, denen neue Rapporte zugeordnet werden dürfen (abgeschlossene/abgerechnete sind gesperrt). */
    public const ASSIGNABLE_ORDER_STATUSES = ['open', 'in_progress'];
    private const MAX_SIGNATURE_BYTES = 600 * 1024;
    public const MAX_EVIDENCE_BYTES = 15 * 1024 * 1024;
    /** Zulässige Rücklaufdokumente, am Inhalt erkannt. */
    private const EVIDENCE_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(
        private PDO $pdo,
        private WorkReportRepository $reports,
        private OrderRepository $orders,
        private NumberRangeRepository $numbers,
        private PriceCalculator $prices = new PriceCalculator(),
        private ?AttachmentStore $store = null,
        private ?OrderService $orderService = null,
    ) {
    }

    /**
     * Auswahllisten für die Erfassung: Kunden (nur Nummer und Name), Aufträge mit Kundenbezug,
     * Mitarbeiter und Lohnkostengruppen – ohne Preise, Kostensätze oder Kontaktdaten.
     *
     * @param array<string,mixed> $actor
     * @return array<string,list<array<string,mixed>>>
     */
    public function referenceData(array $actor): array
    {
        $candidates = array_values(array_filter($this->reports->ordersForSelection(), fn (array $order): bool => in_array($order['status'], self::ASSIGNABLE_ORDER_STATUSES, true)));
        // Monteure: nur für sie sichtbare Aufträge (OrderVisibility).
        $visible = OrderVisibility::visibleIds($this->pdo, $actor, array_map(static fn (array $order): string => (string) $order['id'], $candidates));
        $orders = array_values(array_filter($candidates, static fn (array $order): bool => isset($visible[(string) $order['id']])));
        return [
            'customers' => array_map(static fn (array $row): array => ['id' => $row['id'], 'customerNumber' => $row['customer_number'], 'name' => $row['name']], $this->reports->activeCustomers()),
            'orders' => array_map(static fn (array $order): array => ['id' => $order['id'], 'orderNumber' => $order['order_number'], 'title' => $order['title'], 'customerId' => $order['customer_id']], $orders),
            'employees' => array_map(static fn (array $row): array => ['id' => $row['id'], 'name' => $row['display_name']], $employees = $this->reports->activeEmployees()),
            // Eigener Mitarbeiter (nur über employees.user_id) für die Vorbelegung neuer Stunden.
            'ownEmployeeId' => array_values(array_filter($employees, static fn (array $row): bool => (string) ($row['user_id'] ?? '') === (string) ($actor['id'] ?? '')))[0]['id'] ?? null,
            'laborGroups' => array_map(static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'unit' => $row['unit']], $this->reports->catalog('', 'labor', 200)),
        ];
    }

    /**
     * @param array<string,mixed> $parameters URL-Parameter (q, status, confirmation, order, sort, dir, page, per_page)
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function page(array $parameters, array $actor): array
    {
        $query = ListQuery::fromArray($parameters, WorkReportRepository::LIST_SORTS, 'updated', WorkReportRepository::LIST_FILTERS);
        $page = $this->reports->page($query, $this->isTechnician($actor) ? (string) $actor['id'] : null, $this->canProcess($actor));
        $page['items'] = array_map(fn (array $row): array => $this->summary($row, $actor), $page['items']);
        return $page;
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function forOrder(string $orderId, array $actor): array
    {
        $this->accessibleOrder($orderId, $actor);
        return array_map(fn (array $row): array => $this->summary($row, $actor), $this->reports->forOrder($orderId));
    }

    /**
     * Legt einen Entwurf an – für einen Kunden mit optionalem Auftrag oder als Korrektur eines
     * eingereichten Rapports. Ein Auftrag wird hier nie angelegt (erst bei Bedarf in submit).
     *
     * @param array<string,mixed> $input customerId und/oder orderId | correctionOf, workDate
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function create(array $input, array $actor): array
    {
        $this->requireWrite($actor);
        $correctionOf = trim((string) ($input['correctionOf'] ?? ''));
        $original = null;
        if ($correctionOf !== '') {
            $original = $this->accessibleReport($correctionOf, $actor);
            if ($original['status'] === 'draft') {
                throw new OrderValidationException(['Korrigiert werden können nur eingereichte Rapporte.']);
            }
            if ($original['superseded_by_id'] !== null) {
                throw new OrderValidationException(['Dieser Rapport wurde bereits durch ' . ($original['superseded_by_number'] ?? 'eine Korrektur') . ' ersetzt. Bitte die gültige Fassung korrigieren.']);
            }
            if (!$this->canProcess($actor) && (string) $original['created_by_user_id'] !== (string) $actor['id']) {
                throw new AccessDeniedException('workreports.write.own');
            }
            $customerId = (string) $original['customer_id'];
            $orderId = $original['order_id'] !== null ? (string) $original['order_id'] : '';
        } else {
            $customerId = trim((string) ($input['customerId'] ?? ''));
            $orderId = trim((string) ($input['orderId'] ?? ''));
        }
        if ($orderId !== '' && $original === null) {
            // Ein gewählter Auftrag muss zugänglich sein und zum gewählten Kunden gehören.
            $order = $this->accessibleOrder($orderId, $actor);
            if ($customerId !== '' && $customerId !== (string) $order['customer_id']) {
                throw new OrderValidationException(['Der Auftrag gehört nicht zum ausgewählten Kunden.']);
            }
            $customerId = (string) $order['customer_id'];
        }
        if ($original === null && !$this->customerIsActive($customerId)) {
            throw new OrderValidationException(['Bitte einen aktiven Kunden auswählen.']);
        }
        $date = trim((string) ($input['workDate'] ?? '')) ?: date('Y-m-d');
        $this->validDate($date, 'Leistungsdatum');
        $id = Uuid::v4();
        $now = Clock::now();

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->reports->insert([
                'id' => $id,
                'customer_id' => $customerId,
                'order_id' => $orderId !== '' ? $orderId : null,
                'work_date' => $original['work_date'] ?? $date,
                'work_date_to' => $original['work_date_to'] ?? null,
                'contact_person' => $original['contact_person'] ?? '',
                'work_location' => $original['work_location'] ?? '',
                'description' => $original['description'] ?? '',
                'notes' => $original['notes'] ?? '',
                'correction_of_id' => $original['id'] ?? null,
                'created_by_user_id' => (string) $actor['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($original !== null) {
                // Die Korrektur startet mit dem eingereichten Stand; das Original bleibt unverändert.
                // Historische Positionen ohne eigenes Datum erhalten das Leistungsdatum des Originals (im Entwurf änderbar).
                $labor = array_map(static fn (array $row): array => ['work_date' => $row['work_date'] ?? $original['work_date']] + $row,
                    $this->copyRows($this->reports->labor((string) $original['id']), $id));
                $this->reports->replaceProof($id, $labor, $this->copyRows($this->reports->materials((string) $original['id']), $id));
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function detail(string $id, array $actor): array
    {
        $row = $this->accessibleReport($id, $actor);
        $labor = $this->reports->labor($id);
        $context = $this->reports->documentContext($id);
        $result = $this->summary($row, $actor);
        $result += [
            'contactPerson' => $row['contact_person'],
            'workLocation' => (string) ($row['work_location'] ?? ''),
            'description' => $row['description'],
            'notes' => $row['notes'],
            'workDateTo' => $row['work_date_to'],
            'submittedByName' => $row['submitted_by_name'],
            'submittedSha256' => $row['submitted_sha256'],
            'confirmationMethod' => $row['confirmation_method'],
            'confirmationKind' => $row['confirmation_kind'],
            'confirmedByName' => $row['confirmed_by_name'],
            'confirmedAt' => $row['confirmed_at'],
            'confirmationRecordedByName' => $context['confirmation_recorded_by_name'] ?? null,
            'confirmationRequestedAt' => $row['confirmation_requested_at'],
            'confirmationRequestedByName' => $context['confirmation_requested_by_name'] ?? null,
            'confirmationEvidence' => $row['confirmation_evidence_path'] !== null
                ? ['name' => $row['confirmation_evidence_name'], 'mimeType' => $row['confirmation_evidence_mime'], 'size' => (int) $row['confirmation_evidence_size']]
                : null,
            'customerAddress' => trim(implode(', ', array_filter([
                trim(($context['street'] ?? '') . ' ' . ($context['house_number'] ?? '')),
                trim(($context['postal_code'] ?? '') . ' ' . ($context['city'] ?? '')),
            ]))),
            'projectNumber' => $context['project_number'] ?? null,
            'projectName' => $context['project_name'] ?? null,
            'labor' => array_map(static fn (array $item): array => [
                'id' => $item['id'], 'workDate' => $item['work_date'], 'employeeId' => $item['employee_id'], 'workerName' => $item['worker_name'], 'hours' => $item['hours'],
                'laborItemId' => $item['labor_item_id'], 'laborItemName' => $item['labor_item_name'], 'activity' => $item['activity'],
            ], $labor),
            'materials' => array_map(static fn (array $item): array => [
                'quantity' => $item['quantity'], 'unit' => $item['unit'], 'description' => $item['description'],
            ], $this->reports->materials($id)),
        ];
        // Kaufmännische Ebene nur mit Preisrecht – auch die API liefert sie sonst nicht aus.
        if ($this->canProcess($actor)) {
            $items = $this->reports->billingItems($id);
            $result['billing'] = array_map(static fn (array $item): array => [
                'type' => $item['item_type'], 'description' => $item['description'], 'quantity' => $item['quantity'], 'unit' => $item['unit'],
                'unitPrice' => $item['unit_price'], 'lineTotal' => $item['line_total'], 'costItemId' => $item['cost_item_id'],
            ], $items);
            $result['billingTotal'] = $this->sum(array_column($items, 'line_total'));
            $result['billingTypes'] = self::BILLING_TYPES;
        }
        return $result;
    }

    /**
     * Speichert Kopfdaten, Stunden und Material eines Entwurfs.
     *
     * @param array<string,mixed> $input version, workDate, workDateTo, contactPerson, workLocation, description, notes, labor[], materials[]
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function update(string $id, array $input, array $actor): array
    {
        $current = $this->editableDraft($id, $actor);
        $labor = $this->validateLabor($input['labor'] ?? [], $id, (string) $current['work_date'], $this->reports->laborIds($id));
        $header = $this->validateHeader($input) + self::laborPeriod($labor, (string) $current['work_date']);
        $materials = $this->validateMaterials($input['materials'] ?? [], $id);
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->reports->updateDraft($id, $this->version($input), [...$header, 'updated_at' => Clock::now()])) {
                throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert oder eingereicht. Bitte neu laden.']);
            }
            $this->reports->replaceProof($id, $labor, $materials);
            (new ReportTimeBookings($this->pdo))->syncWorkReport($id, (string) $actor['id'], Clock::now());
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
     * Reicht den Leistungsnachweis verbindlich ein: Auftrag festlegen, Nummer vergeben,
     * Stand per Prüfsumme festschreiben.
     *
     * Auftrag (Pflicht für neue Einreichungen):
     * - bereits zugeordnet → prüfen (Kunde, Status, Zugriff), nicht erneut abfragen;
     * - Korrektur eines Rapports mit Auftrag → dessen Auftrag;
     * - sonst orderId (bestehender Auftrag des Rapportkunden) oder createOrder = true
     *   (Regieauftrag über OrderService::createRegie – auch für Monteure, aber nur hier und
     *   nur für den eigenen bearbeitbaren Entwurf und dessen Kunden).
     * Zuordnung, Auftragsanlage und Einreichung laufen in einer Transaktion (BEGIN IMMEDIATE +
     * Versionsprüfung): entweder alles oder nichts, keine doppelten Aufträge bei Wiederholung.
     * Die Auftragszuordnung erfolgt vor der Prüfsumme; der eingereichte Stand bleibt danach
     * unverändert. PDFs werden erst beim Abruf archiviert (keine Dateioperation hier).
     *
     * @param array<string,mixed> $input version, orderId?, createOrder?
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function submit(string $id, array $input, array $actor): array
    {
        $version = $this->version($input);
        $createdOrder = null;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $report = $this->editableDraft($id, $actor);
            if ((int) $report['version'] !== $version) {
                throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert oder eingereicht. Bitte neu laden.']);
            }
            $labor = $this->reports->labor($id);
            $materials = $this->reports->materials($id);
            $errors = [];
            if (trim((string) $report['description']) === '') {
                $errors[] = 'Bitte die ausgeführten Arbeiten beschreiben.';
            }
            if ($labor === [] && $materials === []) {
                $errors[] = 'Bitte mindestens Arbeitsstunden oder Material erfassen.';
            }
            if ($errors !== []) {
                throw new OrderValidationException($errors);
            }
            $now = Clock::now();
            if ($report['order_id'] === null) {
                [$orderId, $createdOrder] = $this->orderForSubmission($report, $input, $actor);
                if (!$this->reports->assignOrder($id, $version, $orderId, (string) $actor['id'], $now)) {
                    throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert oder eingereicht. Bitte neu laden.']);
                }
                $version++;
                $report = $this->reports->find($id) ?? throw new OrderValidationException(['Rapport wurde nicht gefunden.']);
            } else {
                // Bereits zugeordnet (bei Anlage geprüft oder aus dem Original übernommen): Kunde und Status
                // erneut prüfen; keine erneute Freigabeprüfung, damit z. B. Korrekturen eines automatisch
                // angelegten, noch offenen Regieauftrags einreichbar bleiben.
                $this->assignableOrder((string) $report['order_id'], $report, $actor, false);
            }
            $number = $this->numbers->allocate('work_report', 'work_report', $id, Uuid::v4(), new DateTimeImmutable());
            if (!$this->reports->submit($id, $version, $number, (string) $actor['id'], $now, $this->proofHash($report, $labor, $materials))) {
                throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert oder eingereicht. Bitte neu laden.']);
            }
            $bookings = new ReportTimeBookings($this->pdo);
            if ($report['correction_of_id'] !== null) {
                // Gültigkeitswechsel und Buchungen in derselben Transaktion; konkurrierende Korrekturen scheitern hier.
                if (!$this->reports->supersede((string) $report['correction_of_id'], $id, $now)) {
                    throw new OrderValidationException(['Der korrigierte Rapport wurde bereits durch eine andere Korrektur ersetzt. Bitte die gültige Fassung erneut korrigieren.']);
                }
                $bookings->replaceByCorrection((string) $report['correction_of_id'], $id, (string) $actor['id'], $now);
            } else {
                $bookings->syncWorkReport($id, (string) $actor['id'], $now);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $result = $this->detail($id, $actor);
        $result['createdOrderNumber'] = $createdOrder['orderNumber'] ?? null;
        return $result;
    }

    /**
     * Auftrag für die Einreichung eines Entwurfs ohne Auftrag (innerhalb der Transaktion).
     *
     * @param array<string,mixed> $report
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array{0:string,1:array<string,mixed>|null} Auftrags-ID, neu angelegter Auftrag
     */
    private function orderForSubmission(array $report, array $input, array $actor): array
    {
        // Korrektur: Auftrag des korrigierten Rapports übernehmen, nie einen zweiten anlegen.
        if ($report['correction_of_id'] !== null) {
            $original = $this->reports->find((string) $report['correction_of_id']);
            if ($original !== null && $original['order_id'] !== null) {
                return [(string) $this->assignableOrder((string) $original['order_id'], $report, $actor, false)['id'], null];
            }
        }
        $orderId = trim((string) ($input['orderId'] ?? ''));
        if ($orderId !== '') {
            return [(string) $this->assignableOrder($orderId, $report, $actor)['id'], null];
        }
        if (($input['createOrder'] ?? false) === true || ($input['createOrder'] ?? '') === '1') {
            if ($this->orderService === null) {
                throw new \LogicException('OrderService fehlt.');
            }
            if (!$this->customerIsActive((string) $report['customer_id'])) {
                throw new OrderValidationException(['Für archivierte Kunden kann kein Regieauftrag angelegt werden.']);
            }
            // Bewusst ohne orders.write: auf genau diesen Entwurf und dessen Kunden beschränkt.
            $order = $this->orderService->createRegie([
                'customerId' => (string) $report['customer_id'],
                'title' => self::regieTitle($report),
                'description' => 'Automatisch beim Einreichen eines Rapports angelegt.',
                'orderDate' => (string) $report['work_date'],
            ]);
            // Der erstellende Mitarbeiter wird zugeordnet, damit der Auftrag für ihn erreichbar bleibt
            // (gleiche Transaktion wie die Einreichung; ohne Mitarbeiterdatensatz keine Zuordnung).
            $creatorEmployee = $this->orders->employeeIdForUser((string) $report['created_by_user_id']);
            if ($creatorEmployee !== null) {
                $this->orders->assignEmployee((string) $order['id'], $creatorEmployee, (string) ($actor['id'] ?? '') ?: null, Clock::now());
            }
            return [(string) $order['id'], $order];
        }
        throw new OrderValidationException(['Bitte einen Auftrag auswählen oder einen Regieauftrag anlegen lassen.']);
    }

    /** Standardbezeichnung eines automatisch angelegten Regieauftrags. @param array<string,mixed> $report */
    public static function regieTitle(array $report): string
    {
        $location = trim((string) ($report['work_location'] ?? ''));
        $suffix = $location !== '' ? $location : (new DateTimeImmutable((string) $report['work_date']))->format('d.m.Y');
        return mb_substr('Regiearbeiten – ' . $suffix, 0, 200);
    }

    /**
     * Prüft einen Auftrag für die Zuordnung eines Rapports: vorhanden, gleicher Kunde,
     * nicht abgeschlossen/abgerechnet, für Monteure freigegeben (ohne Rechteausweitung).
     *
     * @param array<string,mixed> $report
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function assignableOrder(string $orderId, array $report, array $actor, bool $checkAccess = true): array
    {
        $order = $checkAccess ? $this->accessibleOrder($orderId, $actor) : ($this->orders->find($orderId) ?? throw new OrderValidationException(['Auftrag wurde nicht gefunden.']));
        if ((string) $order['customer_id'] !== (string) $report['customer_id']) {
            throw new OrderValidationException(['Der Auftrag gehört nicht zum Kunden des Rapports.']);
        }
        if (!in_array($order['status'], self::ASSIGNABLE_ORDER_STATUSES, true)) {
            throw new OrderValidationException(['Der Auftrag ' . $order['order_number'] . ' ist abgeschlossen oder abgerechnet und nimmt keine neuen Rapporte auf.']);
        }
        return $order;
    }

    /**
     * Ersetzt die kaufmännischen Abrechnungspositionen (nur eingereichte, noch nicht abgeschlossene Rapporte).
     *
     * @param array<string,mixed> $input version, items[]
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function saveBilling(string $id, array $input, array $actor): array
    {
        $this->requireProcess($actor);
        $report = $this->accessibleReport($id, $actor);
        if ($report['status'] !== 'submitted') {
            throw new OrderValidationException([$report['status'] === 'draft'
                ? 'Abrechnungspositionen können erst nach dem Einreichen erfasst werden.'
                : 'Die kaufmännische Bearbeitung ist abgeschlossen. Bitte zuerst wieder öffnen.']);
        }
        $items = $this->validateBilling($input['items'] ?? [], $id);
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->reports->replaceBilling($id, $this->version($input), $items, Clock::now())) {
                throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert. Bitte neu laden.']);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /** Schließt die kaufmännische Bearbeitung ab und schreibt den Nettobetrag fest. @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function process(string $id, array $input, array $actor): array
    {
        $this->requireProcess($actor);
        $version = $this->version($input);
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $report = $this->accessibleReport($id, $actor);
            $items = $this->reports->billingItems($id);
            if ($report['status'] !== 'submitted' || (int) $report['version'] !== $version) {
                throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert. Bitte neu laden.']);
            }
            if ($items === []) {
                throw new OrderValidationException(['Bitte mindestens eine Abrechnungsposition erfassen.']);
            }
            $total = $this->sum(array_column($items, 'line_total'));
            if (BigDecimal::of($total)->isNegative()) {
                throw new OrderValidationException(['Der Nettobetrag des Rapports darf nicht negativ sein.']);
            }
            if (!$this->reports->process($id, $version, $total, (string) $actor['id'], Clock::now())) {
                throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert. Bitte neu laden.']);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function reopen(string $id, array $input, array $actor): array
    {
        $this->requireProcess($actor);
        $this->accessibleReport($id, $actor);
        if (!$this->reports->reopen($id, $this->version($input), Clock::now())) {
            throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert oder ist nicht abgeschlossen. Bitte neu laden.']);
        }
        return $this->detail($id, $actor);
    }

    /**
     * Erfasst die Kundenbestätigung einmalig für den eingereichten Leistungsstand.
     *
     * @param array<string,mixed> $input version, confirmedByName, method (on_site|later)
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function confirm(string $id, array $input, array $actor): array
    {
        $report = $this->accessibleReport($id, $actor);
        if (!$this->canProcess($actor) && (string) $report['created_by_user_id'] !== (string) $actor['id']) {
            throw new AccessDeniedException('workreports.write.own');
        }
        $name = trim((string) ($input['confirmedByName'] ?? ''));
        $method = (string) ($input['method'] ?? 'on_site');
        $errors = [];
        if ($report['status'] === 'draft') {
            $errors[] = 'Bestätigt werden kann nur ein eingereichter Leistungsnachweis.';
        }
        if ($report['confirmation_status'] === 'confirmed') {
            $errors[] = 'Der Rapport wurde bereits bestätigt.';
        }
        if ($name === '' || mb_strlen($name) > 200) {
            $errors[] = 'Bitte den Namen der bestätigenden Person angeben (höchstens 200 Zeichen).';
        }
        if (!in_array($method, ['on_site', 'later'], true)) {
            $errors[] = 'Ungültige Art der Bestätigung.';
        }
        if ($errors !== []) {
            throw new OrderValidationException($errors);
        }
        // Ohne Nachweisdatei ausdrücklich als „manuell dokumentiert“ gekennzeichnet.
        if (!$this->reports->confirm($id, $this->version($input), 'manual', $method, $name, (string) $actor['id'], Clock::now())) {
            throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->detail($id, $actor);
    }

    /**
     * Ordnet einen eigenständigen Rapport nachträglich einem Auftrag desselben Kunden zu
     * (nur Büro/Admin). Leistungsnachweis, Prüfsumme, Bestätigung und Abrechnung bleiben unverändert.
     *
     * @param array<string,mixed> $input version, orderId
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function assignOrder(string $id, array $input, array $actor): array
    {
        $this->requireProcess($actor);
        $report = $this->accessibleReport($id, $actor);
        if ($report['order_id'] !== null) {
            throw new OrderValidationException(['Der Rapport ist bereits einem Auftrag zugeordnet.']);
        }
        $order = $this->orders->find(trim((string) ($input['orderId'] ?? '')));
        if ($order === null) {
            throw new OrderValidationException(['Auftrag wurde nicht gefunden.']);
        }
        if ((string) $order['customer_id'] !== (string) $report['customer_id']) {
            throw new OrderValidationException(['Der Auftrag gehört nicht zum Kunden des Rapports.']);
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->reports->assignOrder($id, $this->version($input), (string) $order['id'], (string) $actor['id'], Clock::now())) {
                throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert. Bitte neu laden.']);
            }
            // Zeitbuchungen folgen dem Auftrag des Rapports (keine neuen Buchungen).
            (new ReportTimeBookings($this->pdo))->syncWorkReport($id, (string) $actor['id'], Clock::now());
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
     * Digitale Kundenunterschrift vor Ort (einfache elektronische Unterschrift).
     *
     * Die Unterschrift wird nur angenommen, wenn der Kunde genau den aktuell eingereichten
     * Leistungsstand gesehen hat (submittedSha256 aus der Kundenansicht).
     *
     * @param array<string,mixed> $input version, signerName, image (data:image/jpeg;base64,…), submittedSha256
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function sign(string $id, array $input, array $actor): array
    {
        $report = $this->confirmableReport($id, $actor, false);
        $name = $this->signerName($input);
        $hash = trim((string) ($input['submittedSha256'] ?? ''));
        if ($hash === '' || !hash_equals((string) $report['submitted_sha256'], $hash)) {
            throw new OrderValidationException(['Der unterschriebene Stand stimmt nicht mit dem eingereichten Leistungsnachweis überein. Bitte die Kundenansicht neu laden.']);
        }
        $image = (string) ($input['image'] ?? '');
        $data = str_starts_with($image, 'data:image/jpeg;base64,') ? base64_decode(substr($image, 23), true) : false;
        $size = is_string($data) ? @getimagesizefromstring($data) : false;
        if (!is_string($data) || strlen($data) > self::MAX_SIGNATURE_BYTES || !is_array($size) || ($size[2] ?? null) !== IMAGETYPE_JPEG
            || $size[0] < 100 || $size[1] < 40 || $size[0] > 4000 || $size[1] > 4000) {
            throw new OrderValidationException(['Die Unterschrift konnte nicht gelesen werden. Bitte erneut unterschreiben.']);
        }
        $evidence = $this->storeEvidence($id, $data, 'jpg', 'Unterschrift.jpg', 'image/jpeg');
        return $this->confirmWithEvidence($id, $this->version($input), 'signature', 'on_site', $name, $actor, $evidence, $hash);
    }

    /**
     * Unterschrieben zurückerhaltenes Dokument (PDF oder Bild) archivieren und als Bestätigung erfassen.
     *
     * @param array<string,mixed> $input version, signerName
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function uploadConfirmation(string $id, string $sourcePath, string $originalName, array $input, array $actor): array
    {
        $this->requireProcess($actor);
        $this->confirmableReport($id, $actor, true);
        $name = $this->signerName($input);
        $size = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;
        if ($size <= 0 || $size > self::MAX_EVIDENCE_BYTES) {
            throw new OrderValidationException(['Das Dokument ist leer oder größer als 15 MB.']);
        }
        // Der Typ wird am Inhalt erkannt, nicht am Dateinamen.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        $extension = self::EVIDENCE_TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new OrderValidationException(['Bitte ein PDF oder ein Foto (JPG, PNG) des unterschriebenen Dokuments hochladen.']);
        }
        $contents = (string) file_get_contents($sourcePath);
        $base = pathinfo(basename(str_replace('\\', '/', $originalName)), PATHINFO_FILENAME);
        $base = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $base)) ?: 'Ruecklauf', 0, 120);
        $evidence = $this->storeEvidence($id, $contents, $extension, $base . '.' . $extension, $mime);
        return $this->confirmWithEvidence($id, $this->version($input), 'returned_document', 'later', $name, $actor, $evidence, null);
    }

    /** Vermerkt, dass das PDF dem Kunden zur Bestätigung übermittelt wurde. @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function requestConfirmation(string $id, array $input, array $actor): array
    {
        $this->requireProcess($actor);
        $this->confirmableReport($id, $actor, true);
        if (!$this->reports->markConfirmationRequested($id, $this->version($input), (string) $actor['id'], Clock::now())) {
            throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->detail($id, $actor);
    }

    /**
     * Nachweisdatei (Unterschrift oder Rücklauf) für berechtigte Benutzer.
     *
     * @param array<string,mixed> $actor
     * @return array{contents:string,name:string,mimeType:string,inline:bool}
     */
    public function confirmationEvidence(string $id, array $actor): array
    {
        $report = $this->accessibleReport($id, $actor);
        if ($report['confirmation_evidence_path'] === null || $this->store === null) {
            throw new OrderValidationException(['Für diesen Rapport liegt kein Bestätigungsnachweis vor.']);
        }
        $mime = (string) $report['confirmation_evidence_mime'];
        return [
            'contents' => $this->store->read((string) $report['confirmation_evidence_path']),
            'name' => (string) $report['confirmation_evidence_name'],
            'mimeType' => $mime,
            'inline' => in_array($mime, ['image/jpeg', 'image/png'], true),
        ];
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function confirmableReport(string $id, array $actor, bool $officeOnly): array
    {
        $report = $this->accessibleReport($id, $actor);
        if (!$officeOnly && (!$this->can($actor, 'workreports.write') || (!$this->canProcess($actor) && (string) $report['created_by_user_id'] !== (string) $actor['id']))) {
            throw new AccessDeniedException('workreports.write.own');
        }
        if ($report['status'] === 'draft') {
            throw new OrderValidationException(['Bestätigt werden kann nur ein eingereichter Leistungsnachweis.']);
        }
        if ($report['confirmation_status'] === 'confirmed') {
            throw new OrderValidationException(['Der Rapport wurde bereits bestätigt. Für Änderungen bitte einen Korrekturrapport anlegen.']);
        }
        return $report;
    }

    /** @param array<string,mixed> $input */
    private function signerName(array $input): string
    {
        $name = trim((string) ($input['signerName'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) {
            throw new OrderValidationException(['Bitte den Namen der unterschreibenden Person angeben (höchstens 200 Zeichen).']);
        }
        return $name;
    }

    /** @return array{path:string,name:string,mime:string,size:int,sha256:string} */
    private function storeEvidence(string $id, string $contents, string $extension, string $name, string $mime): array
    {
        if ($this->store === null) {
            throw new \RuntimeException('Für Nachweise ist keine Dateiablage konfiguriert.');
        }
        $path = $this->store->storeContents($contents, 'work-reports/' . $id, Uuid::v4(), $extension);
        return ['path' => $path, 'name' => $name, 'mime' => $mime, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents)];
    }

    /**
     * @param array{path:string,name:string,mime:string,size:int,sha256:string} $evidence
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function confirmWithEvidence(string $id, int $version, string $kind, string $method, string $name, array $actor, array $evidence, ?string $expectedHash): array
    {
        try {
            if (!$this->reports->confirm($id, $version, $kind, $method, $name, (string) $actor['id'], Clock::now(), $evidence, $expectedHash)) {
                throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert oder bereits bestätigt. Bitte neu laden.']);
            }
        } catch (Throwable $exception) {
            // Ohne gültige Bestätigung keine verwaiste Nachweisdatei.
            try {
                $this->store?->delete($evidence['path']);
            } catch (Throwable) {
            }
            throw $exception;
        }
        return $this->detail($id, $actor);
    }

    /** @param array<string,mixed> $actor */
    public function delete(string $id, int $version, array $actor): void
    {
        $this->editableDraft($id, $actor);
        if (!$this->reports->deleteDraft($id, $version)) {
            throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert oder eingereicht. Bitte neu laden.']);
        }
    }

    /**
     * Artikel und Lohnkostengruppen zur Übernahme in Abrechnungspositionen – nur Verkaufspreise.
     *
     * @param array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    public function catalog(string $search, array $actor): array
    {
        $this->requireProcess($actor);
        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'articleNumber' => $row['article_number'],
            'name' => $row['name'],
            'unit' => $row['unit'],
            'type' => $row['calculation_type'] === 'labor' ? 'labor' : ($row['calculation_type'] === 'external' ? 'external' : 'material'),
            'salesPrice' => $this->salesPrice($row),
        ], $this->reports->catalog($search, null, 30));
    }

    // -------------------------------------------------------------------------
    // Validierung
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $input @return array{contact_person:string,work_location:string,description:string,notes:string} */
    private function validateHeader(array $input): array
    {
        // Kein allgemeines Leistungsdatum mehr: Datum je Stundenposition (validateLabor), Einreichungsdatum = submitted_at.
        $contact = trim((string) ($input['contactPerson'] ?? ''));
        $location = trim((string) ($input['workLocation'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $errors = [];
        if (mb_strlen($contact) > 200) {
            $errors[] = 'Der Ansprechpartner vor Ort darf höchstens 200 Zeichen lang sein.';
        }
        if (mb_strlen($location) > 200) {
            $errors[] = 'Der Einsatzort darf höchstens 200 Zeichen lang sein.';
        }
        if (mb_strlen($description) > 5000 || mb_strlen($notes) > 5000) {
            $errors[] = 'Beschreibung und Bemerkungen dürfen jeweils höchstens 5.000 Zeichen lang sein.';
        }
        if ($errors !== []) {
            throw new OrderValidationException($errors);
        }
        return ['contact_person' => $contact, 'work_location' => $location, 'description' => $description, 'notes' => $notes];
    }

    /** @return list<array<string,mixed>> */
    /** @param list<string> $existingIds IDs vorhandener Positionen dieses Rapports (bleiben stabil) */
    private function validateLabor(mixed $raw, string $reportId, string $defaultDate, array $existingIds = []): array
    {
        $usedIds = [];
        $rows = $this->rows($raw, 'Arbeitsstunden');
        $result = [];
        foreach ($rows as $index => $row) {
            $label = 'Stunden, Zeile ' . ($index + 1);
            $employeeId = trim((string) ($row['employeeId'] ?? ''));
            $name = trim((string) ($row['workerName'] ?? ''));
            if ($employeeId !== '') {
                $employee = $this->reports->employee($employeeId);
                if ($employee === null) {
                    throw new OrderValidationException([$label . ': Mitarbeiter wurde nicht gefunden.']);
                }
                $name = (string) $employee['display_name'];
            }
            if ($name === '' || mb_strlen($name) > 150) {
                throw new OrderValidationException([$label . ': Bitte einen Mitarbeiter auswählen oder einen Namen angeben.']);
            }
            $workDate = trim((string) ($row['workDate'] ?? '')) ?: $defaultDate;
            $this->validDate($workDate, $label . ': Leistungsdatum');
            $hours = $this->positive((string) ($row['hours'] ?? ''), Decimal::TIME_SCALE, $label . ': Stunden');
            // Stunden sind tatsächliche Arbeitszeit in ganzen Minuten (zentrale Zeitbuchung).
            if (!ReportTimeBookings::wholeMinutes(BigDecimal::of($hours))) {
                throw new OrderValidationException([$label . ': Stunden müssen ganzen Minuten entsprechen (z. B. 1,5 oder 0,25).']);
            }
            $rowId = trim((string) ($row['id'] ?? ''));
            $rowId = in_array($rowId, $existingIds, true) && !isset($usedIds[$rowId]) ? $rowId : Uuid::v4();
            $usedIds[$rowId] = true;
            $itemId = trim((string) ($row['laborItemId'] ?? ''));
            $itemName = '';
            if ($itemId !== '') {
                $item = $this->reports->costItem($itemId);
                if ($item === null || $item['calculation_type'] !== 'labor') {
                    throw new OrderValidationException([$label . ': Die Lohnkostengruppe ist ungültig.']);
                }
                $itemName = (string) $item['name'];
            }
            $activity = trim((string) ($row['activity'] ?? ''));
            if (mb_strlen($activity) > 500) {
                throw new OrderValidationException([$label . ': Die Tätigkeit darf höchstens 500 Zeichen lang sein.']);
            }
            $result[] = [
                'id' => $rowId, 'report_id' => $reportId, 'employee_id' => $employeeId !== '' ? $employeeId : null, 'worker_name' => $name, 'work_date' => $workDate,
                'hours' => $hours, 'labor_item_id' => $itemId !== '' ? $itemId : null, 'labor_item_name' => $itemName,
                'activity' => $activity, 'sort_order' => $index,
            ];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function validateMaterials(mixed $raw, string $reportId): array
    {
        $result = [];
        foreach ($this->rows($raw, 'Material') as $index => $row) {
            $label = 'Material, Zeile ' . ($index + 1);
            $description = trim((string) ($row['description'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));
            if ($description === '' || mb_strlen($description) > 500) {
                throw new OrderValidationException([$label . ': Bitte eine Bezeichnung angeben (höchstens 500 Zeichen).']);
            }
            if (mb_strlen($unit) > 20) {
                throw new OrderValidationException([$label . ': Die Einheit darf höchstens 20 Zeichen lang sein.']);
            }
            $result[] = [
                'id' => Uuid::v4(), 'report_id' => $reportId,
                'quantity' => $this->positive((string) ($row['quantity'] ?? ''), Decimal::QUANTITY_SCALE, $label . ': Menge'),
                'unit' => $unit, 'description' => $description, 'sort_order' => $index,
            ];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function validateBilling(mixed $raw, string $reportId): array
    {
        $result = [];
        foreach ($this->rows($raw, 'Abrechnungspositionen') as $index => $row) {
            $label = 'Position ' . ($index + 1);
            $type = (string) ($row['type'] ?? '');
            $description = trim((string) ($row['description'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));
            if (!array_key_exists($type, self::BILLING_TYPES)) {
                throw new OrderValidationException([$label . ': Ungültige Positionsart.']);
            }
            if ($description === '' || mb_strlen($description) > 500 || mb_strlen($unit) > 20) {
                throw new OrderValidationException([$label . ': Bitte eine Bezeichnung (höchstens 500 Zeichen) und eine Einheit (höchstens 20 Zeichen) angeben.']);
            }
            $quantity = $this->positive((string) ($row['quantity'] ?? ''), Decimal::QUANTITY_SCALE, $label . ': Menge');
            try {
                $price = Decimal::parse(str_replace(',', '.', (string) ($row['unitPrice'] ?? '')), Decimal::MONEY_SCALE, $label . ': Einzelpreis');
            } catch (\InvalidArgumentException $exception) {
                throw new OrderValidationException([$exception->getMessage()]);
            }
            $costItemId = trim((string) ($row['costItemId'] ?? ''));
            if ($costItemId !== '' && $this->reports->costItem($costItemId) === null) {
                throw new OrderValidationException([$label . ': Der Stammartikel wurde nicht gefunden.']);
            }
            $result[] = [
                'id' => Uuid::v4(), 'report_id' => $reportId, 'item_type' => $type, 'description' => $description,
                'quantity' => $quantity, 'unit' => $unit, 'unit_price' => Decimal::money($price),
                // Menge × Verkaufspreis, kaufmännisch auf Cent wie in der Angebotskalkulation.
                'line_total' => Decimal::money(BigDecimal::of($quantity)->multipliedBy($price)),
                'cost_item_id' => $costItemId !== '' ? $costItemId : null, 'sort_order' => $index,
            ];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $raw, string $label): array
    {
        if (!is_array($raw)) {
            throw new OrderValidationException([$label . ' müssen als Liste übergeben werden.']);
        }
        $rows = array_values(array_filter($raw, 'is_array'));
        if (count($rows) > self::MAX_ROWS) {
            throw new OrderValidationException([$label . ': höchstens ' . self::MAX_ROWS . ' Zeilen.']);
        }
        return $rows;
    }

    private function positive(string $value, int $scale, string $label): string
    {
        try {
            $decimal = Decimal::parse(str_replace(',', '.', trim($value)), $scale, $label);
        } catch (\InvalidArgumentException $exception) {
            throw new OrderValidationException([$exception->getMessage()]);
        }
        if (!$decimal->isPositive()) {
            throw new OrderValidationException([$label . ' muss größer als null sein.']);
        }
        return (string) $decimal->toScale($scale, RoundingMode::HalfUp);
    }

    private function validDate(string $date, string $label): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new OrderValidationException([$label . ' ist ungültig.']);
        }
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

    // -------------------------------------------------------------------------
    // Hilfen
    // -------------------------------------------------------------------------

    /**
     * Prüfsumme des Leistungsnachweises (Kopf, Stunden, Material) in fester Reihenfolge.
     *
     * @param array<string,mixed> $report
     * @param list<array<string,mixed>> $labor
     * @param list<array<string,mixed>> $materials
     */
    private function proofHash(array $report, array $labor, array $materials): string
    {
        $proof = [
            'customerId' => $report['customer_id'], 'orderId' => $report['order_id'], 'workDate' => $report['work_date'], 'workDateTo' => $report['work_date_to'],
            'contactPerson' => $report['contact_person'], 'description' => $report['description'], 'notes' => $report['notes'],
            // Leistungsdatum je Position nur, wenn vorhanden: historische Rapporte (ohne Positionsdatum)
            // behalten ihre bisherige Prüfsummenbildung.
            'labor' => array_map(static fn (array $row): array => array_merge([
                $row['employee_id'], $row['worker_name'], $row['hours'], $row['labor_item_id'], $row['labor_item_name'], $row['activity'],
            ], ($row['work_date'] ?? null) !== null ? [$row['work_date']] : []), $labor),
            'materials' => array_map(static fn (array $row): array => [$row['quantity'], $row['unit'], $row['description']], $materials),
        ];
        // Einsatzort nur bei Angabe aufnehmen: Rapporte ohne Einsatzort behalten die bisherige Prüfsummenbildung.
        if (trim((string) ($report['work_location'] ?? '')) !== '') {
            $proof['workLocation'] = $report['work_location'];
        }
        return hash('sha256', json_encode($proof, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Leistungszeitraum des Rapports, abgeleitet aus den datierten Stundenpositionen (für Listen,
     * Suche und Sortierung; keine eigene Eingabe). Ohne Stunden bleibt das bisherige Datum.
     *
     * @param list<array<string,mixed>> $labor
     * @return array{work_date:string,work_date_to:?string}
     */
    private static function laborPeriod(array $labor, string $fallback): array
    {
        $dates = array_values(array_filter(array_column($labor, 'work_date')));
        if ($dates === []) {
            return ['work_date' => $fallback, 'work_date_to' => null];
        }
        $from = min($dates);
        $to = max($dates);
        return ['work_date' => $from, 'work_date_to' => $to !== $from ? $to : null];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function copyRows(array $rows, string $reportId): array
    {
        return array_map(static fn (array $row): array => ['id' => Uuid::v4(), 'report_id' => $reportId] + array_diff_key($row, ['id' => true, 'report_id' => true]), $rows);
    }

    /** @param list<string> $values */
    private function sum(array $values): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }
        return Decimal::money($total);
    }

    /** Verkaufspreis eines Stammartikels wie in der Kalkulation (Einkaufspreis + Zuschlag, auf Cent). @param array<string,mixed> $row */
    private function salesPrice(array $row): string
    {
        return $this->prices->calculatePosition('1.000', [[
            'type' => (string) $row['calculation_type'], 'quantity' => '1.000',
            'costPrice' => (string) $row['cost_price'], 'markupPercent' => (string) $row['markup_percent'],
        ]])['positionTotal'];
    }

    private function customerIsActive(string $customerId): bool
    {
        return $customerId !== '' && $this->reports->customerIsActive($customerId);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function accessibleOrder(string $orderId, array $actor): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new OrderValidationException(['Auftrag wurde nicht gefunden.']);
        }
        if (!$this->orders->visibleTo($orderId, $actor)) {
            throw new AccessDeniedException('orders.released.read');
        }
        return $order;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function accessibleReport(string $id, array $actor): array
    {
        $report = $this->reports->find($id);
        if ($report === null) {
            throw new OrderValidationException(['Rapport wurde nicht gefunden.']);
        }
        // Techniker: eigene Rapporte immer; fremde nur an für sie sichtbaren Aufträgen, nie fremde eigenständige.
        if ($this->isTechnician($actor) && (string) $report['created_by_user_id'] !== (string) ($actor['id'] ?? '')
            && ($report['order_id'] === null || !$this->orders->visibleTo((string) $report['order_id'], $actor))) {
            throw new AccessDeniedException('workreports.read.own');
        }
        return $report;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function editableDraft(string $id, array $actor): array
    {
        $this->requireWrite($actor);
        $report = $this->accessibleReport($id, $actor);
        if ($report['status'] !== 'draft') {
            throw new OrderValidationException(['Eingereichte Rapporte sind unveränderlich. Änderungen bitte über eine Korrektur erfassen.']);
        }
        if (!$this->canEditDraft($report, $actor)) {
            throw new AccessDeniedException('workreports.write.own');
        }
        return $report;
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $actor */
    private function canEditDraft(array $report, array $actor): bool
    {
        return $report['status'] === 'draft' && $this->can($actor, 'workreports.write')
            && ($this->canProcess($actor) || (string) $report['created_by_user_id'] === (string) ($actor['id'] ?? ''));
    }

    /** @param array<string,mixed> $actor */
    private function requireWrite(array $actor): void
    {
        if (!$this->can($actor, 'workreports.write')) {
            throw new AccessDeniedException('workreports.write');
        }
    }

    /** @param array<string,mixed> $actor */
    private function requireProcess(array $actor): void
    {
        if (!$this->canProcess($actor)) {
            throw new AccessDeniedException('workreports.process');
        }
    }

    /** @param array<string,mixed> $actor */
    private function canProcess(array $actor): bool
    {
        return $this->can($actor, 'workreports.process');
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
        $own = (string) $row['created_by_user_id'] === (string) ($actor['id'] ?? '');
        $result = [
            'id' => $row['id'],
            'orderId' => $row['order_id'],
            'orderNumber' => $row['order_number'],
            'orderTitle' => $row['order_title'],
            'orderType' => $row['order_type'],
            'orderAssignedAt' => $row['order_assigned_at'],
            'orderAssignedByName' => $row['order_assigned_by_name'],
            'customerId' => $row['customer_id'],
            'customerNumber' => $row['customer_number'],
            'customerName' => $row['customer_name'],
            'reportNumber' => $row['report_number'],
            'workDate' => $row['work_date'],
            'status' => $row['status'],
            'confirmationStatus' => $row['confirmation_status'],
            'totalHours' => $this->hours((string) ($row['hour_values'] ?? '')),
            'correctionOfId' => $row['correction_of_id'],
            'correctedNumber' => $row['corrected_number'],
            'correctionCount' => (int) $row['correction_count'],
            // Korrekturkette: ersetzte Fassungen bleiben vollständig erhalten, zählen aber nicht mehr.
            'supersededById' => $row['superseded_by_id'] ?? null,
            'supersededByNumber' => $row['superseded_by_number'] ?? null,
            'supersededAt' => $row['superseded_at'] ?? null,
            'valid' => $row['status'] !== 'draft' && ($row['superseded_by_id'] ?? null) === null,
            'creatorName' => $row['creator_name'],
            'createdByUserId' => $row['created_by_user_id'],
            'createdAt' => $row['created_at'],
            'submittedAt' => $row['submitted_at'],
            'processedAt' => $row['processed_at'],
            'processedByName' => $row['processed_by_name'],
            'updatedAt' => $row['updated_at'],
            'version' => (int) $row['version'],
            'canEdit' => $this->canEditDraft($row, $actor),
            'canProcess' => $this->canProcess($actor),
            'canConfirm' => $row['status'] !== 'draft' && $row['confirmation_status'] === 'none' && ($this->canProcess($actor) || $own),
            'canCorrect' => $row['status'] !== 'draft' && ($row['superseded_by_id'] ?? null) === null && $this->can($actor, 'workreports.write') && ($this->canProcess($actor) || $own),
            'canAssignOrder' => $row['order_id'] === null && $this->canProcess($actor),
            'canSign' => $row['status'] !== 'draft' && $row['confirmation_status'] === 'none' && $this->can($actor, 'workreports.write') && ($this->canProcess($actor) || $own),
            'canUploadConfirmation' => $row['status'] !== 'draft' && $row['confirmation_status'] === 'none' && $this->canProcess($actor),
        ];
        // Beträge ausschließlich mit Preisrecht.
        if ($this->canProcess($actor)) {
            $result['netTotal'] = $row['net_total'];
        }
        return $result;
    }

    private function hours(string $values): string
    {
        $total = BigDecimal::zero();
        foreach (array_filter(explode('|', $values)) as $value) {
            $total = $total->plus($value);
        }
        return (string) $total->toScale(Decimal::TIME_SCALE, RoundingMode::HalfUp);
    }
}
