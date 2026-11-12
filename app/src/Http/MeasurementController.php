<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\MeasurementService;
use App\Domain\Order\OrderValidationException;
use App\View\View;
use JsonException;

/** Aufmaße: Übersicht, Bearbeitungsseite und JSON-API (Rechte siehe AuthorizationPolicy). */
final readonly class MeasurementController
{
    public function __construct(private View $view, private MeasurementService $service)
    {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/measurements', fn (Request $request): Response => $this->view->render('measurements/index', [
            'initialOrderId' => (string) ($request->query['create_for'] ?? ''),
            'canWrite' => Session::can('measurements.write'),
        ]));
        $router->add('GET', '/measurements/{id}', fn (Request $request, array $parameters): Response => $this->view->render('measurements/show', [
            'measurementId' => $parameters['id'],
        ]));
        $router->add('GET', '/api/measurements', fn (Request $request): Response => Response::json(['data' => $this->service->page($request->query, $this->actor())]));
        $router->add('GET', '/api/measurements/{id}', fn (Request $request, array $parameters): Response => Response::json(['data' => $this->service->detail($parameters['id'], $this->actor())]));
        $router->add('POST', '/api/measurements', fn (Request $request): Response => $this->mutate($request, fn (array $data) => $this->service->create($data, $this->actor()), 201));
        $router->add('PUT', '/api/measurements/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data) => $this->service->update($parameters['id'], $data, $this->actor())));
        $router->add('DELETE', '/api/measurements/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, function (array $data) use ($parameters): array {
            $this->service->delete($parameters['id'], (int) ($data['version'] ?? 0), $this->actor());
            return ['deleted' => true];
        }));
        $router->add('POST', '/api/measurements/{id}/complete', fn (Request $request, array $parameters): Response => $this->complete($request, $parameters['id']));
    }

    /** @return array<string,mixed> */
    private function actor(): array
    {
        return Session::user() ?? throw new AccessDeniedException('measurements.read');
    }

    /** Ohne Bestätigung von Mengenüberschreitungen antwortet der Abschluss mit 409 und der Liste. */
    private function complete(Request $request, string $id): Response
    {
        $data = $this->body($request);
        $result = $this->service->complete($id, $data, $this->actor());
        return Response::json(['data' => $result], $result['completed'] ? 200 : 409);
    }

    private function mutate(Request $request, callable $operation, int $status = 200): Response
    {
        return Response::json(['data' => $operation($this->body($request))], $status);
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
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
        return $data;
    }
}
