<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\DocumentSettingsService;
use App\Application\OperationalSettingsService;
use App\Domain\Document\DocumentValidationException;
use App\View\View;
use InvalidArgumentException;
use JsonException;

final readonly class SettingsController
{
    public function __construct(private View $view, private DocumentSettingsService $service, private ?OperationalSettingsService $operations = null) {}
    public function register(Router $router):void
    {
        $router->add('GET','/settings',fn():Response=>$this->auth(fn()=> $this->view->render('settings/index')));
        $router->add('GET','/api/settings',fn():Response=>$this->auth(fn()=>Response::json(['settings'=>$this->service->get()]),true));
        $router->add('PUT','/api/settings',fn(Request $request):Response=>$this->auth(function()use($request){
            if(!Session::verifyCsrf($request->headers['x-csrf-token']??null))throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
            try{$data=json_decode($request->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){return Response::json(['error'=>'Ungültiges JSON.'],400);}
            if(!is_array($data))return Response::json(['error'=>'Einstellungen müssen ein Objekt sein.'],400);
            try{return Response::json(['settings'=>$this->service->save($data)]);}catch(InvalidArgumentException $e){return Response::json(['error'=>$e->getMessage()],422);}
        },true));
        $this->registerOperations($router);
    }
    /** Einstellungen → Betrieb (nur Administratoren; Recht operations.manage, Prüfung zusätzlich im Service). */
    private function registerOperations(Router $router): void
    {
        $router->add('GET', '/settings/operations', fn (): Response => $this->auth(fn () => $this->view->render('settings/operations')));
        $router->add('GET', '/api/operational-settings', fn (): Response => $this->auth(fn () => Response::json(['data' => $this->operationsService()->all(Session::user() ?? [])]), true));
        $router->add('PUT', '/api/operational-settings', fn (Request $request): Response => $this->auth(function () use ($request): Response {
            if (!Session::verifyCsrf($request->headers['x-csrf-token'] ?? null)) {
                throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
            }
            try {
                $data = json_decode($request->body, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return Response::json(['error' => 'Ungültiges JSON.'], 400);
            }
            if (!is_array($data)) {
                return Response::json(['error' => 'Einstellungen müssen ein Objekt sein.'], 400);
            }
            try {
                return Response::json(['data' => $this->operationsService()->save($data, Session::user() ?? [])]);
            } catch (DocumentValidationException $exception) {
                return Response::json(['error' => $exception->getMessage(), 'details' => $exception->errors], 422);
            }
        }, true));
    }

    private function operationsService(): OperationalSettingsService
    {
        return $this->operations ?? throw new \RuntimeException('Betriebseinstellungen sind nicht konfiguriert.');
    }

    private function auth(callable $handler,bool $api=false):Response{return Session::user()===null?($api?Response::json(['error'=>'Anmeldung erforderlich.'],401):Response::redirect('/login')):$handler();}
}
