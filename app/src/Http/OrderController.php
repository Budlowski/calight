<?php
declare(strict_types=1);
namespace App\Http;
use App\Application\OrderService;
use App\Application\DailyReportService;
use App\Application\MaterialRequestService;
use App\Application\MeasurementService;
use App\Application\WorkReportService;
use App\Application\PurchaseOrderService;
use App\Application\ModuleService;
use App\Domain\Order\OrderValidationException;
use App\View\View;
use JsonException;
final readonly class OrderController
{
 public function __construct(private View $view,private OrderService $service,private ?DailyReportService $dailyReports=null,private ?MaterialRequestService $materialRequests=null,private ?MeasurementService $measurements=null,private ?WorkReportService $workReports=null,private ?PurchaseOrderService $purchaseOrders=null,private ?ModuleService $modules=null){}
 public function register(Router $r):void{
  $r->add('GET','/orders',fn():Response=>$this->page(fn()=> $this->view->render('orders/index',$this->service->referenceData())));
  $r->add('GET','/orders/{id}',fn(Request $q,array $p):Response=>$this->page(fn()=>$this->show((string)$p['id'])));
  $r->add('GET','/api/orders',fn(Request $q):Response=>$this->api(fn()=>Response::json(['data'=>$this->service->page($q->query,$this->technician())])));
  $r->add('POST','/api/orders',fn(Request $q):Response=>$this->mutation($q,fn($d)=>$this->service->createRegie($d),201));
  $r->add('POST','/api/offers/{id}/order',fn(Request $q,array $p):Response=>$this->mutation($q,fn()=> $this->service->createFromOffer($p['id']),201));
  $r->add('POST','/api/orders/{id}/change-orders',fn(Request $q,array $p):Response=>$this->mutation($q,fn($d)=>$this->service->createChangeOrder($p['id'],(string)($d['title']??'')),201));
  $r->add('POST','/api/change-orders/{id}/accept',fn(Request $q,array $p):Response=>$this->mutation($q,fn($d)=>$this->service->acceptChangeOrder($p['id'],(string)($d['expectedStatus']??''))));
  $r->add('PATCH','/api/orders/{id}',fn(Request $q,array $p):Response=>$this->mutation($q,fn($d)=>$this->service->updateDetails($p['id'],$d)));
  $r->add('PATCH','/api/orders/{id}/status',fn(Request $q,array $p):Response=>$this->mutation($q,fn($d)=>$this->service->changeStatus($p['id'],(string)($d['expectedStatus']??''),(string)($d['status']??''))));
 }
 /**
  * Auftragsdetail als zentrale Arbeitsoberfläche. Bereiche abgeschalteter Module erscheinen nur,
  * wenn bereits Daten vorhanden sind (lesend, ohne Anlegeaktionen). Alle Werte stammen aus den
  * bestehenden Diensten; keine eigene Auftragsfachlogik.
  */
 private function show(string $id):Response
 {
  $actor=Session::user()??[];
  $order=$this->service->readableDetail($id,$actor);
  $workTracking=$this->modules===null||$this->modules->isEnabled('work_tracking');
  $procurement=$this->modules===null||$this->modules->isEnabled('procurement');
  $reportPage=$this->dailyReports!==null&&Session::can('reports.read')?$this->dailyReports->page(['order'=>$id,'per_page'=>'10'],$actor):['items'=>[],'total'=>0];
  // Eine Stundenaggregation aus time_entries für Übersicht und Ausführung; Kosten nur mit costs.read.
  $hours=$this->dailyReports?->orderHours($id)??['hours'=>'0.000','approvedHours'=>'0.000','provisionalHours'=>'0.000','bySource'=>[],'costs'=>'0.00','costsComplete'=>true];
  if(!Session::can('costs.read'))unset($hours['costs'],$hours['costsComplete']);
  $purchasePage=$this->purchaseOrders!==null&&Session::can('purchases.read')?$this->purchaseOrders->page(['order'=>$id,'per_page'=>'25'],$actor):null;
  $purchaseCosts=$purchasePage!==null&&Session::can('costs.read')?$this->purchaseOrders?->costsByOrder($id,$actor):null;
  return$this->view->render('orders/show',[
   'order'=>$order,
   'modulesActive'=>['work_tracking'=>$workTracking,'procurement'=>$procurement],
   'dailyReports'=>$reportPage['items'],'dailyReportTotal'=>(int)$reportPage['total'],
   'materialRequests'=>$this->materialRequests?->openForOrder($id,$actor)??[],
   'workReports'=>$this->workReports!==null&&Session::can('workreports.read')?$this->workReports->forOrder($id,$actor):null,
   'measurementOverview'=>$this->measurements!==null&&Session::can('measurements.read')?$this->measurements->forOrder($id,$actor):null,
   'orderHours'=>$hours,
   'purchaseOrders'=>$purchasePage,
   'purchaseCosts'=>$purchaseCosts,
  ]);
 }
 private function page(callable $h):Response{return Session::user()===null?Response::redirect('/login'):$h();}
 private function api(callable $h):Response{return Session::user()===null?Response::json(['error'=>'Anmeldung erforderlich.'],401):$h();}
 private function mutation(Request $q,callable $op,int $status=200):Response{return$this->api(function()use($q,$op,$status){if(!Session::verifyCsrf($q->headers['x-csrf-token']??null))throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');try{$d=$q->body===''?[]:json_decode($q->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){throw new OrderValidationException(['Ungültiger JSON-Anfrageinhalt.']);}if(!is_array($d))throw new OrderValidationException(['Der Anfrageinhalt muss ein Objekt sein.']);return Response::json(['data'=>$op($d)],$status);});}
 private function technician():bool{return(Session::user()['role']??'')==='technician';}
}
