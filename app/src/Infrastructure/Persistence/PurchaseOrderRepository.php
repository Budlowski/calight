<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\ListQuery;
use PDO;

/** Datenzugriff für Bestellungen (Phase 3.4.1). Geldwerte bleiben Dezimalstrings. */
final readonly class PurchaseOrderRepository
{
    public const LIST_SORTS = ['updated' => 'desc', 'date' => 'desc', 'number' => 'asc', 'supplier' => 'asc', 'order' => 'asc', 'expected' => 'desc', 'actual' => 'desc'];
    public const LIST_FILTERS = ['supplier', 'order', 'origin', 'status', 'delivery', 'payment'];

    private const SELECT = "p.*, s.name AS supplier_name, s.supplier_number, o.order_number AS job_number, o.title AS job_title,
        u.display_name AS creator_name, iu.display_name AS issued_by_name,
        (SELECT group_concat(i.net_amount, '|') FROM purchase_order_invoices i WHERE i.purchase_order_id = p.id) AS invoice_amounts,
        (SELECT COUNT(*) FROM purchase_order_items it WHERE it.purchase_order_id = p.id) AS item_count";
    private const FROM = 'purchase_orders p JOIN suppliers s ON s.id = p.supplier_id LEFT JOIN orders o ON o.id = p.order_id
        JOIN users u ON u.id = p.created_by_user_id LEFT JOIN users iu ON iu.id = p.issued_by_user_id';
    // Nur zum Sortieren: Ist-Kosten aus direkt erfasstem Betrag oder Rechnungssumme (nie beides).
    private const ACTUAL_SORT = 'CAST(COALESCE(p.actual_net_total, (SELECT SUM(CAST(i.net_amount AS REAL)) FROM purchase_order_invoices i WHERE i.purchase_order_id = p.id)) AS REAL)';

    public function __construct(private PDO $pdo)
    {
    }

    /** @param bool $amountSort Sortierung nach Beträgen nur mit Kostenleserecht. */
    public function page(ListQuery $query, bool $amountSort): array
    {
        $page = (new Paginator($this->pdo))
            ->search($query, ['p.order_number', 'p.external_reference', 'p.title', 'p.notes', 's.name', 's.supplier_number', 'o.order_number', 'o.title']);
        $filters = [
            'supplier' => ['p.supplier_id = :po_supplier', null],
            'order' => ['p.order_id = :po_order', null],
            'origin' => ['p.origin = :po_origin', ['internal', 'external']],
            'status' => ['p.status = :po_status', ['draft', 'ordered', 'cancelled']],
            'delivery' => ['p.delivery_status = :po_delivery', ['pending', 'partial', 'delivered']],
            'payment' => ['p.payment_status = :po_payment', ['open', 'partial', 'paid']],
        ];
        foreach ($filters as $name => [$condition, $allowed]) {
            $value = $query->filter($name);
            if ($value === null || ($allowed !== null && !in_array($value, $allowed, true))) {
                continue;
            }
            preg_match('/:(\w+)/', $condition, $match);
            $page->where($condition, [$match[1] => $value]);
        }
        $sorts = [
            'updated' => 'p.updated_at',
            'date' => 'COALESCE(p.order_date, p.created_at)',
            'number' => 'p.order_number COLLATE NOCASE',
            'supplier' => 's.name COLLATE NOCASE',
            'order' => 'o.order_number COLLATE NOCASE',
            'expected' => $amountSort ? 'CAST(p.expected_net_total AS REAL)' : 'p.updated_at',
            'actual' => $amountSort ? self::ACTUAL_SORT : 'p.updated_at',
        ];
        return $page->fetch(self::SELECT, self::FROM, $sorts, 'p.id', $query);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE p.id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): void
    {
        $columns = array_keys($data);
        $this->pdo->prepare(sprintf('INSERT INTO purchase_orders (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns))))
            ->execute($data);
    }

    /**
     * Ändert Spalten nur bei passender Version (Konfliktschutz) und erhöht sie.
     *
     * @param array<string,mixed> $fields
     */
    public function update(string $id, int $version, array $fields, string $now): bool
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($fields)));
        $statement = $this->pdo->prepare('UPDATE purchase_orders SET ' . ($assignments !== '' ? $assignments . ', ' : '')
            . 'version = version + 1, updated_at = :updated_at WHERE id = :id AND version = :version');
        $statement->execute([...$fields, 'updated_at' => $now, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM purchase_orders WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function items(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = :id ORDER BY position');
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    /** @param list<array<string,mixed>> $items */
    public function replaceItems(string $id, array $items): void
    {
        $this->pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = :id')->execute(['id' => $id]);
        $insert = $this->pdo->prepare('INSERT INTO purchase_order_items (id, purchase_order_id, position, cost_item_id, article_number, description, quantity, unit, unit_price, line_total, notes)
            VALUES (:id, :purchase_order_id, :position, :cost_item_id, :article_number, :description, :quantity, :unit, :unit_price, :line_total, :notes)');
        foreach ($items as $item) {
            $insert->execute([...$item, 'purchase_order_id' => $id]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function materialRequests(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT r.id, r.description, r.notes, r.status, r.needed_by, o.order_number
            FROM purchase_order_material_requests l JOIN material_requests r ON r.id = l.material_request_id
            LEFT JOIN orders o ON o.id = r.order_id WHERE l.purchase_order_id = :id ORDER BY r.created_at');
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    public function linkMaterialRequest(string $id, string $requestId): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO purchase_order_material_requests (purchase_order_id, material_request_id) VALUES (:id, :request)')
            ->execute(['id' => $id, 'request' => $requestId]);
    }

    /** Offene Materialanforderungen als Grundlage neuer Bestellungen. @return list<array<string,mixed>> */
    public function openMaterialRequests(): array
    {
        return $this->pdo->query("SELECT r.id, r.order_id, r.description, r.notes, r.needed_by, r.created_at, o.order_number, o.title AS order_title,
                (SELECT COUNT(*) FROM purchase_order_material_requests l WHERE l.material_request_id = r.id) AS order_count
            FROM material_requests r JOIN orders o ON o.id = r.order_id WHERE r.status = 'open' ORDER BY r.created_at DESC LIMIT 200")->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function materialRequest(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM material_requests WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function documents(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT d.*, u.display_name AS uploaded_by_name FROM purchase_order_documents d
            JOIN users u ON u.id = d.uploaded_by_user_id WHERE d.purchase_order_id = :id ORDER BY d.created_at, d.id');
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function document(string $id, string $documentId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM purchase_order_documents WHERE id = :document AND purchase_order_id = :id');
        $statement->execute(['document' => $documentId, 'id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function insertDocument(array $data): void
    {
        $this->pdo->prepare('INSERT INTO purchase_order_documents (id, purchase_order_id, kind, revision, original_name, mime_type, size_bytes, sha256, storage_path, uploaded_by_user_id, created_at)
            VALUES (:id, :purchase_order_id, :kind, :revision, :original_name, :mime_type, :size_bytes, :sha256, :storage_path, :uploaded_by_user_id, :created_at)')->execute($data);
    }

    public function deleteDocument(string $documentId): void
    {
        $this->pdo->prepare('DELETE FROM purchase_order_documents WHERE id = :id')->execute(['id' => $documentId]);
    }

    /** @return list<array<string,mixed>> */
    public function invoices(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM purchase_order_invoices WHERE purchase_order_id = :id ORDER BY invoice_date, created_at');
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function insertInvoice(array $data): void
    {
        $this->pdo->prepare('INSERT INTO purchase_order_invoices (id, purchase_order_id, invoice_number, invoice_date, net_amount, vat_amount, gross_amount, document_id, notes, created_by_user_id, created_at)
            VALUES (:id, :purchase_order_id, :invoice_number, :invoice_date, :net_amount, :vat_amount, :gross_amount, :document_id, :notes, :created_by_user_id, :created_at)')->execute($data);
    }

    public function deleteInvoice(string $id, string $invoiceId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM purchase_order_invoices WHERE id = :invoice AND purchase_order_id = :id');
        $statement->execute(['invoice' => $invoiceId, 'id' => $id]);
        return $statement->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    public function allocations(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT a.*, o.order_number, o.title AS order_title FROM purchase_order_allocations a
            JOIN orders o ON o.id = a.order_id WHERE a.purchase_order_id = :id ORDER BY o.order_number');
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    /** @param list<array{id:string,order_id:string,amount:string}> $allocations */
    public function replaceAllocations(string $id, array $allocations, string $now): void
    {
        $this->pdo->prepare('DELETE FROM purchase_order_allocations WHERE purchase_order_id = :id')->execute(['id' => $id]);
        $insert = $this->pdo->prepare('INSERT INTO purchase_order_allocations (id, purchase_order_id, order_id, amount, created_at) VALUES (:id, :purchase_order_id, :order_id, :amount, :created_at)');
        foreach ($allocations as $allocation) {
            $insert->execute([...$allocation, 'purchase_order_id' => $id, 'created_at' => $now]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT h.*, u.display_name AS user_name FROM purchase_order_history h
            JOIN users u ON u.id = h.user_id WHERE h.purchase_order_id = :id ORDER BY h.created_at DESC, h.rowid DESC');
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    public function addHistory(string $id, string $historyId, string $field, ?string $old, string $new, string $note, string $userId, string $now): void
    {
        $this->pdo->prepare('INSERT INTO purchase_order_history (id, purchase_order_id, field, old_value, new_value, note, user_id, created_at)
            VALUES (:id, :purchase_order_id, :field, :old_value, :new_value, :note, :user_id, :created_at)')
            ->execute(['id' => $historyId, 'purchase_order_id' => $id, 'field' => $field, 'old_value' => $old, 'new_value' => $new, 'note' => $note, 'user_id' => $userId, 'created_at' => $now]);
    }

    /**
     * Grundlage der Kostenauswertung je Auftrag (Phase 3.5): alle nicht als Entwurf geführten
     * Bestellungen mit Bezug zum Auftrag – direkt oder über eine Kostenverteilung.
     *
     * @return list<array<string,mixed>>
     */
    public function forCostEvaluation(string $orderId): array
    {
        $statement = $this->pdo->prepare("SELECT p.id, p.order_number, p.order_id, p.actual_net_total, p.status,
                (SELECT group_concat(i.net_amount, '|') FROM purchase_order_invoices i WHERE i.purchase_order_id = p.id) AS invoice_amounts,
                (SELECT a.amount FROM purchase_order_allocations a WHERE a.purchase_order_id = p.id AND a.order_id = :order_a) AS allocated_amount,
                (SELECT COUNT(*) FROM purchase_order_allocations a WHERE a.purchase_order_id = p.id) AS allocation_count
            FROM purchase_orders p
            WHERE p.status <> 'draft' AND (p.order_id = :order_b OR EXISTS (SELECT 1 FROM purchase_order_allocations a WHERE a.purchase_order_id = p.id AND a.order_id = :order_c))
            ORDER BY p.order_number");
        $statement->execute(['order_a' => $orderId, 'order_b' => $orderId, 'order_c' => $orderId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(string $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SELECT . ' FROM ' . self::FROM . '
            WHERE p.order_id = :order_a OR EXISTS (SELECT 1 FROM purchase_order_allocations a WHERE a.purchase_order_id = p.id AND a.order_id = :order_b)
            ORDER BY p.created_at DESC');
        $statement->execute(['order_a' => $orderId, 'order_b' => $orderId]);
        return $statement->fetchAll();
    }

    // -------------------------------------------------------------------------
    // Stammdaten und Referenzen
    // -------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function supplier(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM suppliers WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function activeSuppliers(): array
    {
        return $this->pdo->query('SELECT id, supplier_number, name FROM suppliers WHERE active = 1 ORDER BY name COLLATE NOCASE')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function order(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, order_number, title, status FROM orders WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function orders(): array
    {
        return $this->pdo->query("SELECT o.id, o.order_number, o.title, o.status FROM orders o ORDER BY o.created_at DESC LIMIT 500")->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function costItem(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cost_items WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    /** Artikelsuche (alle Suchbegriffe müssen vorkommen) in Bezeichnung, Artikel- oder Lieferanten-Artikelnummer. @return list<array<string,mixed>> */
    public function catalog(string $search, ?string $supplierId): array
    {
        $sql = "SELECT id, article_number, name, unit, cost_price, supplier_id, supplier_article_number FROM cost_items
            WHERE active = 1 AND calculation_type IN ('material', 'external', 'other')";
        $parameters = ['supplier' => $supplierId ?? ''];
        foreach (array_slice(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $index => $term) {
            $pattern = '%' . addcslashes(mb_strtolower($term, 'UTF-8'), '%_\\') . '%';
            $sql .= " AND (calight_fold(name) LIKE :n{$index} ESCAPE '\\' OR calight_fold(article_number) LIKE :a{$index} ESCAPE '\\'"
                . " OR calight_fold(COALESCE(supplier_article_number, '')) LIKE :s{$index} ESCAPE '\\')";
            $parameters['n' . $index] = $pattern;
            $parameters['a' . $index] = $pattern;
            $parameters['s' . $index] = $pattern;
        }
        // Artikel des gewählten Lieferanten zuerst.
        $statement = $this->pdo->prepare($sql . ' ORDER BY CASE WHEN supplier_id = :supplier THEN 0 ELSE 1 END, name COLLATE NOCASE, id LIMIT 30');
        $statement->execute($parameters);
        return $statement->fetchAll();
    }
}
