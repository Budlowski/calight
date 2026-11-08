<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\OfferCalculationService;
use App\Domain\MasterData\MasterDataValidationException;
use JsonException;

final readonly class OfferCalculationController
{
    public function __construct(private OfferCalculationService $service){}
    public function register(Router $router): void
    {
        $router->add('GET','/api/offer-calculation/library',fn():Response=>$this->api(fn()=>Response::json($this->service->library())));
        $router->add('POST','/api/offer-calculation/calculate',fn(Request $r):Response=>$this->mutation($r,fn($d)=>$this->service->calculate($d)));
        $router->add('POST','/api/offer-calculation/price-updates',fn(Request $r):Response=>$this->mutation($r,fn($d)=>$this->service->previewPriceUpdates($d)));
        $router->add('POST','/api/offer-calculation/save-position',fn(Request $r):Response=>$this->mutation($r,fn($d)=>$this->service->savePosition($d)));
    }
    private function api(callable $operation): Response{return Session::user()===null?Response::json(['error'=>'Anmeldung erforderlich.'],401):$operation();}
    private function mutation(Request $request,callable $operation):Response{return $this->api(function()use($request,$operation){if(!Session::verifyCsrf($request->headers['x-csrf-token']??null))throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');if(strlen($request->body)>5_000_000)throw new MasterDataValidationException(['JSON-Anfragen dürfen höchstens 5 MB groß sein.']);try{$data=json_decode($request->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){throw new MasterDataValidationException(['Ungültiger JSON-Anfrageinhalt.']);}if(!is_array($data))throw new MasterDataValidationException(['JSON-Objekt erwartet.']);return Response::json(['data'=>$operation($data)]);});}
}
