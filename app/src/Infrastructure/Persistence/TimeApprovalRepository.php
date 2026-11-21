<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

/** Datenzugriff für Einreichung, Festschreibung, Wiederöffnung und archivierte Stundennachweise. */
final readonly class TimeApprovalRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function begin(): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** Tagesstatus (ohne Eintrag = offen). @return array<string,array<string,mixed>> work_date => Sperre */
    public function locks(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM time_day_locks WHERE employee_id = :employee AND work_date BETWEEN :from AND :to');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return array_column($statement->fetchAll(), null, 'work_date');
    }

    /** Sperrt offene bzw. zur Korrektur geöffnete Tage; bestehende Einreichungen/Festschreibungen bleiben. @param list<string> $dates */
    public function lockDays(string $employeeId, array $dates, string $submissionId, string $userId, string $now): void
    {
        $statement = $this->pdo->prepare("INSERT INTO time_day_locks (employee_id, work_date, status, submission_id, updated_by_user_id, updated_at)
            VALUES (:employee, :date, 'submitted', :submission, :user, :now)
            ON CONFLICT(employee_id, work_date) DO UPDATE SET status = 'submitted', submission_id = excluded.submission_id, updated_by_user_id = excluded.updated_by_user_id, updated_at = excluded.updated_at
            WHERE time_day_locks.status = 'reopened'");
        foreach ($dates as $date) {
            $statement->execute(['employee' => $employeeId, 'date' => $date, 'submission' => $submissionId, 'user' => $userId, 'now' => $now]);
        }
    }

    public function setSubmissionDays(string $submissionId, string $from, string $status, string $userId, string $now): int
    {
        $statement = $this->pdo->prepare("UPDATE time_day_locks SET status = :status, updated_by_user_id = :user, updated_at = :now
            WHERE submission_id = :submission AND status = :from");
        $statement->execute(['status' => $status, 'user' => $userId, 'now' => $now, 'submission' => $submissionId, 'from' => $from]);
        return $statement->rowCount();
    }

    /** Festgeschriebene Tage im Zeitraum zur Korrektur öffnen. @return list<string> geöffnete Tage */
    public function reopenDays(string $employeeId, string $from, string $to, string $userId, string $now): array
    {
        $statement = $this->pdo->prepare("SELECT work_date FROM time_day_locks WHERE employee_id = :employee AND work_date BETWEEN :from AND :to AND status = 'approved' ORDER BY work_date");
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        $dates = $statement->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->prepare("UPDATE time_day_locks SET status = 'reopened', updated_by_user_id = :user, updated_at = :now
            WHERE employee_id = :employee AND work_date BETWEEN :from AND :to AND status = 'approved'")
            ->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to, 'user' => $userId, 'now' => $now]);
        return $dates;
    }

    /** @param array<string,mixed> $data */
    public function insertSubmission(array $data): void
    {
        $this->insert('time_submissions', $data);
    }

    /** @return array<string,mixed>|null */
    public function submission(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT s.*, e.display_name AS employee_name, e.personnel_number, e.user_id AS employee_user_id,
                su.display_name AS submitted_by_name, du.display_name AS decided_by_name
            FROM time_submissions s JOIN employees e ON e.id = s.employee_id JOIN users su ON su.id = s.submitted_by_user_id LEFT JOIN users du ON du.id = s.decided_by_user_id
            WHERE s.id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function submissions(?string $status, ?string $employeeId, ?string $from = null, ?string $to = null): array
    {
        $sql = 'SELECT s.*, e.display_name AS employee_name, e.personnel_number, su.display_name AS submitted_by_name, du.display_name AS decided_by_name
            FROM time_submissions s JOIN employees e ON e.id = s.employee_id JOIN users su ON su.id = s.submitted_by_user_id LEFT JOIN users du ON du.id = s.decided_by_user_id WHERE 1 = 1';
        $parameters = [];
        if ($status !== null) {
            $sql .= ' AND s.status = :status';
            $parameters['status'] = $status;
        }
        if ($employeeId !== null) {
            $sql .= ' AND s.employee_id = :employee';
            $parameters['employee'] = $employeeId;
        }
        if ($from !== null && $to !== null) {
            $sql .= ' AND s.to_date >= :from AND s.from_date <= :to';
            $parameters += ['from' => $from, 'to' => $to];
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY s.submitted_at, e.display_name LIMIT 500');
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function decideSubmission(string $id, int $version, string $status, string $userId, string $now, string $comment, bool $acknowledged): bool
    {
        $statement = $this->pdo->prepare("UPDATE time_submissions SET status = :status, decided_by_user_id = :user, decided_at = :now, decision_comment = :comment,
            warnings_acknowledged = :ack, version = version + 1 WHERE id = :id AND version = :version AND status = 'submitted'");
        $statement->execute(['status' => $status, 'user' => $userId, 'now' => $now, 'comment' => $comment, 'ack' => $acknowledged ? 1 : 0, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** @param array<string,mixed> $data */
    public function insertEvent(array $data): void
    {
        $this->insert('time_lock_events', $data);
    }

    /** @return list<array<string,mixed>> */
    public function events(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT v.*, u.display_name AS user_name FROM time_lock_events v JOIN users u ON u.id = v.user_id
            WHERE v.employee_id = :employee AND v.to_date >= :from AND v.from_date <= :to ORDER BY v.created_at, v.rowid');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function statements(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT id, employee_id, period_type, period_key, from_date, to_date, revision, status, file_name, created_at, superseded_at,
                (SELECT display_name FROM users WHERE id = created_by_user_id) AS created_by_name
            FROM time_statements WHERE employee_id = :employee AND to_date >= :from AND from_date <= :to ORDER BY from_date, period_type DESC, revision');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function statement(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM time_statements WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function currentStatement(string $employeeId, string $type, string $key): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM time_statements WHERE employee_id = :employee AND period_type = :type AND period_key = :key AND status = 'current'");
        $statement->execute(['employee' => $employeeId, 'type' => $type, 'key' => $key]);
        return $statement->fetch() ?: null;
    }

    public function hasStatement(string $employeeId, string $type, string $key): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM time_statements WHERE employee_id = :employee AND period_type = :type AND period_key = :key');
        $statement->execute(['employee' => $employeeId, 'type' => $type, 'key' => $key]);
        return $statement->fetchColumn() !== false;
    }

    public function nextRevision(string $employeeId, string $type, string $key): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(revision), 0) + 1 FROM time_statements WHERE employee_id = :employee AND period_type = :type AND period_key = :key');
        $statement->execute(['employee' => $employeeId, 'type' => $type, 'key' => $key]);
        return (int) $statement->fetchColumn();
    }

    /** @param array<string,mixed> $data */
    public function insertStatement(array $data): void
    {
        $this->insert('time_statements', $data);
    }

    /** Aktuelle Nachweise, die einen der Tage enthalten, als „ersetzt“ kennzeichnen (Woche und Monat). @param list<string> $dates @return int */
    public function supersedeStatements(string $employeeId, array $dates, string $userId, string $now): int
    {
        $count = 0;
        $statement = $this->pdo->prepare("UPDATE time_statements SET status = 'superseded', superseded_at = :now, superseded_by_user_id = :user
            WHERE employee_id = :employee AND status = 'current' AND from_date <= :date AND to_date >= :date");
        foreach ($dates as $date) {
            $statement->execute(['now' => $now, 'user' => $userId, 'employee' => $employeeId, 'date' => $date]);
            $count += $statement->rowCount();
        }
        return $count;
    }

    /**
     * Stundenpositionen aus Tagesberichten und maßgeblichen Rapporten des Mitarbeiters mit ihrer
     * zentralen Buchung (für die Konsistenzprüfung).
     *
     * @return list<array<string,mixed>>
     */
    public function reportPositions(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare("SELECT 'daily_report' AS kind, e.id, e.work_date, e.duration_hours AS hours, t.id AS booking_id, t.work_date AS booking_date, t.net_minutes AS booking_minutes
                FROM daily_report_time_entries e JOIN daily_reports r ON r.id = e.report_id LEFT JOIN time_entries t ON t.daily_report_entry_id = e.id
                WHERE e.employee_id = :employee AND e.work_date BETWEEN :from AND :to AND r.status <> 'cancelled'
            UNION ALL
            SELECT 'work_report', l.id, COALESCE(l.work_date, w.work_date), l.hours, t.id, t.work_date, t.net_minutes
                FROM work_report_labor l JOIN work_reports w ON w.id = l.report_id LEFT JOIN time_entries t ON t.work_report_labor_id = l.id
                WHERE l.employee_id = :employee AND COALESCE(l.work_date, w.work_date) BETWEEN :from AND :to
                  AND w.superseded_by_id IS NULL AND (w.status <> 'draft' OR w.correction_of_id IS NULL)");
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** Noch nicht eingereichte Tagesberichte (Entwurf bzw. zur Korrektur geöffnet) mit Stunden des Mitarbeiters. @return list<array<string,mixed>> */
    public function openDailyReports(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare("SELECT r.id, r.report_date, r.title, MIN(e.work_date) AS work_date FROM daily_report_time_entries e JOIN daily_reports r ON r.id = e.report_id
            WHERE e.employee_id = :employee AND e.work_date BETWEEN :from AND :to AND r.status IN ('draft', 'reopened') GROUP BY r.id ORDER BY MIN(e.work_date), r.id");
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** Offene Korrekturentwürfe mit Stunden des Mitarbeiters im Zeitraum. @return list<array<string,mixed>> */
    public function correctionDrafts(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare("SELECT DISTINCT w.id, p.report_number AS corrects_number FROM work_report_labor l JOIN work_reports w ON w.id = l.report_id
            LEFT JOIN work_reports p ON p.id = w.correction_of_id
            WHERE l.employee_id = :employee AND COALESCE(l.work_date, w.work_date) BETWEEN :from AND :to AND w.status = 'draft' AND w.correction_of_id IS NOT NULL");
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $data */
    private function insert(string $table, array $data): void
    {
        $columns = array_keys($data);
        $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')')->execute($data);
    }
}
