<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\TimeTrackingService;
use App\Domain\Document\DocumentValidationException;
use App\View\View;
use JsonException;

/** Zeiterfassung: Seite und JSON-API (Rechte prüft TimeTrackingService). */
final readonly class TimeTrackingController
{
    public function __construct(private View $view, private TimeTrackingService $service)
    {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/time-tracking', fn (): Response => $this->view->render('time-tracking/index'));
        $router->add('GET', '/api/time-tracking/reference-data', fn (): Response => Response::json(['data' => $this->service->referenceData($this->actor())]));
        $router->add('GET', '/api/time-tracking/day', fn (Request $request): Response => Response::json(['data' => $this->service->day(
            $this->query($request, 'employee'), (string) ($request->query['date'] ?? ''), $this->actor(),
        )]));
        $router->add('GET', '/api/time-tracking/range', fn (Request $request): Response => Response::json(['data' => $this->service->range(
            $this->query($request, 'employee'), (string) ($request->query['from'] ?? ''), (string) ($request->query['to'] ?? ''), $this->actor(),
        )]));
        $router->add('GET', '/api/time-tracking/report', fn (Request $request): Response => Response::json(['data' => $this->service->report(
            (string) ($request->query['from'] ?? ''), (string) ($request->query['to'] ?? ''), $this->query($request, 'employee'), $this->query($request, 'order'), $this->actor(),
        )]));
        $router->add('POST', '/api/time-tracking/entries', fn (Request $request): Response => $this->mutate($request, fn (array $data): array => $this->service->createEntry($data, $this->actor()), 201));
        $router->add('PUT', '/api/time-tracking/entries/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->updateEntry($parameters['id'], $data, $this->actor())));
        $router->add('DELETE', '/api/time-tracking/entries/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->deleteEntry($parameters['id'], $data, $this->actor())));
        // Altfall klären (Büro): Buchung zählt oder zählt nicht; Stunden bleiben unverändert.
        $router->add('PUT', '/api/time-tracking/entries/{id}/legacy', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->resolveLegacy($parameters['id'], $data, $this->actor())));
        $router->add('PUT', '/api/time-tracking/details', fn (Request $request): Response => $this->mutate($request, fn (array $data): array => $this->service->saveDetails($data, $this->actor())));
        $router->add('POST', '/api/time-tracking/expenses', fn (Request $request): Response => $this->expense($request));
        $router->add('DELETE', '/api/time-tracking/expenses/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (): array => $this->service->deleteExpense($parameters['id'], $this->actor())));
        $router->add('GET', '/api/time-tracking/expenses/{id}/receipt', function (Request $request, array $parameters): Response {
            $file = $this->service->receipt($parameters['id'], $this->actor());
            return FileResponse::make($file['contents'], $file['name'], $file['mimeType'], $file['inline']);
        });
    }

    /** Auslage als multipart/form-data mit optionalem Beleg im Feld „file“. */
    private function expense(Request $request): Response
    {
        $this->verifyCsrf($request);
        $file = $request->files['file'] ?? null;
        $upload = null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new DocumentValidationException(['Der Beleg konnte nicht hochgeladen werden.']);
            }
            $upload = ['path' => (string) $file['tmp_name'], 'name' => (string) $file['name']];
        }
        return Response::json(['data' => $this->service->addExpense($request->post, $upload, $this->actor())], 201);
    }

    private function query(Request $request, string $key): ?string
    {
        $value = trim((string) ($request->query[$key] ?? ''));
        return $value === '' ? null : $value;
    }

    private function mutate(Request $request, callable $operation, int $status = 200): Response
    {
        $this->verifyCsrf($request);
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

    private function verifyCsrf(Request $request): void
    {
        if (!Session::verifyCsrf($request->headers['x-csrf-token'] ?? null)) {
            throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
        }
    }

    /** @return array<string,mixed> */
    private function actor(): array
    {
        return Session::user() ?? throw new AccessDeniedException('timesheet.own');
    }
}
