<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

/** Datenzugriff der Zeiterfassung: zentrale Zeitbuchungen aller Herkünfte, Tagesangaben und Auslagen. */
final readonly class TimeTrackingRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Gemeinsame Spalten aller Buchungen: Auftrag, Kunde und Herkunft (Tagesbericht/Rapport).
     * Der Kunde kommt aus dem Auftrag, sonst aus dem Bericht.
     */
    private const ENTRY_SELECT = "t.*, o.order_number, o.title AS order_title, u.display_name AS updated_by_name, cu.display_name AS created_by_name,
        de.report_id AS daily_report_id, de.description AS daily_entry_description, dr.title AS daily_report_title, dr.report_date AS daily_report_date,
        cc.number AS cost_center_number, cc.name AS cost_center_name,
        wl.report_id AS work_report_id, wl.activity AS labor_activity, wl.labor_item_name, wr.report_number AS work_report_number, wr.status AS work_report_status,
        CASE WHEN c.customer_type = 'company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END AS customer_name";
    private const ENTRY_FROM = 'time_entries t LEFT JOIN orders o ON o.id = t.order_id LEFT JOIN cost_centers cc ON cc.id = t.cost_center_id JOIN users u ON u.id = t.updated_by_user_id JOIN users cu ON cu.id = t.created_by_user_id
        LEFT JOIN daily_report_time_entries de ON de.id = t.daily_report_entry_id LEFT JOIN daily_reports dr ON dr.id = de.report_id
        LEFT JOIN work_report_labor wl ON wl.id = t.work_report_labor_id LEFT JOIN work_reports wr ON wr.id = wl.report_id
        LEFT JOIN customers c ON c.id = COALESCE(o.customer_id, dr.customer_id, wr.customer_id)';

    /** Alle Buchungen eines Mitarbeiters (alle Herkünfte). Ohne Uhrzeiten nach den Blöcken mit Uhrzeit. @return list<array<string,mixed>> */
    public function entries(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ENTRY_SELECT . ' FROM ' . self::ENTRY_FROM . '
            WHERE t.employee_id = :employee AND t.work_date BETWEEN :from AND :to ORDER BY t.work_date, t.start_time IS NULL, t.start_time, t.created_at, t.id');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function entry(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ENTRY_SELECT . ' FROM ' . self::ENTRY_FROM . ' WHERE t.id = :id');
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

    /** Nur manuelle Buchungen; Berichtsbuchungen entfallen ausschließlich mit ihrer Berichtsposition. */
    public function deleteEntry(string $id, int $version): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM time_entries WHERE id = :id AND version = :version AND source_kind = 'manual'");
        $statement->execute(['id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** Globale Betriebseinstellung: Stundenerfassung ohne Uhrzeiten erlaubt (Standard: ja). */
    public function timesOptional(): bool
    {
        $value = $this->pdo->query("SELECT setting_value FROM operational_settings WHERE setting_key = 'time_tracking.times_optional'")->fetchColumn();
        return $value === false || $value === '1';
    }

    /** Tagesstatus der Freigabe (ohne Eintrag: offen). @return array<string,string> work_date => Status */
    public function dayLocks(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT work_date, status FROM time_day_locks WHERE employee_id = :employee AND work_date BETWEEN :from AND :to');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Gespeicherte Betriebseinstellungen (Rohwerte; Standardwerte ergänzt OperationalSettingsService::resolve). @return array<string,string> */
    public function rawSettings(): array
    {
        return $this->pdo->query('SELECT setting_key, setting_value FROM operational_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return array<string,mixed>|null */
    public function details(string $employeeId, string $date): ?array
    {
        $statement = $this->pdo->prepare('SELECT d.* FROM time_day_details d WHERE d.employee_id = :employee AND d.work_date = :date');
        $statement->execute(['employee' => $employeeId, 'date' => $date]);
        return $statement->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function saveDetails(array $data): void
    {
        $this->pdo->prepare('INSERT INTO time_day_details (employee_id, work_date, travel_category, abroad, absence_minutes, overnight, meal_breakfast, meal_lunch, meal_dinner, location, notes, updated_by_user_id, updated_at)
            VALUES (:employee_id, :work_date, :travel_category, :abroad, :absence_minutes, :overnight, :meal_breakfast, :meal_lunch, :meal_dinner, :location, :notes, :updated_by_user_id, :updated_at)
            ON CONFLICT(employee_id, work_date) DO UPDATE SET travel_category = excluded.travel_category, abroad = excluded.abroad, absence_minutes = excluded.absence_minutes,
            overnight = excluded.overnight, meal_breakfast = excluded.meal_breakfast, meal_lunch = excluded.meal_lunch, meal_dinner = excluded.meal_dinner,
            location = excluded.location, notes = excluded.notes, updated_by_user_id = excluded.updated_by_user_id, updated_at = excluded.updated_at')->execute($data);
    }

    /** @return list<array<string,mixed>> */
    public function detailsRange(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM time_day_details WHERE employee_id = :employee AND work_date BETWEEN :from AND :to');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** Abwesenheiten (getrennt von Arbeitszeiten). Ohne Mitarbeiter: alle (Auswertung). @return list<array<string,mixed>> */
    public function absences(?string $employeeId, string $from, string $to): array
    {
        $sql = 'SELECT a.*, e.display_name AS employee_name FROM time_absences a JOIN employees e ON e.id = a.employee_id WHERE a.work_date BETWEEN :from AND :to';
        $parameters = ['from' => $from, 'to' => $to];
        if ($employeeId !== null) {
            $sql .= ' AND a.employee_id = :employee';
            $parameters['employee'] = $employeeId;
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY a.work_date, a.full_day DESC, a.created_at, a.rowid');
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function absence(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM time_absences WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function insertAbsence(array $data): void
    {
        $columns = array_keys($data);
        $this->pdo->prepare('INSERT INTO time_absences (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')')->execute($data);
    }

    /** @param array<string,mixed> $fields */
    public function updateAbsence(string $id, int $version, array $fields): bool
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($fields)));
        $statement = $this->pdo->prepare('UPDATE time_absences SET ' . $assignments . ', version = version + 1 WHERE id = :id AND version = :version');
        $statement->execute([...$fields, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    public function deleteAbsence(string $id, int $version): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM time_absences WHERE id = :id AND version = :version');
        $statement->execute(['id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** Dienstliche Privat-Pkw-Fahrten. @return list<array<string,mixed>> */
    public function trips(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT p.*, o.order_number, o.title AS order_title, cc.number AS cost_center_number, cc.name AS cost_center_name
            FROM time_private_trips p LEFT JOIN orders o ON o.id = p.order_id LEFT JOIN cost_centers cc ON cc.id = p.cost_center_id
            WHERE p.employee_id = :employee AND p.work_date BETWEEN :from AND :to ORDER BY p.work_date, p.created_at, p.rowid');
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function trip(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM time_private_trips WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function insertTrip(array $data): void
    {
        $columns = array_keys($data);
        $this->pdo->prepare('INSERT INTO time_private_trips (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')')->execute($data);
    }

    /** @param array<string,mixed> $fields */
    public function updateTrip(string $id, int $version, array $fields): bool
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($fields)));
        $statement = $this->pdo->prepare('UPDATE time_private_trips SET ' . $assignments . ', version = version + 1 WHERE id = :id AND version = :version');
        $statement->execute([...$fields, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    public function deleteTrip(string $id, int $version): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM time_private_trips WHERE id = :id AND version = :version');
        $statement->execute(['id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    public function expenses(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT x.*, o.order_number, cc.number AS cost_center_number FROM time_expenses x LEFT JOIN orders o ON o.id = x.order_id LEFT JOIN cost_centers cc ON cc.id = x.cost_center_id
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

    /** Buchungen eines Auftrags (alle Herkünfte). @return list<array<string,mixed>> */
    public function orderEntries(string $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ENTRY_SELECT . ', e.display_name AS employee_name FROM ' . self::ENTRY_FROM . ' JOIN employees e ON e.id = t.employee_id
            WHERE t.order_id = :order ORDER BY t.work_date, e.display_name, t.start_time IS NULL, t.start_time, t.id');
        $statement->execute(['order' => $orderId]);
        return $statement->fetchAll();
    }

    /** Auswertung für das Büro: Blöcke ab dem Vortag (Anteil nach Mitternacht). @return list<array<string,mixed>> */
    public function report(string $from, string $to, ?string $employeeId, ?string $orderId): array
    {
        $sql = 'SELECT ' . self::ENTRY_SELECT . ', e.display_name AS employee_name FROM ' . self::ENTRY_FROM . " JOIN employees e ON e.id = t.employee_id
            WHERE t.work_date BETWEEN date(:from, '-1 day') AND :to";
        $parameters = ['from' => $from, 'to' => $to];
        if ($employeeId !== null) {
            $sql .= ' AND t.employee_id = :employee';
            $parameters['employee'] = $employeeId;
        }
        if ($orderId !== null) {
            $sql .= ' AND t.order_id = :order';
            $parameters['order'] = $orderId;
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY t.work_date, e.display_name, t.start_time IS NULL, t.start_time, t.id LIMIT 5000');
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
