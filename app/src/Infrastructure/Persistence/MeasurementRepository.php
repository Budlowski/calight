<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\ListQuery;
use PDO;

final readonly class MeasurementRepository
{
    /** Sortierbare Spalten der Übersicht mit natürlicher Richtung. */
    public const LIST_SORTS = ['updated' => 'desc', 'date' => 'desc', 'number' => 'asc', 'order' => 'asc', 'customer' => 'asc', 'status' => 'asc', 'total' => 'desc'];
    public const LIST_FILTERS = ['status', 'order'];

    private const CUSTOMER_NAME = "CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END";
    private const SELECT = 'm.*, o.order_number, o.title AS order_title, o.status AS order_status, c.customer_number, %s AS customer_name,
        u.display_name AS creator_name, cu.display_name AS completed_by_name, corrected.measurement_number AS corrected_number,
        (SELECT COUNT(*) FROM measurement_items i WHERE i.measurement_id = m.id) AS item_count';
    private const FROM = 'measurements m JOIN orders o ON o.id = m.order_id JOIN customers c ON c.id = o.customer_id
        JOIN users u ON u.id = m.created_by_user_id LEFT JOIN users cu ON cu.id = m.completed_by_user_id
        LEFT JOIN measurements corrected ON corrected.id = m.correction_of_id';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param bool $releasedOnly Techniker sehen nur Aufmaße freigegebener Aufträge.
     * @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,pageCount:int,sort:string,dir:string}
     */
    public function page(ListQuery $query, bool $releasedOnly): array
    {
        $page = (new Paginator($this->pdo))
            ->search($query, ['m.measurement_number', 'm.title', 'o.order_number', 'o.title', self::CUSTOMER_NAME]);
        if ($releasedOnly) {
            $page->where("o.status <> 'open'");
        }
        if (in_array($query->filter('status'), ['draft', 'completed'], true)) {
            $page->where('m.status = :measurement_status', ['measurement_status' => (string) $query->filter('status')]);
        }
        if ($query->filter('order') !== null) {
            $page->where('m.order_id = :measurement_order', ['measurement_order' => (string) $query->filter('order')]);
        }
        return $page->fetch(
            sprintf(self::SELECT, self::CUSTOMER_NAME),
            self::FROM,
            [
                'updated' => 'm.updated_at',
                'date' => 'm.measurement_date',
                'number' => 'm.measurement_number COLLATE NOCASE',
                'order' => 'o.order_number COLLATE NOCASE',
                'customer' => self::CUSTOMER_NAME . ' COLLATE NOCASE',
                'status' => "CASE m.status WHEN 'draft' THEN 0 ELSE 1 END",
                // Nur zum Ordnen; Entwürfe haben noch keine festgeschriebene Summe.
                'total' => 'CAST(m.net_total AS REAL)',
            ],
            'm.id',
            $query,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . sprintf(self::SELECT, self::CUSTOMER_NAME) . ' FROM ' . self::FROM . ' WHERE m.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(string $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT ' . sprintf(self::SELECT, self::CUSTOMER_NAME) . ' FROM ' . self::FROM
            . ' WHERE m.order_id = :order ORDER BY m.measurement_date DESC, m.created_at DESC, m.id');
        $statement->execute(['order' => $orderId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function items(string $measurementId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM measurement_items WHERE measurement_id = :id ORDER BY sort_order, id');
        $statement->execute(['id' => $measurementId]);
        return $statement->fetchAll();
    }

    /**
     * Mengen aller abgeschlossenen Aufmaße eines Auftrags je Ziel, ohne das angegebene Aufmaß.
     *
     * @return list<array{source_type:string,source_id:string,node_id:string,quantity:string}>
     */
    public function completedQuantities(string $orderId, ?string $excludeId = null, ?string $completedBefore = null): array
    {
        // Für ein abgeschlossenes Aufmaß zählen nur die vorher abgeschlossenen als „bereits aufgemessen“.
        $statement = $this->pdo->prepare("SELECT i.source_type, i.source_id, i.node_id, i.quantity
            FROM measurement_items i JOIN measurements m ON m.id = i.measurement_id
            WHERE m.order_id = :order AND m.status = 'completed' AND m.id <> :exclude
              AND (:before IS NULL OR m.completed_at < :before_value)");
        $statement->execute(['order' => $orderId, 'exclude' => $excludeId ?? '', 'before' => $completedBefore, 'before_value' => $completedBefore ?? '']);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function sourceTotals(string $measurementId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM measurement_source_totals WHERE measurement_id = :id ORDER BY source_type DESC, label');
        $statement->execute(['id' => $measurementId]);
        return $statement->fetchAll();
    }

    /**
     * Angenommene Nachträge eines Auftrags mit ihrer Nachtragsnummer.
     *
     * @return list<array{id:string,basis_json:string,offer_number:?string,revision_number:int}>
     */
    public function acceptedAddenda(string $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT a.id, a.basis_json, COALESCE(o.offer_number, root.offer_number) AS offer_number, o.revision_number
            FROM order_addenda a JOIN offers o ON o.id = a.offer_revision_id LEFT JOIN offers root ON root.id = a.revision_root_id
            WHERE a.order_id = :order ORDER BY a.accepted_at, a.id');
        $statement->execute(['order' => $orderId]);
        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): void
    {
        $this->pdo->prepare('INSERT INTO measurements
            (id, order_id, title, measurement_date, notes, status, correction_of_id, version, created_by_user_id, created_at, updated_at)
            VALUES (:id, :order_id, :title, :measurement_date, :notes, \'draft\', :correction_of_id, 1, :created_by_user_id, :created_at, :updated_at)')
            ->execute($data);
    }

    /** Ändert einen Entwurf nur bei passender Version (optimistischer Konkurrenzschutz). */
    public function updateDraft(string $id, int $version, string $title, string $date, string $notes, string $updatedAt): bool
    {
        $statement = $this->pdo->prepare("UPDATE measurements SET title = :title, measurement_date = :date, notes = :notes,
            version = version + 1, updated_at = :updated_at WHERE id = :id AND version = :version AND status = 'draft'");
        $statement->execute(['title' => $title, 'date' => $date, 'notes' => $notes, 'updated_at' => $updatedAt, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** @param list<array<string,mixed>> $items */
    public function replaceItems(string $measurementId, array $items): void
    {
        $this->pdo->prepare('DELETE FROM measurement_items WHERE measurement_id = :id')->execute(['id' => $measurementId]);
        $insert = $this->pdo->prepare('INSERT INTO measurement_items
            (id, measurement_id, source_type, source_id, node_id, position_number, short_text, unit, effective_type, agreed_quantity,
             unit_price, quantity, line_total, overrun_confirmed, sort_order)
            VALUES (:id, :measurement_id, :source_type, :source_id, :node_id, :position_number, :short_text, :unit, :effective_type,
             :agreed_quantity, :unit_price, :quantity, :line_total, :overrun_confirmed, :sort_order)');
        foreach ($items as $item) {
            $insert->execute($item);
        }
    }

    /** @param list<string> $keys Zielschlüssel "<quelle>:<quell-id>:<knoten-id>" */
    public function markOverrunsConfirmed(string $measurementId, array $keys): void
    {
        $statement = $this->pdo->prepare("UPDATE measurement_items SET overrun_confirmed = 1
            WHERE measurement_id = :id AND source_type || ':' || source_id || ':' || node_id = :key");
        foreach ($keys as $key) {
            $statement->execute(['id' => $measurementId, 'key' => $key]);
        }
    }

    /** @param list<array<string,string>> $totals */
    public function complete(string $id, int $version, string $number, string $netTotal, string $userId, string $completedAt, array $totals): bool
    {
        $statement = $this->pdo->prepare("UPDATE measurements SET status = 'completed', measurement_number = :number, net_total = :net_total,
            completed_by_user_id = :user, completed_at = :completed_at, version = version + 1, updated_at = :completed_at
            WHERE id = :id AND version = :version AND status = 'draft'");
        $statement->execute(['number' => $number, 'net_total' => $netTotal, 'user' => $userId, 'completed_at' => $completedAt, 'id' => $id, 'version' => $version]);
        if ($statement->rowCount() !== 1) {
            return false;
        }
        $insert = $this->pdo->prepare('INSERT INTO measurement_source_totals
            (measurement_id, source_type, source_id, label, items_total, adjustable_total, adjustment_amount, net_total)
            VALUES (:measurement_id, :source_type, :source_id, :label, :items_total, :adjustable_total, :adjustment_amount, :net_total)');
        foreach ($totals as $total) {
            $insert->execute(['measurement_id' => $id, ...$total]);
        }
        return true;
    }

    public function deleteDraft(string $id, int $version): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM measurements WHERE id = :id AND version = :version AND status = 'draft'");
        $statement->execute(['id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }
}
