<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

/** Datenzugriff der Zeiterfassung: Zeitblöcke, Tagesangaben, Auslagen und Referenzzeiten. */
final readonly class TimeTrackingRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function entries(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT t.*, o.order_number, o.title AS order_title, u.display_name AS updated_by_name, cu.display_name AS created_by_name
            FROM time_entries t LEFT JOIN orders o ON o.id = t.order_id JOIN users u ON u.id = t.updated_by_user_id JOIN users cu ON cu.id = t.created_by_user_id
            WHERE t.employee_id = :employee AND t.work_date BETWEEN :from AND :to ORDER BY t.work_date, t.start_time, t.id');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function entry(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM time_entries WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function insertEntry(array $data): void
    {
        $columns = array_keys($data);
        $this->pdo->prepare('INSERT INTO time_entries (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')')->execute($data);
    }

    /** Änderung nur bei passender Version. @param array<string,mixed> $fields */
    public function updateEntry(string $id, int $version, array $fields): bool
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($fields)));
        $statement = $this->pdo->prepare('UPDATE time_entries SET ' . $assignments . ', version = version + 1 WHERE id = :id AND version = :version');
        $statement->execute([...$fields, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    public function deleteEntry(string $id, int $version): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM time_entries WHERE id = :id AND version = :version');
        $statement->execute(['id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** @return array<string,mixed>|null */
    public function details(string $employeeId, string $date): ?array
    {
        $statement = $this->pdo->prepare('SELECT d.*, o.order_number AS km_order_number FROM time_day_details d LEFT JOIN orders o ON o.id = d.private_km_order_id
            WHERE d.employee_id = :employee AND d.work_date = :date');
        $statement->execute(['employee' => $employeeId, 'date' => $date]);
        return $statement->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function saveDetails(array $data): void
    {
        $this->pdo->prepare('INSERT INTO time_day_details (employee_id, work_date, assignment_kind, overnight, travel_day, location, notes, private_km, private_km_purpose, private_km_order_id, updated_by_user_id, updated_at)
            VALUES (:employee_id, :work_date, :assignment_kind, :overnight, :travel_day, :location, :notes, :private_km, :private_km_purpose, :private_km_order_id, :updated_by_user_id, :updated_at)
            ON CONFLICT(employee_id, work_date) DO UPDATE SET assignment_kind = excluded.assignment_kind, overnight = excluded.overnight, travel_day = excluded.travel_day,
            location = excluded.location, notes = excluded.notes, private_km = excluded.private_km, private_km_purpose = excluded.private_km_purpose,
            private_km_order_id = excluded.private_km_order_id, updated_by_user_id = excluded.updated_by_user_id, updated_at = excluded.updated_at')->execute($data);
    }

    /** @return list<array<string,mixed>> */
    public function detailsRange(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM time_day_details WHERE employee_id = :employee AND work_date BETWEEN :from AND :to');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function expenses(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT x.*, o.order_number FROM time_expenses x LEFT JOIN orders o ON o.id = x.order_id
            WHERE x.employee_id = :employee AND x.work_date BETWEEN :from AND :to ORDER BY x.work_date, x.created_at, x.rowid');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function expense(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM time_expenses WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function insertExpense(array $data): void
    {
        $columns = array_keys($data);
        $this->pdo->prepare('INSERT INTO time_expenses (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')')->execute($data);
    }

    public function deleteExpense(string $id): void
    {
        $this->pdo->prepare('DELETE FROM time_expenses WHERE id = :id')->execute(['id' => $id]);
    }

    /** Zeiten aus Bautagesberichten (Referenz bzw. Ist-Basis ohne Zeiterfassung). @return list<array<string,mixed>> */
    public function dailyReportTimes(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT e.id, e.report_id, e.work_date, e.duration_hours, e.description, r.order_id, o.order_number, o.title AS order_title
            FROM daily_report_time_entries e JOIN daily_reports r ON r.id = e.report_id JOIN orders o ON o.id = r.order_id
            WHERE e.employee_id = :employee AND e.work_date BETWEEN :from AND :to ORDER BY e.work_date, o.order_number');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /**
     * Rapportstunden eines Mitarbeiters (nur Referenz). Nur Zeilen mit eindeutigem Mitarbeiter;
     * mehrtägige Rapporte erscheinen an ihrem Beginndatum mit Hinweis auf den Zeitraum.
     *
     * @return list<array<string,mixed>>
     */
    public function workReportTimes(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare("SELECT l.id, l.hours, l.activity, w.id AS report_id, w.report_number, w.status, w.work_date, w.work_date_to, w.order_id, o.order_number
            FROM work_report_labor l JOIN work_reports w ON w.id = l.report_id LEFT JOIN orders o ON o.id = w.order_id
            WHERE l.employee_id = :employee AND w.work_date BETWEEN :from AND :to ORDER BY w.work_date, w.report_number");
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** Ist-Stunden-Quellen eines Auftrags. @return array{entries:list<array<string,mixed>>,reports:list<array<string,mixed>>} */
    public function orderTimes(string $orderId): array
    {
        $entries = $this->pdo->prepare('SELECT t.employee_id, t.work_date, t.activity, t.net_minutes, t.hourly_cost_rate_snapshot, e.display_name AS employee_name
            FROM time_entries t JOIN employees e ON e.id = t.employee_id WHERE t.order_id = :order ORDER BY t.work_date');
        $entries->execute(['order' => $orderId]);
        $reports = $this->pdo->prepare('SELECT e.employee_id, e.work_date, e.duration_hours, e.hourly_cost_rate_snapshot, m.display_name AS employee_name
            FROM daily_report_time_entries e JOIN daily_reports r ON r.id = e.report_id JOIN employees m ON m.id = e.employee_id WHERE r.order_id = :order ORDER BY e.work_date');
        $reports->execute(['order' => $orderId]);
        return ['entries' => $entries->fetchAll(), 'reports' => $reports->fetchAll()];
    }

    /** Auswertung für das Büro. @return list<array<string,mixed>> */
    public function report(string $from, string $to, ?string $employeeId, ?string $orderId): array
    {
        $sql = 'SELECT t.*, e.display_name AS employee_name, o.order_number, o.title AS order_title FROM time_entries t
            JOIN employees e ON e.id = t.employee_id LEFT JOIN orders o ON o.id = t.order_id WHERE t.work_date BETWEEN :from AND :to';
        $parameters = ['from' => $from, 'to' => $to];
        if ($employeeId !== null) {
            $sql .= ' AND t.employee_id = :employee';
            $parameters['employee'] = $employeeId;
        }
        if ($orderId !== null) {
            $sql .= ' AND t.order_id = :order';
            $parameters['order'] = $orderId;
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY t.work_date, e.display_name, t.start_time LIMIT 5000');
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function employees(): array
    {
        return $this->pdo->query('SELECT id, display_name, personnel_number, active, user_id, hourly_cost_rate FROM employees ORDER BY active DESC, display_name COLLATE NOCASE')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function employee(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM employees WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function employeeForUser(string $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM employees WHERE user_id = :user');
        $statement->execute(['user' => $userId]);
        return $statement->fetch() ?: null;
    }

    /** Auswählbare Aufträge (Techniker: nur freigegebene). @return list<array<string,mixed>> */
    public function orders(bool $releasedOnly): array
    {
        $sql = "SELECT id, order_number, title, status FROM orders WHERE status <> 'invoiced'" . ($releasedOnly ? " AND status <> 'open'" : '');
        return $this->pdo->query($sql . ' ORDER BY order_number DESC LIMIT 500')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function order(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, order_number, title, status FROM orders WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }
}
