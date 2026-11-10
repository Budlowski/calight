<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\UserAccessService;
use InvalidArgumentException;
use JsonException;

final readonly class UserAccessController
{
    public function __construct(private UserAccessService $service) {}
    public function register(Router $router):void
    {
        $router->add('GET','/api/users',fn():Response=>Session::user()===null?Response::json(['error'=>'Anmeldung erforderlich.'],401):Response::json(['users'=>$this->service->all()]));
        $router->add('PATCH','/api/users/{id}',function(Request $request,array $parameters):Response{
            if(Session::user()===null)return Response::json(['error'=>'Anmeldung erforderlich.'],401);
            if(!Session::verifyCsrf($request->headers['x-csrf-token']??null))throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
            try{$data=json_decode($request->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){return Response::json(['error'=>'Ungültiger JSON-Anfrageinhalt.'],400);}
            if(!is_array($data))return Response::json(['error'=>'JSON-Objekt erwartet.'],400);$user=Session::user();
            try{return Response::json(['users'=>$this->service->update($parameters['id'],$data,(string)($user['id']??''))]);}catch(InvalidArgumentException $exception){return Response::json(['error'=>$exception->getMessage()],422);}
        });
    }
}
