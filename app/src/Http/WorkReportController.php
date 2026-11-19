<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\WorkReportService;
use App\Domain\Order\OrderValidationException;
use App\View\View;
use JsonException;

/** Rapporte: Übersicht, Detailseite und JSON-API (Rechte siehe AuthorizationPolicy). */
final readonly class WorkReportController
{
    public function __construct(private View $view, private WorkReportService $service)
    {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/work-reports', fn (): Response => $this->view->render('work-reports/index', [
            'canWrite' => Session::can('workreports.write'),
            'canProcess' => Session::can('workreports.process'),
        ]));
        $router->add('GET', '/work-reports/{id}', fn (Request $request, array $parameters): Response => $this->view->render('work-reports/show', [
            'reportId' => $parameters['id'],
        ]));
        // Feste Pfade vor /{id} registrieren.
        $router->add('GET', '/api/work-reports/reference-data', fn (): Response => Response::json(['data' => $this->service->referenceData($this->actor())]));
        $router->add('GET', '/api/work-reports/catalog', fn (Request $request): Response => Response::json(['data' => $this->service->catalog((string) ($request->query['q'] ?? ''), $this->actor())]));
        $router->add('GET', '/api/work-reports', fn (Request $request): Response => Response::json(['data' => $this->service->page($request->query, $this->actor())]));
        $router->add('GET', '/api/work-reports/{id}', fn (Request $request, array $parameters): Response => Response::json(['data' => $this->service->detail($parameters['id'], $this->actor())]));
        $router->add('POST', '/api/work-reports', fn (Request $request): Response => $this->mutate($request, fn (array $data) => $this->service->create($data, $this->actor()), 201));
        $router->add('PUT', '/api/work-reports/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data) => $this->service->update($parameters['id'], $data, $this->actor())));
        $router->add('DELETE', '/api/work-reports/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, function (array $data) use ($parameters): array {
            $this->service->delete($parameters['id'], (int) ($data['version'] ?? 0), $this->actor());
            return ['deleted' => true];
        }));
        $router->add('POST', '/api/work-reports/{id}/submit', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data) => $this->service->submit($parameters['id'], $data, $this->actor())));
        $router->add('POST', '/api/work-reports/{id}/confirm', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data) => $this->service->confirm($parameters['id'], $data, $this->actor())));
        $router->add('PUT', '/api/work-reports/{id}/billing', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data) => $this->service->saveBilling($parameters['id'], $data, $this->actor())));
        $router->add('POST', '/api/work-reports/{id}/process', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data) => $this->service->process($parameters['id'], $data, $this->actor())));
        $router->add('POST', '/api/work-reports/{id}/assign-order', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data) => $this->service->assignOrder($parameters['id'], $data, $this->actor())));
        $router->add('POST', '/api/work-reports/{id}/reopen', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data) => $this->service->reopen($parameters['id'], $data, $this->actor())));
    }

    /** @return array<string,mixed> */
    private function actor(): array
    {
        return Session::user() ?? throw new AccessDeniedException('workreports.read');
    }

    private function mutate(Request $request, callable $operation, int $status = 200): Response
    {
        if (!Session::verifyCsrf($request->headers['x-csrf-token'] ?? null)) {
            throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
        }
        try {
            $data = $request->body === '' ? [] : json_decode($request->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OrderValidationException(['Ungültiger JSON-Anfrageinhalt.']);
        }
        if (!is_array($data)) {
            throw new OrderValidationException(['Der Anfrageinhalt muss ein JSON-Objekt sein.']);
        }
        return Response::json(['data' => $operation($data)], $status);
    }
}
