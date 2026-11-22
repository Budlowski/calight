<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\SystemPurgeConflictException;
use App\Application\SystemPurgeService;
use App\Domain\Document\DocumentValidationException;
use App\View\View;
use JsonException;

/**
 * Einstellungen → System → Datenbereinigung (nur Administratoren, Recht system.purge in
 * AuthorizationPolicy; der Dienst prüft zusätzlich Rolle, Passwort, Vorschau und Sicherung).
 * Alle schreibenden Aufrufe verlangen den CSRF-Header.
 */
final readonly class SystemPurgeController
{
    public function __construct(private View $view, private SystemPurgeService $service)
    {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/settings/system/purge', fn (): Response => $this->auth(fn (): Response => $this->view->render('settings/system-purge')));
        $router->add('GET', '/api/system/purge', fn (): Response => $this->auth(fn (): Response => Response::json(['data' => $this->service->overview(Session::user() ?? [])]), true));
        $router->add('POST', '/api/system/purge/preview', fn (Request $request): Response => $this->write($request, fn (array $data): array => $this->service->preview($data, Session::user() ?? [])));
        $router->add('POST', '/api/system/purge/backup', fn (Request $request): Response => $this->write($request, fn (array $data): array => $this->service->createBackup((string) ($data['previewId'] ?? ''), Session::user() ?? [])));
        $router->add('POST', '/api/system/purge/execute', fn (Request $request): Response => $this->write($request, fn (array $data): array => $this->service->execute($data, Session::user() ?? [])));
        $router->add('POST', '/api/system/production', fn (Request $request): Response => $this->write($request, fn (array $data): array => $this->service->activateProduction($data, Session::user() ?? [])));
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $action */
    private function write(Request $request, callable $action): Response
    {
        return $this->auth(function () use ($request, $action): Response {
            if (!Session::verifyCsrf($request->headers['x-csrf-token'] ?? null)) {
                throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
            }
            try {
                $data = json_decode($request->body, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return Response::json(['error' => 'Ungültiges JSON.'], 400);
            }
            if (!is_array($data)) {
                return Response::json(['error' => 'Ungültige Anfrage.'], 400);
            }
            try {
                return Response::json(['data' => $action($data)]);
            } catch (DocumentValidationException $exception) {
                return Response::json(['error' => $exception->getMessage(), 'details' => $exception->errors], 422);
            } catch (SystemPurgeConflictException $exception) {
                return Response::json(['error' => $exception->getMessage(), 'details' => [$exception->getMessage()]], 409);
            }
        }, true);
    }

    private function auth(callable $handler, bool $api = false): Response
    {
        return Session::user() === null ? ($api ? Response::json(['error' => 'Anmeldung erforderlich.'], 401) : Response::redirect('/login')) : $handler();
    }
}
