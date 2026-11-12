<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\ListQuery;
use PDO;

final readonly class DailyReportRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function all():array
    {
        return$this->pdo->query("SELECT r.*,o.order_number,o.title order_title,o.status order_status,c.customer_number,CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name||' '||c.last_name) END customer_name,u.display_name creator_name,(SELECT COUNT(*) FROM daily_report_time_entries t WHERE t.report_id=r.id) entry_count,(SELECT group_concat(t.duration_hours,'|') FROM daily_report_time_entries t WHERE t.report_id=r.id) duration_values,(SELECT group_concat(t.labor_cost,'|') FROM daily_report_time_entries t WHERE t.report_id=r.id) cost_values,(SELECT group_concat(DISTINCT t.employee_id) FROM daily_report_time_entries t WHERE t.report_id=r.id) employee_ids,(SELECT group_concat(DISTINCT e.display_name) FROM daily_report_time_entries t JOIN employees e ON e.id=t.employee_id WHERE t.report_id=r.id) employee_names FROM daily_reports r JOIN orders o ON o.id=r.order_id JOIN customers c ON c.id=o.customer_id JOIN users u ON u.id=r.created_by_user_id ORDER BY r.report_date DESC,r.created_at DESC")->fetchAll();
    }

    /** Sortierbare Spalten der Berichtsübersicht mit natürlicher Richtung. */
    public const LIST_SORTS = ['date' => 'desc', 'order' => 'asc', 'customer' => 'asc', 'title' => 'asc', 'hours' => 'desc', 'updated' => 'desc'];
    public const LIST_FILTERS = ['order', 'date', 'employee'];

    /**
     * @param bool $releasedOnly Techniker sehen nur Berichte freigegebener Aufträge.
     * @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,pageCount:int,sort:string,dir:string}
     */
    public function page(ListQuery $query, bool $releasedOnly): array
    {
        $customer = "CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END";
        $employeeNames = '(SELECT group_concat(DISTINCT e.display_name) FROM daily_report_time_entries t JOIN employees e ON e.id = t.employee_id WHERE t.report_id = r.id)';
        $page = (new Paginator($this->pdo))
            ->search($query, ['o.order_number', 'o.title', $customer, 'r.title', 'r.activities', $employeeNames]);
        if ($releasedOnly) {
            $page->where("o.status <> 'open'");
        }
        if ($query->filter('order') !== null) {
            $page->where('r.order_id = :report_order', ['report_order' => (string) $query->filter('order')]);
        }
        $date = (string) $query->filter('date');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) {
            $page->where('r.report_date = :report_date', ['report_date' => $date]);
        }
        if ($query->filter('employee') !== null) {
            $page->where('EXISTS (SELECT 1 FROM daily_report_time_entries t WHERE t.report_id = r.id AND t.employee_id = :report_employee)',
                ['report_employee' => (string) $query->filter('employee')]);
        }

        return $page->fetch(
            "r.id, r.order_id, r.report_date, r.title, r.activities, r.created_by_user_id, r.created_at, r.updated_at,
             o.order_number, o.title AS order_title, o.status AS order_status, c.customer_number, {$customer} AS customer_name,
             {$employeeNames} AS employee_names,
             (SELECT group_concat(t.duration_hours, '|') FROM daily_report_time_entries t WHERE t.report_id = r.id) AS duration_values,
             (SELECT group_concat(t.labor_cost, '|') FROM daily_report_time_entries t WHERE t.report_id = r.id) AS cost_values",
            'daily_reports r JOIN orders o ON o.id = r.order_id JOIN customers c ON c.id = o.customer_id',
            [
                'date' => 'r.report_date',
                'order' => 'o.order_number COLLATE NOCASE',
                'customer' => "{$customer} COLLATE NOCASE",
                'title' => "NULLIF(r.title, '') COLLATE NOCASE",
                // Nur zum Ordnen; angezeigte Stunden werden exakt summiert.
                'hours' => '(SELECT COALESCE(SUM(CAST(t.duration_hours AS REAL)), 0) FROM daily_report_time_entries t WHERE t.report_id = r.id)',
                'updated' => 'r.updated_at',
            ],
            // Mehrere Berichte am selben Tag: nach Anlagezeitpunkt, bei Gleichstand nach ID.
            'r.created_at || r.id',
            $query,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id):?array
    {
        $s=$this->pdo->prepare("SELECT r.*,o.order_number,o.title order_title,o.status order_status,o.basis_json,c.customer_number,CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name||' '||c.last_name) END customer_name,u.display_name creator_name FROM daily_reports r JOIN orders o ON o.id=r.order_id JOIN customers c ON c.id=o.customer_id JOIN users u ON u.id=r.created_by_user_id WHERE r.id=:id");$s->execute(['id'=>$id]);$row=$s->fetch();return is_array($row)?$row:null;
    }

    /** @return list<array<string,mixed>> */
    public function entries(string $reportId):array
    {
        $s=$this->pdo->prepare('SELECT t.*,e.display_name employee_name,e.personnel_number FROM daily_report_time_entries t JOIN employees e ON e.id=t.employee_id WHERE t.report_id=:id ORDER BY t.work_date,t.created_at');$s->execute(['id'=>$reportId]);return$s->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function insertReport(array $data):void
    {
        $s=$this->pdo->prepare('INSERT INTO daily_reports(id,order_id,report_date,title,activities,notes,created_by_user_id,version,created_at,updated_at) VALUES(:id,:order_id,:report_date,:title,:activities,:notes,:created_by_user_id,1,:created_at,:updated_at)');$s->execute($data);
    }

    /** @param array<string,mixed> $data */
    public function updateReport(array $data):bool
    {
        $s=$this->pdo->prepare('UPDATE daily_reports SET report_date=:report_date,title=:title,activities=:activities,notes=:notes,version=version+1,updated_at=:updated_at WHERE id=:id AND version=:expected_version');$s->execute($data);return$s->rowCount()===1;
    }

    /** @param list<array<string,mixed>> $entries */
    public function replaceEntries(string $reportId,array $entries):void
    {
        $d=$this->pdo->prepare('DELETE FROM daily_report_time_entries WHERE report_id=:id');$d->execute(['id'=>$reportId]);
        $s=$this->pdo->prepare('INSERT INTO daily_report_time_entries(id,report_id,employee_id,work_date,duration_hours,description,target_kind,target_basis_type,target_basis_id,target_node_id,hourly_cost_rate_snapshot,labor_cost,created_at,updated_at) VALUES(:id,:report_id,:employee_id,:work_date,:duration_hours,:description,:target_kind,:target_basis_type,:target_basis_id,:target_node_id,:hourly_cost_rate_snapshot,:labor_cost,:created_at,:updated_at)');
        foreach($entries as $entry)$s->execute($entry);
    }

    public function delete(string $id,int $version):bool
    {
        $s=$this->pdo->prepare('DELETE FROM daily_reports WHERE id=:id AND version=:version');$s->execute(['id'=>$id,'version'=>$version]);return$s->rowCount()===1;
    }

    /** @return list<array<string,mixed>> */
    public function attachments(string $reportId): array
    {
        $statement = $this->pdo->prepare('SELECT a.id, a.report_id, a.original_name, a.mime_type, a.size_bytes, a.created_at,
            a.uploaded_by_user_id, u.display_name AS uploaded_by_name
            FROM daily_report_attachments a JOIN users u ON u.id = a.uploaded_by_user_id
            WHERE a.report_id = :report ORDER BY a.created_at, a.id');
        $statement->execute(['report' => $reportId]);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function attachment(string $reportId, string $attachmentId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM daily_report_attachments WHERE id = :id AND report_id = :report');
        $statement->execute(['id' => $attachmentId, 'report' => $reportId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function attachmentCount(string $reportId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM daily_report_attachments WHERE report_id = :report');
        $statement->execute(['report' => $reportId]);
        return (int) $statement->fetchColumn();
    }

    /** @return list<string> relative Speicherpfade aller Anhänge eines Berichts */
    public function attachmentPaths(string $reportId): array
    {
        $statement = $this->pdo->prepare('SELECT storage_path FROM daily_report_attachments WHERE report_id = :report');
        $statement->execute(['report' => $reportId]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<string,mixed> $data */
    public function insertAttachment(array $data): void
    {
        $this->pdo->prepare('INSERT INTO daily_report_attachments
            (id, report_id, original_name, mime_type, size_bytes, sha256, storage_path, uploaded_by_user_id, created_at)
            VALUES (:id, :report_id, :original_name, :mime_type, :size_bytes, :sha256, :storage_path, :uploaded_by_user_id, :created_at)')
            ->execute($data);
    }

    public function deleteAttachment(string $reportId, string $attachmentId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM daily_report_attachments WHERE id = :id AND report_id = :report');
        $statement->execute(['id' => $attachmentId, 'report' => $reportId]);
        return $statement->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    public function acceptedAddenda(string $orderId):array
    {
        $s=$this->pdo->prepare('SELECT id,basis_json FROM order_addenda WHERE order_id=:id ORDER BY accepted_at');$s->execute(['id'=>$orderId]);return$s->fetchAll();
    }

    /** @return list<array{duration_hours:string,labor_cost:string}> */
    public function costRowsForOrder(string $orderId):array
    {
        $s=$this->pdo->prepare('SELECT t.duration_hours,t.labor_cost FROM daily_report_time_entries t JOIN daily_reports r ON r.id=t.report_id WHERE r.order_id=:id');$s->execute(['id'=>$orderId]);return$s->fetchAll();
    }
}
