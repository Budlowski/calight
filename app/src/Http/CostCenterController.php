<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\CostCenterService;
use App\Domain\Document\DocumentValidationException;
use App\View\View;
use JsonException;

/** Stammdaten → Kostenstellen: Seite und JSON-API (Rechte prüft CostCenterService). */
final readonly class CostCenterController
{
    public function __construct(private View $view, private CostCenterService $service)
    {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/master-data/cost-centers', fn (): Response => $this->view->render('master-data/cost-centers'));
        $router->add('GET', '/api/cost-centers', fn (Request $request): Response => Response::json(['data' => $this->service->overview($this->actor(), isset($request->query['year']) ? (string) $request->query['year'] : null)]));
        $router->add('POST', '/api/cost-centers', fn (Request $request): Response => $this->mutate($request, fn (array $data): array => $this->service->save(null, $data, $this->actor()), 201));
        $router->add('POST', '/api/cost-centers/template', fn (Request $request): Response => $this->mutate($request, fn (): array => $this->service->importTemplate($this->actor())));
        $router->add('PUT', '/api/cost-centers/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->save($parameters['id'], $data, $this->actor())));
        $router->add('DELETE', '/api/cost-centers/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, function (array $data) use ($parameters): array {
            $this->service->delete($parameters['id'], (int) ($data['version'] ?? 0), $this->actor());
            return ['deleted' => true];
        }));
    }

    private function mutate(Request $request, callable $operation, int $status = 200): Response
    {
        if (!Session::verifyCsrf($request->headers['x-csrf-token'] ?? null)) {
            throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
        }
        try {
            $data = $request->body === '' ? [] : json_decode($request->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DocumentValidationException(['Ungültiger JSON-Anfrageinhalt.']);
        }
        if (!is_array($data)) {
            throw new DocumentValidationException(['Der Anfrageinhalt muss ein JSON-Objekt sein.']);
        }
        return Response::json(['data' => $operation($data)], $status);
    }

    /** @return array<string,mixed> */
    private function actor(): array
    {
        return Session::user() ?? throw new AccessDeniedException('master_data.read');
    }
}
