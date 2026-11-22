<?php
declare(strict_types=1);
namespace App\Infrastructure\Persistence;
use App\Support\ListQuery;
use PDO;
final readonly class OrderRepository
{
 public function __construct(private PDO $pdo){}
 /** @return list<array<string,mixed>> */ public function all():array{return$this->pdo->query("SELECT o.*,p.name project_name,p.project_number,c.customer_number,CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name||' '||c.last_name) END customer_name,COALESCE(ofr.offer_number,root.offer_number) source_offer_number,ofr.revision_number source_revision FROM orders o JOIN customers c ON c.id=o.customer_id LEFT JOIN projects p ON p.id=o.project_id LEFT JOIN offers ofr ON ofr.id=o.source_offer_id LEFT JOIN offers root ON root.id=ofr.revision_root_id ORDER BY o.updated_at DESC")->fetchAll();}
 /** @return array<string,mixed>|null */ public function find(string $id):?array{$s=$this->pdo->prepare("SELECT o.*,p.name project_name,p.project_number,c.customer_number,CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name||' '||c.last_name) END customer_name,COALESCE(ofr.offer_number,root.offer_number) source_offer_number,ofr.revision_number source_revision FROM orders o JOIN customers c ON c.id=o.customer_id LEFT JOIN projects p ON p.id=o.project_id LEFT JOIN offers ofr ON ofr.id=o.source_offer_id LEFT JOIN offers root ON root.id=ofr.revision_root_id WHERE o.id=:id");$s->execute(['id'=>$id]);$r=$s->fetch();return is_array($r)?$r:null;}
    /** Sortierbare Spalten der Auftragsübersicht mit natürlicher Richtung. */
    public const LIST_SORTS = ['updated' => 'desc', 'number' => 'asc', 'title' => 'asc', 'customer' => 'asc', 'status' => 'asc', 'date' => 'desc', 'total' => 'desc'];
    public const LIST_FILTERS = ['status', 'type'];

    /**
     * @param string|null $technicianId Monteur: nur für ihn sichtbare Aufträge (OrderVisibility).
     * @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,pageCount:int,sort:string,dir:string}
     */
    public function page(ListQuery $query, ?string $technicianId): array
    {
        $customer = "CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END";
        $sourceNumber = 'COALESCE(ofr.offer_number, root.offer_number)';
        $page = (new Paginator($this->pdo))
            ->search($query, ['o.order_number', 'o.title', $customer, 'c.customer_number', 'p.name', 'p.project_number', $sourceNumber]);
        if ($technicianId !== null) {
            $page->where(OrderVisibility::condition('o', 'order_visibility'), ['order_visibility' => $technicianId]);
        }
        if (in_array($query->filter('status'), ['open', 'in_progress', 'completed', 'invoiced'], true)) {
            $page->where('o.status = :order_status', ['order_status' => (string) $query->filter('status')]);
        } elseif ($query->filter('status') === 'active') {
            // Sammelfilter „aktiv“ (mobile Auftragsliste): offen oder in Bearbeitung.
            $page->where("o.status IN ('open', 'in_progress')");
        }
        if (in_array($query->filter('type'), ['offer', 'regie'], true)) {
            $page->where('o.order_type = :order_type', ['order_type' => (string) $query->filter('type')]);
        }

        return $page->fetch(
            "o.id, o.order_number, o.customer_id, o.project_id, o.source_offer_id, o.order_type, o.title, o.description, o.order_date,
             o.status, o.updated_at, o.original_net_total, p.name AS project_name, p.project_number, c.customer_number,
             p.site_street, p.site_house_number, p.site_postal_code, p.site_city, p.site_address,
             {$customer} AS customer_name, {$sourceNumber} AS source_offer_number, ofr.revision_number AS source_revision,
             (SELECT group_concat(a.net_total, '|') FROM order_addenda a WHERE a.order_id = o.id) AS addenda_net_totals",
            'orders o JOIN customers c ON c.id = o.customer_id
             LEFT JOIN projects p ON p.id = o.project_id
             LEFT JOIN offers ofr ON ofr.id = o.source_offer_id
             LEFT JOIN offers root ON root.id = ofr.revision_root_id',
            [
                'updated' => 'o.updated_at',
                'number' => 'o.order_number COLLATE NOCASE',
                'title' => 'o.title COLLATE NOCASE',
                'customer' => "{$customer} COLLATE NOCASE",
                'status' => "CASE o.status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END",
                'date' => 'o.order_date',
                // Nur zum Ordnen; Regieaufträge ohne vereinbarte Summe stehen am Ende.
                'total' => 'CASE WHEN o.original_net_total IS NULL AND NOT EXISTS (SELECT 1 FROM order_addenda a WHERE a.order_id = o.id) THEN NULL
                    ELSE COALESCE(CAST(o.original_net_total AS REAL), 0) + COALESCE((SELECT SUM(CAST(a.net_total AS REAL)) FROM order_addenda a WHERE a.order_id = o.id), 0) END',
            ],
            'o.id',
            $query,
        );
    }

 /** @param array<string,mixed> $d */ public function insert(array $d):void{$s=$this->pdo->prepare('INSERT INTO orders(id,order_number,customer_id,project_id,source_offer_id,order_type,title,description,order_date,status,basis_json,basis_sha256,original_net_total,created_at,updated_at) VALUES(:id,:order_number,:customer_id,:project_id,:source_offer_id,:order_type,:title,:description,:order_date,:status,:basis_json,:basis_sha256,:original_net_total,:created_at,:updated_at)');$s->execute($d);}
 public function updateDetails(string $id,string $expectedUpdatedAt,string $title,string $description,?string $projectId,string $updatedAt):bool{$s=$this->pdo->prepare('UPDATE orders SET title=:title,description=:description,project_id=:project_id,updated_at=:updated_at WHERE id=:id AND updated_at=:expected_updated_at');$s->execute(['title'=>$title,'description'=>$description,'project_id'=>$projectId,'updated_at'=>$updatedAt,'id'=>$id,'expected_updated_at'=>$expectedUpdatedAt]);return$s->rowCount()===1;}
 public function addProjectHistory(string $orderId,?string $previousProjectId,?string $newProjectId,string $userId,string $at):void{$s=$this->pdo->prepare('INSERT INTO order_project_history(id,order_id,previous_project_id,new_project_id,changed_by_user_id,changed_at) VALUES(:id,:order,:previous,:new,:user,:at)');$s->execute(['id'=>\App\Support\Uuid::v4(),'order'=>$orderId,'previous'=>$previousProjectId,'new'=>$newProjectId,'user'=>$userId,'at'=>$at]);}
 /** @return list<array<string,mixed>> */ public function projectHistory(string $id):array{$s=$this->pdo->prepare('SELECT h.*, old.name AS previous_project_name, old.project_number AS previous_project_number, new.name AS new_project_name, new.project_number AS new_project_number, u.display_name AS changed_by_name FROM order_project_history h LEFT JOIN projects old ON old.id=h.previous_project_id LEFT JOIN projects new ON new.id=h.new_project_id JOIN users u ON u.id=h.changed_by_user_id WHERE h.order_id=:id ORDER BY h.changed_at DESC,h.rowid DESC');$s->execute(['id'=>$id]);return$s->fetchAll();}
    /** Statuswechsel mit Verlaufseintrag (Art, Benutzer, Begründung, bestätigte offene Vorgänge; Migration 0051). Optimistisch über den erwarteten Status. */
    public function changeStatus(string $id, string $from, string $to, string $historyId, string $at, ?string $userId = null, string $kind = 'advance', string $reason = '', string $openItemsJson = '[]'): bool
    {
        $s = $this->pdo->prepare('UPDATE orders SET status=:to,updated_at=:at WHERE id=:id AND status=:from');
        $s->execute(['to' => $to, 'at' => $at, 'id' => $id, 'from' => $from]);
        if ($s->rowCount() !== 1) {
            return false;
        }
        $this->addHistory($id, $from, $to, $historyId, $at, $userId, $kind, $reason, $openItemsJson);
        return true;
    }

    public function addHistory(string $id, ?string $from, string $to, string $historyId, string $at, ?string $userId = null, ?string $kind = null, string $reason = '', string $openItemsJson = '[]'): void
    {
        $h = $this->pdo->prepare('INSERT INTO order_status_history(id,order_id,previous_status,new_status,changed_at,changed_by_user_id,change_kind,reason,open_items_json)
            VALUES(:hid,:id,:from,:to,:at,:user,:kind,:reason,:items)');
        $h->execute(['hid' => $historyId, 'id' => $id, 'from' => $from, 'to' => $to, 'at' => $at, 'user' => $userId,
            'kind' => $kind ?? ($from === null ? 'create' : 'advance'), 'reason' => $reason, 'items' => $openItemsJson]);
    }

    /**
     * Ausführungsbezogener Kontext eines Auftrags: Baustellenadresse und Ansprechpartner des
     * Projekts sowie Anschrift und Kontaktdaten des Kunden. Enthält keine kaufmännischen Werte.
     *
     * @return array{project:array<string,mixed>|null,customer:array<string,mixed>|null,contacts:list<array<string,mixed>>}
     */
    public function executionContext(string $orderId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT o.customer_id, o.project_id,
                p.site_street, p.site_house_number, p.site_postal_code, p.site_city, p.site_country, p.site_address, p.contact_id,
                c.street, c.house_number, c.postal_code, c.city, c.country, c.phone, c.email
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             LEFT JOIN projects p ON p.id = o.project_id
             WHERE o.id = :id"
        );
        $statement->execute(['id' => $orderId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return ['project' => null, 'customer' => null, 'contacts' => []];
        }
        // Ansprechpartner: der des Projekts, sonst die Kontakte des Kunden in ihrer Reihenfolge.
        $contacts = $this->pdo->prepare(
            'SELECT id, salutation, first_name, last_name, function, email, phone, mobile
             FROM customer_contacts
             WHERE customer_id = :customer AND (:has_contact = 0 OR id = :contact)
             ORDER BY sort_order, last_name COLLATE NOCASE, id
             LIMIT 5'
        );
        $contacts->execute(['customer' => $row['customer_id'], 'has_contact' => $row['contact_id'] !== null ? 1 : 0, 'contact' => (string) ($row['contact_id'] ?? '')]);
        return [
            'project' => $row['project_id'] !== null ? [
                'street' => $row['site_street'], 'houseNumber' => $row['site_house_number'], 'postalCode' => $row['site_postal_code'],
                'city' => $row['site_city'], 'country' => $row['site_country'], 'freeText' => $row['site_address'],
                'hasContact' => $row['contact_id'] !== null,
            ] : null,
            'customer' => [
                'street' => $row['street'], 'houseNumber' => $row['house_number'], 'postalCode' => $row['postal_code'],
                'city' => $row['city'], 'country' => $row['country'], 'phone' => $row['phone'], 'email' => $row['email'],
            ],
            'contacts' => $contacts->fetchAll(),
        ];
    }

    /** Auftrag für den Benutzer sichtbar (OrderVisibility)? @param array<string,mixed> $actor */
    public function visibleTo(string $orderId, array $actor): bool
    {
        return OrderVisibility::visible($this->pdo, $orderId, $actor);
    }

    /** Zugeordnete Mitarbeiter eines Auftrags. @return list<array<string,mixed>> */
    public function assignedEmployees(string $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT e.id, e.display_name, e.personnel_number, e.active, e.user_id, oe.assigned_at, u.display_name AS assigned_by_name
            FROM order_employees oe JOIN employees e ON e.id = oe.employee_id LEFT JOIN users u ON u.id = oe.assigned_by_user_id
            WHERE oe.order_id = :order ORDER BY e.display_name COLLATE NOCASE, e.id');
        $statement->execute(['order' => $orderId]);
        return $statement->fetchAll();
    }

    /** Zuordnung anlegen (idempotent). */
    public function assignEmployee(string $orderId, string $employeeId, ?string $userId, string $now): bool
    {
        $statement = $this->pdo->prepare('INSERT OR IGNORE INTO order_employees (order_id, employee_id, assigned_by_user_id, assigned_at) VALUES (:order, :employee, :user, :now)');
        $statement->execute(['order' => $orderId, 'employee' => $employeeId, 'user' => $userId, 'now' => $now]);
        return $statement->rowCount() === 1;
    }

    /** Zuordnung entfernen; Zeiten, Berichte und andere Vorgänge bleiben unberührt. */
    public function removeEmployee(string $orderId, string $employeeId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM order_employees WHERE order_id = :order AND employee_id = :employee');
        $statement->execute(['order' => $orderId, 'employee' => $employeeId]);
        return $statement->rowCount() === 1;
    }

    /** Mitarbeiter-ID zu einem Benutzerkonto (nur über employees.user_id). */
    public function employeeIdForUser(string $userId): ?string
    {
        $statement = $this->pdo->prepare('SELECT id FROM employees WHERE user_id = :user');
        $statement->execute(['user' => $userId]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (string) $id;
    }

 /** @return list<array<string,mixed>> */ public function history(string $id):array{$s=$this->pdo->prepare('SELECT h.*, u.display_name AS changed_by_name FROM order_status_history h LEFT JOIN users u ON u.id = h.changed_by_user_id WHERE h.order_id=:id ORDER BY h.changed_at DESC, h.rowid DESC');$s->execute(['id'=>$id]);return$s->fetchAll();}
 /** @return list<array<string,mixed>> */ public function changeOrders(string $orderId):array{$s=$this->pdo->prepare("SELECT o.*,COALESCE(o.offer_number,root.offer_number) effective_offer_number,accepted.offer_revision_id accepted_revision_id,accepted.net_total accepted_net_total,accepted.accepted_at,accepted_revision.revision_number accepted_revision_number FROM offers o LEFT JOIN offers root ON root.id=COALESCE(o.revision_root_id,o.id) LEFT JOIN order_addenda accepted ON accepted.revision_root_id=COALESCE(o.revision_root_id,o.id) LEFT JOIN offers accepted_revision ON accepted_revision.id=accepted.offer_revision_id WHERE o.order_id=:order_id AND o.document_type='change_order' AND NOT EXISTS(SELECT 1 FROM offers newer WHERE COALESCE(newer.revision_root_id,newer.id)=COALESCE(o.revision_root_id,o.id) AND newer.revision_number>o.revision_number) ORDER BY o.created_at DESC");$s->execute(['order_id'=>$orderId]);return$s->fetchAll();}
 /** @return list<array<string,mixed>> */ public function acceptedAddenda(string $orderId):array{$s=$this->pdo->prepare('SELECT * FROM order_addenda WHERE order_id=:id ORDER BY accepted_at');$s->execute(['id'=>$orderId]);return$s->fetchAll();}
 /** @param array<string,mixed> $data */ public function addAcceptedAddendum(array $data):void{$s=$this->pdo->prepare('INSERT INTO order_addenda(id,order_id,offer_revision_id,revision_root_id,basis_json,basis_sha256,net_total,accepted_at) VALUES(:id,:order_id,:offer_revision_id,:revision_root_id,:basis_json,:basis_sha256,:net_total,:accepted_at)');$s->execute($data);}
}
