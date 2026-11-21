<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\Uuid;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PDO;

/**
 * Pflegt die zentralen Zeitbuchungen (time_entries) zu Berichtsstundenpositionen.
 *
 * Invariante: Jede maßgebliche Stundenposition eines Berichts mit Mitarbeiter hat genau eine
 * Buchung mit derselben Dauer (UNIQUE-Referenz). Die Dauer, das Datum, der Mitarbeiter und der
 * Auftrag kommen immer aus dem Bericht. Uhrzeiten, Pause, Reisezeit und Einsatzart sind
 * Ergänzungen der Zeiterfassung; passen sie nach einer Änderung nicht mehr (Dauer, Datum oder
 * Mitarbeiter geändert), werden sie entfernt – die Buchung ist dann „Uhrzeiten fehlen“.
 * Es werden nie Uhrzeiten erfunden.
 *
 * Alle Methoden laufen in der Transaktion des aufrufenden Fachdienstes.
 */
final readonly class ReportTimeBookings
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Minuten einer Stundenangabe (Dezimalstring); Berichte erfassen ganze Minuten. */
    public static function minutes(string $hours): int
    {
        return (int) BigDecimal::of($hours)->multipliedBy(60)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** true, wenn die Stundenangabe genau ganzen Minuten entspricht. */
    public static function wholeMinutes(BigDecimal $hours): bool
    {
        $minutes = $hours->multipliedBy(60);
        return $minutes->isEqualTo($minutes->toScale(0, RoundingMode::Down));
    }

    /** Buchungen eines Tagesberichts (jede Stundenposition zählt; Kostenziel – Auftrag oder Kostenstelle – folgt dem Bericht). */
    public function syncDailyReport(string $reportId, string $userId, string $now): void
    {
        $report = $this->row('SELECT id, order_id, cost_center_id FROM daily_reports WHERE id = :id', ['id' => $reportId]);
        if ($report === null) {
            return;
        }
        $rows = $this->rows('SELECT e.id, e.employee_id, e.work_date, e.duration_hours, e.hourly_cost_rate_snapshot FROM daily_report_time_entries e WHERE e.report_id = :id', ['id' => $reportId]);
        foreach ($rows as $row) {
            $this->upsert('daily_report', 'daily_report_entry_id', (string) $row['id'], [
                'employee_id' => (string) $row['employee_id'], 'work_date' => (string) $row['work_date'],
                'net_minutes' => self::minutes((string) $row['duration_hours']), 'order_id' => $report['order_id'], 'cost_center_id' => $report['cost_center_id'],
                'hourly_cost_rate_snapshot' => (string) $row['hourly_cost_rate_snapshot'],
            ], $userId, $now);
        }
    }

    /**
     * Buchungen eines Rapports. Maßgeblich sind eingereichte, nicht ersetzte Fassungen sowie
     * Entwürfe ohne Korrekturbezug (als Entwurf gekennzeichnet). Korrekturentwürfe und ersetzte
     * Fassungen haben keine Buchungen.
     */
    public function syncWorkReport(string $reportId, string $userId, string $now): void
    {
        $report = $this->row('SELECT id, status, order_id, correction_of_id, superseded_by_id FROM work_reports WHERE id = :id', ['id' => $reportId]);
        if ($report === null) {
            return;
        }
        $effective = $report['superseded_by_id'] === null && ($report['status'] !== 'draft' || $report['correction_of_id'] === null);
        if (!$effective) {
            $this->pdo->prepare('DELETE FROM time_entries WHERE work_report_labor_id IN (SELECT id FROM work_report_labor WHERE report_id = :id)')->execute(['id' => $reportId]);
            return;
        }
        $rows = $this->rows('SELECT l.id, l.employee_id, COALESCE(l.work_date, w.work_date) AS work_date, l.hours, m.hourly_cost_rate
            FROM work_report_labor l JOIN work_reports w ON w.id = l.report_id LEFT JOIN employees m ON m.id = l.employee_id WHERE l.report_id = :id', ['id' => $reportId]);
        foreach ($rows as $row) {
            if ($row['employee_id'] === null) {
                // Andere Person ohne Mitarbeiterdatensatz: keine Zeiterfassung möglich.
                $this->pdo->prepare('DELETE FROM time_entries WHERE work_report_labor_id = :id')->execute(['id' => $row['id']]);
                continue;
            }
            $this->upsert('work_report', 'work_report_labor_id', (string) $row['id'], [
                'employee_id' => (string) $row['employee_id'], 'work_date' => (string) $row['work_date'],
                // Rapportstunden gehören ausschließlich zum Auftrag (nie zu einer Kostenstelle).
                'net_minutes' => self::minutes((string) $row['hours']), 'order_id' => $report['order_id'], 'cost_center_id' => null,
                'hourly_cost_rate_snapshot' => (string) $row['hourly_cost_rate'],
            ], $userId, $now);
        }
    }

    /**
     * Korrekturrapport wird wirksam: Die Buchungen des Vorgängers entfallen, die der Korrektur
     * entstehen. Ergänzte Uhrzeiten werden nur übernommen, wenn genau eine frühere Buchung
     * desselben Mitarbeiters mit gleichem Datum und gleicher Dauer existiert.
     */
    public function replaceByCorrection(string $previousId, string $correctionId, string $userId, string $now): void
    {
        $previous = $this->rows('SELECT t.* FROM time_entries t JOIN work_report_labor l ON l.id = t.work_report_labor_id WHERE l.report_id = :id', ['id' => $previousId]);
        $this->syncWorkReport($previousId, $userId, $now);
        $this->syncWorkReport($correctionId, $userId, $now);
        $created = $this->rows('SELECT t.* FROM time_entries t JOIN work_report_labor l ON l.id = t.work_report_labor_id WHERE l.report_id = :id', ['id' => $correctionId]);
        $key = static fn (array $entry): string => $entry['employee_id'] . '|' . $entry['work_date'] . '|' . $entry['net_minutes'];
        $candidates = [];
        foreach ($previous as $entry) {
            if ($entry['start_time'] !== null || (int) $entry['travel_minutes'] > 0 || $entry['assignment_kind'] !== null) {
                $candidates[$key($entry)][] = $entry;
            }
        }
        $targets = [];
        foreach ($created as $entry) {
            $targets[$key($entry)][] = $entry;
        }
        foreach ($targets as $match => $entries) {
            if (count($entries) !== 1 || count($candidates[$match] ?? []) !== 1) {
                continue;
            }
            $source = $candidates[$match][0];
            $this->pdo->prepare('UPDATE time_entries SET start_time = :start, end_time = :end, ends_next_day = :next, break_minutes = :break, travel_minutes = :travel,
                assignment_kind = :assignment, notes = :notes, updated_by_user_id = :user, updated_at = :now, version = version + 1 WHERE id = :id')
                ->execute(['start' => $source['start_time'], 'end' => $source['end_time'], 'next' => $source['ends_next_day'], 'break' => $source['break_minutes'],
                    'travel' => $source['travel_minutes'], 'assignment' => $source['assignment_kind'], 'notes' => $source['notes'],
                    'user' => $userId, 'now' => $now, 'id' => $entries[0]['id']]);
        }
    }

    /**
     * Legt die Buchung zu einer Berichtsposition an oder gleicht sie an. Geänderte Dauer, geändertes
     * Datum oder ein anderer Mitarbeiter entfernen die ergänzten Uhrzeiten und die Reisezeit.
     *
     * @param array{employee_id:string,work_date:string,net_minutes:int,order_id:?string,cost_center_id:?string,hourly_cost_rate_snapshot:string} $values
     */
    private function upsert(string $kind, string $column, string $reference, array $values, string $userId, string $now): void
    {
        $existing = $this->row('SELECT * FROM time_entries WHERE ' . $column . ' = :ref', ['ref' => $reference]);
        if ($existing === null) {
            $this->pdo->prepare('INSERT INTO time_entries (id, employee_id, work_date, source_kind, ' . $column . ', net_minutes, activity, order_id, cost_center_id, notes,
                hourly_cost_rate_snapshot, created_by_user_id, created_at, updated_by_user_id, updated_at)
                VALUES (:id, :employee, :date, :kind, :ref, :net, \'work\', :order, :cost_center, \'\', :rate, :user, :now, :user, :now)')
                ->execute(['id' => Uuid::v4(), 'employee' => $values['employee_id'], 'date' => $values['work_date'], 'kind' => $kind, 'ref' => $reference,
                    'net' => $values['net_minutes'], 'order' => $values['order_id'], 'cost_center' => $values['cost_center_id'], 'rate' => $values['hourly_cost_rate_snapshot'], 'user' => $userId, 'now' => $now]);
            return;
        }
        $timesInvalid = (int) $existing['net_minutes'] !== $values['net_minutes'] || $existing['work_date'] !== $values['work_date'] || $existing['employee_id'] !== $values['employee_id'];
        $orderChanged = $existing['order_id'] !== $values['order_id'] || $existing['cost_center_id'] !== $values['cost_center_id'];
        if (!$timesInvalid && !$orderChanged) {
            return;
        }
        $sets = ['order_id = :order', 'cost_center_id = :cost_center', 'updated_by_user_id = :user', 'updated_at = :now', 'version = version + 1'];
        $parameters = ['order' => $values['order_id'], 'cost_center' => $values['cost_center_id'], 'user' => $userId, 'now' => $now, 'id' => $existing['id']];
        if ($timesInvalid) {
            $sets = [...$sets, 'employee_id = :employee', 'work_date = :date', 'net_minutes = :net', 'start_time = NULL', 'end_time = NULL', 'ends_next_day = 0', 'break_minutes = 0', 'travel_minutes = 0'];
            $parameters += ['employee' => $values['employee_id'], 'date' => $values['work_date'], 'net' => $values['net_minutes']];
            if ($existing['employee_id'] !== $values['employee_id']) {
                $sets[] = 'hourly_cost_rate_snapshot = :rate';
                $parameters['rate'] = $values['hourly_cost_rate_snapshot'];
            }
        }
        $this->pdo->prepare('UPDATE time_entries SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($parameters);
    }

    /** @param array<string,mixed> $parameters @return array<string,mixed>|null */
    private function row(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $parameters @return list<array<string,mixed>> */
    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }
}
