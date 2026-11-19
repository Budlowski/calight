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
 * - Zeitblöcke mit Beginn, Ende (ggf. Folgetag), Pause, Tätigkeit, optional Einsatzart und
 *   „davon Reisezeit“. Nettozeit = Ende − Beginn − Pause; Reisezeit ist Teil der Nettozeit,
 *   übrige Arbeitszeit = Nettozeit − Reisezeit. Keine Überschneidungen (auch über Mitternacht).
 * - Ist-Stunden je Mitarbeiter + Tag + Auftrag: eine Quelle oder die ausdrücklich gewählte;
 *   Zeiterfassung und Bautagesbericht ohne Auswahl sind ein Konflikt (actualHoursForOrder).
 *   Bautagesberichte selbst bleiben unverändert.
 * - Rapportstunden sind abrechenbare Leistung und erscheinen nur als Referenz.
 * - Rechte: timesheet.own (eigene Zeiten), timesheet.manage (alle Mitarbeiter, Korrekturen,
 *   Auswertung). Kostensatz-Snapshots nur mit costs.read.
 */
final readonly class TimeTrackingService
{
    /** Tätigkeiten; „travel“ nur noch für Bestandsblöcke (bis 0033), Reisezeit ist ein Feld je Block. */
    public const ACTIVITIES = ['work' => 'Arbeit / Montage', 'preparation' => 'Vorbereitung / Werkstatt', 'internal' => 'Interne Tätigkeit', 'other' => 'Sonstiges', 'travel' => 'Fahrt (bisherige Erfassung)'];
    public const SELECTABLE_ACTIVITIES = ['work', 'preparation', 'internal', 'other'];
    public const ASSIGNMENTS = ['regular' => 'Erste Tätigkeitsstätte', 'external' => 'Auswärtstätigkeit / wechselnder Einsatzort'];
    public const TRAVEL_DAYS = ['none' => 'Kein besonderer Reisetag', 'arrival' => 'Anreisetag', 'departure' => 'Abreisetag'];
    public const SOURCES = ['time_tracking' => 'Zeiterfassung', 'daily_report' => 'Bautagesbericht'];
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
            'activities' => array_intersect_key(self::ACTIVITIES, array_flip(self::SELECTABLE_ACTIVITIES)),
            'activityLabels' => self::ACTIVITIES,
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
        $previous = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $withPrevious = $this->times->entries((string) $employee['id'], $previous, $date);
        $entries = array_values(array_filter($withPrevious, static fn (array $entry): bool => $entry['work_date'] === $date));
        // Blöcke des Vortags, die über Mitternacht in diesen Tag reichen.
        $carried = [];
        foreach ($withPrevious as $entry) {
            if ($entry['work_date'] === $previous && (bool) $entry['ends_next_day']) {
                $share = self::split($entry)[1];
                $carried[] = ['start' => $entry['start_time'], 'end' => $entry['end_time'], 'netMinutes' => $share['net'], 'travelMinutes' => $share['travel']];
            }
        }
        $details = $this->times->details((string) $employee['id'], $date);
        $expenses = $this->times->expenses((string) $employee['id'], $date, $date);
        return [
            'employee' => ['id' => $employee['id'], 'name' => $employee['display_name'], 'active' => (bool) $employee['active']],
            'date' => $date,
            'entries' => array_map(fn (array $entry): array => $this->mapEntry($entry, $employee, $costs), $entries),
            'carriedOver' => $carried,
            // Summen nach Kalendertag (über Mitternacht anteilig, siehe split()).
            'totals' => $this->dayShares($withPrevious, $date, $date)[$date],
            'details' => $details !== null ? $this->mapDetails($details) : null,
            'expenses' => array_map(fn (array $expense): array => $this->mapExpense($expense), $expenses),
            'expenseTotal' => $this->sum(array_column($expenses, 'amount')),
            'dailyReportTimes' => $this->sourceStatus((string) $employee['id'], $date, $entries),
            'workReportTimes' => array_map(static fn (array $row): array => [
                'reportId' => $row['report_id'], 'reportNumber' => $row['report_number'], 'status' => $row['status'], 'hours' => $row['hours'], 'activity' => $row['activity'],
                'orderNumber' => $row['order_number'], 'dateTo' => $row['work_date_to'],
            ], $this->times->workReportTimes((string) $employee['id'], $date, $date)),
            'warnings' => $this->warnings((string) $employee['id'], $date, $entries),
            'canEdit' => $this->canEdit($employee, $actor),
            'canChooseSource' => $this->can($actor, 'timesheet.manage'),
        ];
    }

    /**
     * Summen je Kalendertag für Wochen- und Monatsansicht (höchstens 92 Tage). Blöcke über
     * Mitternacht werden anteilig auf beide Tage verteilt; die Summen der Tage ergeben die Summe.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function range(?string $employeeId, string $from, string $to, array $actor): array
    {
        $employee = $this->employee($employeeId, $actor);
        [$from, $to] = $this->period($from, $to);
        $entries = $this->times->entries((string) $employee['id'], (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'), $to);
        $shares = $this->dayShares($entries, $from, $to);
        $details = array_column($this->times->detailsRange((string) $employee['id'], $from, $to), null, 'work_date');
        $expenses = $this->times->expenses((string) $employee['id'], $from, $to);
        $reports = $this->times->dailyReportTimes((string) $employee['id'], $from, $to);
        $days = [];
        $totals = ['netMinutes' => 0, 'travelMinutes' => 0, 'workMinutes' => 0];
        foreach ($shares as $key => $share) {
            $dayExpenses = array_filter($expenses, static fn (array $expense): bool => $expense['work_date'] === $key);
            $dayReports = array_filter($reports, static fn (array $report): bool => $report['work_date'] === $key);
            foreach ($totals as $field => $value) {
                $totals[$field] = $value + $share[$field];
            }
            $days[] = $share + [
                'date' => $key,
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
            'totals' => $totals + [
                'privateKm' => $this->decimalSum(array_filter(array_column($details, 'private_km'), static fn (?string $value): bool => $value !== null), 1),
                'expenseTotal' => $this->sum(array_column($expenses, 'amount')),
            ],
        ];
    }

    /** Büroauswertung: Zeitblöcke nach Zeitraum, Mitarbeiter und Auftrag (je Block am Beginntag). @param array<string,mixed> $actor @return array<string,mixed> */
    public function report(string $from, string $to, ?string $employeeId, ?string $orderId, array $actor): array
    {
        $this->require($actor, 'timesheet.manage');
        [$from, $to] = $this->period($from, $to, 370);
        $rows = $this->times->report($from, $to, $employeeId ?: null, $orderId ?: null);
        $byOrder = [];
        foreach ($rows as $row) {
            $key = (string) ($row['order_id'] ?? '');
            $byOrder[$key] ??= ['orderId' => $row['order_id'], 'orderNumber' => $row['order_number'], 'orderTitle' => $row['order_title'], 'netMinutes' => 0, 'travelMinutes' => 0, 'workMinutes' => 0];
            $byOrder[$key]['netMinutes'] += (int) $row['net_minutes'];
            $byOrder[$key]['travelMinutes'] += (int) $row['travel_minutes'];
            $byOrder[$key]['workMinutes'] += (int) $row['net_minutes'] - (int) $row['travel_minutes'];
        }
        return [
            'from' => $from, 'to' => $to,
            'rows' => array_map(static fn (array $row): array => [
                'id' => $row['id'], 'date' => $row['work_date'], 'employeeName' => $row['employee_name'], 'start' => $row['start_time'], 'end' => $row['end_time'],
                'endsNextDay' => (bool) $row['ends_next_day'], 'breakMinutes' => (int) $row['break_minutes'], 'netMinutes' => (int) $row['net_minutes'],
                'travelMinutes' => (int) $row['travel_minutes'], 'activity' => $row['activity'], 'activityLabel' => self::ACTIVITIES[$row['activity']] ?? $row['activity'],
                'assignmentKind' => $row['assignment_kind'], 'assignmentLabel' => $row['assignment_kind'] !== null ? self::ASSIGNMENTS[$row['assignment_kind']] ?? '' : '',
                'orderNumber' => $row['order_number'], 'notes' => $row['notes'],
            ], $rows),
            'byOrder' => array_values($byOrder),
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * Ist-Stunden eines Auftrags für die spätere Nachkalkulation (Phase 3.5).
     *
     * Je Mitarbeiter und Tag gilt:
     * - nur Zeitblöcke oder nur Bautagesbericht → diese Quelle;
     * - beide vorhanden → die ausdrücklich gewählte Quelle (time_source_choices);
     * - beide vorhanden und keine Auswahl → Konflikt: nicht in den Summen, sondern in conflicts.
     * Rapportstunden zählen nie. Es wird nichts addiert und nichts still verworfen.
     *
     * @param array<string,mixed> $actor
     * @return array{items:list<array<string,mixed>>,conflicts:list<array<string,mixed>>,totalMinutes:int,travelMinutes:int}
     */
    public function actualHoursForOrder(string $orderId, array $actor): array
    {
        $this->require($actor, 'timesheet.manage');
        $sources = $this->times->orderTimes($orderId);
        $groups = [];
        foreach ($sources['entries'] as $entry) {
            $key = $entry['employee_id'] . '|' . $entry['work_date'];
            $groups[$key] ??= ['employeeId' => $entry['employee_id'], 'employeeName' => $entry['employee_name'], 'date' => $entry['work_date'], 'tracking' => null, 'report' => null];
            $groups[$key]['tracking'] ??= ['netMinutes' => 0, 'travelMinutes' => 0];
            $groups[$key]['tracking']['netMinutes'] += (int) $entry['net_minutes'];
            $groups[$key]['tracking']['travelMinutes'] += (int) $entry['travel_minutes'];
        }
        foreach ($sources['reports'] as $report) {
            $key = $report['employee_id'] . '|' . $report['work_date'];
            $groups[$key] ??= ['employeeId' => $report['employee_id'], 'employeeName' => $report['employee_name'], 'date' => $report['work_date'], 'tracking' => null, 'report' => null];
            $groups[$key]['report'] ??= ['netMinutes' => 0, 'travelMinutes' => 0];
            $groups[$key]['report']['netMinutes'] += self::hoursToMinutes((string) $report['duration_hours']);
        }
        $choices = [];
        foreach ($sources['choices'] as $choice) {
            $choices[$choice['employee_id'] . '|' . $choice['work_date']] = $choice['source'];
        }
        ksort($groups);
        $items = [];
        $conflicts = [];
        foreach ($groups as $key => $group) {
            $source = match (true) {
                $group['report'] === null => 'time_tracking',
                $group['tracking'] === null => 'daily_report',
                default => $choices[$key] ?? null,
            };
            if ($source === null) {
                $conflicts[] = ['employeeId' => $group['employeeId'], 'employeeName' => $group['employeeName'], 'date' => $group['date'],
                    'trackingMinutes' => $group['tracking']['netMinutes'], 'reportMinutes' => $group['report']['netMinutes']];
                continue;
            }
            $values = $source === 'time_tracking' ? $group['tracking'] : $group['report'];
            $items[] = ['employeeId' => $group['employeeId'], 'employeeName' => $group['employeeName'], 'date' => $group['date'], 'source' => $source,
                'chosen' => isset($choices[$key]) && $group['report'] !== null && $group['tracking'] !== null,
                'netMinutes' => $values['netMinutes'], 'travelMinutes' => $values['travelMinutes'], 'workMinutes' => $values['netMinutes'] - $values['travelMinutes']];
        }
        return [
            'items' => $items,
            'conflicts' => $conflicts,
            'totalMinutes' => array_sum(array_column($items, 'netMinutes')),
            'travelMinutes' => array_sum(array_column($items, 'travelMinutes')),
        ];
    }

    /**
     * Maßgebliche Quelle für Mitarbeiter + Tag + Auftrag festlegen (nur Büro). null hebt die Wahl auf.
     *
     * @param array<string,mixed> $input employeeId, date, orderId, source
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function chooseSource(array $input, array $actor): array
    {
        $this->require($actor, 'timesheet.manage');
        $employee = $this->employee((string) ($input['employeeId'] ?? ''), $actor);
        $date = $this->date((string) ($input['date'] ?? ''));
        $order = $this->times->order(trim((string) ($input['orderId'] ?? ''))) ?? throw new DocumentValidationException(['Der Auftrag wurde nicht gefunden.']);
        $source = (string) ($input['source'] ?? '');
        if ($source !== '' && !isset(self::SOURCES[$source])) {
            throw new DocumentValidationException(['Ungültige Quelle.']);
        }
        $this->times->saveChoice((string) $employee['id'], $date, (string) $order['id'], $source !== '' ? $source : null, (string) $actor['id'], Clock::now());
        return $this->day((string) $employee['id'], $date, $actor);
    }

    /**
     * Bautagesbericht-Zeiten eines Tages mit ihrem Status gegenüber der Zeiterfassung.
     *
     * @param list<array<string,mixed>> $entries Zeitblöcke des Tages
     * @return list<array<string,mixed>>
     */
    private function sourceStatus(string $employeeId, string $date, array $entries): array
    {
        $tracked = [];
        foreach ($entries as $entry) {
            if ($entry['order_id'] !== null) {
                $tracked[$entry['order_id']] = ($tracked[$entry['order_id']] ?? 0) + (int) $entry['net_minutes'];
            }
        }
        $choices = array_column($this->times->choices($employeeId, $date, $date), null, 'order_id');
        return array_map(static function (array $row) use ($tracked, $choices): array {
            $choice = $choices[$row['order_id']] ?? null;
            $status = !isset($tracked[$row['order_id']]) ? 'report_only' : ($choice === null ? 'conflict' : 'chosen_' . $choice['source']);
            return [
                'reportId' => $row['report_id'], 'orderId' => $row['order_id'], 'orderNumber' => $row['order_number'], 'orderTitle' => $row['order_title'],
                'hours' => $row['duration_hours'], 'description' => $row['description'], 'status' => $status,
                'trackedMinutes' => $tracked[$row['order_id']] ?? 0, 'chosenByName' => $choice['chosen_by_name'] ?? null,
            ];
        }, $this->times->dailyReportTimes($employeeId, $date, $date));
    }

    /**
     * Verteilt einen Block auf Beginntag und Folgetag. Bruttozeit (Ende − Beginn) nach Uhrzeit;
     * Pause und Reisezeit sind ohne Uhrzeit erfasst und werden im Verhältnis der Bruttozeit
     * aufgeteilt (Rundung auf Minuten, Rest am Beginntag). Netto je Tag = Brutto − Pausenanteil.
     *
     * @param array<string,mixed> $entry
     * @return array{0:array{net:int,travel:int},1:array{net:int,travel:int}}
     */
    public static function split(array $entry): array
    {
        $net = (int) $entry['net_minutes'];
        $travel = (int) ($entry['travel_minutes'] ?? 0);
        $break = (int) $entry['break_minutes'];
        $gross = $net + $break;
        $start = self::minutes((string) $entry['start_time']);
        $firstGross = min($gross, 1440 - $start);
        if (!(bool) $entry['ends_next_day'] || $firstGross >= $gross) {
            return [['net' => $net, 'travel' => $travel], ['net' => 0, 'travel' => 0]];
        }
        $secondGross = $gross - $firstGross;
        $secondNet = $secondGross - (int) round($break * $secondGross / $gross);
        $secondTravel = min($secondNet, (int) round($travel * $secondGross / $gross));
        return [['net' => $net - $secondNet, 'travel' => $travel - $secondTravel], ['net' => $secondNet, 'travel' => $secondTravel]];
    }

    /**
     * Summen je Kalendertag im Zeitraum (Blöcke ab dem Vortag berücksichtigen).
     *
     * @param list<array<string,mixed>> $entries
     * @return array<string,array{netMinutes:int,travelMinutes:int,workMinutes:int,entryCount:int}>
     */
    private function dayShares(array $entries, string $from, string $to): array
    {
        $days = [];
        for ($day = new DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = ['netMinutes' => 0, 'travelMinutes' => 0, 'workMinutes' => 0, 'entryCount' => 0];
        }
        foreach ($entries as $entry) {
            [$first, $second] = self::split($entry);
            $next = (new DateTimeImmutable((string) $entry['work_date']))->modify('+1 day')->format('Y-m-d');
            foreach ([[(string) $entry['work_date'], $first, true], [$next, $second, false]] as [$key, $share, $own]) {
                if (!isset($days[$key]) || ($share['net'] === 0 && !$own)) {
                    continue;
                }
                $days[$key]['netMinutes'] += $share['net'];
                $days[$key]['travelMinutes'] += $share['travel'];
                $days[$key]['workMinutes'] += $share['net'] - $share['travel'];
                $days[$key]['entryCount'] += $own ? 1 : 0;
            }
        }
        return $days;
    }

    private static function hoursToMinutes(string $hours): int
    {
        return (int) BigDecimal::of($hours)->multipliedBy(60)->toScale(0, \Brick\Math\RoundingMode::HalfUp)->toInt();
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
        // Abwesenheit (optional, nur Dokumentation).
        $absenceStart = trim((string) ($input['absenceStart'] ?? '')) !== '' ? $this->time((string) $input['absenceStart'], 'Beginn der Abwesenheit') : null;
        $absenceEnd = trim((string) ($input['absenceEnd'] ?? '')) !== '' ? $this->time((string) $input['absenceEnd'], 'Ende der Abwesenheit') : null;
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
            'employee_id' => $employee['id'], 'work_date' => $date, 'absence_start' => $absenceStart, 'absence_end' => $absenceEnd,
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
        // „travel“ nur für unveränderte Bestandsblöcke; neu ist Reisezeit ein Feld.
        if (!in_array($activity, self::SELECTABLE_ACTIVITIES, true) && !($activity === 'travel' && ($existing['activity'] ?? null) === 'travel')) {
            throw new DocumentValidationException(['Bitte die Art der Tätigkeit wählen.']);
        }
        $net = $span - $break;
        $rawTravel = trim((string) ($input['travelMinutes'] ?? ''));
        $travel = $rawTravel === '' ? 0 : filter_var($rawTravel, FILTER_VALIDATE_INT);
        if ($travel === false || $travel < 0) {
            throw new DocumentValidationException(['Die Reisezeit muss eine ganze Zahl von Minuten (0 oder mehr) sein.']);
        }
        if ($travel > $net) {
            throw new DocumentValidationException(['Die Reisezeit darf die Nettozeit nicht überschreiten.']);
        }
        $assignment = trim((string) ($input['assignmentKind'] ?? ''));
        if ($assignment !== '' && !isset(self::ASSIGNMENTS[$assignment])) {
            throw new DocumentValidationException(['Ungültige Einsatzart.']);
        }
        $order = $this->optionalOrder($input['orderId'] ?? '', $actor, $existing['order_id'] ?? null);
        $this->assertNoOverlap($employeeId, $date, $startMinutes, $span, $existing['id'] ?? null);
        return [
            'work_date' => $date, 'start_time' => $start, 'end_time' => $end, 'ends_next_day' => $nextDay ? 1 : 0,
            'break_minutes' => $break, 'net_minutes' => $net, 'travel_minutes' => $travel, 'activity' => $activity, 'order_id' => $order,
            'assignment_kind' => $assignment !== '' ? $assignment : null,
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
            // Reisezeit ist Teil der Nettozeit (Bestandsblöcke „travel“ seit 0033 vollständig).
            $travel += (int) $entry['travel_minutes'];
        }
        return ['netMinutes' => $net, 'workMinutes' => $net - $travel, 'travelMinutes' => $travel];
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $employee @return array<string,mixed> */
    private function mapEntry(array $entry, array $employee, bool $costs): array
    {
        return [
            'id' => $entry['id'], 'version' => (int) $entry['version'], 'date' => $entry['work_date'], 'start' => $entry['start_time'], 'end' => $entry['end_time'],
            'endsNextDay' => (bool) $entry['ends_next_day'], 'breakMinutes' => (int) $entry['break_minutes'], 'netMinutes' => (int) $entry['net_minutes'],
            'travelMinutes' => (int) $entry['travel_minutes'], 'workMinutes' => (int) $entry['net_minutes'] - (int) $entry['travel_minutes'],
            'assignmentKind' => $entry['assignment_kind'], 'assignmentLabel' => $entry['assignment_kind'] !== null ? (self::ASSIGNMENTS[$entry['assignment_kind']] ?? '') : null,
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
            'overnight' => (bool) $details['overnight'], 'travelDay' => $details['travel_day'],
            'absenceStart' => $details['absence_start'], 'absenceEnd' => $details['absence_end'],
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
