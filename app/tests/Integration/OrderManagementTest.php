<?php
declare(strict_types=1);
namespace App\Tests\Integration;
use App\Application\OfferPdfService;use App\Application\OfferService;use App\Application\OrderService;use App\Domain\Calculation\PriceCalculator;use App\Domain\Offer\OfferCalculator;use App\Domain\Offer\OfferValidator;use App\Domain\Order\OrderValidationException;use App\Infrastructure\Database\ConnectionFactory;use App\Infrastructure\Database\Migrator;use App\Infrastructure\Persistence\DocumentSettingsRepository;use App\Infrastructure\Persistence\NumberRangeRepository;use App\Infrastructure\Persistence\OfferJsonStore;use App\Infrastructure\Persistence\OfferRepository;use App\Infrastructure\Persistence\OrderRepository;use App\Infrastructure\Persistence\ProjectRepository;use App\Support\Clock;use App\Support\Uuid;use App\Tests\TestCase;use PDO;
final class OrderManagementTest extends TestCase
{
 public function testOfferAndDirectOrdersOptionalProjectsAndStatusHistory():void
 {
  $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/calight-orders-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
  try{
   $pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$now=Clock::now();$user=Uuid::v4();$customer=Uuid::v4();$foreignCustomer=Uuid::v4();
   $pdo->prepare("INSERT INTO users(id,username,password_hash,display_name,role,created_at,updated_at) VALUES(:id,'office','x','Büro','office',:now,:now)")->execute(['id'=>$user,'now'=>$now]);
   $customers=$pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,:number,'company',:name,:now,:now)");
   $customers->execute(['id'=>$customer,'number'=>'10003','name'=>'Kunde','now'=>$now]);$customers->execute(['id'=>$foreignCustomer,'number'=>'10004','name'=>'Fremdkunde','now'=>$now]);
   $projectA=$this->project($pdo,$customer,'PR-A','Projekt A',$now);$projectB=$this->project($pdo,$customer,'PR-B','Projekt B',$now);$foreignProject=$this->project($pdo,$foreignCustomer,'PR-X','Fremdprojekt',$now);
   $offers=new OfferRepository($pdo);$projects=new ProjectRepository($pdo);$validator=new OfferValidator();$calculator=new OfferCalculator(new PriceCalculator());$numbers=new NumberRangeRepository($pdo);
   $offerService=new OfferService($pdo,$projects,$offers,new OfferJsonStore($tmp.'/offers',$validator),$calculator,$numbers,new OfferPdfService($pdo,new DocumentSettingsRepository($pdo),$validator,$calculator));
   $offerId=$offerService->createDraft($projectA,'Projektangebot',$customer);$loaded=$offerService->loadDraft($offerId);$document=$loaded['offer'];$document['nodes']=[['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','workStatus'=>'done','shortText'=>'Leistung','longText'=>'','quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>'100.00','components'=>[]]];
   $etag=$offerService->saveDraft($offerId,$document,$loaded['etag']);$offerService->submitOffer($offerId,$etag,null,'2026-11-09',true);$offerService->changeStatus($offerId,'submitted','commissioned');
   $orders=new OrderService($pdo,new OrderRepository($pdo),$offers,$offerService,$numbers);$offerOrder=$orders->createFromOffer($offerId);$basisBefore=$offerOrder['basis'];$offerBefore=$offerService->loadDraft($offerId)['offer'];
   $dailyReport=Uuid::v4();$workReport=Uuid::v4();
   $pdo->prepare("INSERT INTO daily_reports(id,customer_id,project_id,order_id,report_date,activities,created_by_user_id,created_at,updated_at) VALUES(:id,:customer,:project,:order,'2026-11-09','Bestand',:user,:now,:now)")->execute(['id'=>$dailyReport,'customer'=>$customer,'project'=>$projectA,'order'=>$offerOrder['id'],'user'=>$user,'now'=>$now]);
   $pdo->prepare("INSERT INTO work_reports(id,customer_id,project_id,order_id,work_date,description,created_by_user_id,created_at,updated_at) VALUES(:id,:customer,:project,:order,'2026-11-09','Bestand',:user,:now,:now)")->execute(['id'=>$workReport,'customer'=>$customer,'project'=>$projectA,'order'=>$offerOrder['id'],'user'=>$user,'now'=>$now]);
   $updated=$orders->updateDetails($offerOrder['id'],['title'=>'Geänderte Bezeichnung','description'=>'Interne Auftragsbeschreibung','projectId'=>$projectB,'expectedUpdatedAt'=>$offerOrder['updatedAt']],['id'=>$user]);
   $this->assertSame($projectB,$updated['projectId']);$this->assertSame($basisBefore,$updated['basis']);$this->assertSame($offerBefore,$offerService->loadDraft($offerId)['offer']);
   $this->assertSame($projectA,$pdo->query("SELECT project_id FROM daily_reports WHERE id=".$pdo->quote($dailyReport))->fetchColumn());$this->assertSame($projectA,$pdo->query("SELECT project_id FROM work_reports WHERE id=".$pdo->quote($workReport))->fetchColumn());
   $removed=$orders->updateDetails($offerOrder['id'],['title'=>$updated['title'],'description'=>$updated['description'],'projectId'=>'','expectedUpdatedAt'=>$updated['updatedAt']],['id'=>$user]);
   $this->assertSame(null,$removed['projectId']);$this->assertSame(2,count($removed['projectHistory']));$this->assertSame([$projectB,null,$user],[$removed['projectHistory'][0]['previous_project_id'],$removed['projectHistory'][0]['new_project_id'],$removed['projectHistory'][0]['changed_by_user_id']]);
   $this->assertThrows(OrderValidationException::class,fn()=>$orders->updateDetails($offerOrder['id'],['title'=>$removed['title'],'projectId'=>$foreignProject,'expectedUpdatedAt'=>$removed['updatedAt']],['id'=>$user]));
   $this->assertThrows(OrderValidationException::class,fn()=>$orders->updateDetails($offerOrder['id'],['title'=>'Veraltet','projectId'=>'','expectedUpdatedAt'=>$offerOrder['updatedAt']],['id'=>$user]));
   $regie=$orders->createRegie(['customerId'=>$customer,'projectId'=>'','title'=>'Störung','orderDate'=>'2026-11-09']);$regie=$orders->updateDetails($regie['id'],['title'=>$regie['title'],'projectId'=>$projectA,'expectedUpdatedAt'=>$regie['updatedAt']],['id'=>$user]);$this->assertSame($projectA,$regie['projectId']);
   $changed=$orders->changeStatus($regie['id'],'open','in_progress');$this->assertSame('in_progress',$changed['status']);$this->assertTrue(count($changed['history'])>=2);
  }finally{$this->remove($tmp);}
 }
 private function project(PDO $pdo,string $customer,string $number,string $name,string $now):string{$id=Uuid::v4();$pdo->prepare("INSERT INTO projects(id,project_number,name,customer_id,status,created_at,updated_at) VALUES(:id,:number,:name,:customer,'active',:now,:now)")->execute(['id'=>$id,'number'=>$number,'name'=>$name,'customer'=>$customer,'now'=>$now]);return$id;}
 private function remove(string $path):void{if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->remove($child):unlink($child);}rmdir($path);}
}
