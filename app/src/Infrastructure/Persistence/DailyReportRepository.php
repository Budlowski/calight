<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final readonly class DailyReportRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function all():array
    {
        return$this->pdo->query("SELECT r.*,o.order_number,o.title order_title,o.status order_status,c.customer_number,CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name||' '||c.last_name) END customer_name,u.display_name creator_name,(SELECT COUNT(*) FROM daily_report_time_entries t WHERE t.report_id=r.id) entry_count,(SELECT group_concat(t.duration_hours,'|') FROM daily_report_time_entries t WHERE t.report_id=r.id) duration_values,(SELECT group_concat(DISTINCT t.employee_id) FROM daily_report_time_entries t WHERE t.report_id=r.id) employee_ids,(SELECT group_concat(DISTINCT e.display_name) FROM daily_report_time_entries t JOIN employees e ON e.id=t.employee_id WHERE t.report_id=r.id) employee_names FROM daily_reports r JOIN orders o ON o.id=r.order_id JOIN customers c ON c.id=o.customer_id JOIN users u ON u.id=r.created_by_user_id ORDER BY r.report_date DESC,r.created_at DESC")->fetchAll();
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
