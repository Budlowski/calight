<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\ListQuery;
use PDO;

final readonly class WorkReportRepository
{
    /** Sortierbare Spalten der Übersicht mit natürlicher Richtung; "total" nur mit Preisrecht. */
    public const LIST_SORTS = ['updated' => 'desc', 'date' => 'desc', 'number' => 'asc', 'order' => 'asc', 'customer' => 'asc', 'status' => 'asc', 'total' => 'desc'];
    public const LIST_FILTERS = ['status', 'confirmation', 'order'];

    private const CUSTOMER_NAME = "CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END";
    private const SELECT = 'w.*, o.order_number, o.title AS order_title, o.status AS order_status, o.order_type, c.customer_number, %s AS customer_name,
        u.display_name AS creator_name, su.display_name AS submitted_by_name, pu.display_name AS processed_by_name,
        corrected.report_number AS corrected_number,
        (SELECT COUNT(*) FROM work_reports later WHERE later.correction_of_id = w.id) AS correction_count,
        (SELECT group_concat(l.hours, \'|\') FROM work_report_labor l WHERE l.report_id = w.id) AS hour_values';
    private const FROM = 'work_reports w JOIN orders o ON o.id = w.order_id JOIN customers c ON c.id = o.customer_id
        JOIN users u ON u.id = w.created_by_user_id LEFT JOIN users su ON su.id = w.submitted_by_user_id
        LEFT JOIN users pu ON pu.id = w.processed_by_user_id LEFT JOIN work_reports corrected ON corrected.id = w.correction_of_id';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param bool $releasedOnly Techniker sehen nur Rapporte freigegebener Aufträge.
     * @param bool $priceSort Sortierung nach Nettobetrag nur mit Preisrecht.
     * @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,pageCount:int,sort:string,dir:string}
     */
    public function page(ListQuery $query, bool $releasedOnly, bool $priceSort): array
    {
        $page = (new Paginator($this->pdo))
            ->search($query, ['w.report_number', 'w.description', 'w.contact_person', 'o.order_number', 'o.title', self::CUSTOMER_NAME, 'u.display_name']);
        if ($releasedOnly) {
            $page->where("o.status <> 'open'");
        }
        if (in_array($query->filter('status'), ['draft', 'submitted', 'processed'], true)) {
            $page->where('w.status = :work_status', ['work_status' => (string) $query->filter('status')]);
        }
        if (in_array($query->filter('confirmation'), ['none', 'confirmed'], true)) {
            $page->where('w.confirmation_status = :work_confirmation', ['work_confirmation' => (string) $query->filter('confirmation')]);
        }
        if ($query->filter('order') !== null) {
            $page->where('w.order_id = :work_order', ['work_order' => (string) $query->filter('order')]);
        }
        $sorts = [
            'updated' => 'w.updated_at',
            'date' => 'w.work_date',
            'number' => 'w.report_number COLLATE NOCASE',
            'order' => 'o.order_number COLLATE NOCASE',
            'customer' => self::CUSTOMER_NAME . ' COLLATE NOCASE',
            'status' => "CASE w.status WHEN 'draft' THEN 0 WHEN 'submitted' THEN 1 ELSE 2 END",
            // Nur zum Ordnen; ohne Preisrecht wird nicht nach Beträgen sortiert.
            'total' => $priceSort ? 'CAST(w.net_total AS REAL)' : 'w.updated_at',
        ];
        return $page->fetch(sprintf(self::SELECT, self::CUSTOMER_NAME), self::FROM, $sorts, 'w.id', $query);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . sprintf(self::SELECT, self::CUSTOMER_NAME) . ' FROM ' . self::FROM . ' WHERE w.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(string $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT ' . sprintf(self::SELECT, self::CUSTOMER_NAME) . ' FROM ' . self::FROM
            . ' WHERE w.order_id = :order ORDER BY w.work_date DESC, w.created_at DESC, w.id');
        $statement->execute(['order' => $orderId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function labor(string $reportId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM work_report_labor WHERE report_id = :id ORDER BY sort_order, id');
        $statement->execute(['id' => $reportId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function materials(string $reportId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM work_report_materials WHERE report_id = :id ORDER BY sort_order, id');
        $statement->execute(['id' => $reportId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function billingItems(string $reportId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM work_report_billing_items WHERE report_id = :id ORDER BY sort_order, id');
        $statement->execute(['id' => $reportId]);
        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): void
    {
        $this->pdo->prepare('INSERT INTO work_reports
            (id, order_id, work_date, work_date_to, contact_person, description, notes, status, correction_of_id, version,
             created_by_user_id, created_at, updated_at)
            VALUES (:id, :order_id, :work_date, :work_date_to, :contact_person, :description, :notes, \'draft\', :correction_of_id, 1,
             :created_by_user_id, :created_at, :updated_at)')->execute($data);
    }

    /** Ändert die Kopfdaten eines Entwurfs nur bei passender Version. @param array<string,mixed> $data */
    public function updateDraft(string $id, int $version, array $data): bool
    {
        $statement = $this->pdo->prepare("UPDATE work_reports SET work_date = :work_date, work_date_to = :work_date_to,
            contact_person = :contact_person, description = :description, notes = :notes, version = version + 1, updated_at = :updated_at
            WHERE id = :id AND version = :version AND status = 'draft'");
        $statement->execute([...$data, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** @param list<array<string,mixed>> $labor @param list<array<string,mixed>> $materials */
    public function replaceProof(string $reportId, array $labor, array $materials): void
    {
        $this->pdo->prepare('DELETE FROM work_report_labor WHERE report_id = :id')->execute(['id' => $reportId]);
        $this->pdo->prepare('DELETE FROM work_report_materials WHERE report_id = :id')->execute(['id' => $reportId]);
        $insertLabor = $this->pdo->prepare('INSERT INTO work_report_labor
            (id, report_id, employee_id, worker_name, hours, labor_item_id, labor_item_name, activity, sort_order)
            VALUES (:id, :report_id, :employee_id, :worker_name, :hours, :labor_item_id, :labor_item_name, :activity, :sort_order)');
        foreach ($labor as $row) {
            $insertLabor->execute($row);
        }
        $insertMaterial = $this->pdo->prepare('INSERT INTO work_report_materials (id, report_id, quantity, unit, description, sort_order)
            VALUES (:id, :report_id, :quantity, :unit, :description, :sort_order)');
        foreach ($materials as $row) {
            $insertMaterial->execute($row);
        }
    }

    public function submit(string $id, int $version, string $number, string $userId, string $at, string $hash): bool
    {
        $statement = $this->pdo->prepare("UPDATE work_reports SET status = 'submitted', report_number = :number, submitted_by_user_id = :user,
            submitted_at = :at, submitted_sha256 = :hash, version = version + 1, updated_at = :at
            WHERE id = :id AND version = :version AND status = 'draft'");
        $statement->execute(['number' => $number, 'user' => $userId, 'at' => $at, 'hash' => $hash, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** Kaufmännische Positionen ersetzen; nur im Status „eingereicht“. @param list<array<string,mixed>> $items */
    public function replaceBilling(string $id, int $version, array $items, string $at): bool
    {
        $statement = $this->pdo->prepare("UPDATE work_reports SET version = version + 1, updated_at = :at
            WHERE id = :id AND version = :version AND status = 'submitted'");
        $statement->execute(['at' => $at, 'id' => $id, 'version' => $version]);
        if ($statement->rowCount() !== 1) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM work_report_billing_items WHERE report_id = :id')->execute(['id' => $id]);
        $insert = $this->pdo->prepare('INSERT INTO work_report_billing_items
            (id, report_id, item_type, description, quantity, unit, unit_price, line_total, cost_item_id, sort_order)
            VALUES (:id, :report_id, :item_type, :description, :quantity, :unit, :unit_price, :line_total, :cost_item_id, :sort_order)');
        foreach ($items as $item) {
            $insert->execute($item);
        }
        return true;
    }

    public function process(string $id, int $version, string $netTotal, string $userId, string $at): bool
    {
        $statement = $this->pdo->prepare("UPDATE work_reports SET status = 'processed', net_total = :net_total, processed_by_user_id = :user,
            processed_at = :at, version = version + 1, updated_at = :at WHERE id = :id AND version = :version AND status = 'submitted'");
        $statement->execute(['net_total' => $netTotal, 'user' => $userId, 'at' => $at, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** Kaufmännische Bearbeitung wieder öffnen; der Leistungsnachweis bleibt unberührt. */
    public function reopen(string $id, int $version, string $at): bool
    {
        $statement = $this->pdo->prepare("UPDATE work_reports SET status = 'submitted', net_total = NULL, processed_by_user_id = NULL,
            processed_at = NULL, version = version + 1, updated_at = :at WHERE id = :id AND version = :version AND status = 'processed'");
        $statement->execute(['at' => $at, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** Kundenbestätigung einmalig erfassen, gebunden an die Prüfsumme des eingereichten Stands. */
    public function confirm(string $id, int $version, string $method, string $name, string $userId, string $at): bool
    {
        $statement = $this->pdo->prepare("UPDATE work_reports SET confirmation_status = 'confirmed', confirmation_method = :method,
            confirmed_by_name = :name, confirmed_at = :at, confirmation_recorded_by_user_id = :user, confirmation_sha256 = submitted_sha256,
            version = version + 1, updated_at = :at
            WHERE id = :id AND version = :version AND status <> 'draft' AND confirmation_status = 'none'");
        $statement->execute(['method' => $method, 'name' => $name, 'at' => $at, 'user' => $userId, 'id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    public function deleteDraft(string $id, int $version): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM work_reports WHERE id = :id AND version = :version AND status = 'draft'");
        $statement->execute(['id' => $id, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    /** Aktive Artikel für die kaufmännische Übernahme (ohne Einkaufspreise im Ergebnis des Service). @return list<array<string,mixed>> */
    public function catalog(string $search, ?string $type, int $limit): array
    {
        $sql = 'SELECT id, article_number, name, unit, calculation_type, cost_price, markup_percent FROM cost_items WHERE active = 1';
        $parameters = [];
        if ($type !== null) {
            $sql .= ' AND calculation_type = :type';
            $parameters['type'] = $type;
        }
        $index = 0;
        foreach (array_slice(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $term) {
            $name = 'term_' . $index++;
            $number = 'number_' . $index;
            $sql .= " AND (calight_fold(name) LIKE :{$name} ESCAPE '\\' OR calight_fold(article_number) LIKE :{$number} ESCAPE '\\')";
            $pattern = '%' . addcslashes(mb_strtolower($term, 'UTF-8'), '%_\\') . '%';
            $parameters[$name] = $pattern;
            $parameters[$number] = $pattern;
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY name COLLATE NOCASE, id LIMIT ' . max(1, min(200, $limit)));
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function costItem(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, unit, calculation_type, cost_price, markup_percent FROM cost_items WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array{id:string,display_name:string}> aktive Mitarbeiter ohne Kostensätze */
    public function activeEmployees(): array
    {
        return $this->pdo->query('SELECT id, display_name FROM employees WHERE active = 1 ORDER BY display_name COLLATE NOCASE, id')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function employee(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, display_name, active FROM employees WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}
