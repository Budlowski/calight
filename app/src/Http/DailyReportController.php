<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\DailyReportService;
use App\Domain\DailyReportValidationException;
use App\View\View;
use JsonException;

final readonly class DailyReportController
{
    public function __construct(private View $view,private DailyReportService $service) {}
    public function register(Router $router):void
    {
        $router->add('GET','/daily-reports',fn(Request $request):Response=>$this->view->render('daily-reports/index',['initialOrderId'=>(string)($request->query['order']??''),'initialReportId'=>(string)($request->query['open']??'')]));
        $router->add('GET','/daily-reports/{id}',fn(Request $request,array $parameters):Response=>$this->view->render('daily-reports/show',['reportId'=>$parameters['id']]));
        $router->add('GET','/api/daily-reports/reference-data',fn():Response=>Response::json($this->service->referenceData($this->actor())));
        $router->add('GET','/api/daily-reports',fn():Response=>Response::json(['reports'=>$this->service->overview($this->actor())]));
        $router->add('GET','/api/daily-reports/order/{id}/targets',fn(Request $request,array $parameters):Response=>$this->respond(fn()=>$this->service->orderTargets($parameters['id'],$this->actor())));
        $router->add('GET','/api/daily-reports/order/{id}/totals',fn(Request $request,array $parameters):Response=>$this->totals($parameters['id']));
        $router->add('GET','/api/daily-reports/{id}',fn(Request $request,array $parameters):Response=>$this->respond(fn()=>$this->service->detail($parameters['id'],$this->actor())));
        $router->add('POST','/api/daily-reports',fn(Request $request):Response=>$this->mutate($request,fn(array $data)=>$this->service->create($data,$this->actor()),201));
        $router->add('PUT','/api/daily-reports/{id}',fn(Request $request,array $parameters):Response=>$this->mutate($request,fn(array $data)=>$this->service->update($parameters['id'],$data,$this->actor())));
        $router->add('DELETE','/api/daily-reports/{id}',fn(Request $request,array $parameters):Response=>$this->mutate($request,function(array $data)use($parameters){$this->service->delete($parameters['id'],(int)($data['version']??0),$this->actor());return['deleted'=>true];}));
    }
    private function actor():array{return Session::user()??throw new AccessDeniedException('reports.read');}
    private function mutate(Request $request,callable $operation,int $status=200):Response
    {
        if(!Session::verifyCsrf($request->headers['x-csrf-token']??null))throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');try{$data=$request->body===''?[]:json_decode($request->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){return Response::json(['error'=>'Ungültiger JSON-Anfrageinhalt.'],400);}if(!is_array($data))return Response::json(['error'=>'JSON-Objekt erwartet.'],400);return$this->respond(fn()=>$operation($data),$status);
    }
    private function respond(callable $operation,int $status=200):Response{try{return Response::json(['data'=>$operation()],$status);}catch(DailyReportValidationException $e){return Response::json(['error'=>$e->getMessage(),'details'=>$e->errors],422);}}
    private function totals(string $orderId):Response{$this->service->orderTargets($orderId,$this->actor());$totals=$this->service->orderTotals($orderId);if(!Session::can('costs.read'))unset($totals['costs']);return Response::json(['data'=>$totals]);}
}
