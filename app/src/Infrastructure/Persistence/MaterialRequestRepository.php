<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\ListQuery;
use PDO;

final readonly class MaterialRequestRepository
{
    /** Sortierbare Spalten der Übersicht mit natürlicher Richtung. */
    public const LIST_SORTS = ['created' => 'desc', 'needed' => 'asc', 'order' => 'asc', 'customer' => 'asc', 'status' => 'asc', 'updated' => 'desc'];
    public const LIST_FILTERS = ['status', 'order'];

    private const CUSTOMER_NAME = "CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END";
    private const SELECT = "m.*, o.order_number, o.title AS order_title, o.status AS order_status, r.report_date,
        u.display_name AS creator_name, cu.display_name AS completed_by_name, c.customer_number, %s AS customer_name";
    private const FROM = 'material_requests m JOIN orders o ON o.id = m.order_id JOIN customers c ON c.id = o.customer_id
        JOIN users u ON u.id = m.created_by_user_id LEFT JOIN users cu ON cu.id = m.completed_by_user_id
        LEFT JOIN daily_reports r ON r.id = m.daily_report_id';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param bool $releasedOnly Techniker sehen nur Anforderungen freigegebener Aufträge.
     * @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,pageCount:int,sort:string,dir:string}
     */
    public function page(ListQuery $query, bool $releasedOnly): array
    {
        $page = (new Paginator($this->pdo))
            ->search($query, ['m.description', 'm.notes', 'o.order_number', 'o.title', self::CUSTOMER_NAME, 'u.display_name']);
        if ($releasedOnly) {
            $page->where("o.status <> 'open'");
        }
        // Ohne Statusfilter zeigt die Übersicht offene Anforderungen; „all“ zeigt alle.
        $status = $query->filter('status');
        if ($status !== 'all') {
            $page->where('m.status = :material_status', ['material_status' => $status === 'done' ? 'done' : 'open']);
        }
        if ($query->filter('order') !== null) {
            $page->where('m.order_id = :material_order', ['material_order' => (string) $query->filter('order')]);
        }

        return $page->fetch(
            sprintf(self::SELECT, self::CUSTOMER_NAME),
            self::FROM,
            [
                'created' => 'm.created_at',
                'needed' => 'm.needed_by',
                'order' => 'o.order_number COLLATE NOCASE',
                'customer' => self::CUSTOMER_NAME . ' COLLATE NOCASE',
                'status' => "CASE m.status WHEN 'open' THEN 0 ELSE 1 END",
                'updated' => 'm.updated_at',
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
    public function forReport(string $reportId): array
    {
        $statement = $this->pdo->prepare('SELECT ' . sprintf(self::SELECT, self::CUSTOMER_NAME) . ' FROM ' . self::FROM
            . ' WHERE m.daily_report_id = :report ORDER BY m.created_at, m.id');
        $statement->execute(['report' => $reportId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> Offene Anforderungen eines Auftrags, älteste zuerst */
    public function openForOrder(string $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT ' . sprintf(self::SELECT, self::CUSTOMER_NAME) . ' FROM ' . self::FROM
            . " WHERE m.order_id = :order AND m.status = 'open' ORDER BY m.created_at, m.id");
        $statement->execute(['order' => $orderId]);
        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): void
    {
        $this->pdo->prepare('INSERT INTO material_requests
            (id, order_id, daily_report_id, description, notes, needed_by, status, created_by_user_id, version, created_at, updated_at)
            VALUES (:id, :order_id, :daily_report_id, :description, :notes, :needed_by, \'open\', :created_by_user_id, 1, :created_at, :updated_at)')
            ->execute($data);
    }

    /** Ändert Inhalt nur, solange die Anforderung offen ist und die Version passt. */
    public function update(string $id, int $version, string $description, string $notes, ?string $neededBy, string $updatedAt): bool
    {
        $statement = $this->pdo->prepare("UPDATE material_requests SET description = :description, notes = :notes, needed_by = :needed_by,
            version = version + 1, updated_at = :updated_at WHERE id = :id AND version = :version AND status = 'open'");
        $statement->execute(['description' => $description, 'notes' => $notes, 'needed_by' => $neededBy, 'updated_at' => $updatedAt, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    public function setStatus(string $id, int $version, string $status, ?string $userId, string $updatedAt): bool
    {
        $statement = $this->pdo->prepare('UPDATE material_requests SET status = :status, completed_by_user_id = :completed_by,
            completed_at = :completed_at, version = version + 1, updated_at = :updated_at WHERE id = :id AND version = :version');
        $statement->execute([
            'status' => $status,
            'completed_by' => $status === 'done' ? $userId : null,
            'completed_at' => $status === 'done' ? $updatedAt : null,
            'updated_at' => $updatedAt,
            'id' => $id,
            'version' => $version,
        ]);
        return $statement->rowCount() === 1;
    }

    /** Nur offene Anforderungen dürfen gelöscht werden; erledigte bleiben nachvollziehbar. */
    public function deleteOpen(string $id, int $version): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM material_requests WHERE id = :id AND version = :version AND status = 'open'");
        $statement->execute(['id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }
}
