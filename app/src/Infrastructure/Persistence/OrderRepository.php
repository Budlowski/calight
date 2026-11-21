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
     * @param bool $releasedOnly Techniker sehen nur freigegebene Aufträge (nicht „Offen“).
     * @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,pageCount:int,sort:string,dir:string}
     */
    public function page(ListQuery $query, bool $releasedOnly): array
    {
        $customer = "CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name || ' ' || c.last_name) END";
        $sourceNumber = 'COALESCE(ofr.offer_number, root.offer_number)';
        $page = (new Paginator($this->pdo))
            ->search($query, ['o.order_number', 'o.title', $customer, 'c.customer_number', 'p.name', 'p.project_number', $sourceNumber]);
        if ($releasedOnly) {
            $page->where("o.status <> 'open'");
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
 public function updateDetails(string $id,string $expectedUpdatedAt,string $title,string $description,string $updatedAt):bool{$s=$this->pdo->prepare('UPDATE orders SET title=:title,description=:description,updated_at=:updated_at WHERE id=:id AND updated_at=:expected_updated_at');$s->execute(['title'=>$title,'description'=>$description,'updated_at'=>$updatedAt,'id'=>$id,'expected_updated_at'=>$expectedUpdatedAt]);return$s->rowCount()===1;}
 public function changeStatus(string $id,string $from,string $to,string $historyId,string $at):bool{$s=$this->pdo->prepare('UPDATE orders SET status=:to,updated_at=:at WHERE id=:id AND status=:from');$s->execute(['to'=>$to,'at'=>$at,'id'=>$id,'from'=>$from]);if($s->rowCount()!==1)return false;$this->addHistory($id,$from,$to,$historyId,$at);return true;}
 public function addHistory(string $id,?string $from,string $to,string $historyId,string $at):void{$h=$this->pdo->prepare('INSERT INTO order_status_history(id,order_id,previous_status,new_status,changed_at) VALUES(:hid,:id,:from,:to,:at)');$h->execute(['hid'=>$historyId,'id'=>$id,'from'=>$from,'to'=>$to,'at'=>$at]);}

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

 /** @return list<array<string,mixed>> */ public function history(string $id):array{$s=$this->pdo->prepare('SELECT * FROM order_status_history WHERE order_id=:id ORDER BY changed_at DESC');$s->execute(['id'=>$id]);return$s->fetchAll();}
 /** @return list<array<string,mixed>> */ public function changeOrders(string $orderId):array{$s=$this->pdo->prepare("SELECT o.*,COALESCE(o.offer_number,root.offer_number) effective_offer_number,accepted.offer_revision_id accepted_revision_id,accepted.net_total accepted_net_total,accepted.accepted_at,accepted_revision.revision_number accepted_revision_number FROM offers o LEFT JOIN offers root ON root.id=COALESCE(o.revision_root_id,o.id) LEFT JOIN order_addenda accepted ON accepted.revision_root_id=COALESCE(o.revision_root_id,o.id) LEFT JOIN offers accepted_revision ON accepted_revision.id=accepted.offer_revision_id WHERE o.order_id=:order_id AND o.document_type='change_order' AND NOT EXISTS(SELECT 1 FROM offers newer WHERE COALESCE(newer.revision_root_id,newer.id)=COALESCE(o.revision_root_id,o.id) AND newer.revision_number>o.revision_number) ORDER BY o.created_at DESC");$s->execute(['order_id'=>$orderId]);return$s->fetchAll();}
 /** @return list<array<string,mixed>> */ public function acceptedAddenda(string $orderId):array{$s=$this->pdo->prepare('SELECT * FROM order_addenda WHERE order_id=:id ORDER BY accepted_at');$s->execute(['id'=>$orderId]);return$s->fetchAll();}
 /** @param array<string,mixed> $data */ public function addAcceptedAddendum(array $data):void{$s=$this->pdo->prepare('INSERT INTO order_addenda(id,order_id,offer_revision_id,revision_root_id,basis_json,basis_sha256,net_total,accepted_at) VALUES(:id,:order_id,:offer_revision_id,:revision_root_id,:basis_json,:basis_sha256,:net_total,:accepted_at)');$s->execute($data);}
}
