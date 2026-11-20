<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

/** Datenzugriff für allgemeine Kostenstellen und ihre einfachen Auswertungen. */
final readonly class CostCenterRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM cost_centers ORDER BY number COLLATE NOCASE, id')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cost_centers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function numberTaken(string $number, ?string $exceptId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM cost_centers WHERE number = :number COLLATE NOCASE AND id <> :id');
        $statement->execute(['number' => $number, 'id' => $exceptId ?? '']);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): void
    {
        $this->pdo->prepare('INSERT INTO cost_centers (id, number, name, description, active, version, created_at, updated_at)
            VALUES (:id, :number, :name, :description, :active, 1, :now, :now)')->execute($data);
    }

    /** @param array<string,mixed> $data */
    public function update(string $id, int $version, array $data): bool
    {
        $statement = $this->pdo->prepare('UPDATE cost_centers SET number = :number, name = :name, description = :description, active = :active,
            version = version + 1, updated_at = :now WHERE id = :id AND version = :version');
        $statement->execute($data + ['id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /**
     * Einfache Auswertung je Kostenstelle für einen Zeitraum: Arbeitsminuten (zentrale
     * Zeitbuchungen nach Buchungstag), Auslagen (nach Datum) und Beschaffungskosten (Bestellungen
     * nach Bestelldatum, bekannte Ist-Kosten; mit Kostenverteilung nur der zugeordnete Betrag).
     *
     * @return array<string,array{minutes:int,expenseValues:list<string>,purchaseValues:list<array<string,mixed>>}>
     */
    public function usage(string $from, string $to): array
    {
        $result = [];
        $minutes = $this->pdo->prepare('SELECT cost_center_id, SUM(net_minutes) FROM time_entries WHERE cost_center_id IS NOT NULL AND work_date BETWEEN :from AND :to GROUP BY cost_center_id');
        $minutes->execute(['from' => $from, 'to' => $to]);
        foreach ($minutes->fetchAll(PDO::FETCH_NUM) as [$id, $sum]) {
            $result[$id]['minutes'] = (int) $sum;
        }
        $expenses = $this->pdo->prepare('SELECT cost_center_id, amount FROM time_expenses WHERE cost_center_id IS NOT NULL AND work_date BETWEEN :from AND :to');
        $expenses->execute(['from' => $from, 'to' => $to]);
        foreach ($expenses->fetchAll(PDO::FETCH_NUM) as [$id, $amount]) {
            $result[$id]['expenseValues'][] = (string) $amount;
        }
        // Bestellungen: Kostenverteilung hat Vorrang; sonst die vollen Ist-Kosten der Hauptzuordnung.
        $purchases = $this->pdo->prepare("SELECT COALESCE(a.cost_center_id, p.cost_center_id) AS cost_center_id, p.id, p.order_number, p.actual_net_total, p.invoiced_net_total,
                a.amount AS allocated_amount, (SELECT COUNT(*) FROM purchase_order_allocations x WHERE x.purchase_order_id = p.id) AS allocation_count
            FROM purchase_orders p LEFT JOIN purchase_order_allocations a ON a.purchase_order_id = p.id AND a.cost_center_id IS NOT NULL
            WHERE p.status = 'ordered' AND COALESCE(p.order_date, substr(p.created_at, 1, 10)) BETWEEN :from AND :to
              AND (a.cost_center_id IS NOT NULL OR (p.cost_center_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM purchase_order_allocations y WHERE y.purchase_order_id = p.id)))");
        $purchases->execute(['from' => $from, 'to' => $to]);
        foreach ($purchases->fetchAll() as $row) {
            $result[(string) $row['cost_center_id']]['purchaseValues'][] = $row;
        }
        return $result;
    }

    /** Wird die Kostenstelle irgendwo verwendet? (Dann nur deaktivieren, nie löschen.) */
    public function inUse(string $id): bool
    {
        $statement = $this->pdo->prepare('SELECT EXISTS (SELECT 1 FROM time_entries WHERE cost_center_id = :id)
            OR EXISTS (SELECT 1 FROM time_expenses WHERE cost_center_id = :id) OR EXISTS (SELECT 1 FROM purchase_orders WHERE cost_center_id = :id)
            OR EXISTS (SELECT 1 FROM purchase_order_allocations WHERE cost_center_id = :id) OR EXISTS (SELECT 1 FROM daily_reports WHERE cost_center_id = :id)');
        $statement->execute(['id' => $id]);
        return (bool) $statement->fetchColumn();
    }

    public function delete(string $id, int $version): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM cost_centers WHERE id = :id AND version = :version');
        $statement->execute(['id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }
}
