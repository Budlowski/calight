<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\DocumentLockService;
use JsonException;

final readonly class DocumentLockController
{
    public function __construct(private DocumentLockService $service) {}

    public function register(Router $router):void
    {
        $router->add('POST','/api/document-locks/offers/{id}',fn(Request $request,array $parameters):Response=>$this->mutate($request,fn(array $data,array $user):array=>$this->service->acquire('offer',$parameters['id'],$user,(bool)($data['takeOver']??false))));
        $router->add('PUT','/api/document-locks/offers/{id}',fn(Request $request,array $parameters):Response=>$this->mutate($request,fn(array $data,array $user):array=>$this->service->renew('offer',$parameters['id'],$user,(string)($data['token']??''))));
        $router->add('DELETE','/api/document-locks/offers/{id}',fn(Request $request,array $parameters):Response=>$this->mutate($request,fn(array $data,array $user):array=>$this->service->release('offer',$parameters['id'],$user,(string)($data['token']??''))));
    }

    private function mutate(Request $request,callable $operation):Response
    {
        $user=Session::user();if($user===null)return Response::json(['error'=>'Anmeldung erforderlich.'],401);
        if(!Session::verifyCsrf($request->headers['x-csrf-token']??null))throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
        try{$data=$request->body===''?[]:json_decode($request->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){return Response::json(['error'=>'Ungültiger JSON-Anfrageinhalt.'],400);}
        if(!is_array($data))return Response::json(['error'=>'JSON-Objekt erwartet.'],400);
        return Response::json($operation($data,$user));
    }
}
