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
        }
        if (in_array($query->filter('type'), ['offer', 'regie'], true)) {
            $page->where('o.order_type = :order_type', ['order_type' => (string) $query->filter('type')]);
        }

        return $page->fetch(
            "o.id, o.order_number, o.customer_id, o.project_id, o.source_offer_id, o.order_type, o.title, o.description, o.order_date,
             o.status, o.updated_at, o.original_net_total, p.name AS project_name, p.project_number, c.customer_number,
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
 /** @return list<array<string,mixed>> */ public function history(string $id):array{$s=$this->pdo->prepare('SELECT * FROM order_status_history WHERE order_id=:id ORDER BY changed_at DESC');$s->execute(['id'=>$id]);return$s->fetchAll();}
 /** @return list<array<string,mixed>> */ public function changeOrders(string $orderId):array{$s=$this->pdo->prepare("SELECT o.*,COALESCE(o.offer_number,root.offer_number) effective_offer_number,accepted.offer_revision_id accepted_revision_id,accepted.net_total accepted_net_total,accepted.accepted_at,accepted_revision.revision_number accepted_revision_number FROM offers o LEFT JOIN offers root ON root.id=COALESCE(o.revision_root_id,o.id) LEFT JOIN order_addenda accepted ON accepted.revision_root_id=COALESCE(o.revision_root_id,o.id) LEFT JOIN offers accepted_revision ON accepted_revision.id=accepted.offer_revision_id WHERE o.order_id=:order_id AND o.document_type='change_order' AND NOT EXISTS(SELECT 1 FROM offers newer WHERE COALESCE(newer.revision_root_id,newer.id)=COALESCE(o.revision_root_id,o.id) AND newer.revision_number>o.revision_number) ORDER BY o.created_at DESC");$s->execute(['order_id'=>$orderId]);return$s->fetchAll();}
 /** @return list<array<string,mixed>> */ public function acceptedAddenda(string $orderId):array{$s=$this->pdo->prepare('SELECT * FROM order_addenda WHERE order_id=:id ORDER BY accepted_at');$s->execute(['id'=>$orderId]);return$s->fetchAll();}
 /** @param array<string,mixed> $data */ public function addAcceptedAddendum(array $data):void{$s=$this->pdo->prepare('INSERT INTO order_addenda(id,order_id,offer_revision_id,revision_root_id,basis_json,basis_sha256,net_total,accepted_at) VALUES(:id,:order_id,:offer_revision_id,:revision_root_id,:basis_json,:basis_sha256,:net_total,:accepted_at)');$s->execute($data);}
}
