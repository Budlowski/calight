<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final readonly class MasterDataRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function trailingTexts(): array
    {
        return $this->pdo->query('SELECT * FROM trailing_texts ORDER BY code COLLATE NOCASE')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function trailingText(string $id): ?array
    {
        return $this->one('SELECT * FROM trailing_texts WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function createTrailingText(array $data): void
    {
        $this->execute(
            'INSERT INTO trailing_texts (id, code, name, created_at, updated_at)
             VALUES (:id, :code, :name, :created_at, :updated_at)',
            $data,
        );
    }

    /** @param array<string,mixed> $data */
    public function updateTrailingText(array $data): bool
    {
        return $this->execute(
            'UPDATE trailing_texts SET code = :code, name = :name, updated_at = :updated_at WHERE id = :id',
            $data,
        ) === 1;
    }

    public function deleteTrailingText(string $id): bool
    {
        return $this->execute('DELETE FROM trailing_texts WHERE id = :id', ['id' => $id]) === 1;
    }

    public function trailingTextReferenced(string $id): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT EXISTS(SELECT 1 FROM service_templates WHERE trailing_text_id = :id)'
        );
        $statement->execute(['id' => $id]);
        return (bool) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function categories(bool $includeArchived = true): array
    {
        $sql = 'SELECT * FROM master_categories';
        if (!$includeArchived) {
            $sql .= ' WHERE active = 1';
        }
        $sql .= ' ORDER BY sort_order, name COLLATE NOCASE';
        return $this->pdo->query($sql)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function category(string $id): ?array
    {
        return $this->one('SELECT * FROM master_categories WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function createCategory(array $data): void
    {
        $this->execute(
            'INSERT INTO master_categories (id, parent_id, name, description, sort_order, created_at, updated_at)
             VALUES (:id, :parent_id, :name, :description, :sort_order, :created_at, :updated_at)',
            $data,
        );
    }

    /** @param array<string,mixed> $data */
    public function updateCategory(array $data): bool
    {
        return $this->execute(
            'UPDATE master_categories SET parent_id = :parent_id, name = :name, description = :description,
             sort_order = :sort_order, updated_at = :updated_at WHERE id = :id',
            $data,
        ) === 1;
    }

    public function categoryWouldCycle(string $id, ?string $parentId): bool
    {
        if ($parentId === null) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'WITH RECURSIVE descendants(id) AS (
                SELECT id FROM master_categories WHERE id = :id
                UNION ALL
                SELECT c.id FROM master_categories c JOIN descendants d ON c.parent_id = d.id
             ) SELECT 1 FROM descendants WHERE id = :parent_id LIMIT 1'
        );
        $statement->execute(['id' => $id, 'parent_id' => $parentId]);
        return (bool) $statement->fetchColumn();
    }

    public function categoryHasActiveReferences(string $id): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT EXISTS(SELECT 1 FROM master_categories WHERE parent_id = :id AND active = 1)
             OR EXISTS(SELECT 1 FROM cost_items WHERE category_id = :id AND active = 1)'
        );
        $statement->execute(['id' => $id]);
        return (bool) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function suppliers(bool $includeArchived = true): array
    {
        $sql = 'SELECT * FROM suppliers';
        if (!$includeArchived) {
            $sql .= ' WHERE active = 1';
        }
        return $this->pdo->query($sql . ' ORDER BY name COLLATE NOCASE')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function supplier(string $id): ?array
    {
        return $this->one('SELECT * FROM suppliers WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function createSupplier(array $data): void
    {
        $this->execute(
            'INSERT INTO suppliers (id, supplier_number, name, contact, created_at, updated_at)
             VALUES (:id, :supplier_number, :name, :contact, :created_at, :updated_at)',
            $data,
        );
    }

    /** @param array<string,mixed> $data */
    public function updateSupplier(array $data): bool
    {
        return $this->execute(
            'UPDATE suppliers SET supplier_number = :supplier_number, name = :name, contact = :contact,
             updated_at = :updated_at WHERE id = :id',
            $data,
        ) === 1;
    }

    public function supplierHasActiveItems(string $id): bool
    {
        $statement = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM cost_items WHERE supplier_id = :id AND active = 1)');
        $statement->execute(['id' => $id]);
        return (bool) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function costItems(bool $includeArchived = true): array
    {
        $sql = 'SELECT i.*, c.name AS category_name, s.name AS supplier_name
                FROM cost_items i
                LEFT JOIN master_categories c ON c.id = i.category_id
                LEFT JOIN suppliers s ON s.id = i.supplier_id';
        if (!$includeArchived) {
            $sql .= ' WHERE i.active = 1';
        }
        return $this->pdo->query($sql . ' ORDER BY i.name COLLATE NOCASE')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function costItem(string $id): ?array
    {
        return $this->one(
            'SELECT i.*, c.name AS category_name, s.name AS supplier_name
             FROM cost_items i
             LEFT JOIN master_categories c ON c.id = i.category_id
             LEFT JOIN suppliers s ON s.id = i.supplier_id
             WHERE i.id = :id',
            ['id' => $id],
        );
    }

    /** @param array<string,mixed> $data */
    public function createCostItem(array $data): void
    {
        $this->execute(
            'INSERT INTO cost_items
             (id, article_number, name, description, category_id, calculation_type, unit, cost_price,
              markup_percent, supplier_id, supplier_article_number, copied_from_id, created_at, updated_at)
             VALUES
             (:id, :article_number, :name, :description, :category_id, :calculation_type, :unit, :cost_price,
              :markup_percent, :supplier_id, :supplier_article_number, :copied_from_id, :created_at, :updated_at)',
            $data,
        );
    }

    /** @param array<string,mixed> $data */
    public function updateCostItem(array $data): bool
    {
        return $this->execute(
            'UPDATE cost_items SET article_number = :article_number, name = :name, description = :description,
             category_id = :category_id, calculation_type = :calculation_type, unit = :unit,
             cost_price = :cost_price, markup_percent = :markup_percent, supplier_id = :supplier_id,
             supplier_article_number = :supplier_article_number, updated_at = :updated_at WHERE id = :id',
            $data,
        ) === 1;
    }

    /** @return list<array<string,mixed>> */
    public function serviceTemplates(bool $includeArchived = true): array
    {
        $sql = 'SELECT * FROM service_templates';
        if (!$includeArchived) {
            $sql .= ' WHERE active = 1';
        }
        return $this->pdo->query($sql . ' ORDER BY short_text COLLATE NOCASE')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function serviceTemplate(string $id): ?array
    {
        return $this->one('SELECT * FROM service_templates WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function serviceComponents(string $serviceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM service_template_components WHERE service_template_id = :service_id ORDER BY sort_order'
        );
        $statement->execute(['service_id' => $serviceId]);
        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function createServiceTemplate(array $data): void
    {
        $this->execute(
            'INSERT INTO service_templates (id, article_number, short_text, long_text, unit, trailing_text_id, copied_from_id, created_at, updated_at)
             VALUES (:id, :article_number, :short_text, :long_text, :unit, :trailing_text_id, :copied_from_id, :created_at, :updated_at)',
            $data,
        );
    }

    /** @param array<string,mixed> $data */
    public function updateServiceTemplate(array $data): bool
    {
        return $this->execute(
            'UPDATE service_templates SET article_number = :article_number, short_text = :short_text, long_text = :long_text,
             unit = :unit, trailing_text_id=:trailing_text_id, updated_at = :updated_at WHERE id = :id',
            $data,
        ) === 1;
    }

    /** @param list<array<string,mixed>> $components */
    public function replaceServiceComponents(string $serviceId, array $components): void
    {
        $statement = $this->pdo->prepare('DELETE FROM service_template_components WHERE service_template_id = :service_id');
        $statement->execute(['service_id' => $serviceId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO service_template_components
             (id, service_template_id, master_data_id, source_article_number, name, calculation_type, unit, quantity, cost_price,
              markup_percent, price_mode, sort_order, created_at, updated_at)
             VALUES
             (:id, :service_template_id, :master_data_id, :source_article_number, :name, :calculation_type, :unit, :quantity, :cost_price,
              :markup_percent, :price_mode, :sort_order, :created_at, :updated_at)'
        );
        foreach ($components as $component) {
            $insert->execute($component);
        }
    }

    public function setActive(string $table, string $id, bool $active, string $updatedAt): bool
    {
        if (!in_array($table, ['master_categories', 'suppliers', 'cost_items', 'service_templates'], true)) {
            return false;
        }
        $statement = $this->pdo->prepare(
            sprintf('UPDATE %s SET active = :active, updated_at = :updated_at WHERE id = :id', $table)
        );
        $statement->execute(['active' => $active ? 1 : 0, 'updated_at' => $updatedAt, 'id' => $id]);
        return $statement->rowCount() === 1;
    }

    public function beginImmediate(): void
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

    /** @param array<string,mixed> $parameters */
    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $parameters */
    private function execute(string $sql, array $parameters): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount();
    }
}
