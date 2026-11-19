<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

/** Allgemeine geschäftsbezogene Anhänge und ihre Zuordnungen (Phase 3.4.2). */
final readonly class AttachmentRepository
{
    /** Zulässige Zuordnungsziele => Tabelle (feste Werte, nie aus Eingaben). */
    public const TARGET_TABLES = ['customer' => 'customers', 'contact' => 'customer_contacts', 'project' => 'projects', 'offer' => 'offers', 'order' => 'orders'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): void
    {
        $this->pdo->prepare('INSERT INTO attachments (id, original_name, title, description, mime_type, size_bytes, sha256, storage_path, uploaded_by_user_id, created_at)
            VALUES (:id, :original_name, :title, :description, :mime_type, :size_bytes, :sha256, :storage_path, :uploaded_by_user_id, :created_at)')->execute($data);
    }

    public function link(string $attachmentId, string $type, string $targetId, string $userId, string $now): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO attachment_links (attachment_id, target_type, target_id, created_by_user_id, created_at)
            VALUES (:attachment, :type, :target, :user, :now)')
            ->execute(['attachment' => $attachmentId, 'type' => $type, 'target' => $targetId, 'user' => $userId, 'now' => $now]);
    }

    public function unlink(string $attachmentId, string $type, string $targetId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM attachment_links WHERE attachment_id = :attachment AND target_type = :type AND target_id = :target');
        $statement->execute(['attachment' => $attachmentId, 'type' => $type, 'target' => $targetId]);
        return $statement->rowCount() === 1;
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT a.*, u.display_name AS uploaded_by_name FROM attachments a JOIN users u ON u.id = a.uploaded_by_user_id WHERE a.id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @return list<array{target_type:string,target_id:string}> */
    public function links(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT target_type, target_id FROM attachment_links WHERE attachment_id = :id ORDER BY target_type, target_id');
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    /** Alle Anhänge mit ihren Zuordnungen (für die Leseprojektion). @return list<array<string,mixed>> */
    public function allWithLinks(): array
    {
        $rows = $this->pdo->query("SELECT a.*, u.display_name AS uploaded_by_name,
                (SELECT group_concat(l.target_type || ':' || l.target_id, '|') FROM attachment_links l WHERE l.attachment_id = a.id) AS link_list
            FROM attachments a JOIN users u ON u.id = a.uploaded_by_user_id ORDER BY a.created_at DESC")->fetchAll();
        return $rows;
    }

    /**
     * Zuordnungsziel mit Kontext (Kunde/Projekt/Auftrag des Objekts) – null, wenn es nicht existiert.
     *
     * @return array{label:string,href:string,customer_id:?string,project_id:?string,order_id:?string,status:?string}|null
     */
    public function target(string $type, string $id): ?array
    {
        $sql = match ($type) {
            'customer' => "SELECT COALESCE(NULLIF(company_name, ''), trim(first_name || ' ' || last_name)) AS name, customer_number AS number, id AS customer_id, NULL AS project_id, NULL AS order_id, NULL AS status FROM customers WHERE id = :id",
            'contact' => "SELECT trim(first_name || ' ' || last_name) AS name, NULL AS number, customer_id, NULL AS project_id, NULL AS order_id, NULL AS status FROM customer_contacts WHERE id = :id",
            'project' => 'SELECT name, project_number AS number, customer_id, id AS project_id, NULL AS order_id, NULL AS status FROM projects WHERE id = :id',
            'offer' => "SELECT COALESCE(NULLIF(list_title, ''), 'Angebot') AS name, offer_number AS number, customer_id, project_id, order_id, status FROM offers WHERE id = :id",
            'order' => 'SELECT title AS name, order_number AS number, customer_id, project_id, id AS order_id, status FROM orders WHERE id = :id',
            default => null,
        };
        if ($sql === null) {
            return null;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }
        $href = match ($type) {
            'customer' => '/customers/' . rawurlencode($id),
            // Ansprechpartner haben keine eigene Seite; sie stehen beim Kunden.
            'contact' => '/customers/' . rawurlencode((string) $row['customer_id']),
            'project' => '/projects/' . rawurlencode($id),
            'offer' => '/offers/' . rawurlencode($id),
            'order' => '/orders/' . rawurlencode($id),
        };
        $prefix = ['customer' => 'Kunde', 'contact' => 'Ansprechpartner', 'project' => 'Projekt', 'offer' => 'Angebot', 'order' => 'Auftrag'][$type];
        $label = trim((string) ($row['number'] ?? '') . ' ' . (string) $row['name']);
        return [
            'label' => $prefix . ' ' . ($label !== '' ? $label : '–'),
            'href' => $href,
            'customer_id' => $row['customer_id'] !== null ? (string) $row['customer_id'] : null,
            'project_id' => $row['project_id'] !== null ? (string) $row['project_id'] : null,
            'order_id' => $row['order_id'] !== null ? (string) $row['order_id'] : null,
            'status' => $row['status'] !== null ? (string) $row['status'] : null,
        ];
    }
}
