<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\ReportTimeBookings;
use App\Infrastructure\Persistence\TimeApprovalRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Security\RolePermissions;
use App\Support\Clock;
use App\Support\Uuid;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Throwable;

/**
 * Einreichung, Büroprüfung, Festschreibung und Wiederöffnung der Zeiterfassung (Phase A).
 *
 * - Verbindlich ist der Status je Mitarbeiter und Kalendertag (time_day_locks); Woche (ISO-KW)
 *   und Monat sind Sammelaktionen. Eine Einreichung sperrt nur die noch offenen bzw. zur
 *   Korrektur geöffneten Tage ihres Zeitraums – auch arbeitsfreie Tage.
 * - Die Sperre selbst greift zentral per Datenbank-Trigger in allen Modulen (Migration 0042).
 * - Prüfungen: Sperrfehler verhindern Einreichung und Festschreibung, Warnungen nicht. Sie
 *   werden bei der Festschreibung serverseitig erneut ausgeführt. Calight prüft nur, was die
 *   Daten zuverlässig hergeben, und behauptet keine vollständige arbeitsrechtliche Prüfung.
 * - Festschreibung erzeugt einen unveränderlichen PDF-Stundennachweis mit festem Datenstand
 *   (time_statements). Wiederöffnen kennzeichnet betroffene Wochen- und Monatsnachweise als
 *   ersetzt; nach erneuter Festschreibung entsteht eine neue Revision.
 *
 * Rechte: Mitarbeiter reichen eigene Zeiten ein (timesheet.own), das Büro prüft, schreibt fest,
 * gibt zurück und öffnet wieder (timesheet.manage). Nachweise sehen nur der Mitarbeiter selbst
 * und das Büro; Kostensätze sind nie enthalten.
 */
final readonly class TimeApprovalService
{
    public const DAY_STATUS = ['open' => 'Offen', 'submitted' => 'Eingereicht', 'approved' => 'Festgeschrieben', 'reopened' => 'Zur Korrektur geöffnet'];
    public const PERIOD_TYPES = ['week' => 'Woche', 'month' => 'Monat'];
    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public function __construct(
        private TimeApprovalRepository $approvals,
        private TimeTrackingRepository $times,
        private TimeTrackingService $tracking,
        private OperationalSettingsService $settings,
        private DocumentSettingsRepository $documentSettings,
        private AttachmentStore $store,
    ) {
    }

    // -------------------------------------------------------------------------
    // Zeitraum: Status, Prüfung, Nachweise
    // -------------------------------------------------------------------------

    /**
     * Zusammenfassung eines Zeitraums (Woche oder Monat) mit Tagesstatus, Summen, Sperrfehlern,
     * Warnungen, Nachweisen und Protokoll – Grundlage für die Einreichung.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function period(?string $employeeId, string $type, string $date, array $actor): array
    {
        $employee = $this->tracking->resolveEmployee($employeeId, $actor);
        $period = self::periodFor($type, $date);
        $settings = $this->settings->values();
        $check = $this->check($employee, $period['from'], $period['to'], $settings);
        $locks = $this->approvals->locks((string) $employee['id'], $period['from'], $period['to']);
        $openDays = array_values(array_filter(array_keys($check['days']), static fn (string $day): bool => in_array($locks[$day]['status'] ?? 'open', ['open', 'reopened'], true)));
        $periodErrors = $this->periodErrors($period, $settings, $openDays);
        $manage = $this->can($actor, 'timesheet.manage');
        $own = $employee['user_id'] !== null && $employee['user_id'] === $actor['id'];
        $statusCounts = array_count_values(array_map(static fn (array $day): string => $day['status'], $check['days']));
        return [
            'employee' => ['id' => $employee['id'], 'name' => $employee['display_name']],
            'period' => $period,
            'rhythm' => $settings['time_tracking.submission_rhythm'],
            'allowedTypes' => self::allowedTypes((string) $settings['time_tracking.submission_rhythm']),
            'status' => self::periodStatus($statusCounts),
            'statusCounts' => $statusCounts,
            'days' => array_values($check['days']),
            'totals' => $check['totals'],
            'errors' => [...$periodErrors, ...$check['errors']],
            'warnings' => $check['warnings'],
            'canSubmit' => ($manage || ($own && $this->can($actor, 'timesheet.own') && (bool) $employee['active'])) && $periodErrors === [] && $check['errors'] === [],
            'canReopen' => $manage && ($statusCounts['approved'] ?? 0) > 0,
            'submissions' => array_map($this->mapSubmission(...), $this->approvals->submissions(null, (string) $employee['id'], $period['from'], $period['to'])),
            'statements' => array_map($this->mapStatement(...), $this->approvals->statements((string) $employee['id'], $period['from'], $period['to'])),
            'events' => array_map(static fn (array $event): array => [
                'action' => $event['action'], 'from' => $event['from_date'], 'to' => $event['to_date'], 'reason' => $event['reason'], 'userName' => $event['user_name'], 'at' => $event['created_at'],
            ], $this->approvals->events((string) $employee['id'], $period['from'], $period['to'])),
            'canManage' => $manage,
        ];
    }

    /**
     * Zeitraum einreichen: sperrt alle noch offenen bzw. zur Korrektur geöffneten Tage. Bereits
     * eingereichte oder festgeschriebene Tage bleiben unverändert. Wiederholte Anfragen finden
     * keine offenen Tage mehr und werden abgelehnt.
     *
     * @param array<string,mixed> $input type, date, employeeId
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function submit(array $input, array $actor): array
    {
        $employee = $this->tracking->resolveEmployee(isset($input['employeeId']) && $input['employeeId'] !== '' ? (string) $input['employeeId'] : null, $actor);
        $own = $employee['user_id'] !== null && $employee['user_id'] === $actor['id'];
        if (!$this->can($actor, 'timesheet.manage') && !($own && $this->can($actor, 'timesheet.own') && (bool) $employee['active'])) {
            throw new AccessDeniedException('timesheet.own');
        }
        $period = self::periodFor((string) ($input['type'] ?? ''), (string) ($input['date'] ?? ''));
        $now = Clock::now();
        $this->approvals->begin();
        try {
            $settings = $this->settings->values();
            $locks = $this->approvals->locks((string) $employee['id'], $period['from'], $period['to']);
            $openDays = array_values(array_filter(self::dates($period['from'], $period['to']), static fn (string $day): bool => in_array($locks[$day]['status'] ?? 'open', ['open', 'reopened'], true)));
            $check = $this->check($employee, $period['from'], $period['to'], $settings);
            $errors = [...$this->periodErrors($period, $settings, $openDays), ...$check['errors']];
            if ($errors !== []) {
                throw new DocumentValidationException(array_map(static fn (array $error): string => $error['message'], $errors));
            }
            $id = Uuid::v4();
            $this->approvals->insertSubmission([
                'id' => $id, 'employee_id' => $employee['id'], 'period_type' => $period['type'], 'period_key' => $period['key'], 'from_date' => $period['from'], 'to_date' => $period['to'],
                'status' => 'submitted', 'summary_json' => json_encode(['totals' => $check['totals'], 'warnings' => $check['warnings'], 'days' => $openDays], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'submitted_by_user_id' => (string) $actor['id'], 'submitted_at' => $now,
            ]);
            $this->approvals->lockDays((string) $employee['id'], $openDays, $id, (string) $actor['id'], $now);
            $this->event((string) $employee['id'], $period['from'], $period['to'], 'submit', $id, '', $actor, $now);
            $this->approvals->commit();
        } catch (Throwable $exception) {
            $this->approvals->rollBack();
            throw $exception;
        }
        return $this->period((string) $employee['id'], $period['type'], $period['from'], $actor);
    }

    // -------------------------------------------------------------------------
    // Büro: Freigaben
    // -------------------------------------------------------------------------

    /** Eingereichte Zeiträume aller Mitarbeiter mit aktueller Prüfung. @param array<string,mixed> $actor @return array<string,mixed> */
    public function pending(array $actor): array
    {
        $this->require($actor, 'timesheet.manage');
        $settings = $this->settings->values();
        $items = [];
        foreach ($this->approvals->submissions('submitted', null) as $submission) {
            $employee = $this->times->employee((string) $submission['employee_id']) ?? [];
            $check = $this->check($employee, (string) $submission['from_date'], (string) $submission['to_date'], $settings);
            $items[] = $this->mapSubmission($submission) + ['totals' => $check['totals'], 'errors' => $check['errors'], 'warnings' => $check['warnings']];
        }
        return ['items' => $items, 'recent' => array_map($this->mapSubmission(...), array_slice(array_reverse(array_values(array_filter(
            $this->approvals->submissions(null, null), static fn (array $row): bool => $row['status'] !== 'submitted'))), 0, 20))];
    }

    /**
     * Festschreiben: Prüfungen serverseitig erneut, Warnungen müssen gesammelt bestätigt werden
     * (Kommentar optional, bei erheblichen Auffälligkeiten empfohlen). Erzeugt atomar die
     * betroffenen Stundennachweise; scheitert die Archivierung, bleibt alles eingereicht.
     *
     * @param array<string,mixed> $input version, acknowledgeWarnings, comment
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function approve(string $id, array $input, array $actor): array
    {
        $this->require($actor, 'timesheet.manage');
        $comment = $this->text($input['comment'] ?? '', 1000, 'Kommentar');
        $stored = [];
        $now = Clock::now();
        $this->approvals->begin();
        try {
            $submission = $this->openSubmission($id);
            $employee = $this->times->employee((string) $submission['employee_id']) ?? throw new DocumentValidationException(['Der Mitarbeiter wurde nicht gefunden.']);
            $settings = $this->settings->values();
            $check = $this->check($employee, (string) $submission['from_date'], (string) $submission['to_date'], $settings);
            if ($check['errors'] !== []) {
                throw new DocumentValidationException(array_map(static fn (array $error): string => $error['message'], $check['errors']));
            }
            $acknowledged = filter_var($input['acknowledgeWarnings'] ?? false, FILTER_VALIDATE_BOOL);
            if ($check['warnings'] !== [] && !$acknowledged) {
                throw new DocumentValidationException(['Bitte die Warnungen prüfen und bestätigen.']);
            }
            if (!$this->approvals->decideSubmission($id, $this->version($input), 'approved', (string) $actor['id'], $now, $comment, $acknowledged)) {
                throw new DocumentValidationException(['Die Einreichung wurde zwischenzeitlich geändert. Bitte neu laden.']);
            }
            $this->approvals->setSubmissionDays($id, 'submitted', 'approved', (string) $actor['id'], $now);
            $this->event((string) $employee['id'], (string) $submission['from_date'], (string) $submission['to_date'], 'approve', $id, $comment, $actor, $now);
            $stored = $this->refreshStatements($employee, $submission, $settings, $actor, $now);
            $this->approvals->commit();
        } catch (Throwable $exception) {
            $this->approvals->rollBack();
            foreach ($stored as $path) {
                $this->discard($path);
            }
            throw $exception;
        }
        return $this->pending($actor);
    }

    /** Zur Korrektur zurückgeben (Begründung Pflicht): Tage werden wieder bearbeitbar. @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function returnSubmission(string $id, array $input, array $actor): array
    {
        $this->require($actor, 'timesheet.manage');
        $reason = $this->reason($input);
        $now = Clock::now();
        $this->approvals->begin();
        try {
            $submission = $this->openSubmission($id);
            if (!$this->approvals->decideSubmission($id, $this->version($input), 'returned', (string) $actor['id'], $now, $reason, false)) {
                throw new DocumentValidationException(['Die Einreichung wurde zwischenzeitlich geändert. Bitte neu laden.']);
            }
            $this->approvals->setSubmissionDays($id, 'submitted', 'reopened', (string) $actor['id'], $now);
            $this->event((string) $submission['employee_id'], (string) $submission['from_date'], (string) $submission['to_date'], 'return', $id, $reason, $actor, $now);
            $this->approvals->commit();
        } catch (Throwable $exception) {
            $this->approvals->rollBack();
            throw $exception;
        }
        return $this->pending($actor);
    }

    /**
     * Festgeschriebene Tage gezielt zur Korrektur öffnen (Begründung Pflicht). Bestehende
     * Nachweise mit diesen Tagen (Woche und Monat) gelten danach als nicht mehr aktuell; die
     * Dateien bleiben unverändert erhalten.
     *
     * @param array<string,mixed> $input employeeId, from, to, reason
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function reopen(array $input, array $actor): array
    {
        $this->require($actor, 'timesheet.manage');
        $employee = $this->times->employee((string) ($input['employeeId'] ?? '')) ?? throw new DocumentValidationException(['Der Mitarbeiter wurde nicht gefunden.']);
        $from = self::date((string) ($input['from'] ?? ''));
        $to = self::date((string) ($input['to'] ?? ''));
        if ($to < $from || count(self::dates($from, $to)) > 92) {
            throw new DocumentValidationException(['Ungültiger Zeitraum.']);
        }
        $reason = $this->reason($input);
        $now = Clock::now();
        $this->approvals->begin();
        try {
            $locks = $this->approvals->locks((string) $employee['id'], $from, $to);
            if (array_filter($locks, static fn (array $lock): bool => $lock['status'] === 'submitted') !== []) {
                throw new DocumentValidationException(['Im Zeitraum sind eingereichte, noch nicht festgeschriebene Tage. Bitte die Einreichung zur Korrektur zurückgeben.']);
            }
            $dates = $this->approvals->reopenDays((string) $employee['id'], $from, $to, (string) $actor['id'], $now);
            if ($dates === []) {
                throw new DocumentValidationException(['Im Zeitraum sind keine festgeschriebenen Tage.']);
            }
            $this->approvals->supersedeStatements((string) $employee['id'], $dates, (string) $actor['id'], $now);
            $this->event((string) $employee['id'], $dates[0], $dates[count($dates) - 1], 'reopen', null, $reason, $actor, $now);
            $this->approvals->commit();
        } catch (Throwable $exception) {
            $this->approvals->rollBack();
            throw $exception;
        }
        return $this->period((string) $employee['id'], 'month', $from, $actor);
    }

    /** Archivierter Nachweis (unverändert aus dem Archiv). @param array<string,mixed> $actor @return array{pdf:string,fileName:string} */
    public function statementPdf(string $id, array $actor): array
    {
        $statement = $this->approvals->statement($id) ?? throw new DocumentValidationException(['Der Stundennachweis wurde nicht gefunden.']);
        // Eigene Nachweise oder Büro (resolveEmployee prüft timesheet.manage für fremde Mitarbeiter).
        $this->tracking->resolveEmployee((string) $statement['employee_id'], $actor);
        try {
            $pdf = $this->store->read((string) $statement['storage_path']);
        } catch (Throwable) {
            throw new DocumentValidationException(['Die Datei des Stundennachweises ist nicht verfügbar.']);
        }
        if (!hash_equals((string) $statement['sha256'], hash('sha256', $pdf))) {
            throw new DocumentValidationException(['Die Datei des Stundennachweises ist beschädigt (Prüfsumme).']);
        }
        return ['pdf' => $pdf, 'fileName' => (string) $statement['file_name']];
    }

    // -------------------------------------------------------------------------
    // Nachweise
    // -------------------------------------------------------------------------

    /**
     * Erzeugt fehlende aktuelle Nachweise nach einer Festschreibung: für den eingereichten
     * Zeitraum, für jeden vollständig festgeschriebenen Monat mit Tagen der Einreichung (auch aus
     * Wochenfreigaben) und für jede Woche, deren früherer Nachweis ersetzt wurde.
     *
     * @param array<string,mixed> $employee
     * @param array<string,mixed> $submission
     * @param array<string,bool|string> $settings
     * @param array<string,mixed> $actor
     * @return list<string> gespeicherte Dateien (für Aufräumen bei Fehlern)
     */
    private function refreshStatements(array $employee, array $submission, array $settings, array $actor, string $now): array
    {
        $candidates = [(string) $submission['period_type'] . '|' . (string) $submission['period_key'] => self::periodFor((string) $submission['period_type'], (string) $submission['from_date'])];
        foreach (self::dates((string) $submission['from_date'], (string) $submission['to_date']) as $date) {
            $month = self::periodFor('month', $date);
            $candidates['month|' . $month['key']] ??= $month;
            $week = self::periodFor('week', $date);
            if ($this->approvals->hasStatement((string) $employee['id'], 'week', $week['key'])) {
                $candidates['week|' . $week['key']] ??= $week;
            }
        }
        $stored = [];
        foreach ($candidates as $period) {
            if ($this->approvals->currentStatement((string) $employee['id'], $period['type'], $period['key']) !== null) {
                continue;
            }
            $locks = $this->approvals->locks((string) $employee['id'], $period['from'], $period['to']);
            if (count(array_filter($locks, static fn (array $lock): bool => $lock['status'] === 'approved')) !== count(self::dates($period['from'], $period['to']))) {
                continue;
            }
            $revision = $this->approvals->nextRevision((string) $employee['id'], $period['type'], $period['key']);
            $snapshot = $this->snapshot($employee, $period, $revision, $locks, $settings, $actor, $now);
            $pdf = (new TimeStatementPdf($this->documentSettings->get()))->render($snapshot);
            $statementId = Uuid::v4();
            $path = $this->store->storeContents($pdf, 'time-statements/' . $employee['id'], $statementId, 'pdf');
            $stored[] = $path;
            $this->approvals->insertStatement([
                'id' => $statementId, 'employee_id' => $employee['id'], 'period_type' => $period['type'], 'period_key' => $period['key'],
                'from_date' => $period['from'], 'to_date' => $period['to'], 'revision' => $revision, 'status' => 'current', 'submission_id' => $submission['id'],
                'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'file_name' => self::fileName($employee, $period, $revision), 'storage_path' => $path, 'sha256' => hash('sha256', $pdf), 'size_bytes' => strlen($pdf),
                'created_by_user_id' => (string) $actor['id'], 'created_at' => $now,
            ]);
        }
        return $stored;
    }

    /**
     * Fester Datenstand eines Nachweises: alles, was das PDF zeigt, ohne Kostensätze.
     *
     * @param array<string,mixed> $employee
     * @param array<string,mixed> $period
     * @param array<string,array<string,mixed>> $locks
     * @param array<string,bool|string> $settings
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function snapshot(array $employee, array $period, int $revision, array $locks, array $settings, array $actor, string $now): array
    {
        $check = $this->check($employee, $period['from'], $period['to'], $settings);
        $submissions = [];
        foreach (array_unique(array_filter(array_column($locks, 'submission_id'))) as $submissionId) {
            $row = $this->approvals->submission((string) $submissionId);
            if ($row !== null) {
                $submissions[] = ['periodLabel' => self::periodFor((string) $row['period_type'], (string) $row['from_date'])['label'], 'submittedBy' => $row['submitted_by_name'], 'submittedAt' => $row['submitted_at'],
                    'approvedBy' => $row['decided_by_name'], 'approvedAt' => $row['decided_at'], 'comment' => $row['decision_comment'], 'warningsAcknowledged' => (bool) $row['warnings_acknowledged']];
            }
        }
        usort($submissions, static fn (array $a, array $b): int => strcmp((string) $a['submittedAt'], (string) $b['submittedAt']));
        return [
            'format' => 1,
            'employee' => ['id' => $employee['id'], 'name' => $employee['display_name'], 'personnelNumber' => $employee['personnel_number'] ?? null],
            'period' => $period, 'revision' => $revision, 'createdAt' => $now, 'createdBy' => $this->userName($actor),
            'submissions' => $submissions,
            'days' => array_values(array_map(static fn (array $day): array => array_diff_key($day, ['status' => true]), $check['days'])),
            'totals' => $check['totals'],
            'warnings' => $check['warnings'],
            'settings' => $settings,
        ];
    }

    // -------------------------------------------------------------------------
    // Prüfungen
    // -------------------------------------------------------------------------

    /**
     * Prüft einen Zeitraum. Liefert je Tag Status, Buchungen und Summen sowie Sperrfehler und
     * Warnungen (jeweils mit Datum, Code und Text).
     *
     * @param array<string,mixed> $employee
     * @param array<string,bool|string> $settings
     * @return array{days:array<string,array<string,mixed>>,totals:array<string,mixed>,errors:list<array<string,mixed>>,warnings:list<array<string,mixed>>}
     */
    public function check(array $employee, string $from, string $to, array $settings): array
    {
        $employeeId = (string) $employee['id'];
        $before = self::shift($from, -1);
        $entries = $this->times->entries($employeeId, $before, self::shift($to, 1));
        $details = array_column($this->times->detailsRange($employeeId, $from, $to), null, 'work_date');
        $expenses = $this->times->expenses($employeeId, $from, $to);
        $locks = $this->approvals->locks($employeeId, $from, $to);
        $timesOptional = (bool) $settings['time_tracking.times_optional'];
        $legal = (bool) $settings['time_tracking.warnings_enabled'];
        $errors = [];
        $warnings = [];
        $error = static function (?string $date, string $code, string $message) use (&$errors): void {
            $errors[] = ['date' => $date, 'code' => $code, 'message' => ($date !== null ? self::german($date) . ': ' : '') . $message];
        };
        $warn = static function (?string $date, string $code, string $message) use (&$warnings): void {
            $warnings[] = ['date' => $date, 'code' => $code, 'message' => ($date !== null ? self::german($date) . ': ' : '') . $message];
        };

        // Tage mit Anteilen (über Mitternacht anteilig, wie in allen Ansichten).
        $days = [];
        foreach (self::dates($from, $to) as $date) {
            $days[$date] = ['date' => $date, 'status' => $locks[$date]['status'] ?? 'open', 'netMinutes' => 0, 'travelMinutes' => 0, 'untimedMinutes' => 0, 'entries' => [],
                'details' => null, 'expenses' => [], 'expenseTotal' => '0.00', 'holiday' => self::holiday($date)];
        }
        foreach ($entries as $entry) {
            [$first, $second] = TimeTrackingService::split($entry);
            foreach ([[(string) $entry['work_date'], $first], [self::shift((string) $entry['work_date'], 1), $second]] as [$date, $share]) {
                if (isset($days[$date]) && $share['net'] > 0) {
                    $days[$date]['netMinutes'] += $share['net'];
                    $days[$date]['travelMinutes'] += $share['travel'];
                    $days[$date]['untimedMinutes'] += $entry['start_time'] === null ? $share['net'] : 0;
                }
            }
            if (isset($days[$entry['work_date']])) {
                $days[$entry['work_date']]['entries'][] = self::mapEntry($entry);
            }
        }
        foreach ($details as $date => $row) {
            $days[$date]['details'] = $this->tracking->detailsSnapshot($row);
        }
        foreach ($expenses as $expense) {
            $days[$expense['work_date']]['expenses'][] = ['amount' => $expense['amount'], 'purpose' => $expense['purpose'],
                'target' => $expense['order_number'] ?? $expense['cost_center_number'] ?? null, 'hasReceipt' => $expense['receipt_path'] !== null];
            $days[$expense['work_date']]['expenseTotal'] = (string) BigDecimal::of($days[$expense['work_date']]['expenseTotal'])->plus((string) $expense['amount'])->toScale(Decimal::MONEY_SCALE);
        }

        // --- Sperrfehler -----------------------------------------------------
        $inPeriod = static fn (array $entry): bool => (string) $entry['work_date'] >= $from && (string) $entry['work_date'] <= $to
            || ((bool) $entry['ends_next_day'] && self::shift((string) $entry['work_date'], 1) >= $from && (string) $entry['work_date'] < $from);
        $timed = array_values(array_filter($entries, static fn (array $entry): bool => $entry['start_time'] !== null));
        usort($timed, static fn (array $a, array $b): int => self::absoluteStart($a) <=> self::absoluteStart($b));
        foreach ($timed as $index => $entry) {
            for ($next = $index + 1; $next < count($timed) && self::absoluteStart($timed[$next]) < self::absoluteEnd($entry); $next++) {
                if ($inPeriod($entry) || $inPeriod($timed[$next])) {
                    $error((string) $timed[$next]['work_date'], 'overlap', sprintf('Zeitblöcke überschneiden sich (%s–%s %s und %s–%s %s).',
                        $entry['start_time'], $entry['end_time'], TimeTrackingService::SOURCE_KINDS[$entry['source_kind']] ?? '',
                        $timed[$next]['start_time'], $timed[$next]['end_time'], TimeTrackingService::SOURCE_KINDS[$timed[$next]['source_kind']] ?? ''));
                }
            }
        }
        foreach ($entries as $entry) {
            if (!$inPeriod($entry)) {
                continue;
            }
            $date = (string) $entry['work_date'];
            $net = (int) $entry['net_minutes'];
            if ($net <= 0 || (int) $entry['break_minutes'] < 0 || (int) $entry['travel_minutes'] < 0) {
                $error($date, 'invalid', 'Ungültige Dauer, Pause oder Reisezeit.');
            }
            if ((int) $entry['travel_minutes'] > $net) {
                $error($date, 'travel', 'Die Reisezeit ist größer als die Nettoarbeitszeit.');
            }
            if ($entry['start_time'] !== null) {
                $span = ((int) $entry['ends_next_day'] * 1440 + self::minutes((string) $entry['end_time'])) - self::minutes((string) $entry['start_time']);
                if ($span <= 0 || $span - (int) $entry['break_minutes'] !== $net) {
                    $error($date, 'interval', sprintf('Ende − Beginn − Pause (%s–%s) passt nicht zur Nettozeit.', $entry['start_time'], $entry['end_time']));
                }
            } elseif (!$timesOptional) {
                $error($date, 'times_missing', sprintf('Uhrzeiten fehlen (%s, %s). Laut Betriebseinstellung sind Beginn und Ende zu erfassen.', TimeTrackingService::SOURCE_KINDS[$entry['source_kind']] ?? '', self::hours($net)));
            }
            if ($entry['source_kind'] === 'work_report' && $entry['work_report_status'] === 'draft') {
                $error($date, 'draft_report', 'Stunden aus einem noch nicht eingereichten Rapportentwurf. Bitte den Rapport einreichen oder die Stunden entfernen.');
            }
        }
        foreach ($this->approvals->reportPositions($employeeId, $from, $to) as $position) {
            $label = $position['kind'] === 'daily_report' ? 'Tagesbericht' : 'Rapport';
            if ($position['booking_id'] === null || (string) $position['booking_date'] !== (string) $position['work_date']
                || (int) $position['booking_minutes'] !== ReportTimeBookings::minutes((string) $position['hours'])) {
                $error((string) $position['work_date'], 'booking', $label . ': Die zentrale Zeitbuchung passt nicht zur Berichtsposition. Bitte den Bericht erneut speichern oder das Büro informieren.');
            }
        }

        // --- Warnungen --------------------------------------------------------
        $regular = self::limitMinutes($settings['time_limits.regular_daily_hours']);
        $max = self::limitMinutes($settings['time_limits.max_daily_hours']);
        $threshold1 = self::limitMinutes($settings['time_limits.break_threshold_1_hours']);
        $threshold2 = self::limitMinutes($settings['time_limits.break_threshold_2_hours']);
        $minimum1 = (int) $settings['time_limits.break_minimum_1_minutes'];
        $minimum2 = (int) $settings['time_limits.break_minimum_2_minutes'];
        $continuous = self::limitMinutes($settings['time_limits.max_continuous_hours']);
        $part = (int) $settings['time_limits.min_break_part_minutes'];
        $rest = self::limitMinutes($settings['time_limits.min_rest_hours']);
        $byStartDay = [];
        foreach ($entries as $entry) {
            $byStartDay[(string) $entry['work_date']][] = $entry;
        }
        foreach ($days as $date => $day) {
            $own = $byStartDay[$date] ?? [];
            $weekday = (int) (new DateTimeImmutable($date))->format('N');
            if ($legal && $day['netMinutes'] > $max) {
                $warn($date, 'max_daily', sprintf('%s Arbeitszeit – mehr als die tägliche Höchstarbeitszeit von %s.', self::hours($day['netMinutes']), self::hours($max)));
            } elseif ($legal && $day['netMinutes'] > $regular) {
                $warn($date, 'regular_daily', sprintf('%s Arbeitszeit – mehr als die reguläre tägliche Arbeitszeit von %s.', self::hours($day['netMinutes']), self::hours($regular)));
            }
            if ($legal && $day['netMinutes'] > 0 && ($weekday === 7 || $day['holiday'] !== null)) {
                $warn($date, 'sunday', $weekday === 7 ? 'Sonntagsarbeit.' : 'Arbeit am Feiertag (' . $day['holiday'] . ').');
            }
            if ($day['netMinutes'] === 0 && $weekday <= 5 && $day['holiday'] === null && $own === []) {
                $warn($date, 'empty_day', 'Keine Arbeitszeit erfasst (Werktag).');
            }
            $untargeted = count(array_filter($own, static fn (array $entry): bool => $entry['order_id'] === null && $entry['cost_center_id'] === null));
            if ($untargeted > 0) {
                $warn($date, 'no_target', $untargeted === 1 ? 'Eine Buchung ohne Auftrag/Kostenstelle.' : $untargeted . ' Buchungen ohne Auftrag/Kostenstelle.');
            }
            $untimed = array_filter($own, static fn (array $entry): bool => $entry['start_time'] === null);
            if ($untimed !== [] && $timesOptional) {
                $warn($date, 'untimed', sprintf('%s ohne Uhrzeit – Pausen und Ruhezeiten sind für diesen Tag nicht prüfbar.', self::hours(array_sum(array_map(static fn (array $entry): int => (int) $entry['net_minutes'], $untimed)))));
            }
            if ($legal && $own !== [] && $untimed === []) {
                // Ruhepausen: erfasste Pausen ab Mindestdauer und Lücken zwischen Blöcken ab Mindestdauer.
                usort($own, static fn (array $a, array $b): int => self::absoluteStart($a) <=> self::absoluteStart($b));
                $net = array_sum(array_map(static fn (array $entry): int => (int) $entry['net_minutes'], $own));
                $breaks = 0;
                $chain = 0;
                $longest = 0;
                $previousEnd = null;
                foreach ($own as $entry) {
                    $gap = $previousEnd === null ? 0 : self::absoluteStart($entry) - $previousEnd;
                    if ($gap >= $part) {
                        $breaks += $gap;
                        $chain = 0;
                    }
                    $blockBreak = (int) $entry['break_minutes'];
                    if ($blockBreak >= $part) {
                        // Lage der Pause im Block unbekannt: keine Aussage zur ununterbrochenen Arbeitszeit.
                        $breaks += $blockBreak;
                        $chain = 0;
                    } else {
                        $chain += (int) $entry['net_minutes'] + max(0, $gap < $part ? $gap : 0) + $blockBreak;
                        $longest = max($longest, $chain);
                    }
                    $previousEnd = max($previousEnd ?? 0, self::absoluteEnd($entry));
                }
                $required = $net > $threshold2 ? $minimum2 : ($net > $threshold1 ? $minimum1 : 0);
                if ($breaks < $required) {
                    $warn($date, 'breaks', sprintf('Möglicherweise unzureichende Ruhepausen: %s Arbeitszeit, %d min Pause erfasst (vorgesehen mindestens %d min).', self::hours($net), $breaks, $required));
                }
                if ($longest > $continuous) {
                    $warn($date, 'continuous', sprintf('Mehr als %s ohne erfasste Ruhepause (%s am Stück).', self::hours($continuous), self::hours($longest)));
                }
                // Ruhezeit seit dem Vortag (nur mit Uhrzeiten an beiden Tagen).
                $previous = $byStartDay[self::shift($date, -1)] ?? [];
                if ($previous !== [] && array_filter($previous, static fn (array $entry): bool => $entry['start_time'] === null) === []) {
                    $lastEnd = max(array_map(self::absoluteEnd(...), $previous));
                    $firstStart = self::absoluteStart($own[0]);
                    if ($firstStart - $lastEnd < $rest) {
                        $warn($date, 'rest', sprintf('Nur %s Ruhezeit seit dem Vortag (vorgesehen mindestens %s).', self::hours(max(0, $firstStart - $lastEnd)), self::hours($rest)));
                    }
                }
            }
            foreach ($this->tracking->travelWarnings($day['details'], $settings) as $message) {
                $warn($date, 'travel_incomplete', $message);
            }
        }
        foreach ($this->approvals->correctionDrafts($employeeId, $from, $to) as $draft) {
            $warn(null, 'correction_draft', 'Offener Korrekturentwurf' . ($draft['corrects_number'] ? ' zu Rapport ' . $draft['corrects_number'] : '') . ' – er zählt erst nach dem Einreichen und ersetzt dann seinen Vorgänger.');
        }

        $totals = ['netMinutes' => 0, 'travelMinutes' => 0, 'untimedMinutes' => 0, 'expenseTotal' => '0.00'];
        foreach ($days as $day) {
            $totals['netMinutes'] += $day['netMinutes'];
            $totals['travelMinutes'] += $day['travelMinutes'];
            $totals['untimedMinutes'] += $day['untimedMinutes'];
            $totals['expenseTotal'] = (string) BigDecimal::of($totals['expenseTotal'])->plus($day['expenseTotal'])->toScale(Decimal::MONEY_SCALE);
        }
        $totals['workMinutes'] = $totals['netMinutes'] - $totals['travelMinutes'];
        $totals += $this->tracking->travelTotals(array_column($days, 'details'));
        return ['days' => $days, 'totals' => $totals, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @param array<string,mixed> $period @param array<string,bool|string> $settings @param list<string> $openDays @return list<array<string,mixed>> */
    private function periodErrors(array $period, array $settings, array $openDays): array
    {
        $errors = [];
        if (!in_array($period['type'], self::allowedTypes((string) $settings['time_tracking.submission_rhythm']), true)) {
            $errors[] = ['date' => null, 'code' => 'rhythm', 'message' => 'Laut Betriebseinstellung wird ' . ($period['type'] === 'week' ? 'monatlich' : 'wöchentlich') . ' eingereicht.'];
        }
        if ($period['to'] >= (new DateTimeImmutable('today'))->format('Y-m-d')) {
            $errors[] = ['date' => null, 'code' => 'unfinished', 'message' => 'Der Zeitraum ist noch nicht abgeschlossen und kann erst ab dem ' . self::german(self::shift($period['to'], 1)) . ' eingereicht werden.'];
        }
        if ($openDays === []) {
            $errors[] = ['date' => null, 'code' => 'nothing_open', 'message' => 'Alle Tage des Zeitraums sind bereits eingereicht oder festgeschrieben.'];
        }
        return $errors;
    }

    // -------------------------------------------------------------------------
    // Hilfen
    // -------------------------------------------------------------------------

    /**
     * Woche (ISO-Kalenderwoche, Mo–So) bzw. Kalendermonat zu einem Datum.
     *
     * @return array{type:string,key:string,from:string,to:string,label:string}
     */
    public static function periodFor(string $type, string $date): array
    {
        $day = new DateTimeImmutable(self::date($date));
        if ($type === 'week') {
            $from = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
            return ['type' => 'week', 'key' => $day->format('o-\WW'), 'from' => $from->format('Y-m-d'), 'to' => $from->modify('+6 days')->format('Y-m-d'),
                'label' => 'KW ' . (int) $day->format('W') . '/' . $day->format('o')];
        }
        if ($type === 'month') {
            return ['type' => 'month', 'key' => $day->format('Y-m'), 'from' => $day->format('Y-m-01'), 'to' => $day->format('Y-m-t'),
                'label' => self::MONTHS[(int) $day->format('n') - 1] . ' ' . $day->format('Y')];
        }
        throw new DocumentValidationException(['Bitte Woche oder Monat wählen.']);
    }

    /** @return list<string> */
    public static function allowedTypes(string $rhythm): array
    {
        return match ($rhythm) {
            'week' => ['week'],
            'month' => ['month'],
            default => ['week', 'month'],
        };
    }

    /**
     * Bundesweite gesetzliche Feiertage (Neujahr, Karfreitag, Ostermontag, 1. Mai, Christi
     * Himmelfahrt, Pfingstmontag, Tag der Deutschen Einheit, 1. und 2. Weihnachtstag).
     * Landesspezifische Feiertage sind nicht hinterlegt.
     */
    public static function holiday(string $date): ?string
    {
        $year = (int) substr($date, 0, 4);
        $fixed = ['01-01' => 'Neujahr', '05-01' => 'Tag der Arbeit', '10-03' => 'Tag der Deutschen Einheit', '12-25' => '1. Weihnachtstag', '12-26' => '2. Weihnachtstag'];
        if (isset($fixed[substr($date, 5)])) {
            return $fixed[substr($date, 5)];
        }
        // Ostersonntag (gregorianisch, anonymer Algorithmus).
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv(8 * $b + 13, 25) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $dayOfMonth = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $dayOfMonth));
        foreach ([-2 => 'Karfreitag', 1 => 'Ostermontag', 39 => 'Christi Himmelfahrt', 50 => 'Pfingstmontag'] as $offset => $name) {
            if ($easter->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d') === $date) {
                return $name;
            }
        }
        return null;
    }

    /** @param array<string,int> $counts */
    private static function periodStatus(array $counts): string
    {
        $total = array_sum($counts);
        foreach (['approved', 'submitted', 'open'] as $status) {
            if (($counts[$status] ?? 0) === $total) {
                return $status;
            }
        }
        if (($counts['reopened'] ?? 0) > 0) {
            return 'reopened';
        }
        return 'mixed';
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private static function mapEntry(array $entry): array
    {
        $kind = (string) $entry['source_kind'];
        return [
            'start' => $entry['start_time'], 'end' => $entry['end_time'], 'endsNextDay' => (bool) $entry['ends_next_day'], 'breakMinutes' => (int) $entry['break_minutes'],
            'netMinutes' => (int) $entry['net_minutes'], 'travelMinutes' => (int) $entry['travel_minutes'],
            'source' => TimeTrackingService::SOURCE_KINDS[$kind] ?? $kind,
            'reference' => match ($kind) {
                'work_report' => 'Rapport ' . ($entry['work_report_number'] ?? 'Entwurf'),
                'daily_report' => 'Tagesbericht' . (($entry['daily_report_title'] ?? '') !== '' ? ' ' . $entry['daily_report_title'] : ''),
                default => TimeTrackingService::ACTIVITIES[$entry['activity']] ?? (string) $entry['activity'],
            },
            'target' => $entry['order_number'] !== null ? $entry['order_number'] . ' · ' . $entry['order_title']
                : ($entry['cost_center_number'] !== null ? $entry['cost_center_number'] . ' · ' . $entry['cost_center_name'] : null),
            'notes' => (string) $entry['notes'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapSubmission(array $row): array
    {
        $summary = json_decode((string) $row['summary_json'], true) ?: [];
        return [
            'id' => $row['id'], 'version' => (int) $row['version'], 'employeeId' => $row['employee_id'], 'employeeName' => $row['employee_name'],
            'type' => $row['period_type'], 'key' => $row['period_key'], 'from' => $row['from_date'], 'to' => $row['to_date'],
            'label' => self::periodFor((string) $row['period_type'], (string) $row['from_date'])['label'],
            'status' => $row['status'], 'submittedByName' => $row['submitted_by_name'], 'submittedAt' => $row['submitted_at'],
            'decidedByName' => $row['decided_by_name'], 'decidedAt' => $row['decided_at'], 'comment' => $row['decision_comment'],
            'warningsAcknowledged' => (bool) $row['warnings_acknowledged'], 'submittedWarnings' => count($summary['warnings'] ?? []),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapStatement(array $row): array
    {
        return [
            'id' => $row['id'], 'type' => $row['period_type'], 'key' => $row['period_key'], 'from' => $row['from_date'], 'to' => $row['to_date'],
            'label' => self::periodFor((string) $row['period_type'], (string) $row['from_date'])['label'], 'revision' => (int) $row['revision'],
            'current' => $row['status'] === 'current', 'createdAt' => $row['created_at'], 'createdByName' => $row['created_by_name'], 'supersededAt' => $row['superseded_at'],
            'url' => '/api/time-tracking/statements/' . rawurlencode((string) $row['id']) . '/pdf',
        ];
    }

    /** @param array<string,mixed> $employee @param array<string,mixed> $period */
    private static function fileName(array $employee, array $period, int $revision): string
    {
        $name = preg_replace('/[^\p{L}\p{N}]+/u', '-', (string) $employee['display_name']) ?? 'Mitarbeiter';
        return sprintf('Stundennachweis_%s_%s_Rev%d.pdf', trim($name, '-') ?: 'Mitarbeiter', $period['key'], $revision);
    }

    /** @return array<string,mixed> */
    private function openSubmission(string $id): array
    {
        $submission = $this->approvals->submission($id) ?? throw new DocumentValidationException(['Die Einreichung wurde nicht gefunden.']);
        if ($submission['status'] !== 'submitted') {
            throw new DocumentValidationException(['Die Einreichung wurde bereits bearbeitet.']);
        }
        return $submission;
    }

    /** @param array<string,mixed> $actor */
    private function event(string $employeeId, string $from, string $to, string $action, ?string $submissionId, string $reason, array $actor, string $now): void
    {
        $this->approvals->insertEvent(['id' => Uuid::v4(), 'employee_id' => $employeeId, 'from_date' => $from, 'to_date' => $to, 'action' => $action,
            'submission_id' => $submissionId, 'reason' => $reason, 'user_id' => (string) $actor['id'], 'created_at' => $now]);
    }

    /** @param array<string,mixed> $actor */
    private function userName(array $actor): string
    {
        return (string) ($actor['displayName'] ?? $actor['display_name'] ?? $actor['username'] ?? '');
    }

    /** @param array<string,mixed> $input */
    private function reason(array $input): string
    {
        $reason = $this->text($input['reason'] ?? '', 1000, 'Begründung');
        if ($reason === '') {
            throw new DocumentValidationException(['Bitte eine Begründung angeben.']);
        }
        return $reason;
    }

    private function text(mixed $value, int $max, string $label): string
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));
        if (mb_strlen($text) > $max) {
            throw new DocumentValidationException([$label . ' darf höchstens ' . $max . ' Zeichen lang sein.']);
        }
        return $text;
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

    private static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new DocumentValidationException(['Bitte ein gültiges Datum angeben.']);
        }
        return $date->format('Y-m-d');
    }

    /** @return list<string> */
    private static function dates(string $from, string $to): array
    {
        $dates = [];
        for ($day = new DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }
        return $dates;
    }

    private static function shift(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
    }

    private static function german(string $date): string
    {
        return (new DateTimeImmutable($date))->format('d.m.Y');
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    /** Minuten seit 1970-01-01 für Vergleiche über Tagesgrenzen. @param array<string,mixed> $entry */
    private static function absoluteStart(array $entry): int
    {
        return intdiv((int) (new DateTimeImmutable((string) $entry['work_date'] . ' 00:00:00 UTC'))->getTimestamp(), 60) + self::minutes((string) $entry['start_time']);
    }

    /** @param array<string,mixed> $entry */
    private static function absoluteEnd(array $entry): int
    {
        return self::absoluteStart($entry) + (int) $entry['net_minutes'] + (int) $entry['break_minutes'];
    }

    private static function limitMinutes(bool|string $hours): int
    {
        return (int) BigDecimal::of((string) $hours)->multipliedBy(60)->toScale(0, \Brick\Math\RoundingMode::HalfUp)->toInt();
    }

    public static function hours(int $minutes): string
    {
        return sprintf('%d:%02d h', intdiv($minutes, 60), $minutes % 60);
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
    private function can(array $actor, string $permission): bool
    {
        return RolePermissions::allows((string) ($actor['role'] ?? ''), $permission);
    }
}
