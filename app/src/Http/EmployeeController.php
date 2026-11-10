<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\EmployeeService;
use App\Domain\DailyReportValidationException;
use App\View\View;
use JsonException;

final readonly class EmployeeController
{
    public function __construct(private View $view,private EmployeeService $service) {}
    public function register(Router $router):void
    {
        $router->add('GET','/settings/employees',fn():Response=>$this->view->render('employees/index'));
        $router->add('GET','/api/employees',fn():Response=>Response::json($this->service->overview()));
        $router->add('POST','/api/employees',fn(Request $request):Response=>$this->save($request,null));
        $router->add('PUT','/api/employees/{id}',fn(Request $request,array $parameters):Response=>$this->save($request,$parameters['id']));
    }
    private function save(Request $request,?string $id):Response
    {
        if(!Session::verifyCsrf($request->headers['x-csrf-token']??null))throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');try{$data=json_decode($request->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){return Response::json(['error'=>'Ungültiger JSON-Anfrageinhalt.'],400);}if(!is_array($data))return Response::json(['error'=>'JSON-Objekt erwartet.'],400);
        try{return Response::json(['employee'=>$this->service->save($id,$data)],$id===null?201:200);}catch(DailyReportValidationException $e){return Response::json(['error'=>$e->getMessage(),'details'=>$e->errors],422);}
    }
}
