<?php
declare(strict_types=1);
namespace App\Http;
use App\Application\OrderService;
use App\Application\DailyReportService;
use App\Domain\Order\OrderValidationException;
use App\View\View;
use JsonException;
final readonly class OrderController
{
 public function __construct(private View $view,private OrderService $service,private ?DailyReportService $dailyReports=null){}
 public function register(Router $r):void{
  $r->add('GET','/orders',fn():Response=>$this->page(fn()=> $this->view->render('orders/index',$this->service->referenceData())));
  $r->add('GET','/orders/{id}',fn(Request $q,array $p):Response=>$this->page(function()use($p){$order=$this->service->detail($p['id']);if(($this->technician())&&$order['status']==='open')throw new AccessDeniedException('orders.released.read');$actor=Session::user()??[];$reports=$this->dailyReports===null?[]:array_values(array_filter($this->dailyReports->overview($actor),static fn(array $report):bool=>(string)$report['order_id']===(string)$p['id']));$actual=$this->dailyReports===null?['hours'=>'0.000','costs'=>'0.00']:$this->dailyReports->orderTotals($p['id']);if(!Session::can('costs.read'))unset($actual['costs']);return$this->view->render('orders/show',['order'=>$order,'dailyReports'=>$reports,'actualLabor'=>$actual]);}));
  $r->add('GET','/api/orders',fn():Response=>$this->api(fn()=>Response::json(['orders'=>$this->service->overview($this->technician())])));
  $r->add('POST','/api/orders',fn(Request $q):Response=>$this->mutation($q,fn($d)=>$this->service->createRegie($d),201));
  $r->add('POST','/api/offers/{id}/order',fn(Request $q,array $p):Response=>$this->mutation($q,fn()=> $this->service->createFromOffer($p['id']),201));
  $r->add('POST','/api/orders/{id}/change-orders',fn(Request $q,array $p):Response=>$this->mutation($q,fn($d)=>$this->service->createChangeOrder($p['id'],(string)($d['title']??'')),201));
  $r->add('POST','/api/change-orders/{id}/accept',fn(Request $q,array $p):Response=>$this->mutation($q,fn($d)=>$this->service->acceptChangeOrder($p['id'],(string)($d['expectedStatus']??''))));
  $r->add('PATCH','/api/orders/{id}',fn(Request $q,array $p):Response=>$this->mutation($q,fn($d)=>$this->service->updateDetails($p['id'],$d)));
  $r->add('PATCH','/api/orders/{id}/status',fn(Request $q,array $p):Response=>$this->mutation($q,fn($d)=>$this->service->changeStatus($p['id'],(string)($d['expectedStatus']??''),(string)($d['status']??''))));
 }
 private function page(callable $h):Response{return Session::user()===null?Response::redirect('/login'):$h();}
 private function api(callable $h):Response{return Session::user()===null?Response::json(['error'=>'Anmeldung erforderlich.'],401):$h();}
 private function mutation(Request $q,callable $op,int $status=200):Response{return$this->api(function()use($q,$op,$status){if(!Session::verifyCsrf($q->headers['x-csrf-token']??null))throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');try{$d=$q->body===''?[]:json_decode($q->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){throw new OrderValidationException(['Ungültiger JSON-Anfrageinhalt.']);}if(!is_array($d))throw new OrderValidationException(['Der Anfrageinhalt muss ein Objekt sein.']);return Response::json(['data'=>$op($d)],$status);});}
 private function technician():bool{return(Session::user()['role']??'')==='technician';}
}
