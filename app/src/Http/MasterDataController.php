<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\MasterDataService;
use App\Domain\MasterData\MasterDataValidationException;
use App\View\View;
use JsonException;

final readonly class MasterDataController
{
    public function __construct(private View $view, private MasterDataService $service)
    {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/master-data', fn (): Response => $this->page(
            fn (): Response => $this->view->render('master-data/index')
        ));
        $router->add('GET', '/api/master-data', fn (Request $request): Response => $this->api(
            fn (): Response => Response::json($this->service->overview(($request->query['archived'] ?? '1') !== '0'))
        ));
        $router->add('GET','/api/master-data/export/{scope}',fn(Request $_,array $parameters):Response=>$this->api(
            fn():Response=>$this->export($parameters['scope'])
        ));
        $router->add('POST','/api/master-data/import/{scope}',fn(Request $request,array $parameters):Response=>$this->mutation(
            $request,fn(array $data):array=>$this->service->importScope($parameters['scope'],$data)
        ));
        $router->add('POST', '/api/master-data/preview-price', fn (Request $request): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->preview($data)
        ));
        $router->add('POST', '/api/master-data/preview-master-price-updates', fn (Request $request): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->previewMasterPriceUpdates($data)
        ));
        $router->add('POST', '/api/master-data/trailing-texts', fn (Request $request): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->createTrailingText($data), 201
        ));
        $router->add('PUT', '/api/master-data/trailing-texts/{id}', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->updateTrailingText($parameters['id'], $data)
        ));
        $router->add('DELETE', '/api/master-data/trailing-texts/{id}', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $_): array => $this->service->deleteTrailingText($parameters['id'])
        ));

        $router->add('POST', '/api/master-data/categories', fn (Request $request): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->createCategory($data), 201
        ));
        $router->add('PUT', '/api/master-data/categories/{id}', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->updateCategory($parameters['id'], $data)
        ));
        $router->add('POST', '/api/master-data/categories/{id}/archive', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->setCategoryArchived($parameters['id'], (bool) ($data['archived'] ?? true))
        ));

        $router->add('POST', '/api/master-data/suppliers', fn (Request $request): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->createSupplier($data), 201
        ));
        $router->add('PUT', '/api/master-data/suppliers/{id}', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->updateSupplier($parameters['id'], $data)
        ));
        $router->add('POST', '/api/master-data/suppliers/{id}/archive', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->setSupplierArchived($parameters['id'], (bool) ($data['archived'] ?? true))
        ));

        $router->add('POST', '/api/master-data/cost-items', fn (Request $request): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->createCostItem($data), 201
        ));
        $router->add('PUT', '/api/master-data/cost-items/{id}', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->updateCostItem($parameters['id'], $data)
        ));
        $router->add('POST', '/api/master-data/cost-items/{id}/copy', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $_): array => $this->service->copyCostItem($parameters['id']), 201
        ));
        $router->add('POST', '/api/master-data/cost-items/{id}/archive', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->setCostItemArchived($parameters['id'], (bool) ($data['archived'] ?? true))
        ));

        $router->add('POST', '/api/master-data/services', fn (Request $request): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->createService($data), 201
        ));
        $router->add('PUT', '/api/master-data/services/{id}', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->updateService($parameters['id'], $data)
        ));
        $router->add('POST', '/api/master-data/services/{id}/copy', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $_): array => $this->service->copyService($parameters['id']), 201
        ));
        $router->add('POST', '/api/master-data/services/{id}/archive', fn (Request $request, array $parameters): Response => $this->mutation(
            $request, fn (array $data): array => $this->service->setServiceArchived($parameters['id'], (bool) ($data['archived'] ?? true))
        ));
    }

    private function page(callable $handler): Response
    {
        return Session::user() === null ? Response::redirect('/login') : $handler();
    }

    private function api(callable $handler): Response
    {
        return Session::user() === null
            ? Response::json(['error' => 'Anmeldung erforderlich.'], 401)
            : $handler();
    }

    private function export(string $scope):Response
    {
        $names=['cost-items'=>'artikelstamm','services'=>'positionsstamm','categories'=>'kategorien','suppliers'=>'lieferanten','trailing-texts'=>'schlepptexte'];
        $document=$this->service->exportScope($scope);
        $name='calight-'.($names[$scope]??'stammdaten').'-'.date('Y-m-d').'.json';
        return Response::json($document,200,['Content-Disposition'=>'attachment; filename="'.$name.'"','Cache-Control'=>'no-store']);
    }

    private function mutation(Request $request, callable $operation, int $status = 200): Response
    {
        return $this->api(function () use ($request, $operation, $status): Response {
            if (!Session::verifyCsrf($request->headers['x-csrf-token'] ?? null)) {
                throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
            }
            try {
                $data = $request->body === '' ? [] : json_decode($request->body, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new MasterDataValidationException(['Ungültiger JSON-Anfrageinhalt.']);
            }
            if (!is_array($data)) {
                throw new MasterDataValidationException(['Der Anfrageinhalt muss ein JSON-Objekt sein.']);
            }
            return Response::json(['data' => $operation($data)], $status);
        });
    }
}
