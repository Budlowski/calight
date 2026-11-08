<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\CustomerProjectService;
use App\Domain\Customer\CustomerProjectValidationException;
use App\View\View;
use JsonException;

final readonly class CustomerProjectController
{
    public function __construct(private View $view, private CustomerProjectService $service)
    {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/customers', fn (): Response => $this->page(fn () => $this->view->render('customers/index')));
        $router->add('GET', '/projects', fn (): Response => $this->page(fn () => $this->view->render('projects/index')));
        $router->add('GET', '/api/customer-projects', fn (): Response => $this->api(fn () => Response::json($this->service->overview())));
        $router->add('POST', '/api/customers', fn (Request $r): Response => $this->mutation($r, fn ($d) => $this->service->createCustomer($d), 201));
        $router->add('PUT', '/api/customers/{id}', fn (Request $r, array $p): Response => $this->mutation($r, fn ($d) => $this->service->updateCustomer($p['id'], $d)));
        $router->add('POST', '/api/customers/{id}/copy', fn (Request $r, array $p): Response => $this->mutation($r, fn () => $this->service->copyCustomer($p['id']), 201));
        $router->add('POST', '/api/customers/{id}/archive', fn (Request $r, array $p): Response => $this->mutation($r, fn ($d) => $this->service->setCustomerArchived($p['id'], (bool)($d['archived']??true))));
        $router->add('POST', '/api/projects', fn (Request $r): Response => $this->mutation($r, fn ($d) => $this->service->createProject($d), 201));
        $router->add('PUT', '/api/projects/{id}', fn (Request $r, array $p): Response => $this->mutation($r, fn ($d) => $this->service->updateProject($p['id'], $d)));
    }

    private function page(callable $handler): Response { return Session::user()===null?Response::redirect('/login'):$handler(); }
    private function api(callable $handler): Response { return Session::user()===null?Response::json(['error'=>'Anmeldung erforderlich.'],401):$handler(); }
    private function mutation(Request $request, callable $operation, int $status=200): Response
    {
        return $this->api(function() use($request,$operation,$status){
            if(!Session::verifyCsrf($request->headers['x-csrf-token']??null)) throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
            try{$data=$request->body===''?[]:json_decode($request->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){throw new CustomerProjectValidationException(['Ungültiger JSON-Anfrageinhalt.']);}
            if(!is_array($data))throw new CustomerProjectValidationException(['Der Anfrageinhalt muss ein JSON-Objekt sein.']);
            return Response::json(['data'=>$operation($data)],$status);
        });
    }
}
