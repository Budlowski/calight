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
 * Zeiterfassung: zentrale Basis tatsächlich geleisteter Mitarbeiterstunden (time_entries).
 *
 * Herkunft je Buchung (source_kind), jede Stunde zählt genau einmal:
 * - work_report:  Stundenposition eines Rapports (gültige Fassung bzw. Entwurf ohne Korrekturbezug),
 * - daily_report: Stundenposition eines Tagesberichts,
 * - manual:       direkt erfasster Zeitblock (Büroarbeit, Rüstzeit, Vorbereitung …).
 * Berichtsbuchungen werden von den Berichtsdiensten gepflegt (ReportTimeBookings). Ihre Dauer ist
 * vorgegeben; hier werden nur Beginn, Ende, Pause, Reisezeit und Einsatzart ergänzt – mit Uhrzeiten
 * muss Ende − Beginn − Pause exakt der Berichtsdauer entsprechen. Reisezeit ist Teil der Nettozeit.
 *
 * Uhrzeiten sind optional, solange die Betriebseinstellung „Stundenerfassung ohne Uhrzeiten“
 * aktiv ist; sonst sind Buchungen ohne Uhrzeiten „Uhrzeiten fehlen“ (zählen trotzdem voll).
 * Es gibt keine konkurrierenden Stundenquellen: jede Buchung zählt genau einmal.
 * Rechte: timesheet.own (eigene Zeiten), timesheet.manage (alle Mitarbeiter, Auswertung);
 * Kostensatz-Snapshots nur mit costs.read.
 */
final readonly class TimeTrackingService
{
    /** Tätigkeiten; „travel“ nur noch für Bestandsblöcke (bis 0033), Reisezeit ist ein Feld je Block. */
    /**
     * Tätigkeitsarten gibt es nicht mehr (Unterscheidung über Auftrag/Kostenstelle und Bemerkung);
     * neue Blöcke sind „Arbeitszeit“. Die Spalte bleibt aus Schemagründen, Bestandswerte werden
     * einheitlich als Arbeitszeit angezeigt („travel“ nur für Bestandsblöcke bis 0033).
     */
    public const ACTIVITIES = ['work' => 'Arbeitszeit', 'preparation' => 'Arbeitszeit', 'internal' => 'Arbeitszeit', 'other' => 'Arbeitszeit', 'travel' => 'Fahrt (bisherige Erfassung)'];
    /** Abwesenheiten: getrennt von Ist-Arbeitsstunden, ohne Kostenziel, ohne Sollzeitannahme. */
    public const ABSENCE_TYPES = ['vacation' => 'Urlaub', 'holiday' => 'Feiertag', 'sick' => 'Krankheit', 'compensatory' => 'Zeitausgleich', 'day_off' => 'Arbeitsfrei', 'other' => 'Sonstige Abwesenheit'];
    public const ASSIGNMENTS = ['regular' => 'Erste Tätigkeitsstätte', 'external' => 'Auswärtstätigkeit / wechselnder Einsatzort'];
    public const SOURCE_KINDS = ['work_report' => 'Rapport', 'daily_report' => 'Tagesbericht', 'manual' => 'Manuell'];
    public const RECEIPT_TYPES = DocumentService::ALLOWED_TYPES;
    private const LONG_DAY_MINUTES = 600;
    private const MIN_REST_MINUTES = 660;
    private const MAX_RANGE_DAYS = 92;

    public function __construct(private TimeTrackingRepository $times, private AttachmentStore $store, private ?CostTargets $targets = null)
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
            'absenceTypes' => self::ABSENCE_TYPES,
            'assignments' => self::ASSIGNMENTS,
            // Reisekosten: Kategorien und rechnerische Werte aus den Betriebseinstellungen.
            'travelCategories' => TravelAllowance::labels($settings = $this->settings()),
            'travelSettings' => ['mealAllowances' => (bool) $settings['travel.meal_allowances_enabled'], 'kmRate' => $settings['travel.km_rate'],
                'thresholdHours' => $settings['travel.single_day_threshold_hours']],
            'sourceKinds' => self::SOURCE_KINDS,
            'timesOptional' => $this->times->timesOptional(),
            'ownEmployeeId' => $own['id'] ?? null,
            'canManage' => $manage,
            'employees' => $manage ? array_map(static fn (array $employee): array => [
                'id' => $employee['id'], 'name' => $employee['display_name'], 'number' => $employee['personnel_number'], 'active' => (bool) $employee['active'],
            ], $this->times->employees()) : [],
            'orders' => $orders = array_map(static fn (array $order): array => ['id' => $order['id'], 'orderNumber' => $order['order_number'], 'title' => $order['title']],
                $this->times->orders(!$this->releasedAll($actor))),
            // Gemeinsames Suchfeld „Auftrag / Kostenstelle“ (Aufträge mit den bestehenden Freigaberegeln).
            'costTargets' => $this->targets?->options($orders) ?? array_map(static fn (array $order): array => ['type' => 'order', 'id' => $order['id'], 'number' => $order['orderNumber'],
                'label' => $order['title'], 'active' => true], $orders),
        ];
    }

    /** Tagesansicht mit allen Buchungen (alle Herkünfte), Summen, Einsatz/Auslagen. @param array<string,mixed> $actor @return array<string,mixed> */
    public function day(?string $employeeId, string $date, array $actor): array
    {
        $employee = $this->employee($employeeId, $actor);
        $date = $this->date($date);
        $costs = $this->can($actor, 'costs.read');
        $timesOptional = $this->times->timesOptional();
        $previous = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $withPrevious = $this->times->entries((string) $employee['id'], $previous, $date);
        $entries = array_values(array_filter($withPrevious, static fn (array $entry): bool => $entry['work_date'] === $date));
        // Blöcke des Vortags, die über Mitternacht in diesen Tag reichen.
        $carried = [];
        foreach ($withPrevious as $entry) {
            if ($entry['work_date'] === $previous && (bool) $entry['ends_next_day']) {
                $share = self::split($entry)[1];
                $carried[] = ['start' => $entry['start_time'], 'end' => $entry['end_time'], 'netMinutes' => $share['net'], 'travelMinutes' => $share['travel'],
                    'sourceKind' => $entry['source_kind'], 'sourceLabel' => self::SOURCE_KINDS[$entry['source_kind']]];
            }
        }
        $details = $this->times->details((string) $employee['id'], $date);
        $trips = $this->times->trips((string) $employee['id'], $date, $date);
        $absences = $this->times->absences((string) $employee['id'], $date, $date);
        $expenses = $this->times->expenses((string) $employee['id'], $date, $date);
        // Eingereichte und festgeschriebene Tage sind für alle gesperrt (auch Büro/Admin).
        $lockStatus = $this->times->dayLocks((string) $employee['id'], $date, $date)[$date] ?? 'open';
        $canEdit = $this->canEdit($employee, $actor) && !self::locked($lockStatus);
        return [
            'employee' => ['id' => $employee['id'], 'name' => $employee['display_name'], 'active' => (bool) $employee['active']],
            'date' => $date,
            'timesOptional' => $timesOptional,
            'entries' => array_map(fn (array $entry): array => $this->mapEntry($entry, $employee, $costs, $timesOptional, $canEdit), $entries),
            'carriedOver' => $carried,
            // Summen nach Kalendertag (über Mitternacht anteilig, siehe split()); nur zählende Buchungen.
            'totals' => $this->dayShares($withPrevious, $date, $date, $timesOptional)[$date],
            'details' => $details !== null ? $this->mapDetails($details) : null,
            'trips' => array_map($this->mapTrip(...), $trips),
            'absences' => array_map(fn (array $absence): array => self::mapAbsence($absence) + ['canEdit' => $canEdit], $absences),
            // Rechnerische Referenzwerte (Verpflegung, Kilometer) und Hinweise des Tages.
            'travel' => $travel = $this->travelDay($details, $trips, $this->settings()),
            'travelWarnings' => $this->travelWarnings($travel, $this->settings()),
            'expenses' => array_map(fn (array $expense): array => $this->mapExpense($expense), $expenses),
            'expenseTotal' => $this->sum(array_column($expenses, 'amount')),
            'warnings' => $this->warnings((string) $employee['id'], $date, $entries),
            'canEdit' => $canEdit,
            'lockStatus' => $lockStatus,
            'lockLabel' => TimeApprovalService::DAY_STATUS[$lockStatus] ?? $lockStatus,
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
        $timesOptional = $this->times->timesOptional();
        $entries = $this->times->entries((string) $employee['id'], (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'), $to);
        $shares = $this->dayShares($entries, $from, $to, $timesOptional);
        $details = array_column($this->times->detailsRange((string) $employee['id'], $from, $to), null, 'work_date');
        $trips = [];
        foreach ($this->times->trips((string) $employee['id'], $from, $to) as $trip) {
            $trips[$trip['work_date']][] = $trip;
        }
        $settings = $this->settings();
        $absences = [];
        foreach ($this->times->absences((string) $employee['id'], $from, $to) as $absence) {
            $absences[$absence['work_date']][] = self::mapAbsence($absence);
        }
        $expenses = $this->times->expenses((string) $employee['id'], $from, $to);
        $locks = $this->times->dayLocks((string) $employee['id'], $from, $to);
        $days = [];
        $totals = ['netMinutes' => 0, 'travelMinutes' => 0, 'workMinutes' => 0, 'incompleteMinutes' => 0, 'untimedMinutes' => 0,
            'bySource' => ['work_report' => 0, 'daily_report' => 0, 'manual' => 0]];
        $travelDays = [];
        foreach ($shares as $key => $share) {
            $travel = $travelDays[] = $this->travelDay($details[$key] ?? null, $trips[$key] ?? [], $settings);
            $dayExpenses = array_filter($expenses, static fn (array $expense): bool => $expense['work_date'] === $key);
            foreach (['netMinutes', 'travelMinutes', 'workMinutes', 'incompleteMinutes', 'untimedMinutes'] as $field) {
                $totals[$field] += $share[$field];
            }
            foreach ($share['bySource'] as $kind => $minutes) {
                $totals['bySource'][$kind] += $minutes;
            }
            $days[] = $share + [
                'date' => $key,
                'privateKm' => $travel['privateKm'] ?? null,
                'mealAllowance' => $travel['allowance'] ?? null,
                'expenseTotal' => $dayExpenses === [] ? null : $this->sum(array_column($dayExpenses, 'amount')),
                'hasDetails' => isset($details[$key]),
                'lockStatus' => $locks[$key] ?? 'open',
                'absences' => $absences[$key] ?? [],
            ];
        }
        return [
            'employee' => ['id' => $employee['id'], 'name' => $employee['display_name']],
            'from' => $from,
            'to' => $to,
            'timesOptional' => $timesOptional,
            'days' => $days,
            'totals' => $totals + [
                'expenseTotal' => $this->sum(array_column($expenses, 'amount')),
                'absences' => self::absenceTotals(array_merge([], ...array_values($absences))),
            ] + $this->travelTotals($travelDays, $settings),
        ];
    }

    /**
     * Büroauswertung nach Zeitraum, Mitarbeiter und Auftrag – alle Herkünfte aus der zentralen
     * Basis. Summen nach Kalendertag wie Tag/Woche/Monat (split). Buchungen ohne Uhrzeiten bei
     * deaktivierter Betriebseinstellung zählen und werden als unvollständig ausgewiesen.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function report(string $from, string $to, ?string $employeeId, ?string $orderId, array $actor): array
    {
        $this->require($actor, 'timesheet.manage');
        [$from, $to] = $this->period($from, $to, 370);
        $timesOptional = $this->times->timesOptional();
        $entries = $this->times->report($from, $to, $employeeId ?: null, $orderId ?: null);
        $rows = [];
        $byOrder = [];
        $empty = ['netMinutes' => 0, 'travelMinutes' => 0, 'workMinutes' => 0, 'incompleteMinutes' => 0, 'untimedMinutes' => 0,
            'bySource' => ['work_report' => 0, 'daily_report' => 0, 'manual' => 0]];
        $totals = $empty;
        foreach ($entries as $entry) {
            [$net, $travel] = $this->shareInPeriod($entry, $from, $to);
            if ($net === 0 && ((string) $entry['work_date'] < $from)) {
                continue; // Vortagsblock ohne Anteil im Zeitraum
            }
            // Gruppiert nach Kostenziel: Auftrag, allgemeine Kostenstelle oder ohne Zuordnung.
            $orderKey = $entry['order_id'] !== null ? 'order:' . $entry['order_id'] : ($entry['cost_center_id'] !== null ? 'cost_center:' . $entry['cost_center_id'] : '');
            $byOrder[$orderKey] ??= ['orderId' => $entry['order_id'], 'orderNumber' => $entry['order_number'], 'orderTitle' => $entry['order_title'],
                'costCenterId' => $entry['cost_center_id'], 'costCenterNumber' => $entry['cost_center_number'], 'costCenterName' => $entry['cost_center_name']] + $empty;
            self::addShare($byOrder[$orderKey], $entry, $net, $travel, $timesOptional);
            self::addShare($totals, $entry, $net, $travel, $timesOptional);
            $rows[] = [
                'id' => $entry['id'], 'date' => $entry['work_date'], 'employeeName' => $entry['employee_name'], 'start' => $entry['start_time'], 'end' => $entry['end_time'],
                'endsNextDay' => (bool) $entry['ends_next_day'], 'breakMinutes' => (int) $entry['break_minutes'], 'netMinutes' => (int) $entry['net_minutes'],
                'travelMinutes' => (int) $entry['travel_minutes'], 'periodNetMinutes' => $net, 'periodTravelMinutes' => $travel,
                'activity' => $entry['activity'], 'activityLabel' => self::ACTIVITIES[$entry['activity']] ?? $entry['activity'],
                'assignmentKind' => $entry['assignment_kind'], 'assignmentLabel' => $entry['assignment_kind'] !== null ? self::ASSIGNMENTS[$entry['assignment_kind']] ?? '' : '',
                'orderNumber' => $entry['order_number'], 'costCenterNumber' => $entry['cost_center_number'], 'notes' => $entry['notes'], 'hasTimes' => $entry['start_time'] !== null,
            ] + self::source($entry);
        }
        // Abwesenheiten getrennt von der Ist-Arbeitszeit (nur Mitarbeiterfilter; nie je Auftrag).
        $absences = $orderId ? [] : array_map(self::mapAbsence(...), $this->times->absences($employeeId ?: null, $from, $to));
        return ['from' => $from, 'to' => $to, 'timesOptional' => $timesOptional, 'rows' => $rows, 'byOrder' => array_values($byOrder), 'totals' => $totals,
            'absences' => self::absenceTotals($absences), 'absenceRows' => $absences];
    }

    /** @param array<string,mixed> $sum @param array<string,mixed> $entry */
    private static function addShare(array &$sum, array $entry, int $net, int $travel, bool $timesOptional): void
    {
        $sum['netMinutes'] += $net;
        $sum['travelMinutes'] += $travel;
        $sum['workMinutes'] += $net - $travel;
        // Ohne Uhrzeit: reine Information; „unvollständig“ nur bei deaktivierter Betriebseinstellung.
        $sum['untimedMinutes'] += $entry['start_time'] === null ? $net : 0;
        $sum['incompleteMinutes'] += !$timesOptional && $entry['start_time'] === null ? $net : 0;
        $sum['bySource'][$entry['source_kind']] += $net;
    }

    /**
     * Herkunftsangaben einer Buchung für Anzeige und Verlinkung.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function source(array $entry): array
    {
        $kind = (string) $entry['source_kind'];
        return [
            'sourceKind' => $kind,
            'sourceLabel' => self::SOURCE_KINDS[$kind] ?? $kind,
            'reportId' => $kind === 'work_report' ? $entry['work_report_id'] : ($kind === 'daily_report' ? $entry['daily_report_id'] : null),
            'reportUrl' => match ($kind) {
                'work_report' => '/work-reports/' . rawurlencode((string) $entry['work_report_id']),
                'daily_report' => '/daily-reports/' . rawurlencode((string) $entry['daily_report_id']),
                default => null,
            },
            'reportLabel' => match ($kind) {
                'work_report' => 'Rapport ' . ($entry['work_report_number'] ?? 'Entwurf'),
                'daily_report' => 'Tagesbericht ' . (new DateTimeImmutable((string) $entry['daily_report_date']))->format('d.m.Y') . (($entry['daily_report_title'] ?? '') !== '' ? ' · ' . $entry['daily_report_title'] : ''),
                default => null,
            },
            // Rapportentwurf: Quelle ist noch nicht eingereicht.
            'reportDraft' => $kind === 'work_report' && $entry['work_report_status'] === 'draft',
            'reportActivity' => match ($kind) {
                'work_report' => trim(implode(' · ', array_filter([(string) ($entry['labor_item_name'] ?? ''), (string) ($entry['labor_activity'] ?? '')]))),
                'daily_report' => (string) ($entry['daily_entry_description'] ?? ''),
                default => '',
            },
            'customerName' => $entry['customer_name'] ?? null,
        ];
    }

    /**
     * Anteil eines Blocks (Netto, Reise) im Zeitraum nach der Kalendertag-Aufteilung.
     *
     * @param array<string,mixed> $entry
     * @return array{0:int,1:int}
     */
    private function shareInPeriod(array $entry, ?string $from, ?string $to): array
    {
        $net = 0;
        $travel = 0;
        foreach (self::segments($entry) as [$date, $share]) {
            if (($from === null || $date >= $from) && ($to === null || $date <= $to)) {
                $net += $share['net'];
                $travel += $share['travel'];
            }
        }
        return [$net, $travel];
    }

    /**
     * Kalendertag-Anteile eines Blocks; der Folgetag nur mit Anteil > 0.
     *
     * @param array<string,mixed> $entry
     * @return list<array{0:string,1:array{net:int,travel:int}}>
     */
    private static function segments(array $entry): array
    {
        [$first, $second] = self::split($entry);
        $segments = [[(string) $entry['work_date'], $first]];
        if ($second['net'] > 0) {
            $segments[] = [(new DateTimeImmutable((string) $entry['work_date']))->modify('+1 day')->format('Y-m-d'), $second];
        }
        return $segments;
    }

    /**
     * Ist-Stunden eines Auftrags für die spätere Nachkalkulation (Phase 3.5) – ausschließlich aus
     * der zentralen Basis: alle zählenden Buchungen mit diesem Auftrag (Rapport, Tagesbericht,
     * manuell), jede genau einmal. Ersetzte Rapportfassungen haben keine Buchungen. Buchungen ohne Uhrzeiten zählen voll;
     * bei deaktivierter Betriebseinstellung werden sie zusätzlich als unvollständig ausgewiesen.
     *
     * Kalendertag-Aufteilung wie Tag/Woche/Monat (split); items sind Anteile je Kalendertag mit
     * Beginntag (sourceDate). Kostensatz-Snapshots (hourlyCostRate) nur mit costs.read – sie werden
     * hier nicht verrechnet. Spesen und Kilometer sind nicht enthalten.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function actualHoursForOrder(string $orderId, array $actor, ?string $from = null, ?string $to = null): array
    {
        $this->require($actor, 'timesheet.manage');
        $from = $from !== null && $from !== '' ? $this->date($from) : null;
        $to = $to !== null && $to !== '' ? $this->date($to) : null;
        if ($from !== null && $to !== null && $from > $to) {
            throw new DocumentValidationException(['Ungültiger Zeitraum.']);
        }
        $costs = $this->can($actor, 'costs.read');
        $timesOptional = $this->times->timesOptional();
        $items = [];
        foreach ($this->times->orderEntries($orderId) as $entry) {
            $base = ['entryId' => $entry['id'], 'employeeId' => $entry['employee_id'], 'employeeName' => $entry['employee_name'], 'sourceDate' => $entry['work_date']] + self::source($entry);
            foreach (self::segments($entry) as [$date, $share]) {
                if (($from !== null && $date < $from) || ($to !== null && $date > $to)) {
                    continue;
                }
                $item = $base + ['date' => $date, 'netMinutes' => $share['net'], 'travelMinutes' => $share['travel'], 'workMinutes' => $share['net'] - $share['travel'],
                    'hasTimes' => $entry['start_time'] !== null, 'timesMissing' => !$timesOptional && $entry['start_time'] === null]
                    + ($costs ? ['hourlyCostRate' => $entry['hourly_cost_rate_snapshot']] : []);
                $items[] = $item;
            }
        }
        usort($items, static fn (array $a, array $b): int => [$a['date'], $a['employeeName']] <=> [$b['date'], $b['employeeName']]);
        $byEmployee = [];
        $bySource = ['work_report' => 0, 'daily_report' => 0, 'manual' => 0];
        foreach ($items as $item) {
            $byEmployee[$item['employeeId']] ??= ['employeeId' => $item['employeeId'], 'employeeName' => $item['employeeName'], 'netMinutes' => 0, 'travelMinutes' => 0, 'workMinutes' => 0];
            $byEmployee[$item['employeeId']]['netMinutes'] += $item['netMinutes'];
            $byEmployee[$item['employeeId']]['travelMinutes'] += $item['travelMinutes'];
            $byEmployee[$item['employeeId']]['workMinutes'] += $item['workMinutes'];
            $bySource[$item['sourceKind']] += $item['netMinutes'];
        }
        $net = array_sum(array_column($items, 'netMinutes'));
        $travel = array_sum(array_column($items, 'travelMinutes'));
        return [
            'orderId' => $orderId, 'from' => $from, 'to' => $to,
            'items' => $items,
            'byEmployee' => array_values($byEmployee),
            'bySource' => $bySource,
            'totals' => ['netMinutes' => $net, 'travelMinutes' => $travel, 'workMinutes' => $net - $travel,
                'timesMissingMinutes' => array_sum(array_map(static fn (array $item): int => $item['timesMissing'] ? $item['netMinutes'] : 0, $items))],
            'totalMinutes' => $net,
            'travelMinutes' => $travel,
        ];
    }

    /**
     * Verteilt einen Block auf Beginntag und Folgetag. Bruttozeit (Ende − Beginn) nach Uhrzeit;
     * Pause und Reisezeit sind ohne Uhrzeit erfasst und werden im Verhältnis der Bruttozeit
     * aufgeteilt (Rundung auf Minuten, Rest am Beginntag). Netto je Tag = Brutto − Pausenanteil.
     * Buchungen ohne Uhrzeiten zählen vollständig am Buchungstag.
     *
     * @param array<string,mixed> $entry
     * @return array{0:array{net:int,travel:int},1:array{net:int,travel:int}}
     */
    public static function split(array $entry): array
    {
        $net = (int) $entry['net_minutes'];
        $travel = (int) ($entry['travel_minutes'] ?? 0);
        $break = (int) ($entry['break_minutes'] ?? 0);
        $gross = $net + $break;
        if (($entry['start_time'] ?? null) === null) {
            return [['net' => $net, 'travel' => $travel], ['net' => 0, 'travel' => 0]];
        }
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
     * Summen je Kalendertag im Zeitraum (Blöcke ab dem Vortag berücksichtigen); fehlende Uhrzeiten
     * (bei deaktivierter Betriebseinstellung) zusätzlich als incompleteMinutes.
     *
     * @param list<array<string,mixed>> $entries
     * @return array<string,array<string,mixed>>
     */
    private function dayShares(array $entries, string $from, string $to, bool $timesOptional): array
    {
        $days = [];
        for ($day = new DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = ['netMinutes' => 0, 'travelMinutes' => 0, 'workMinutes' => 0, 'incompleteMinutes' => 0, 'untimedMinutes' => 0,
                'bySource' => ['work_report' => 0, 'daily_report' => 0, 'manual' => 0], 'entryCount' => 0];
        }
        foreach ($entries as $entry) {
            foreach (self::segments($entry) as $index => [$key, $share]) {
                if (!isset($days[$key])) {
                    continue;
                }
                self::addShare($days[$key], $entry, $share['net'], $share['travel'], $timesOptional);
                $days[$key]['entryCount'] += $index === 0 ? 1 : 0;
            }
        }
        return $days;
    }

    // -------------------------------------------------------------------------
    // Zeitblöcke
    // -------------------------------------------------------------------------

    /** Manueller Zeitblock (mit Uhrzeiten oder – wenn erlaubt – nur mit Dauer). @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function createEntry(array $input, array $actor): array
    {
        $employee = $this->employee(isset($input['employeeId']) ? (string) $input['employeeId'] : null, $actor);
        $this->assertEditable($employee, $actor);
        $values = $this->entryValues($input, (string) $employee['id'], null, $actor);
        $this->assertDaysOpen((string) $employee['id'], self::touchedDates($values));
        $now = Clock::now();
        $this->times->insertEntry($values + [
            'id' => Uuid::v4(), 'employee_id' => $employee['id'], 'source_kind' => 'manual',
            'hourly_cost_rate_snapshot' => $employee['hourly_cost_rate'],
            'created_by_user_id' => (string) $actor['id'], 'created_at' => $now, 'updated_by_user_id' => (string) $actor['id'], 'updated_at' => $now,
        ]);
        return $this->day((string) $employee['id'], $values['work_date'], $actor);
    }

    /**
     * Manuelle Buchung bearbeiten bzw. bei Berichtsbuchungen nur die zeitliche Einordnung
     * (Beginn, Ende, Pause, Reisezeit, Einsatzart, Bemerkung) ergänzen. Die Berichtsdauer bleibt.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function updateEntry(string $id, array $input, array $actor): array
    {
        $entry = $this->times->entry($id) ?? throw new DocumentValidationException(['Der Zeitblock wurde nicht gefunden.']);
        $employee = $this->employee((string) $entry['employee_id'], $actor);
        $this->assertEditable($employee, $actor);
        $this->assertDaysOpen((string) $employee['id'], self::touchedDates($entry));
        $values = $entry['source_kind'] === 'manual'
            ? $this->entryValues($input, (string) $employee['id'], $entry, $actor)
            : $this->reportTimeValues($input, $entry);
        // Verschiebung: alter und neuer Tag (inkl. Folgetag über Mitternacht).
        $this->assertDaysOpen((string) $employee['id'], self::touchedDates($values + $entry));
        if (!$this->times->updateEntry($id, $this->version($input), $values + ['updated_by_user_id' => (string) $actor['id'], 'updated_at' => Clock::now()])) {
            throw new DocumentValidationException(['Der Zeitblock wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->day((string) $employee['id'], (string) $entry['work_date'], $actor);
    }

    /** Nur manuelle Buchungen; Berichtsstunden werden am Bericht geändert. @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function deleteEntry(string $id, array $input, array $actor): array
    {
        $entry = $this->times->entry($id) ?? throw new DocumentValidationException(['Der Zeitblock wurde nicht gefunden.']);
        $employee = $this->employee((string) $entry['employee_id'], $actor);
        $this->assertEditable($employee, $actor);
        if ($entry['source_kind'] !== 'manual') {
            throw new DocumentValidationException(['Stunden aus ' . self::SOURCE_KINDS[$entry['source_kind']] . 'en werden im Bericht geändert, nicht in der Zeiterfassung.']);
        }
        $this->assertDaysOpen((string) $employee['id'], self::touchedDates($entry));
        if (!$this->times->deleteEntry($id, $this->version($input))) {
            throw new DocumentValidationException(['Der Zeitblock wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->day((string) $employee['id'], (string) $entry['work_date'], $actor);
    }

    // -------------------------------------------------------------------------
    // Einsatz, Kilometer und Auslagen
    // -------------------------------------------------------------------------

    /**
     * Auswärtstätigkeit und Verpflegung eines Tages: Kategorie (nie aus der Arbeitszeit
     * abgeleitet), Inland/Ausland, optionale Abwesenheitsdauer, Übernachtung, gestellte
     * Mahlzeiten, Einsatzort und Bemerkung.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function saveDetails(array $input, array $actor): array
    {
        $employee = $this->employee(isset($input['employeeId']) ? (string) $input['employeeId'] : null, $actor);
        $this->assertEditable($employee, $actor);
        $date = $this->date((string) ($input['date'] ?? ''));
        $this->assertDaysOpen((string) $employee['id'], [$date]);
        $category = (string) ($input['travelCategory'] ?? 'none');
        if (!in_array($category, TravelAllowance::CATEGORIES, true)) {
            throw new DocumentValidationException(['Bitte eine gültige Art der Auswärtstätigkeit wählen.']);
        }
        $absence = $this->absence($input['absence'] ?? '');
        $flag = static fn (string $key): int => filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0;
        $this->times->saveDetails([
            'employee_id' => $employee['id'], 'work_date' => $date, 'travel_category' => $category, 'abroad' => $flag('abroad'), 'absence_minutes' => $absence,
            'overnight' => $flag('overnight'), 'meal_breakfast' => $flag('mealBreakfast'), 'meal_lunch' => $flag('mealLunch'), 'meal_dinner' => $flag('mealDinner'),
            'location' => $this->text($input['location'] ?? '', 200, 'Einsatzort'), 'notes' => $this->text($input['notes'] ?? '', 1000, 'Bemerkung'),
            'updated_by_user_id' => (string) $actor['id'], 'updated_at' => Clock::now(),
        ]);
        return $this->day((string) $employee['id'], $date, $actor);
    }

    /**
     * Dienstliche Privat-Pkw-Fahrt anlegen (ohne id) oder bearbeiten: Kilometer, Strecke,
     * Zweck, optional Auftrag ODER Kostenstelle und Bemerkung. Keine Fahrtenbuchverwaltung.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function saveTrip(array $input, array $actor): array
    {
        $existing = isset($input['id']) && $input['id'] !== '' ? ($this->times->trip((string) $input['id']) ?? throw new DocumentValidationException(['Die Fahrt wurde nicht gefunden.'])) : null;
        $employee = $this->employee($existing !== null ? (string) $existing['employee_id'] : (isset($input['employeeId']) ? (string) $input['employeeId'] : null), $actor);
        $this->assertEditable($employee, $actor);
        $date = $this->date((string) ($input['date'] ?? $existing['work_date'] ?? ''));
        $this->assertDaysOpen((string) $employee['id'], array_values(array_unique(array_filter([$date, $existing['work_date'] ?? null]))));
        $km = $this->decimal($input['kilometers'] ?? '', 1, 'Kilometer');
        if ($km === null || !BigDecimal::of($km)->isPositive() || BigDecimal::of($km)->isGreaterThan(5000)) {
            throw new DocumentValidationException(['Bitte die gefahrenen Kilometer angeben (mehr als 0, höchstens 5.000).']);
        }
        $purpose = $this->text($input['purpose'] ?? '', 200, 'Fahrtzweck');
        if ($purpose === '') {
            throw new DocumentValidationException(['Bitte den Fahrtzweck angeben.']);
        }
        $order = $this->optionalOrder($input['orderId'] ?? '', $actor, $existing['order_id'] ?? null);
        $values = [
            'work_date' => $date, 'kilometers' => $km, 'route' => $this->text($input['route'] ?? '', 200, 'Strecke / Reiseziel'), 'purpose' => $purpose,
            'order_id' => $order, 'cost_center_id' => $this->costCenter($input['costCenterId'] ?? '', $order, $existing['cost_center_id'] ?? null),
            'notes' => $this->text($input['notes'] ?? '', 500, 'Bemerkung'), 'updated_by_user_id' => (string) $actor['id'], 'updated_at' => Clock::now(),
        ];
        if ($existing === null) {
            $this->times->insertTrip($values + ['id' => Uuid::v4(), 'employee_id' => $employee['id'], 'created_by_user_id' => (string) $actor['id'], 'created_at' => $values['updated_at']]);
        } elseif (!$this->times->updateTrip((string) $existing['id'], $this->version($input), $values)) {
            throw new DocumentValidationException(['Die Fahrt wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->day((string) $employee['id'], $date, $actor);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function deleteTrip(string $id, array $input, array $actor): array
    {
        $trip = $this->times->trip($id) ?? throw new DocumentValidationException(['Die Fahrt wurde nicht gefunden.']);
        $employee = $this->employee((string) $trip['employee_id'], $actor);
        $this->assertEditable($employee, $actor);
        $this->assertDaysOpen((string) $employee['id'], [(string) $trip['work_date']]);
        if (!$this->times->deleteTrip($id, $this->version($input))) {
            throw new DocumentValidationException(['Die Fahrt wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->day((string) $employee['id'], (string) $trip['work_date'], $actor);
    }

    /**
     * Abwesenheit anlegen (ohne id) oder bearbeiten: Art, ganztägig oder konkrete Dauer,
     * Bemerkung. Kein Auftrag, keine Kostenstelle, keine Diagnose; Feiertage trägt der
     * Mitarbeiter selbst ein (keine automatische Feiertagsverwaltung).
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function saveAbsence(array $input, array $actor): array
    {
        $existing = isset($input['id']) && $input['id'] !== '' ? ($this->times->absence((string) $input['id']) ?? throw new DocumentValidationException(['Die Abwesenheit wurde nicht gefunden.'])) : null;
        $employee = $this->employee($existing !== null ? (string) $existing['employee_id'] : (isset($input['employeeId']) ? (string) $input['employeeId'] : null), $actor);
        $this->assertEditable($employee, $actor);
        $date = $this->date((string) ($input['date'] ?? $existing['work_date'] ?? ''));
        $this->assertDaysOpen((string) $employee['id'], array_values(array_unique(array_filter([$date, $existing['work_date'] ?? null]))));
        $type = (string) ($input['absenceType'] ?? '');
        if (!isset(self::ABSENCE_TYPES[$type])) {
            throw new DocumentValidationException(['Bitte die Art der Abwesenheit wählen.']);
        }
        $fullDay = filter_var($input['fullDay'] ?? false, FILTER_VALIDATE_BOOL);
        $minutes = null;
        if (!$fullDay) {
            $minutes = $this->absence($input['hours'] ?? '');
            if ($minutes === null || $minutes < 1) {
                throw new DocumentValidationException(['Bitte „ganztägig“ wählen oder die Dauer der Abwesenheit angeben (z. B. 4 oder 4:30 Stunden).']);
            }
        }
        $values = ['work_date' => $date, 'absence_type' => $type, 'full_day' => $fullDay ? 1 : 0, 'minutes' => $minutes,
            'notes' => $this->text($input['notes'] ?? '', 500, 'Bemerkung'), 'updated_by_user_id' => (string) $actor['id'], 'updated_at' => Clock::now()];
        if ($existing === null) {
            $this->times->insertAbsence($values + ['id' => Uuid::v4(), 'employee_id' => $employee['id'], 'created_by_user_id' => (string) $actor['id'], 'created_at' => $values['updated_at']]);
        } elseif (!$this->times->updateAbsence((string) $existing['id'], $this->version($input), $values)) {
            throw new DocumentValidationException(['Die Abwesenheit wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->day((string) $employee['id'], $date, $actor);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function deleteAbsence(string $id, array $input, array $actor): array
    {
        $absence = $this->times->absence($id) ?? throw new DocumentValidationException(['Die Abwesenheit wurde nicht gefunden.']);
        $employee = $this->employee((string) $absence['employee_id'], $actor);
        $this->assertEditable($employee, $actor);
        $this->assertDaysOpen((string) $employee['id'], [(string) $absence['work_date']]);
        if (!$this->times->deleteAbsence($id, $this->version($input))) {
            throw new DocumentValidationException(['Die Abwesenheit wurde zwischenzeitlich geändert. Bitte neu laden.']);
        }
        return $this->day((string) $employee['id'], (string) $absence['work_date'], $actor);
    }

    /** @param array<string,mixed> $absence @return array<string,mixed> */
    public static function mapAbsence(array $absence): array
    {
        return [
            'id' => $absence['id'], 'version' => (int) $absence['version'], 'date' => $absence['work_date'], 'type' => $absence['absence_type'],
            'label' => self::ABSENCE_TYPES[$absence['absence_type']] ?? (string) $absence['absence_type'], 'fullDay' => (bool) $absence['full_day'],
            'minutes' => $absence['minutes'] !== null ? (int) $absence['minutes'] : null, 'notes' => (string) $absence['notes'],
            'employeeName' => $absence['employee_name'] ?? null,
        ];
    }

    /**
     * Summen je Abwesenheitsart: ganztägige als Tage, stundenweise als Minuten (nie zur
     * Arbeitszeit addiert).
     *
     * @param list<array<string,mixed>> $absences Ergebnisse von mapAbsence()
     * @return array<string,array{label:string,days:int,minutes:int}>
     */
    public static function absenceTotals(array $absences): array
    {
        $totals = [];
        foreach (self::ABSENCE_TYPES as $type => $label) {
            $totals[$type] = ['label' => $label, 'days' => 0, 'minutes' => 0];
        }
        foreach ($absences as $absence) {
            if ($absence['fullDay']) {
                $totals[$absence['type']]['days']++;
            } else {
                $totals[$absence['type']]['minutes'] += (int) $absence['minutes'];
            }
        }
        return $totals;
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
        $this->assertDaysOpen((string) $employee['id'], [$date]);
        $amount = $this->decimal($input['amount'] ?? '', Decimal::MONEY_SCALE, 'Betrag');
        if ($amount === null || !BigDecimal::of($amount)->isPositive()) {
            throw new DocumentValidationException(['Bitte einen Betrag größer 0 angeben.']);
        }
        $purpose = $this->text($input['purpose'] ?? '', 200, 'Zweck');
        if ($purpose === '') {
            throw new DocumentValidationException(['Bitte den Zweck der Auslage angeben.']);
        }
        $order = $this->optionalOrder($input['orderId'] ?? '', $actor, null);
        $costCenter = $this->costCenter($input['costCenterId'] ?? '', $order, null);
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
                'id' => $id, 'employee_id' => $employee['id'], 'work_date' => $date, 'amount' => $amount, 'purpose' => $purpose, 'order_id' => $order, 'cost_center_id' => $costCenter,
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
        $this->assertDaysOpen((string) $employee['id'], [(string) $expense['work_date']]);
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
     * Prüft und berechnet einen manuellen Zeitblock. Mit Uhrzeiten: Ende ≤ Beginn bedeutet
     * „endet am Folgetag“, Nettozeit = Ende − Beginn − Pause. Ohne Uhrzeiten (nur wenn die
     * Betriebseinstellung es erlaubt): Dauer in Minuten (durationMinutes).
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function entryValues(array $input, string $employeeId, ?array $existing, array $actor): array
    {
        $date = $this->date((string) ($input['date'] ?? $existing['work_date'] ?? ''));
        $timing = $this->timing($input, $employeeId, $date, $existing['id'] ?? null, null);
        // Keine Tätigkeitsart mehr: neu immer Arbeitszeit, Bestandsblöcke behalten ihren Wert.
        $activity = (string) ($existing['activity'] ?? 'work');
        $order = $this->optionalOrder($input['orderId'] ?? '', $actor, $existing['order_id'] ?? null);
        return $timing + [
            'work_date' => $date, 'activity' => $activity,
            'travel_minutes' => $this->travel($input, $timing['net_minutes']),
            'order_id' => $order,
            // Kostenziel: Auftrag ODER allgemeine Kostenstelle (nur manuelle Zeitblöcke).
            'cost_center_id' => $this->costCenter($input['costCenterId'] ?? '', $order, $existing['cost_center_id'] ?? null),
            'assignment_kind' => $this->assignment($input),
            'notes' => $this->text($input['notes'] ?? '', 500, 'Bemerkung'),
        ];
    }

    /**
     * Zeitliche Einordnung einer Berichtsbuchung. Dauer, Datum, Mitarbeiter und Auftrag kommen aus
     * dem Bericht und bleiben unverändert; Uhrzeiten müssen exakt zur Berichtsdauer passen.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function reportTimeValues(array $input, array $entry): array
    {
        $net = (int) $entry['net_minutes'];
        $timing = $this->timing($input, (string) $entry['employee_id'], (string) $entry['work_date'], (string) $entry['id'], $net);
        return $timing + [
            'travel_minutes' => $this->travel($input, $net),
            'assignment_kind' => $this->assignment($input),
            'notes' => $this->text($input['notes'] ?? '', 500, 'Bemerkung'),
        ];
    }

    /**
     * Beginn/Ende/Pause oder – ohne Uhrzeiten – nur die Dauer. Bei vorgegebener Dauer ($fixedNet)
     * muss die Nettozeit exakt passen; ohne Uhrzeiten bleibt die Dauer.
     *
     * @param array<string,mixed> $input
     * @return array{start_time:?string,end_time:?string,ends_next_day:int,break_minutes:int,net_minutes:int}
     */
    private function timing(array $input, string $employeeId, string $date, ?string $ignoreId, ?int $fixedNet): array
    {
        $rawStart = trim((string) ($input['start'] ?? ''));
        $rawEnd = trim((string) ($input['end'] ?? ''));
        if ($rawStart === '' && $rawEnd === '') {
            if ($fixedNet !== null) {
                return ['start_time' => null, 'end_time' => null, 'ends_next_day' => 0, 'break_minutes' => 0, 'net_minutes' => $fixedNet];
            }
            if (!$this->times->timesOptional()) {
                throw new DocumentValidationException(['Bitte Beginn und Ende angeben (Stundenerfassung ohne Uhrzeiten ist in den Betriebseinstellungen deaktiviert).']);
            }
            $duration = filter_var(trim((string) ($input['durationMinutes'] ?? '')), FILTER_VALIDATE_INT);
            if ($duration === false || $duration < 1 || $duration > 1440) {
                throw new DocumentValidationException(['Bitte Beginn und Ende oder eine Dauer (1 bis 1.440 Minuten) angeben.']);
            }
            return ['start_time' => null, 'end_time' => null, 'ends_next_day' => 0, 'break_minutes' => 0, 'net_minutes' => $duration];
        }
        $start = $this->time($rawStart, 'Beginn');
        $end = $this->time($rawEnd, 'Ende');
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
        $net = $span - $break;
        if ($fixedNet !== null && $net !== $fixedNet) {
            throw new DocumentValidationException([sprintf('Ende − Beginn − Pause ergibt %s, der Bericht dokumentiert %s. Die Berichtsdauer kann hier nicht geändert werden – '
                . 'zusätzliche Zeit bitte als eigenen Zeitblock erfassen oder den Bericht korrigieren.', self::hours($net), self::hours($fixedNet))]);
        }
        $this->assertNoOverlap($employeeId, $date, $startMinutes, $span, $ignoreId);
        return ['start_time' => $start, 'end_time' => $end, 'ends_next_day' => $nextDay ? 1 : 0, 'break_minutes' => $break, 'net_minutes' => $net];
    }

    /** @param array<string,mixed> $input */
    private function travel(array $input, int $net): int
    {
        $rawTravel = trim((string) ($input['travelMinutes'] ?? ''));
        $travel = $rawTravel === '' ? 0 : filter_var($rawTravel, FILTER_VALIDATE_INT);
        if ($travel === false || $travel < 0) {
            throw new DocumentValidationException(['Die Reisezeit muss eine ganze Zahl von Minuten (0 oder mehr) sein.']);
        }
        if ($travel > $net) {
            throw new DocumentValidationException(['Die Reisezeit ist Teil der Arbeitszeit und darf die Nettozeit nicht überschreiten.']);
        }
        return $travel;
    }

    /** @param array<string,mixed> $input */
    private function assignment(array $input): ?string
    {
        $assignment = trim((string) ($input['assignmentKind'] ?? ''));
        if ($assignment !== '' && !isset(self::ASSIGNMENTS[$assignment])) {
            throw new DocumentValidationException(['Ungültige Einsatzart.']);
        }
        return $assignment !== '' ? $assignment : null;
    }

    private static function hours(int $minutes): string
    {
        return sprintf('%d:%02d h', intdiv($minutes, 60), $minutes % 60);
    }

    /** Überschneidungen mit eigenen Buchungen mit Uhrzeiten (alle Herkünfte) desselben, des vorigen und des nächsten Tages. */
    private function assertNoOverlap(string $employeeId, string $date, int $start, int $span, ?string $ignoreId): void
    {
        $day = new DateTimeImmutable($date);
        $neighbours = $this->times->entries($employeeId, $day->modify('-1 day')->format('Y-m-d'), $day->modify('+1 day')->format('Y-m-d'));
        foreach ($neighbours as $other) {
            if ($other['id'] === $ignoreId || $other['start_time'] === null) {
                continue;
            }
            $offset = (int) (new DateTimeImmutable((string) $other['work_date']))->diff($day)->format('%r%a') * -1440;
            $otherStart = $offset + self::minutes((string) $other['start_time']);
            $otherEnd = $otherStart + (int) $other['net_minutes'] + (int) $other['break_minutes'];
            if ($start < $otherEnd && $otherStart < $start + $span) {
                throw new DocumentValidationException([sprintf('Der Zeitblock überschneidet sich mit %s–%s am %s (%s).', $other['start_time'], $other['end_time'],
                    (new DateTimeImmutable((string) $other['work_date']))->format('d.m.Y'), self::SOURCE_KINDS[$other['source_kind']] ?? '')]);
            }
        }
    }

    /**
     * Einfache Hinweise (keine arbeitsrechtliche Prüfung): langer Tag, kurze Ruhezeit. Ohne
     * Uhrzeiten wird nur die Tagessumme geprüft; Buchungen werden nie verändert.
     *
     * @param list<array<string,mixed>> $entries
     * @return list<string>
     */
    private function warnings(string $employeeId, string $date, array $entries): array
    {
        $warnings = [];
        $total = array_sum(array_map('intval', array_column($entries, 'net_minutes')));
        if ($total > self::LONG_DAY_MINUTES) {
            // Plausibilitätshinweis (Grenzwert unverändert); verändert keine Buchung.
            $warnings[] = sprintf('%s erfasst. Bitte prüfen, ob alle Buchungen korrekt sind.', self::hours($total));
        }
        $timed = array_filter($entries, static fn (array $entry): bool => $entry['start_time'] !== null);
        if ($timed !== []) {
            $day = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
            $lastEnd = null;
            foreach ($this->times->entries($employeeId, $day, $day) as $entry) {
                if ($entry['start_time'] === null) {
                    continue;
                }
                $end = self::minutes((string) $entry['start_time']) + (int) $entry['net_minutes'] + (int) $entry['break_minutes'] - 1440;
                $lastEnd = $lastEnd === null ? $end : max($lastEnd, $end);
            }
            $firstStart = min(array_map(static fn (array $entry): int => self::minutes((string) $entry['start_time']), $timed));
            if ($lastEnd !== null && $firstStart - $lastEnd < self::MIN_REST_MINUTES) {
                $warnings[] = 'Weniger als 11 Stunden Ruhezeit seit dem Vortag – bitte prüfen.';
            }
        }
        return $warnings;
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $employee
     * @return array<string,mixed>
     */
    private function mapEntry(array $entry, array $employee, bool $costs, bool $timesOptional, bool $canEdit): array
    {
        $manual = $entry['source_kind'] === 'manual';
        return [
            'id' => $entry['id'], 'version' => (int) $entry['version'], 'date' => $entry['work_date'], 'start' => $entry['start_time'], 'end' => $entry['end_time'],
            'hasTimes' => $entry['start_time'] !== null,
            // Bei deaktivierter Betriebseinstellung: Stunden zählen, Uhrzeiten fehlen noch.
            'timesMissing' => !$timesOptional && $entry['start_time'] === null,
            'endsNextDay' => (bool) $entry['ends_next_day'], 'breakMinutes' => (int) $entry['break_minutes'], 'netMinutes' => (int) $entry['net_minutes'],
            'travelMinutes' => (int) $entry['travel_minutes'], 'workMinutes' => (int) $entry['net_minutes'] - (int) $entry['travel_minutes'],
            'assignmentKind' => $entry['assignment_kind'], 'assignmentLabel' => $entry['assignment_kind'] !== null ? (self::ASSIGNMENTS[$entry['assignment_kind']] ?? '') : null,
            'activity' => $entry['activity'], 'activityLabel' => self::ACTIVITIES[$entry['activity']] ?? $entry['activity'],
            'orderId' => $entry['order_id'], 'orderNumber' => $entry['order_number'], 'orderTitle' => $entry['order_title'], 'notes' => $entry['notes'],
            'costCenterId' => $entry['cost_center_id'], 'costCenterNumber' => $entry['cost_center_number'], 'costCenterName' => $entry['cost_center_name'],
            // Dauer ist bei Berichtsbuchungen vorgegeben; Löschen nur bei manuellen Buchungen.
            'durationFixed' => !$manual, 'canEditTimes' => $canEdit, 'canDelete' => $canEdit && $manual,
            // Nachvollziehbarkeit von Korrekturen durch Büro/Admin.
            'changedByOther' => $employee['user_id'] === null || $entry['updated_by_user_id'] !== $employee['user_id'],
            'updatedByName' => $entry['updated_by_name'], 'updatedAt' => $entry['updated_at'],
            'hourlyCostRate' => $costs ? $entry['hourly_cost_rate_snapshot'] : null,
        ] + self::source($entry);
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    private function mapDetails(array $details): array
    {
        return [
            'travelCategory' => $details['travel_category'], 'abroad' => (bool) $details['abroad'], 'absenceMinutes' => $details['absence_minutes'] !== null ? (int) $details['absence_minutes'] : null,
            'overnight' => (bool) $details['overnight'], 'mealBreakfast' => (bool) $details['meal_breakfast'], 'mealLunch' => (bool) $details['meal_lunch'], 'mealDinner' => (bool) $details['meal_dinner'],
            'location' => $details['location'], 'notes' => $details['notes'],
        ];
    }

    /** @param array<string,mixed> $trip @return array<string,mixed> */
    private function mapTrip(array $trip): array
    {
        return [
            'id' => $trip['id'], 'version' => (int) $trip['version'], 'kilometers' => $trip['kilometers'], 'route' => $trip['route'], 'purpose' => $trip['purpose'], 'notes' => $trip['notes'],
            'orderId' => $trip['order_id'], 'orderNumber' => $trip['order_number'], 'costCenterId' => $trip['cost_center_id'], 'costCenterNumber' => $trip['cost_center_number'],
        ];
    }

    /** @param array<string,mixed> $expense @return array<string,mixed> */
    private function mapExpense(array $expense): array
    {
        return [
            'id' => $expense['id'], 'amount' => $expense['amount'], 'purpose' => $expense['purpose'], 'orderId' => $expense['order_id'], 'orderNumber' => $expense['order_number'],
            'costCenterId' => $expense['cost_center_id'], 'costCenterNumber' => $expense['cost_center_number'],
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

    /**
     * Ziel-Mitarbeiter für andere Fachdienste (Freigaben): eigener Mitarbeiter oder – mit
     * timesheet.manage – ein beliebiger.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function resolveEmployee(?string $employeeId, array $actor): array
    {
        return $this->employee($employeeId, $actor);
    }

    /** Aktuelle Betriebseinstellungen (mit Standardwerten). @return array<string,bool|string> */
    public function settings(): array
    {
        return OperationalSettingsService::resolve($this->times->rawSettings());
    }

    /**
     * Reiseangaben eines Tages für Anzeige, Prüfung und Nachweis: Auswärtstätigkeit mit
     * rechnerischer Verpflegungspauschale, Fahrten mit Kilometern und rechnerischem Betrag.
     * null, wenn weder Tagesangaben noch Fahrten vorhanden sind.
     *
     * @param array<string,mixed>|null $details
     * @param list<array<string,mixed>> $trips
     * @param array<string,bool|string> $settings
     * @return array<string,mixed>|null
     */
    public function travelDay(?array $details, array $trips, array $settings): ?array
    {
        if ($details === null && $trips === []) {
            return null;
        }
        $labels = TravelAllowance::labels($settings);
        $category = (string) ($details['travel_category'] ?? 'none');
        $meals = ['breakfast' => (bool) ($details['meal_breakfast'] ?? false), 'lunch' => (bool) ($details['meal_lunch'] ?? false), 'dinner' => (bool) ($details['meal_dinner'] ?? false)];
        $allowance = TravelAllowance::calculate($category, (bool) ($details['abroad'] ?? false), $meals, $settings);
        $km = $trips === [] ? null : $this->decimalSum(array_column($trips, 'kilometers'), 1);
        $mealNames = array_keys(array_filter(['Frühstück' => $meals['breakfast'], 'Mittagessen' => $meals['lunch'], 'Abendessen' => $meals['dinner']]));
        $absence = isset($details['absence_minutes']) ? (int) $details['absence_minutes'] : null;
        $tripTexts = array_map(fn (array $trip): string => str_replace('.', ',', (string) $trip['kilometers']) . ' km ' . trim(implode(' · ', array_filter([(string) $trip['route'], (string) $trip['purpose'],
            (string) ($trip['order_number'] ?? $trip['cost_center_number'] ?? '')]))), $trips);
        return [
            'category' => $category, 'categoryLabel' => $labels[$category] ?? $category, 'abroad' => (bool) ($details['abroad'] ?? false), 'absenceMinutes' => $absence,
            'overnight' => (bool) ($details['overnight'] ?? false), 'meals' => $meals, 'location' => (string) ($details['location'] ?? ''), 'notes' => (string) ($details['notes'] ?? ''),
            'allowance' => $category === 'none' ? null : $allowance['amount'], 'allowanceBase' => $allowance['base'], 'allowanceReduction' => $allowance['reduction'], 'allowanceNote' => $allowance['note'],
            'trips' => array_map(static fn (array $trip): array => ['kilometers' => $trip['kilometers'], 'route' => $trip['route'], 'purpose' => $trip['purpose'],
                'target' => $trip['order_number'] ?? $trip['cost_center_number'] ?? null, 'notes' => $trip['notes']], $trips),
            'privateKm' => $km, 'kmAmount' => $km !== null ? TravelAllowance::kilometers($km, $settings) : null,
            'summary' => implode(' · ', array_filter([
                $category !== 'none' ? $labels[$category] ?? $category : '', ($details['abroad'] ?? false) ? 'Ausland' : '', (string) ($details['location'] ?? ''),
                $absence !== null ? 'Abwesenheit ' . self::hours($absence) : '', ($details['overnight'] ?? false) ? 'Übernachtung' : '',
                $mealNames !== [] ? 'gestellt: ' . implode(', ', $mealNames) : '', $tripTexts !== [] ? 'Fahrten: ' . implode('; ', $tripTexts) : '', (string) ($details['notes'] ?? ''),
            ])),
        ];
    }

    /**
     * Hinweise zu unvollständigen oder unplausiblen Reiseangaben (verhindern nichts).
     *
     * @param array<string,mixed>|null $travel Ergebnis von travelDay()
     * @param array<string,bool|string> $settings
     * @return list<string>
     */
    public function travelWarnings(?array $travel, array $settings): array
    {
        if ($travel === null) {
            return [];
        }
        $warnings = [];
        $threshold = (int) BigDecimal::of((string) $settings['travel.single_day_threshold_hours'])->multipliedBy(60)->toScale(0, \Brick\Math\RoundingMode::HalfUp)->toInt();
        $category = $travel['category'];
        if ($category !== 'none' && trim($travel['location']) === '') {
            $warnings[] = 'Auswärtstätigkeit ohne Einsatzort/Reiseziel.';
        }
        if ($category === 'single_long' && $travel['absenceMinutes'] !== null && $travel['absenceMinutes'] <= $threshold) {
            $warnings[] = sprintf('Abwesenheit %s – für eine Pauschale ist eine Abwesenheit von mehr als %s erforderlich.', self::hours($travel['absenceMinutes']), self::hours($threshold));
        }
        if ($category === 'single_short' && $travel['absenceMinutes'] !== null && $travel['absenceMinutes'] > $threshold) {
            $warnings[] = sprintf('Abwesenheit %s – Kategorie „über %s“ prüfen.', self::hours($travel['absenceMinutes']), self::hours($threshold));
        }
        if ($category === 'full' && $travel['absenceMinutes'] !== null && $travel['absenceMinutes'] < 1440) {
            $warnings[] = 'Voller Tag erfordert 24 Stunden Abwesenheit.';
        }
        if ($category === 'arrival' && !$travel['overnight']) {
            $warnings[] = 'Anreisetag ohne auswärtige Übernachtung.';
        }
        if ($category !== 'none' && $travel['abroad']) {
            $warnings[] = 'Auslandseinsatz: Verpflegungspauschale wird nicht automatisch berechnet – Prüfung durch das Büro.';
        }
        foreach ($travel['trips'] as $trip) {
            if (trim((string) $trip['route']) === '') {
                $warnings[] = 'Privat-Pkw-Fahrt ohne Strecke/Reiseziel.';
                break;
            }
        }
        return $warnings;
    }

    /**
     * Summen der Reiseangaben: Kilometer, rechnerischer Kilometerbetrag und Verpflegung.
     *
     * @param list<array<string,mixed>|null> $days Ergebnisse von travelDay()
     * @param array<string,bool|string> $settings
     * @return array{privateKm:string,kmAmount:string,mealAllowance:?string}
     */
    public function travelTotals(array $days, array $settings): array
    {
        $km = $this->decimalSum(array_map(static fn (?array $day): ?string => $day['privateKm'] ?? null, $days), 1);
        return [
            'privateKm' => $km,
            'kmAmount' => TravelAllowance::kilometers($km, $settings),
            'mealAllowance' => (bool) $settings['travel.meal_allowances_enabled'] ? $this->sum(array_map(static fn (?array $day): ?string => $day['allowance'] ?? null, $days)) : null,
        ];
    }

    /** Optionale Abwesenheitsdauer „9:30“ bzw. „9,5“ (Stunden) → Minuten. */
    private function absence(mixed $value): ?int
    {
        $text = str_replace(',', '.', trim((string) (is_scalar($value) ? $value : '')));
        if ($text === '') {
            return null;
        }
        $minutes = preg_match('/^(\d{1,2}):([0-5]\d)$/', $text, $match) === 1 ? (int) $match[1] * 60 + (int) $match[2]
            : (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $text) === 1 ? (int) round((float) $text * 60) : -1);
        if ($minutes < 0 || $minutes > 1440) {
            throw new DocumentValidationException(['Abwesenheitsdauer: bitte z. B. 9:30 oder 9,5 Stunden angeben (höchstens 24 Stunden).']);
        }
        return $minutes;
    }

    private static function locked(string $status): bool
    {
        return in_array($status, ['submitted', 'approved'], true);
    }

    /** Kalendertage einer Buchung (Buchungstag und ggf. Folgetag). @param array<string,mixed> $values @return list<string> */
    private static function touchedDates(array $values): array
    {
        $date = (string) $values['work_date'];
        return (bool) ($values['ends_next_day'] ?? false) ? [$date, (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')] : [$date];
    }

    /**
     * Verständliche Vorprüfung der Tagessperre. Maßgeblich ist der Datenbank-Trigger (Migration
     * 0042), der auch parallele Änderungen und alle anderen Module abfängt.
     *
     * @param list<string> $dates
     */
    private function assertDaysOpen(string $employeeId, array $dates): void
    {
        sort($dates);
        $locks = $this->times->dayLocks($employeeId, $dates[0], $dates[count($dates) - 1]);
        foreach ($dates as $date) {
            if (self::locked($locks[$date] ?? 'open')) {
                throw new DocumentValidationException([sprintf('Der %s ist %s und kann nicht geändert werden. Bitte im Büro zur Korrektur öffnen lassen.',
                    (new DateTimeImmutable($date))->format('d.m.Y'), ($locks[$date] ?? '') === 'approved' ? 'festgeschrieben' : 'eingereicht')]);
            }
        }
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

    /** Kostenstelle prüfen (gemeinsame Regel in CostTargets). */
    private function costCenter(mixed $value, ?string $orderId, ?string $current): ?string
    {
        if (trim((string) $value) === '') {
            return null;
        }
        $targets = $this->targets ?? throw new DocumentValidationException(['Kostenstellen sind nicht verfügbar.']);
        return $targets->costCenter($value, $orderId, $current, static fn (string $message): DocumentValidationException => new DocumentValidationException([$message]));
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
