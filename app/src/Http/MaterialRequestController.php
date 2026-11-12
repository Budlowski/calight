<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\MaterialRequestService;
use App\Domain\DailyReportValidationException;
use App\View\View;
use JsonException;

/** Materialanforderungen: zentrale Übersicht und JSON-API (Rechte siehe AuthorizationPolicy). */
final readonly class MaterialRequestController
{
    public function __construct(private View $view, private MaterialRequestService $service)
    {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/material-requests', fn (): Response => $this->view->render('material-requests/index', [
            'canManage' => Session::can('materials.manage'),
        ]));
        $router->add('GET', '/api/material-requests', fn (Request $request): Response => Response::json(['data' => $this->service->page($request->query, $this->actor())]));
        $router->add('POST', '/api/material-requests', fn (Request $request): Response => $this->mutate($request, fn (array $data) => $this->service->create($data, $this->actor()), 201));
        $router->add('PUT', '/api/material-requests/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data) => $this->service->update($parameters['id'], $data, $this->actor())));
        $router->add('PATCH', '/api/material-requests/{id}/status', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data) => $this->service->setStatus($parameters['id'], $data, $this->actor())));
        $router->add('DELETE', '/api/material-requests/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, function (array $data) use ($parameters): array {
            $this->service->delete($parameters['id'], (int) ($data['version'] ?? 0), $this->actor());
            return ['deleted' => true];
        }));
    }

    /** @return array<string,mixed> */
    private function actor(): array
    {
        return Session::user() ?? throw new AccessDeniedException('reports.read');
    }

    private function mutate(Request $request, callable $operation, int $status = 200): Response
    {
        if (!Session::verifyCsrf($request->headers['x-csrf-token'] ?? null)) {
            throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
        }
        try {
            $data = $request->body === '' ? [] : json_decode($request->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return Response::json(['error' => 'Ungültiger JSON-Anfrageinhalt.'], 400);
        }
        if (!is_array($data)) {
            return Response::json(['error' => 'JSON-Objekt erwartet.'], 400);
        }
        try {
            return Response::json(['data' => $operation($data)], $status);
        } catch (DailyReportValidationException $exception) {
            return Response::json(['error' => $exception->getMessage(), 'details' => $exception->errors], 422);
        }
    }
}
