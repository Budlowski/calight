<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Security\RolePermissions;
use App\Support\Clock;
use App\Support\Uuid;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Zeiterfassung (Phase 3.4.3): tatsächliche Arbeits- und Reisezeiten, Einsatzangaben und Auslagen.
 * Keine Lohn-, Reisekosten- oder Personalverwaltung, keine automatische Bewertung.
 *
 * Fachregeln:
 * - Zeitblöcke mit Beginn, Ende (ggf. Folgetag), Pause und Tätigkeit; Nettozeit serverseitig.
 *   Eigene Blöcke dürfen sich nicht überschneiden (auch über Mitternacht).
 * - Eindeutige Ist-Stunden ohne Migration: Für Mitarbeiter + Tag + Auftrag zählt die
 *   Zeiterfassung, sobald dort Zeitblöcke bestehen, sonst die Zeit aus Bautagesberichten
 *   (actualHoursForOrder). Bautagesberichte selbst bleiben unverändert.
 * - Rapportstunden sind abrechenbare Leistung und erscheinen nur als Referenz.
 * - Rechte: timesheet.own (eigene Zeiten), timesheet.manage (alle Mitarbeiter, Korrekturen,
 *   Auswertung). Kostensatz-Snapshots nur mit costs.read.
 */
final readonly class TimeTrackingService
{
    public const ACTIVITIES = ['work' => 'Arbeit / Montage', 'travel' => 'Reisezeit / Fahrt', 'preparation' => 'Vorbereitung / Werkstatt', 'internal' => 'Interne Tätigkeit', 'other' => 'Sonstiges'];
    public const ASSIGNMENTS = ['regular' => 'Erste Tätigkeitsstätte', 'external' => 'Auswärtstätigkeit / wechselnder Einsatzort'];
    public const TRAVEL_DAYS = ['none' => 'Kein besonderer Reisetag', 'arrival' => 'Anreisetag', 'departure' => 'Abreisetag'];
    public const RECEIPT_TYPES = DocumentService::ALLOWED_TYPES;
    private const LONG_DAY_MINUTES = 600;
    private const MIN_REST_MINUTES = 660;
    private const MAX_RANGE_DAYS = 92;

    public function __construct(private TimeTrackingRepository $times, private AttachmentStore $store)
    {
    }

    // -------------------------------------------------------------------------
    // Lesen
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function referenceData(array $actor): array
    {
        $this->requireAccess($actor);
        $manage = $this->can($actor, 'timesheet.manage');
        $own = $this->times->employeeForUser((string) $actor['id']);
        return [
            'activities' => self::ACTIVITIES,
            'assignments' => self::ASSIGNMENTS,
            'travelDays' => self::TRAVEL_DAYS,
            'ownEmployeeId' => $own['id'] ?? null,
            'canManage' => $manage,
            'employees' => $manage ? array_map(static fn (array $employee): array => [
                'id' => $employee['id'], 'name' => $employee['display_name'], 'number' => $employee['personnel_number'], 'active' => (bool) $employee['active'],
            ], $this->times->employees()) : [],
            'orders' => array_map(static fn (array $order): array => ['id' => $order['id'], 'orderNumber' => $order['order_number'], 'title' => $order['title']],
                $this->times->orders(!$this->releasedAll($actor))),
        ];
    }

    /** Tagesansicht mit Blöcken, Summen, Einsatz/Auslagen und Referenzzeiten. @param array<string,mixed> $actor @return array<string,mixed> */
    public function day(?string $employeeId, string $date, array $actor): array
    {
        $employee = $this->employee($employeeId, $actor);
        $date = $this->date($date);
        $costs = $this->can($actor, 'costs.read');
        $entries = $this->times->entries((string) $employee['id'], $date, $date);
        $details = $this->times->details((string) $employee['id'], $date);
        $expenses = $this->times->expenses((string) $employee['id'], $date, $date);
        $reports = $this->times->dailyReportTimes((string) $employee['id'], $date, $date);
        $ordersWithEntries = array_unique(array_filter(array_column($entries, 'order_id')));
        return [
            'employee' => ['id' => $employee['id'], 'name' => $employee['display_name'], 'active' => (bool) $employee['active']],
            'date' => $date,
            'entries' => array_map(fn (array $entry): array => $this->mapEntry($entry, $employee, $costs), $entries),
            'totals' => $this->totals($entries),
            'details' => $details !== null ? $this->mapDetails($details) : null,
            'expenses' => array_map(fn (array $expense): array => $this->mapExpense($expense), $expenses),
            'expenseTotal' => $this->sum(array_column($expenses, 'amount')),
            // Bautagesbericht-Zeiten zählen nur, solange für Tag und Auftrag keine Zeitblöcke bestehen.
            'dailyReportTimes' => array_map(static fn (array $row): array => [
                'reportId' => $row['report_id'], 'orderId' => $row['order_id'], 'orderNumber' => $row['order_number'], 'orderTitle' => $row['order_title'],
                'hours' => $row['duration_hours'], 'description' => $row['description'], 'countsAsActual' => !in_array($row['order_id'], $ordersWithEntries, true),
            ], $reports),
            'workReportTimes' => array_map(static fn (array $row): array => [
                'reportId' => $row['report_id'], 'reportNumber' => $row['report_number'], 'status' => $row['status'], 'hours' => $row['hours'], 'activity' => $row['activity'],
                'orderNumber' => $row['order_number'], 'dateTo' => $row['work_date_to'],
            ], $this->times->workReportTimes((string) $employee['id'], $date, $date)),
            'warnings' => $this->warnings((string) $employee['id'], $date, $entries),
            'canEdit' => $this->canEdit($employee, $actor),
        ];
    }

    /**
     * Summen je Tag für Wochen- und Monatsansicht (höchstens 92 Tage).
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function range(?string $employeeId, string $from, string $to, array $actor): array
    {
        $employee = $this->employee($employeeId, $actor);
        [$from, $to] = $this->period($from, $to);
        $entries = $this->times->entries((string) $employee['id'], $from, $to);
        $details = array_column($this->times->detailsRange((string) $employee['id'], $from, $to), null, 'work_date');
        $expenses = $this->times->expenses((string) $employee['id'], $from, $to);
        $reports = $this->times->dailyReportTimes((string) $employee['id'], $from, $to);
        $days = [];
        for ($day = new DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $dayEntries = array_values(array_filter($entries, static fn (array $entry): bool => $entry['work_date'] === $key));
            $dayExpenses = array_filter($expenses, static fn (array $expense): bool => $expense['work_date'] === $key);
            $dayReports = array_filter($reports, static fn (array $report): bool => $report['work_date'] === $key);
            $days[] = $this->totals($dayEntries) + [
                'date' => $key,
                'entryCount' => count($dayEntries),
                'privateKm' => $details[$key]['private_km'] ?? null,
                'expenseTotal' => $dayExpenses === [] ? null : $this->sum(array_column($dayExpenses, 'amount')),
                'hasDetails' => isset($details[$key]),
                'dailyReportHours' => $dayReports === [] ? null : $this->decimalSum(array_column($dayReports, 'duration_hours'), 3),
            ];
        }
        return [
            'employee' => ['id' => $employee['id'], 'name' => $employee['display_name']],
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'totals' => $this->totals($entries) + [
                'privateKm' => $this->decimalSum(array_filter(array_column($details, 'private_km'), static fn (?string $value): bool => $value !== null), 1),
                'expenseTotal' => $this->sum(array_column($expenses, 'amount')),
            ],
        ];
    }

    /** Büroauswertung: Zeitblöcke nach Zeitraum, Mitarbeiter und Auftrag. @param array<string,mixed> $actor @return array<string,mixed> */
    public function report(string $from, string $to, ?string $employeeId, ?string $orderId, array $actor): array
    {
        $this->require($actor, 'timesheet.manage');
        [$from, $to] = $this->period($from, $to, 370);
        $rows = $this->times->report($from, $to, $employeeId ?: null, $orderId ?: null);
        $byOrder = [];
        foreach ($rows as $row) {
            $key = (string) ($row['order_id'] ?? '');
            $byOrder[$key] ??= ['orderId' => $row['order_id'], 'orderNumber' => $row['order_number'], 'orderTitle' => $row['order_title'], 'workMinutes' => 0, 'travelMinutes' => 0];
            $byOrder[$key][$row['activity'] === 'travel' ? 'travelMinutes' : 'workMinutes'] += (int) $row['net_minutes'];
        }
        return [
            'from' => $from, 'to' => $to,
            'rows' => array_map(static fn (array $row): array => [
                'id' => $row['id'], 'date' => $row['work_date'], 'employeeName' => $row['employee_name'], 'start' => $row['start_time'], 'end' => $row['end_time'],
                'endsNextDay' => (bool) $row['ends_next_day'], 'breakMinutes' => (int) $row['break_minutes'], 'netMinutes' => (int) $row['net_minutes'],
                'activity' => $row['activity'], 'activityLabel' => self::ACTIVITIES[$row['activity']] ?? $row['activity'],
                'orderNumber' => $row['order_number'], 'notes' => $row['notes'],
            ], $rows),
            'byOrder' => array_values($byOrder),
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * Ist-Stunden eines Auftrags für die spätere Nachkalkulation (Phase 3.5) – ohne Doppelzählung:
     * Je Mitarbeiter und Tag zählt die Zeiterfassung, sobald für diesen Auftrag Zeitblöcke
     * bestehen; sonst die Bautagesbericht-Zeit. Rapportstunden zählen nie.
     *
     * @param array<string,mixed> $actor
     * @return array{items:list<array<string,mixed>>,totalMinutes:int,travelMinutes:int}
     */
    public function actualHoursForOrder(string $orderId, array $actor): array
    {
        $this->require($actor, 'timesheet.manage');
        $sources = $this->times->orderTimes($orderId);
        $covered = [];
        $items = [];
        foreach ($sources['entries'] as $entry) {
            $key = $entry['employee_id'] . '|' . $entry['work_date'];
            $covered[$key] = true;
            $items[$key] ??= ['employeeId' => $entry['employee_id'], 'employeeName' => $entry['employee_name'], 'date' => $entry['work_date'], 'source' => 'time_tracking', 'workMinutes' => 0, 'travelMinutes' => 0];
            $items[$key][$entry['activity'] === 'travel' ? 'travelMinutes' : 'workMinutes'] += (int) $entry['net_minutes'];
        }
        foreach ($sources['reports'] as $report) {
            $key = $report['employee_id'] . '|' . $report['work_date'];
            if (isset($covered[$key])) {
                continue;
            }
            $minutes = (int) BigDecimal::of((string) $report['duration_hours'])->multipliedBy(60)->toScale(0, \Brick\Math\RoundingMode::HalfUp)->toInt();
            $items[$key] ??= ['employeeId' => $report['employee_id'], 'employeeName' => $report['employee_name'], 'date' => $report['work_date'], 'source' => 'daily_report', 'workMinutes' => 0, 'travelMinutes' => 0];
            $items[$key]['workMinutes'] += $minutes;
        }
        ksort($items);
        $items = array_values($items);
        return [
            'items' => $items,
            'totalMinutes' => array_sum(array_column($items, 'workMinutes')) + array_sum(array_column($items, 'travelMinutes')),
            'travelMinutes' => array_sum(array_column($items, 'travelMinutes')),
        ];
    }

    // -------------------------------------------------------------------------
    // Zeitblöcke
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function createEntry(array $input, array $actor): array
    {
        $employee = $this->employee(isset($input['employeeId']) ? (string) $input['employeeId'] : null, $actor);
        $this->assertEditable($employee, $actor);
        $values = $this->entryValues($input, (string) $employee['id'], null, $actor);
        $now = Clock::now();
        $this->times->insertEntry($values + [
            'id' => Uuid::v4(), 'employee_id' => $employee['id'],
            'hourly_cost_rate_snapshot' => $employee['hourly_cost_rate'],
            'created_by_user_id' => (string) $actor['id'], 'created_at' => $now, 'updated_by_user_id' => (string) $actor['id'], 'updated_at' => $now,
        ]);
        return $this->day((string) $employee['id'], $values['work_date'], $actor);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function updateEntry(string $id, array $input, array $actor): array
    {
        $entry = $this->times->entry($id) ?? throw new DocumentValidationException(['Der Zeitblock wurde nicht gefunden.']);
        $employee = $this->employee((string) $entry['employee_id'], $actor);
        $this->assertEditable($employee, $actor);
        $values = $this->entryValues($input, (string) $employee['id'], $entry, $actor);
        if (!$this->times->updateEntry($id, $this->version($input), $values + ['updated_by_user_id' => (string) $actor['id'], 'updated_at' => Clock::now()])) {
            throw new DocumentValidationException(['Der Zeitblock wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->day((string) $employee['id'], $values['work_date'], $actor);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function deleteEntry(string $id, array $input, array $actor): array
    {
        $entry = $this->times->entry($id) ?? throw new DocumentValidationException(['Der Zeitblock wurde nicht gefunden.']);
        $employee = $this->employee((string) $entry['employee_id'], $actor);
        $this->assertEditable($employee, $actor);
        if (!$this->times->deleteEntry($id, $this->version($input))) {
            throw new DocumentValidationException(['Der Zeitblock wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->day((string) $employee['id'], (string) $entry['work_date'], $actor);
    }

    // -------------------------------------------------------------------------
    // Einsatz, Kilometer und Auslagen
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function saveDetails(array $input, array $actor): array
    {
        $employee = $this->employee(isset($input['employeeId']) ? (string) $input['employeeId'] : null, $actor);
        $this->assertEditable($employee, $actor);
        $date = $this->date((string) ($input['date'] ?? ''));
        $assignment = (string) ($input['assignmentKind'] ?? '');
        if ($assignment !== '' && !isset(self::ASSIGNMENTS[$assignment])) {
            throw new DocumentValidationException(['Ungültige Einsatzart.']);
        }
        $travelDay = (string) ($input['travelDay'] ?? 'none');
        if (!isset(self::TRAVEL_DAYS[$travelDay])) {
            throw new DocumentValidationException(['Ungültiger Reisetag.']);
        }
        $km = $this->decimal($input['privateKm'] ?? '', 1, 'Kilometer');
        if ($km !== null && (BigDecimal::of($km)->isNegative() || BigDecimal::of($km)->isGreaterThan(5000))) {
            throw new DocumentValidationException(['Bitte zwischen 0 und 5.000 Kilometer angeben.']);
        }
        $kmOrder = $this->optionalOrder($input['privateKmOrderId'] ?? '', $actor, null);
        $this->times->saveDetails([
            'employee_id' => $employee['id'], 'work_date' => $date, 'assignment_kind' => $assignment !== '' ? $assignment : null,
            'overnight' => filter_var($input['overnight'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0, 'travel_day' => $travelDay,
            'location' => $this->text($input['location'] ?? '', 200, 'Einsatzort'), 'notes' => $this->text($input['notes'] ?? '', 1000, 'Bemerkung'),
            'private_km' => $km, 'private_km_purpose' => $this->text($input['privateKmPurpose'] ?? '', 200, 'Fahrtzweck'), 'private_km_order_id' => $kmOrder,
            'updated_by_user_id' => (string) $actor['id'], 'updated_at' => Clock::now(),
        ]);
        return $this->day((string) $employee['id'], $date, $actor);
    }

    /**
     * Auslage mit optionalem Beleg (PDF/Bild, Typ am Inhalt geprüft, höchstens 15 MB).
     *
     * @param array<string,mixed> $input
     * @param array{path:string,name:string}|null $upload
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function addExpense(array $input, ?array $upload, array $actor): array
    {
        $employee = $this->employee(isset($input['employeeId']) && $input['employeeId'] !== '' ? (string) $input['employeeId'] : null, $actor);
        $this->assertEditable($employee, $actor);
        $date = $this->date((string) ($input['date'] ?? ''));
        $amount = $this->decimal($input['amount'] ?? '', Decimal::MONEY_SCALE, 'Betrag');
        if ($amount === null || !BigDecimal::of($amount)->isPositive()) {
            throw new DocumentValidationException(['Bitte einen Betrag größer 0 angeben.']);
        }
        $purpose = $this->text($input['purpose'] ?? '', 200, 'Zweck');
        if ($purpose === '') {
            throw new DocumentValidationException(['Bitte den Zweck der Auslage angeben.']);
        }
        $order = $this->optionalOrder($input['orderId'] ?? '', $actor, null);
        $id = Uuid::v4();
        $receipt = ['receipt_path' => null, 'receipt_name' => null, 'receipt_mime' => null, 'receipt_size' => null, 'receipt_sha256' => null];
        if ($upload !== null) {
            $size = is_file($upload['path']) ? (int) filesize($upload['path']) : 0;
            if ($size <= 0 || $size > DocumentService::MAX_BYTES) {
                throw new DocumentValidationException(['Belege dürfen höchstens 15 MB groß sein.']);
            }
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($upload['path']);
            $extension = self::RECEIPT_TYPES[$mime] ?? throw new DocumentValidationException(['Belege bitte als PDF oder Bild hochladen.']);
            $sha256 = (string) hash_file('sha256', $upload['path']);
            $receipt = [
                'receipt_path' => $this->store->store($upload['path'], 'expenses/' . $employee['id'], $id, $extension),
                'receipt_name' => DocumentService::fileName($upload['name'], $extension), 'receipt_mime' => $mime, 'receipt_size' => $size, 'receipt_sha256' => $sha256,
            ];
        }
        try {
            $this->times->insertExpense([
                'id' => $id, 'employee_id' => $employee['id'], 'work_date' => $date, 'amount' => $amount, 'purpose' => $purpose, 'order_id' => $order,
                'created_by_user_id' => (string) $actor['id'], 'created_at' => Clock::now(),
            ] + $receipt);
        } catch (Throwable $exception) {
            if ($receipt['receipt_path'] !== null) {
                $this->discard((string) $receipt['receipt_path']);
            }
            throw $exception;
        }
        return $this->day((string) $employee['id'], $date, $actor);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function deleteExpense(string $id, array $actor): array
    {
        $expense = $this->times->expense($id) ?? throw new DocumentValidationException(['Die Auslage wurde nicht gefunden.']);
        $employee = $this->employee((string) $expense['employee_id'], $actor);
        $this->assertEditable($employee, $actor);
        $this->times->deleteExpense($id);
        if ($expense['receipt_path'] !== null) {
            $this->discard((string) $expense['receipt_path']);
        }
        return $this->day((string) $employee['id'], (string) $expense['work_date'], $actor);
    }

    /** @param array<string,mixed> $actor @return array{contents:string,name:string,mimeType:string,inline:bool} */
    public function receipt(string $id, array $actor): array
    {
        $expense = $this->times->expense($id) ?? throw new DocumentValidationException(['Die Auslage wurde nicht gefunden.']);
        $this->employee((string) $expense['employee_id'], $actor);
        if ($expense['receipt_path'] === null) {
            throw new DocumentValidationException(['Zu dieser Auslage gibt es keinen Beleg.']);
        }
        try {
            $contents = $this->store->read((string) $expense['receipt_path']);
        } catch (Throwable) {
            throw new DocumentValidationException(['Der Beleg ist nicht mehr verfügbar.']);
        }
        return ['contents' => $contents, 'name' => (string) $expense['receipt_name'], 'mimeType' => (string) $expense['receipt_mime'],
            'inline' => in_array($expense['receipt_mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)];
    }

    // -------------------------------------------------------------------------
    // Hilfen
    // -------------------------------------------------------------------------

    /**
     * Prüft und berechnet einen Zeitblock. Ende ≤ Beginn bedeutet „endet am Folgetag“.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function entryValues(array $input, string $employeeId, ?array $existing, array $actor): array
    {
        $date = $this->date((string) ($input['date'] ?? $existing['work_date'] ?? ''));
        $start = $this->time((string) ($input['start'] ?? ''), 'Beginn');
        $end = $this->time((string) ($input['end'] ?? ''), 'Ende');
        if ($start === $end) {
            throw new DocumentValidationException(['Beginn und Ende dürfen nicht gleich sein.']);
        }
        $startMinutes = self::minutes($start);
        $endMinutes = self::minutes($end);
        $nextDay = $endMinutes <= $startMinutes;
        $span = ($nextDay ? $endMinutes + 1440 : $endMinutes) - $startMinutes;
        $rawBreak = trim((string) ($input['breakMinutes'] ?? ''));
        $break = $rawBreak === '' ? 0 : filter_var($rawBreak, FILTER_VALIDATE_INT);
        if ($break === false || $break < 0) {
            throw new DocumentValidationException(['Die Pause muss eine ganze Zahl von Minuten (0 oder mehr) sein.']);
        }
        if ($break >= $span) {
            throw new DocumentValidationException(['Die Pause muss kürzer als der Zeitblock sein.']);
        }
        $activity = (string) ($input['activity'] ?? '');
        if (!isset(self::ACTIVITIES[$activity])) {
            throw new DocumentValidationException(['Bitte die Art der Tätigkeit wählen.']);
        }
        $order = $this->optionalOrder($input['orderId'] ?? '', $actor, $existing['order_id'] ?? null);
        $this->assertNoOverlap($employeeId, $date, $startMinutes, $span, $existing['id'] ?? null);
        return [
            'work_date' => $date, 'start_time' => $start, 'end_time' => $end, 'ends_next_day' => $nextDay ? 1 : 0,
            'break_minutes' => $break, 'net_minutes' => $span - $break, 'activity' => $activity, 'order_id' => $order,
            'notes' => $this->text($input['notes'] ?? '', 500, 'Bemerkung'),
        ];
    }

    /** Überschneidungen mit eigenen Blöcken desselben, des vorigen und des nächsten Tages. */
    private function assertNoOverlap(string $employeeId, string $date, int $start, int $span, ?string $ignoreId): void
    {
        $day = new DateTimeImmutable($date);
        $neighbours = $this->times->entries($employeeId, $day->modify('-1 day')->format('Y-m-d'), $day->modify('+1 day')->format('Y-m-d'));
        foreach ($neighbours as $other) {
            if ($other['id'] === $ignoreId) {
                continue;
            }
            $offset = (int) (new DateTimeImmutable((string) $other['work_date']))->diff($day)->format('%r%a') * -1440;
            $otherStart = $offset + self::minutes((string) $other['start_time']);
            $otherEnd = $otherStart + (int) $other['net_minutes'] + (int) $other['break_minutes'];
            if ($start < $otherEnd && $otherStart < $start + $span) {
                throw new DocumentValidationException([sprintf('Der Zeitblock überschneidet sich mit %s–%s am %s.', $other['start_time'], $other['end_time'],
                    (new DateTimeImmutable((string) $other['work_date']))->format('d.m.Y'))]);
            }
        }
    }

    /**
     * Einfache Hinweise (keine arbeitsrechtliche Prüfung): langer Tag, kurze Ruhezeit.
     *
     * @param list<array<string,mixed>> $entries
     * @return list<string>
     */
    private function warnings(string $employeeId, string $date, array $entries): array
    {
        $warnings = [];
        $total = array_sum(array_map('intval', array_column($entries, 'net_minutes')));
        if ($total > self::LONG_DAY_MINUTES) {
            $warnings[] = 'Mehr als 10 Stunden erfasst – bitte prüfen.';
        }
        if ($entries !== []) {
            $previous = $this->times->entries($employeeId, (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d'), (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d'));
            $lastEnd = null;
            foreach ($previous as $entry) {
                $end = self::minutes((string) $entry['start_time']) + (int) $entry['net_minutes'] + (int) $entry['break_minutes'] - 1440;
                $lastEnd = $lastEnd === null ? $end : max($lastEnd, $end);
            }
            $firstStart = min(array_map(static fn (array $entry): int => self::minutes((string) $entry['start_time']), $entries));
            if ($lastEnd !== null && $firstStart - $lastEnd < self::MIN_REST_MINUTES) {
                $warnings[] = 'Weniger als 11 Stunden Ruhezeit seit dem Vortag – bitte prüfen.';
            }
        }
        return $warnings;
    }

    /** @param list<array<string,mixed>> $entries @return array{netMinutes:int,workMinutes:int,travelMinutes:int} */
    private function totals(array $entries): array
    {
        $travel = 0;
        $net = 0;
        foreach ($entries as $entry) {
            $net += (int) $entry['net_minutes'];
            if ($entry['activity'] === 'travel') {
                $travel += (int) $entry['net_minutes'];
            }
        }
        return ['netMinutes' => $net, 'workMinutes' => $net - $travel, 'travelMinutes' => $travel];
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $employee @return array<string,mixed> */
    private function mapEntry(array $entry, array $employee, bool $costs): array
    {
        return [
            'id' => $entry['id'], 'version' => (int) $entry['version'], 'date' => $entry['work_date'], 'start' => $entry['start_time'], 'end' => $entry['end_time'],
            'endsNextDay' => (bool) $entry['ends_next_day'], 'breakMinutes' => (int) $entry['break_minutes'], 'netMinutes' => (int) $entry['net_minutes'],
            'activity' => $entry['activity'], 'activityLabel' => self::ACTIVITIES[$entry['activity']] ?? $entry['activity'],
            'orderId' => $entry['order_id'], 'orderNumber' => $entry['order_number'], 'orderTitle' => $entry['order_title'], 'notes' => $entry['notes'],
            // Nachvollziehbarkeit von Korrekturen durch Büro/Admin.
            'changedByOther' => $employee['user_id'] === null || $entry['updated_by_user_id'] !== $employee['user_id'],
            'updatedByName' => $entry['updated_by_name'], 'updatedAt' => $entry['updated_at'],
            'hourlyCostRate' => $costs ? $entry['hourly_cost_rate_snapshot'] : null,
        ];
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    private function mapDetails(array $details): array
    {
        return [
            'assignmentKind' => $details['assignment_kind'], 'overnight' => (bool) $details['overnight'], 'travelDay' => $details['travel_day'],
            'location' => $details['location'], 'notes' => $details['notes'], 'privateKm' => $details['private_km'],
            'privateKmPurpose' => $details['private_km_purpose'], 'privateKmOrderId' => $details['private_km_order_id'], 'privateKmOrderNumber' => $details['km_order_number'],
        ];
    }

    /** @param array<string,mixed> $expense @return array<string,mixed> */
    private function mapExpense(array $expense): array
    {
        return [
            'id' => $expense['id'], 'amount' => $expense['amount'], 'purpose' => $expense['purpose'], 'orderId' => $expense['order_id'], 'orderNumber' => $expense['order_number'],
            'receiptName' => $expense['receipt_name'], 'hasReceipt' => $expense['receipt_path'] !== null,
        ];
    }

    /**
     * Ziel-Mitarbeiter: eigener Mitarbeiter oder – mit timesheet.manage – ein beliebiger.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function employee(?string $employeeId, array $actor): array
    {
        $this->requireAccess($actor);
        $own = $this->times->employeeForUser((string) $actor['id']);
        if ($employeeId !== null && $employeeId !== '' && $employeeId !== ($own['id'] ?? null)) {
            $this->require($actor, 'timesheet.manage');
            return $this->times->employee($employeeId) ?? throw new DocumentValidationException(['Der Mitarbeiter wurde nicht gefunden.']);
        }
        if ($own === null) {
            throw new DocumentValidationException(['Ihrem Benutzerkonto ist kein Mitarbeiter zugeordnet. Bitte im Büro melden.']);
        }
        return $own;
    }

    /** @param array<string,mixed> $employee @param array<string,mixed> $actor */
    private function canEdit(array $employee, array $actor): bool
    {
        if ($this->can($actor, 'timesheet.manage')) {
            return true;
        }
        // Eigene Zeiten nur für aktive Mitarbeiter; archivierte korrigiert das Büro.
        return $employee['user_id'] === $actor['id'] && (bool) $employee['active'] && $this->can($actor, 'timesheet.own');
    }

    /** @param array<string,mixed> $employee @param array<string,mixed> $actor */
    private function assertEditable(array $employee, array $actor): void
    {
        if (!$this->canEdit($employee, $actor)) {
            throw new AccessDeniedException('timesheet.own');
        }
    }

    /** Auftrag optional; neu wählbar nur nicht abgerechnete (Techniker: freigegebene). Ein bereits gesetzter Auftrag bleibt erlaubt. @param array<string,mixed> $actor */
    private function optionalOrder(mixed $value, array $actor, ?string $current): ?string
    {
        $id = trim((string) $value);
        if ($id === '') {
            return null;
        }
        if ($id === $current) {
            return $id;
        }
        $order = $this->times->order($id) ?? throw new DocumentValidationException(['Der Auftrag wurde nicht gefunden.']);
        if ($order['status'] === 'invoiced' || (!$this->releasedAll($actor) && $order['status'] === 'open')) {
            throw new DocumentValidationException(['Dieser Auftrag steht für die Zeiterfassung nicht zur Verfügung.']);
        }
        return $id;
    }

    /** Büro sieht alle offenen Aufträge, Techniker nur freigegebene (wie in den Fachmodulen). @param array<string,mixed> $actor */
    private function releasedAll(array $actor): bool
    {
        return $this->can($actor, 'timesheet.manage') || $this->can($actor, 'orders.write');
    }

    /** @return array{0:string,1:string} */
    private function period(string $from, string $to, int $maxDays = self::MAX_RANGE_DAYS): array
    {
        $from = $this->date($from);
        $to = $this->date($to);
        $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
        if ($days < 0 || $days > $maxDays) {
            throw new DocumentValidationException(['Ungültiger Zeitraum.']);
        }
        return [$from, $to];
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new DocumentValidationException(['Bitte ein gültiges Datum angeben.']);
        }
        return $date->format('Y-m-d');
    }

    private function time(string $value, string $label): string
    {
        $value = trim($value);
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new DocumentValidationException([$label . ': bitte eine Uhrzeit im Format HH:MM angeben.']);
        }
        return $value;
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private function text(mixed $value, int $max, string $label): string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) > $max) {
            throw new DocumentValidationException([$label . ' darf höchstens ' . $max . ' Zeichen lang sein.']);
        }
        return $text;
    }

    private function decimal(mixed $value, int $scale, string $label): ?string
    {
        $text = str_replace(',', '.', trim((string) $value));
        if ($text === '') {
            return null;
        }
        try {
            return (string) Decimal::parse($text, $scale, $label)->toScale($scale);
        } catch (InvalidArgumentException) {
            throw new DocumentValidationException([$label . ': bitte eine gültige Zahl angeben.']);
        }
    }

    /** @param iterable<string> $values */
    private function sum(iterable $values): string
    {
        return $this->decimalSum($values, Decimal::MONEY_SCALE);
    }

    /** @param iterable<string|null> $values */
    private function decimalSum(iterable $values, int $scale): string
    {
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                $sum = $sum->plus((string) $value);
            }
        }
        return (string) $sum->toScale($scale);
    }

    /** @param array<string,mixed> $input */
    private function version(array $input): int
    {
        $version = filter_var($input['version'] ?? null, FILTER_VALIDATE_INT);
        if ($version === false || $version < 1) {
            throw new DocumentValidationException(['Bitte die aktuelle Version mitsenden.']);
        }
        return $version;
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
    private function requireAccess(array $actor): void
    {
        $this->require($actor, 'timesheet.own');
    }

    /** @param array<string,mixed> $actor */
    private function require(array $actor, string $permission): void
    {
        if (!$this->can($actor, $permission)) {
            throw new AccessDeniedException($permission);
        }
    }

    /** @param array<string,mixed> $actor */
    private function can(array $actor, string $permission): bool
    {
        return RolePermissions::allows((string) ($actor['role'] ?? ''), $permission);
    }
}
