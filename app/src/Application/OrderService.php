<?php
declare(strict_types=1);
namespace App\Application;
use App\Domain\Order\OrderValidationException;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Support\Clock;
use App\Support\Uuid;
use DateTimeImmutable;
use PDO;
use Throwable;
final readonly class OrderService
{
 public function __construct(private PDO $pdo,private OrderRepository $orders,private OfferRepository $offers,private OfferService $offerService,private NumberRangeRepository $numbers){}
 /** @return list<array<string,mixed>> */ public function overview():array{return array_map($this->map(...),$this->orders->all());}
 /** @return array<string,list<array<string,mixed>>> */ public function referenceData():array{return['customers'=>$this->pdo->query("SELECT id,customer_number,CASE WHEN customer_type='company' THEN company_name ELSE trim(first_name||' '||last_name) END name FROM customers WHERE active=1 ORDER BY name")->fetchAll(),'projects'=>$this->pdo->query('SELECT id,project_number,name,customer_id FROM projects WHERE status<>\'archived\' ORDER BY name')->fetchAll()];}
 /** @return array<string,mixed> */ public function detail(string $id):array{$r=$this->orders->find($id);if(!$r)throw new OrderValidationException(['Auftrag wurde nicht gefunden.']);$result=$this->map($r);$result['history']=$this->orders->history($id);$result['basis']=$r['basis_json']!==null?json_decode((string)$r['basis_json'],true):null;return$result;}
 /** @param array<string,mixed> $input */ public function createRegie(array $input):array{return$this->create('regie',$input,null,null);}
 public function createFromOffer(string $offerId):array{$offer=$this->offers->find($offerId);if(!$offer||$offer['status']!=='commissioned')throw new OrderValidationException(['Nur eine angenommene Angebotsrevision kann beauftragt werden.']);$loaded=$this->offerService->loadDraft($offerId);$basis=json_encode($loaded['offer'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);return$this->create('offer',['customerId'=>$offer['customer_id'],'projectId'=>$offer['project_id'],'title'=>$loaded['offer']['title'],'description'=>'','orderDate'=>date('Y-m-d')],$offerId,$basis);}
 /** @param array<string,mixed> $input */
 private function create(string $type,array $input,?string $source,?string $basis):array
 {
  $customer=trim((string)($input['customerId']??''));$project=trim((string)($input['projectId']??''))?:null;
  $title=trim((string)($input['title']??''));$date=(string)($input['orderDate']??date('Y-m-d'));
  if($customer===''||!$this->exists('customers',$customer))throw new OrderValidationException(['Ein gültiger Kunde ist erforderlich.']);
  if($project!==null){$p=$this->row('projects',$project);if(!$p||($p['customer_id']??null)!==$customer)throw new OrderValidationException(['Das Projekt gehört nicht zum ausgewählten Kunden.']);}
  if($title==='')throw new OrderValidationException(['Die Auftragsbezeichnung ist erforderlich.']);
  $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if($parsed===false||$parsed->format('Y-m-d')!==$date)throw new OrderValidationException(['Das Auftragsdatum ist ungültig.']);
  $id=Uuid::v4();$now=Clock::now();$this->pdo->exec('BEGIN IMMEDIATE');
  try{
   $number=$this->numbers->allocate('order','order',$id,Uuid::v4(),new DateTimeImmutable());
   $this->orders->insert(['id'=>$id,'order_number'=>$number,'customer_id'=>$customer,'project_id'=>$project,'source_offer_id'=>$source,'order_type'=>$type,'title'=>$title,'description'=>trim((string)($input['description']??'')),'order_date'=>$date,'status'=>'open','basis_json'=>$basis,'basis_sha256'=>$basis!==null?hash('sha256',$basis):null,'created_at'=>$now,'updated_at'=>$now]);
   $this->orders->addHistory($id,null,'open',Uuid::v4(),$now);$this->pdo->commit();return$this->detail($id);
  }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if(str_contains(strtolower($e->getMessage()),'unique'))throw new OrderValidationException(['Aus dieser Angebotsrevision wurde bereits ein Auftrag erstellt.']);throw$e;}
 }
 public function changeStatus(string $id,string $expected,string $next):array{$allowed=['open'=>'in_progress','in_progress'=>'completed','completed'=>'invoiced'];if(($allowed[$expected]??null)!==$next)throw new OrderValidationException(['Dieser Statuswechsel ist nicht zulässig.']);$this->pdo->exec('BEGIN IMMEDIATE');try{if(!$this->orders->changeStatus($id,$expected,$next,Uuid::v4(),Clock::now()))throw new OrderValidationException(['Der Auftragsstatus wurde zwischenzeitlich geändert.']);$this->pdo->commit();return$this->detail($id);}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}}
 private function exists(string $table,string $id):bool{return$this->row($table,$id)!==null;} private function row(string $table,string $id):?array{$s=$this->pdo->prepare("SELECT * FROM {$table} WHERE id=:id");$s->execute(['id'=>$id]);$r=$s->fetch();return is_array($r)?$r:null;}
 /** @param array<string,mixed> $r @return array<string,mixed> */ private function map(array $r):array{return['id'=>$r['id'],'orderNumber'=>$r['order_number'],'customerId'=>$r['customer_id'],'customerName'=>$r['customer_name']??'','customerNumber'=>$r['customer_number']??null,'projectId'=>$r['project_id'],'projectName'=>$r['project_name']??null,'projectNumber'=>$r['project_number']??null,'sourceOfferId'=>$r['source_offer_id'],'sourceOfferNumber'=>$r['source_offer_number']??null,'sourceRevision'=>(int)($r['source_revision']??0),'orderType'=>$r['order_type'],'title'=>$r['title'],'description'=>$r['description'],'orderDate'=>$r['order_date'],'status'=>$r['status'],'updatedAt'=>$r['updated_at']];}
}
