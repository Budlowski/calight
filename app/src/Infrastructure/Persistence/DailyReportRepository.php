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
        return$this->pdo->query("SELECT r.*,o.order_number,o.title order_title,o.status order_status,cc.number cost_center_number,cc.name cost_center_name,c.customer_number,CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name||' '||c.last_name) END customer_name,u.display_name creator_name,(SELECT COUNT(*) FROM daily_report_time_entries t WHERE t.report_id=r.id) entry_count,(SELECT group_concat(t.duration_hours,'|') FROM daily_report_time_entries t WHERE t.report_id=r.id) duration_values,(SELECT group_concat(t.labor_cost,'|') FROM daily_report_time_entries t WHERE t.report_id=r.id) cost_values,(SELECT group_concat(DISTINCT t.employee_id) FROM daily_report_time_entries t WHERE t.report_id=r.id) employee_ids,(SELECT group_concat(DISTINCT e.display_name) FROM daily_report_time_entries t JOIN employees e ON e.id=t.employee_id WHERE t.report_id=r.id) employee_names FROM daily_reports r LEFT JOIN orders o ON o.id=r.order_id LEFT JOIN cost_centers cc ON cc.id=r.cost_center_id LEFT JOIN customers c ON c.id=r.customer_id JOIN users u ON u.id=r.created_by_user_id ORDER BY r.report_date DESC,r.created_at DESC")->fetchAll();
    }

    /** Sortierbare Spalten der Berichtsübersicht mit natürlicher Richtung. */
    public const LIST_SORTS = ['date' => 'desc', 'order' => 'asc', 'customer' => 'asc', 'title' => 'asc', 'hours' => 'desc', 'updated' => 'desc'];
    public const LIST_FILTERS = ['order', 'date', 'employee', 'customer', 'project', 'assignment', 'cost_center', 'status'];

    /**
     * @param string|null $technicianId Techniker: eigene Berichte sowie Berichte freigegebener Aufträge.
     * @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,pageCount:int,sort:string,dir:string}
     */
    public function page(ListQuery $query, ?string $technicianId): array
    {
        $customer = "CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END";
        $employeeNames = '(SELECT group_concat(DISTINCT e.display_name) FROM daily_report_time_entries t JOIN employees e ON e.id = t.employee_id WHERE t.report_id = r.id)';
        $page = (new Paginator($this->pdo))
            ->search($query, ['o.order_number', 'o.title', 'cc.number', 'cc.name', $customer, 'c.customer_number', 'p.project_number', 'p.name', 'r.title', 'r.activities', $employeeNames]);
        if ($technicianId !== null) {
            $page->where("(r.created_by_user_id = :report_technician OR (r.order_id IS NOT NULL AND o.status <> 'open'))", ['report_technician' => $technicianId]);
        }
        if ($query->filter('customer') !== null) {
            $page->where('r.customer_id = :report_customer', ['report_customer' => (string) $query->filter('customer')]);
        }
        if ($query->filter('project') !== null) {
            $page->where('r.project_id = :report_project', ['report_project' => (string) $query->filter('project')]);
        }
        // Zuordnung: Auftrag, Kostenstelle, ohne Auftrag (bisheriger Filter) oder ganz ohne Zuordnung.
        $assignment = $query->filter('assignment');
        $conditions = ['with_order' => 'r.order_id IS NOT NULL', 'without_order' => 'r.order_id IS NULL', 'with_cost_center' => 'r.cost_center_id IS NOT NULL',
            'unassigned' => 'r.order_id IS NULL AND r.cost_center_id IS NULL'];
        if (isset($conditions[(string) $assignment])) {
            $page->where($conditions[(string) $assignment]);
        }
        // Status: einzelner Status oder „open“ (Entwurf bzw. zur Korrektur geöffnet).
        $status = (string) $query->filter('status');
        if ($status === 'open') {
            $page->where("r.status IN ('draft', 'reopened')");
        } elseif (in_array($status, ['draft', 'submitted', 'approved', 'reopened', 'cancelled'], true)) {
            $page->where('r.status = :report_status', ['report_status' => $status]);
        }
        if ($query->filter('cost_center') !== null) {
            $page->where('r.cost_center_id = :report_cost_center', ['report_cost_center' => (string) $query->filter('cost_center')]);
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
            "r.id, r.status, r.customer_id, r.project_id, r.order_id, r.cost_center_id, cc.number AS cost_center_number, cc.name AS cost_center_name, r.order_assigned_at, r.labor_counts_for_order, r.report_date, r.title, r.activities, r.created_by_user_id, r.created_at, r.updated_at,
             o.order_number, o.title AS order_title, o.status AS order_status, c.customer_number, {$customer} AS customer_name, p.project_number, p.name AS project_name,
             {$employeeNames} AS employee_names,
             (SELECT group_concat(t.duration_hours, '|') FROM daily_report_time_entries t WHERE t.report_id = r.id) AS duration_values,
             (SELECT group_concat(t.labor_cost, '|') FROM daily_report_time_entries t WHERE t.report_id = r.id) AS cost_values",
            'daily_reports r LEFT JOIN orders o ON o.id = r.order_id LEFT JOIN cost_centers cc ON cc.id = r.cost_center_id LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN projects p ON p.id = r.project_id',
            [
                'date' => 'r.report_date',
                'order' => "COALESCE(o.order_number, cc.number, '') COLLATE NOCASE",
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
        $s=$this->pdo->prepare("SELECT r.*,o.order_number,o.title order_title,o.status order_status,o.basis_json,o.project_id order_project_id,cc.number cost_center_number,cc.name cost_center_name,c.customer_number,CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name||' '||c.last_name) END customer_name,p.project_number,p.name project_name,u.display_name creator_name,ua.display_name order_assigned_by_name FROM daily_reports r LEFT JOIN orders o ON o.id=r.order_id LEFT JOIN cost_centers cc ON cc.id=r.cost_center_id LEFT JOIN customers c ON c.id=r.customer_id LEFT JOIN projects p ON p.id=r.project_id JOIN users u ON u.id=r.created_by_user_id LEFT JOIN users ua ON ua.id=r.order_assigned_by_user_id WHERE r.id=:id");$s->execute(['id'=>$id]);$row=$s->fetch();return is_array($row)?$row:null;
    }

    /** @return list<array<string,mixed>> */
    public function entries(string $reportId):array
    {
        $s=$this->pdo->prepare('SELECT t.*,e.display_name employee_name,e.personnel_number FROM daily_report_time_entries t JOIN employees e ON e.id=t.employee_id WHERE t.report_id=:id ORDER BY t.work_date,t.created_at');$s->execute(['id'=>$reportId]);return$s->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function insertReport(array $data):void
    {
        $s=$this->pdo->prepare('INSERT INTO daily_reports(id,customer_id,project_id,order_id,cost_center_id,labor_counts_for_order,report_date,title,activities,notes,created_by_user_id,version,created_at,updated_at) VALUES(:id,:customer_id,:project_id,:order_id,:cost_center_id,:labor_counts_for_order,:report_date,:title,:activities,:notes,:created_by_user_id,1,:created_at,:updated_at)');$s->execute($data);
    }

    /** @param array<string,mixed> $data */
    public function updateReport(array $data):bool
    {
        $s=$this->pdo->prepare('UPDATE daily_reports SET report_date=:report_date,title=:title,activities=:activities,notes=:notes,version=version+1,updated_at=:updated_at WHERE id=:id AND version=:expected_version');$s->execute($data);return$s->rowCount()===1;
    }

    /** @param list<array<string,mixed>> $entries */
    public function replaceEntries(string $reportId,array $entries):void
    {
        // Positionen behalten ihre ID (Upsert), damit die zentrale Zeitbuchung (time_entries.daily_report_entry_id,
        // ON DELETE CASCADE) mit ergänzten Uhrzeiten erhalten bleibt. Nur entfernte Positionen werden gelöscht.
        $keep=array_column($entries,'id');$existing=$this->pdo->prepare('SELECT id FROM daily_report_time_entries WHERE report_id=:id');$existing->execute(['id'=>$reportId]);
        $d=$this->pdo->prepare('DELETE FROM daily_report_time_entries WHERE id=:id AND report_id=:report');foreach($existing->fetchAll(PDO::FETCH_COLUMN) as $id)if(!in_array($id,$keep,true))$d->execute(['id'=>$id,'report'=>$reportId]);
        $s=$this->pdo->prepare('INSERT INTO daily_report_time_entries(id,report_id,employee_id,work_date,duration_hours,description,target_kind,target_basis_type,target_basis_id,target_node_id,hourly_cost_rate_snapshot,labor_cost,created_at,updated_at,updated_by_user_id) VALUES(:id,:report_id,:employee_id,:work_date,:duration_hours,:description,:target_kind,:target_basis_type,:target_basis_id,:target_node_id,:hourly_cost_rate_snapshot,:labor_cost,:created_at,:updated_at,:updated_by_user_id)
            ON CONFLICT(id) DO UPDATE SET employee_id=excluded.employee_id,work_date=excluded.work_date,duration_hours=excluded.duration_hours,description=excluded.description,target_kind=excluded.target_kind,target_basis_type=excluded.target_basis_type,target_basis_id=excluded.target_basis_id,target_node_id=excluded.target_node_id,hourly_cost_rate_snapshot=excluded.hourly_cost_rate_snapshot,labor_cost=excluded.labor_cost,updated_at=excluded.updated_at,updated_by_user_id=excluded.updated_by_user_id
            WHERE daily_report_time_entries.report_id=excluded.report_id');
        foreach($entries as $entry)$s->execute($entry+['updated_by_user_id'=>null]);
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

    /**
     * Kostenziel setzen, ändern oder aufheben (Büro): Auftrag ODER Kostenstelle ODER keines.
     * Die Stunden folgen dem Ziel (labor_counts_for_order = 1 nur mit Auftrag); jede Änderung wird
     * in daily_report_order_events protokolliert (eine Historie für beide Zielarten).
     */
    public function setTarget(string $id, int $version, ?string $previousOrderId, ?string $previousCostCenterId, ?string $orderId, ?string $costCenterId, ?string $projectId, string $userId, string $at, ?string $customerId = null): bool
    {
        // Ein Bericht ohne Kunde übernimmt den Kunden des neu zugeordneten Auftrags; ein vorhandener Kunde bleibt.
        $statement = $this->pdo->prepare('UPDATE daily_reports SET order_id = :order_id, cost_center_id = :cost_center_id, project_id = COALESCE(:project_id, project_id),
            customer_id = COALESCE(customer_id, :customer_id),
            labor_counts_for_order = CASE WHEN :order_id IS NULL THEN 0 ELSE 1 END, order_assigned_at = :at, order_assigned_by_user_id = :user,
            version = version + 1, updated_at = :at WHERE id = :id AND version = :version AND order_id IS :previous AND cost_center_id IS :previous_cost_center');
        $statement->execute(['order_id' => $orderId, 'cost_center_id' => $costCenterId, 'project_id' => $projectId, 'customer_id' => $customerId, 'at' => $at, 'user' => $userId, 'id' => $id, 'version' => $version,
            'previous' => $previousOrderId, 'previous_cost_center' => $previousCostCenterId]);
        if ($statement->rowCount() !== 1) {
            return false;
        }
        $this->pdo->prepare('INSERT INTO daily_report_order_events (report_id, previous_order_id, order_id, previous_cost_center_id, cost_center_id, changed_by_user_id, changed_at)
            VALUES (:id, :previous, :order, :previous_cost_center, :cost_center, :user, :at)')
            ->execute(['id' => $id, 'previous' => $previousOrderId, 'order' => $orderId, 'previous_cost_center' => $previousCostCenterId, 'cost_center' => $costCenterId, 'user' => $userId, 'at' => $at]);
        return true;
    }

    /** Protokoll der Auftragszuordnung (älteste zuerst). @return list<array<string,mixed>> */
    public function orderEvents(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT ev.previous_order_id, po.order_number AS previous_order_number, ev.order_id, o.order_number,
                ev.previous_cost_center_id, pcc.number AS previous_cost_center_number, ev.cost_center_id, cc.number AS cost_center_number, ev.changed_at, u.display_name AS changed_by_name
            FROM daily_report_order_events ev LEFT JOIN orders po ON po.id = ev.previous_order_id LEFT JOIN orders o ON o.id = ev.order_id
            LEFT JOIN cost_centers pcc ON pcc.id = ev.previous_cost_center_id LEFT JOIN cost_centers cc ON cc.id = ev.cost_center_id JOIN users u ON u.id = ev.changed_by_user_id
            WHERE ev.report_id = :id ORDER BY ev.id');
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    /** Aktive Kunden nur mit Nummer und Name (Auswahl auch für Monteure). @return list<array{id:string,customer_number:?string,name:string}> */
    public function activeCustomers(): array
    {
        return $this->pdo->query("SELECT id, customer_number, CASE WHEN customer_type='company' THEN company_name ELSE trim(first_name||' '||last_name) END AS name
            FROM customers WHERE active = 1 ORDER BY name COLLATE NOCASE, id")->fetchAll();
    }

    /** Nicht archivierte Projekte mit Kunde (nur Nummer und Name). @return list<array<string,mixed>> */
    public function projectsForSelection(): array
    {
        return $this->pdo->query("SELECT id, project_number, name, customer_id FROM projects WHERE status <> 'archived' AND customer_id IS NOT NULL ORDER BY name COLLATE NOCASE, id")->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function project(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, customer_id, status FROM projects WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function customerIsActive(string $id): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM customers WHERE id = :id AND active = 1');
        $statement->execute(['id' => $id]);
        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function acceptedAddenda(string $orderId):array
    {
        $s=$this->pdo->prepare('SELECT id,basis_json FROM order_addenda WHERE order_id=:id ORDER BY accepted_at');$s->execute(['id'=>$orderId]);return$s->fetchAll();
    }

    /** @return list<array{duration_hours:string,labor_cost:string}> */
    public function costRowsForOrder(string $orderId):array
    {
        // Auftragsbezug der zentralen Buchungen (folgt dem Auftrag des Berichts).
        $s=$this->pdo->prepare('SELECT t.duration_hours,t.labor_cost FROM daily_report_time_entries t JOIN time_entries b ON b.daily_report_entry_id=t.id WHERE b.order_id=:id');$s->execute(['id'=>$orderId]);return$s->fetchAll();
    }

    // -------------------------------------------------------------------------
    // Lebenszyklus (0045)
    // -------------------------------------------------------------------------

    /**
     * Statuswechsel nur aus den erwarteten Status (und optional mit passender Version).
     *
     * @param list<string> $from
     */
    public function setStatus(string $id, ?int $version, array $from, string $to, string $now): bool
    {
        $placeholders = implode(', ', array_map(static fn (int $index): string => ':from' . $index, array_keys($from)));
        $parameters = ['id' => $id, 'to' => $to, 'now' => $now] + array_combine(array_map(static fn (int $index): string => 'from' . $index, array_keys($from)), $from);
        $sql = 'UPDATE daily_reports SET status = :to, submitted_once = CASE WHEN :to = \'submitted\' THEN 1 ELSE submitted_once END, version = version + 1, updated_at = :now
            WHERE id = :id AND status IN (' . $placeholders . ')';
        if ($version !== null) {
            $sql .= ' AND version = :version';
            $parameters['version'] = $version;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount() === 1;
    }

    /** @param array<string,mixed> $data */
    public function insertEvent(array $data): void
    {
        $this->pdo->prepare('INSERT INTO daily_report_events (id, report_id, action, reason, user_id, created_at) VALUES (:id, :report_id, :action, :reason, :user_id, :created_at)')->execute($data);
    }

    /** @return list<array<string,mixed>> */
    public function events(string $reportId): array
    {
        $statement = $this->pdo->prepare('SELECT v.action, v.reason, v.created_at, u.display_name AS user_name FROM daily_report_events v JOIN users u ON u.id = v.user_id
            WHERE v.report_id = :id ORDER BY v.created_at, v.rowid');
        $statement->execute(['id' => $reportId]);
        return $statement->fetchAll();
    }

    /** Archivierte Fassungen (ohne Datenstand). @return list<array<string,mixed>> */
    public function statements(string $reportId): array
    {
        $statement = $this->pdo->prepare('SELECT id, report_id, revision, status, file_name, created_at, superseded_at FROM daily_report_statements WHERE report_id = :id ORDER BY revision');
        $statement->execute(['id' => $reportId]);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function statement(string $reportId, string $statementId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM daily_report_statements WHERE id = :statement AND report_id = :report');
        $statement->execute(['statement' => $statementId, 'report' => $reportId]);
        return $statement->fetch() ?: null;
    }

    public function nextRevision(string $reportId): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(revision), 0) + 1 FROM daily_report_statements WHERE report_id = :id');
        $statement->execute(['id' => $reportId]);
        return (int) $statement->fetchColumn();
    }

    /** @param array<string,mixed> $data */
    public function insertStatement(array $data): void
    {
        $columns = array_keys($data);
        $this->pdo->prepare('INSERT INTO daily_report_statements (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')')->execute($data);
    }

    public function supersedeStatements(string $reportId, string $userId, string $now): void
    {
        $this->pdo->prepare("UPDATE daily_report_statements SET status = 'superseded', superseded_at = :now, superseded_by_user_id = :user WHERE report_id = :id AND status = 'current'")
            ->execute(['now' => $now, 'user' => $userId, 'id' => $reportId]);
    }

    /**
     * Eingereichte Berichte mit Stunden des Mitarbeiters im Zeitraum, deren sämtliche
     * Mitarbeiter-Tage festgeschrieben sind (Sammelfreigabe ohne unbeteiligte Tage).
     *
     * @return list<string>
     */
    public function submittedReportsCoveredBy(string $employeeId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare("SELECT DISTINCT r.id FROM daily_reports r JOIN daily_report_time_entries e ON e.report_id = r.id
            WHERE r.status = 'submitted' AND e.employee_id = :employee AND e.work_date BETWEEN :from AND :to
              AND NOT EXISTS (SELECT 1 FROM daily_report_time_entries x WHERE x.report_id = r.id
                AND NOT EXISTS (SELECT 1 FROM time_day_locks l WHERE l.employee_id = x.employee_id AND l.work_date = x.work_date AND l.status = 'approved'))
            ORDER BY r.report_date, r.id");
        $statement->execute(['employee' => $employeeId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }
}
