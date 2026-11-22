<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\UserAccessService;
use App\View\View;
use InvalidArgumentException;
use JsonException;

/** Benutzerkonten und Berechtigungsübersicht (Einstellungen → Benutzerverwaltung, users.manage). */
final readonly class UserAccessController
{
    public function __construct(private UserAccessService $service, private ?View $view = null)
    {
    }

    public function register(Router $router): void
    {
        if ($this->view !== null) {
            $router->add('GET', '/settings/users', fn (): Response => $this->page(fn (): Response => $this->view->render('settings/users')));
            $router->add('GET', '/settings/permissions', fn (): Response => $this->page(fn (): Response => $this->view->render('settings/permissions')));
        }
        $router->add('GET', '/api/users', fn (): Response => Session::user() === null ? Response::json(['error' => 'Anmeldung erforderlich.'], 401) : Response::json(['users' => $this->service->all()]));
        $router->add('GET', '/api/users/overview', fn (): Response => Response::json(['data' => $this->service->overview($this->actor())]));
        $router->add('GET', '/api/users/{id}', fn (Request $request, array $parameters): Response => Response::json(['data' => $this->service->account($parameters['id'], $this->actor())]));
        $router->add('POST', '/api/users', fn (Request $request): Response => Response::json(['data' => $this->service->create($this->body($request), $this->actor())], 201));
        $router->add('PUT', '/api/users/{id}', fn (Request $request, array $parameters): Response => Response::json(['data' => $this->service->updateAccount($parameters['id'], $this->body($request), $this->actor())]));
        $router->add('POST', '/api/users/{id}/password', function (Request $request, array $parameters): Response {
            $actor = $this->actor();
            $result = $this->service->resetPassword($parameters['id'], $this->body($request), $actor);
            // Eigenes Passwort: andere Sitzungen enden, die aktuelle bleibt angemeldet.
            if ($parameters['id'] === (string) ($actor['id'] ?? '')) {
                Session::acceptSessionVersion($result['sessionVersion']);
            }
            return Response::json(['data' => $result['account']]);
        });
        // Bisherige Kurzänderung von Rolle und Aktivstatus (kompatibel).
        $router->add('PATCH', '/api/users/{id}', function (Request $request, array $parameters): Response {
            $data = $this->body($request);
            try {
                return Response::json(['users' => $this->service->update($parameters['id'], $data, (string) ($this->actor()['id'] ?? ''))]);
            } catch (InvalidArgumentException $exception) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }
        });
    }

    private function page(callable $handler): Response
    {
        return Session::user() === null ? Response::redirect('/login') : $handler();
    }

    /** @return array<string,mixed> */
    private function actor(): array
    {
        return Session::user() ?? throw new AccessDeniedException('users.manage');
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
            throw new \App\Domain\Document\DocumentValidationException(['Ungültiger JSON-Anfrageinhalt.']);
        }
        if (!is_array($data)) {
            throw new \App\Domain\Document\DocumentValidationException(['Der Anfrageinhalt muss ein JSON-Objekt sein.']);
        }
        return $data;
    }
}
