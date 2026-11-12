<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Order\OrderValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OrderRepository;
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
 * Der Leistungsnachweis (Kopf, Stunden, Material) ist ab dem Einreichen
 * unveränderlich und per SHA-256 festgeschrieben. Preise und Beträge werden nur
 * mit workreports.process ausgeliefert. Rapporte erzeugen keine Ist-Kosten.
 */
final readonly class WorkReportService
{
    public const BILLING_TYPES = ['labor' => 'Arbeitsstunden', 'material' => 'Material', 'travel' => 'Anfahrt/Fahrtkosten', 'external' => 'Fremdleistung', 'flat' => 'Pauschale', 'other' => 'Sonstige Leistung'];
    private const MAX_ROWS = 100;

    public function __construct(
        private PDO $pdo,
        private WorkReportRepository $reports,
        private OrderRepository $orders,
        private NumberRangeRepository $numbers,
        private PriceCalculator $prices = new PriceCalculator(),
    ) {
    }

    /**
     * Auswahllisten für die Erfassung: Aufträge, Mitarbeiter und Lohnkostengruppen – ohne Preise oder Kostensätze.
     *
     * @param array<string,mixed> $actor
     * @return array<string,list<array<string,mixed>>>
     */
    public function referenceData(array $actor): array
    {
        $orders = array_values(array_filter($this->orders->all(), fn (array $order): bool => !$this->isTechnician($actor) || $order['status'] !== 'open'));
        return [
            'orders' => array_map(static fn (array $order): array => ['id' => $order['id'], 'orderNumber' => $order['order_number'], 'title' => $order['title'], 'customerName' => $order['customer_name']], $orders),
            'employees' => array_map(static fn (array $row): array => ['id' => $row['id'], 'name' => $row['display_name']], $this->reports->activeEmployees()),
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
        $page = $this->reports->page($query, $this->isTechnician($actor), $this->canProcess($actor));
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
     * Legt einen Entwurf an – für einen Auftrag oder als Korrektur eines eingereichten Rapports.
     *
     * @param array<string,mixed> $input orderId | correctionOf, workDate
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
            if (!$this->canProcess($actor) && (string) $original['created_by_user_id'] !== (string) $actor['id']) {
                throw new AccessDeniedException('workreports.write.own');
            }
            $orderId = (string) $original['order_id'];
        } else {
            $orderId = trim((string) ($input['orderId'] ?? ''));
        }
        $this->accessibleOrder($orderId, $actor);
        $date = trim((string) ($input['workDate'] ?? '')) ?: date('Y-m-d');
        $this->validDate($date, 'Leistungsdatum');
        $id = Uuid::v4();
        $now = Clock::now();

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->reports->insert([
                'id' => $id,
                'order_id' => $orderId,
                'work_date' => $original['work_date'] ?? $date,
                'work_date_to' => $original['work_date_to'] ?? null,
                'contact_person' => $original['contact_person'] ?? '',
                'description' => $original['description'] ?? '',
                'notes' => $original['notes'] ?? '',
                'correction_of_id' => $original['id'] ?? null,
                'created_by_user_id' => (string) $actor['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($original !== null) {
                // Die Korrektur startet mit dem eingereichten Stand; das Original bleibt unverändert.
                $this->reports->replaceProof($id, $this->copyRows($this->reports->labor((string) $original['id']), $id), $this->copyRows($this->reports->materials((string) $original['id']), $id));
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
        $result = $this->summary($row, $actor);
        $result += [
            'contactPerson' => $row['contact_person'],
            'description' => $row['description'],
            'notes' => $row['notes'],
            'workDateTo' => $row['work_date_to'],
            'submittedByName' => $row['submitted_by_name'],
            'submittedSha256' => $row['submitted_sha256'],
            'confirmationMethod' => $row['confirmation_method'],
            'confirmedByName' => $row['confirmed_by_name'],
            'confirmedAt' => $row['confirmed_at'],
            'labor' => array_map(static fn (array $item): array => [
                'employeeId' => $item['employee_id'], 'workerName' => $item['worker_name'], 'hours' => $item['hours'],
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
     * @param array<string,mixed> $input version, workDate, workDateTo, contactPerson, description, notes, labor[], materials[]
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function update(string $id, array $input, array $actor): array
    {
        $this->editableDraft($id, $actor);
        $header = $this->validateHeader($input);
        $labor = $this->validateLabor($input['labor'] ?? [], $id);
        $materials = $this->validateMaterials($input['materials'] ?? [], $id);
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->reports->updateDraft($id, $this->version($input), [...$header, 'updated_at' => Clock::now()])) {
                throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert oder eingereicht. Bitte neu laden.']);
            }
            $this->reports->replaceProof($id, $labor, $materials);
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
     * Reicht den Leistungsnachweis verbindlich ein: Nummer vergeben, Stand per Prüfsumme festschreiben.
     *
     * @param array<string,mixed> $input version
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function submit(string $id, array $input, array $actor): array
    {
        $version = $this->version($input);
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
            $number = $this->numbers->allocate('work_report', 'work_report', $id, Uuid::v4(), new DateTimeImmutable());
            if (!$this->reports->submit($id, $version, $number, (string) $actor['id'], $now, $this->proofHash($report, $labor, $materials))) {
                throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert oder eingereicht. Bitte neu laden.']);
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
        if (!$this->reports->confirm($id, $this->version($input), $method, $name, (string) $actor['id'], Clock::now())) {
            throw new OrderValidationException(['Der Rapport wurde zwischenzeitlich geändert. Bitte neu laden.']);
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

    /** @param array<string,mixed> $input @return array{work_date:string,work_date_to:?string,contact_person:string,description:string,notes:string} */
    private function validateHeader(array $input): array
    {
        $date = trim((string) ($input['workDate'] ?? ''));
        $dateTo = trim((string) ($input['workDateTo'] ?? ''));
        $contact = trim((string) ($input['contactPerson'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $errors = [];
        foreach ([[$date, 'Leistungsdatum', true], [$dateTo, 'Leistungsende', false]] as [$value, $label, $required]) {
            if ($value === '' && !$required) {
                continue;
            }
            try {
                $this->validDate($value, $label);
            } catch (OrderValidationException $exception) {
                $errors = [...$errors, ...$exception->errors];
            }
        }
        if ($dateTo !== '' && $errors === [] && $dateTo < $date) {
            $errors[] = 'Das Leistungsende darf nicht vor dem Leistungsdatum liegen.';
        }
        if (mb_strlen($contact) > 200) {
            $errors[] = 'Der Ansprechpartner vor Ort darf höchstens 200 Zeichen lang sein.';
        }
        if (mb_strlen($description) > 5000 || mb_strlen($notes) > 5000) {
            $errors[] = 'Beschreibung und Bemerkungen dürfen jeweils höchstens 5.000 Zeichen lang sein.';
        }
        if ($errors !== []) {
            throw new OrderValidationException($errors);
        }
        return ['work_date' => $date, 'work_date_to' => $dateTo !== '' && $dateTo !== $date ? $dateTo : null, 'contact_person' => $contact, 'description' => $description, 'notes' => $notes];
    }

    /** @return list<array<string,mixed>> */
    private function validateLabor(mixed $raw, string $reportId): array
    {
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
            $hours = $this->positive((string) ($row['hours'] ?? ''), Decimal::TIME_SCALE, $label . ': Stunden');
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
                'id' => Uuid::v4(), 'report_id' => $reportId, 'employee_id' => $employeeId !== '' ? $employeeId : null, 'worker_name' => $name,
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
            'orderId' => $report['order_id'], 'workDate' => $report['work_date'], 'workDateTo' => $report['work_date_to'],
            'contactPerson' => $report['contact_person'], 'description' => $report['description'], 'notes' => $report['notes'],
            'labor' => array_map(static fn (array $row): array => [
                $row['employee_id'], $row['worker_name'], $row['hours'], $row['labor_item_id'], $row['labor_item_name'], $row['activity'],
            ], $labor),
            'materials' => array_map(static fn (array $row): array => [$row['quantity'], $row['unit'], $row['description']], $materials),
        ];
        return hash('sha256', json_encode($proof, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
    private function accessibleReport(string $id, array $actor): array
    {
        $report = $this->reports->find($id);
        if ($report === null) {
            throw new OrderValidationException(['Rapport wurde nicht gefunden.']);
        }
        if ($this->isTechnician($actor) && $report['order_status'] === 'open') {
            throw new AccessDeniedException('orders.released.read');
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
            'customerName' => $row['customer_name'],
            'reportNumber' => $row['report_number'],
            'workDate' => $row['work_date'],
            'status' => $row['status'],
            'confirmationStatus' => $row['confirmation_status'],
            'totalHours' => $this->hours((string) ($row['hour_values'] ?? '')),
            'correctionOfId' => $row['correction_of_id'],
            'correctedNumber' => $row['corrected_number'],
            'correctionCount' => (int) $row['correction_count'],
            'creatorName' => $row['creator_name'],
            'createdAt' => $row['created_at'],
            'submittedAt' => $row['submitted_at'],
            'processedAt' => $row['processed_at'],
            'processedByName' => $row['processed_by_name'],
            'updatedAt' => $row['updated_at'],
            'version' => (int) $row['version'],
            'canEdit' => $this->canEditDraft($row, $actor),
            'canProcess' => $this->canProcess($actor),
            'canConfirm' => $row['status'] !== 'draft' && $row['confirmation_status'] === 'none' && ($this->canProcess($actor) || $own),
            'canCorrect' => $row['status'] !== 'draft' && $this->can($actor, 'workreports.write') && ($this->canProcess($actor) || $own),
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
