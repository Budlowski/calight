<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Offer\OfferValidationException;
use App\Domain\MasterData\MasterDataValidationException;
use App\Domain\Customer\CustomerProjectValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Http\CsrfException;
use App\Http\MasterDataController;
use App\Http\CustomerProjectController;
use App\Infrastructure\Persistence\OfferConflictException;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\View\View;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class Application
{
    public function __construct(
        private Router $router,
        private View $view,
        private UserRepository $users,
        private ProjectRepository $projects,
        private OfferRepository $offers,
        private OfferService $offerService,
        private MasterDataController $masterDataController,
        private CustomerProjectController $customerProjectController,
    ) {
        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (OfferConflictException $exception) {
            return Response::json(['error' => $exception->getMessage()], 409);
        } catch (OfferValidationException $exception) {
            return Response::json(['error' => 'Validierung fehlgeschlagen.', 'details' => $exception->errors], 422);
        } catch (MasterDataValidationException $exception) {
            return Response::json(['error' => 'Validierung fehlgeschlagen.', 'details' => $exception->errors], 422);
        } catch (CustomerProjectValidationException $exception) {
            return Response::json(['error' => 'Validierung fehlgeschlagen.', 'details' => $exception->errors], 422);
        } catch (CsrfException $exception) {
            return new Response('Sicherheitsprüfung fehlgeschlagen.', 419, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (Throwable $exception) {
            error_log((string) $exception);
            return new Response('<h1>Fehler</h1><p>Die Anfrage konnte nicht verarbeitet werden.</p>', 500, ['Content-Type' => 'text/html; charset=utf-8']);
        }
    }

    private function registerRoutes(): void
    {
        $this->masterDataController->register($this->router);
        $this->customerProjectController->register($this->router);

        $this->router->add('GET', '/', fn (): Response => Session::user() === null
            ? Response::redirect('/login')
            : Response::redirect('/dashboard'));

        $this->router->add('GET', '/login', function (): Response {
            if (Session::user() !== null) {
                return Response::redirect('/dashboard');
            }
            return $this->view->render('auth/login', ['hasUsers' => $this->users->count() > 0]);
        });

        $this->router->add('POST', '/login', function (Request $request): Response {
            if (!Session::verifyCsrf($request->post['_csrf'] ?? null)) {
                return $this->view->render('auth/login', ['hasUsers' => $this->users->count() > 0, 'error' => 'Sicherheitsprüfung fehlgeschlagen.'], 419);
            }
            $user = $this->users->findActiveByUsername(trim((string) ($request->post['username'] ?? '')));
            if ($user === null || !password_verify((string) ($request->post['password'] ?? ''), (string) $user['password_hash'])) {
                usleep(250000);
                return $this->view->render('auth/login', ['hasUsers' => true, 'error' => 'Benutzername oder Passwort ist falsch.'], 401);
            }
            Session::login($user);
            return Response::redirect('/dashboard');
        });

        $this->router->add('POST', '/logout', function (Request $request): Response {
            if (!Session::verifyCsrf($request->post['_csrf'] ?? null)) {
                return new Response('Sicherheitsprüfung fehlgeschlagen.', 419);
            }
            Session::logout();
            return Response::redirect('/login');
        });

        $this->router->add('GET', '/dashboard', fn (): Response => $this->protected(
            fn (): Response => $this->view->render('dashboard/index')
        ));

        $this->router->add('GET', '/projects', fn (): Response => $this->protected(
            fn (): Response => $this->view->render('projects/index', ['projects' => $this->projects->all()])
        ));

        $this->router->add('POST', '/projects', fn (Request $request): Response => $this->protected(function () use ($request): Response {
            $this->requireCsrf($request);
            $name = trim((string) ($request->post['name'] ?? ''));
            if ($name === '') {
                Session::flash('error', 'Die Projektbezeichnung darf nicht leer sein.');
                return Response::redirect('/projects');
            }
            $id = $this->projects->create(
                $name,
                trim((string) ($request->post['customerName'] ?? '')),
                trim((string) ($request->post['siteAddress'] ?? '')),
            );
            Session::flash('success', 'Projekt wurde angelegt.');
            return Response::redirect('/projects/' . rawurlencode($id));
        }));

        $this->router->add('GET', '/projects/{id}', fn (Request $request, array $parameters): Response => $this->protected(function () use ($parameters): Response {
            $project = $this->projects->find($parameters['id']);
            if ($project === null) {
                return new Response('Projekt nicht gefunden.', 404);
            }
            return $this->view->render('projects/show', [
                'project' => $project,
                'offers' => $this->offers->forProject((string) $project['id']),
            ]);
        }));

        $this->router->add('POST', '/projects/{id}/offers', fn (Request $request, array $parameters): Response => $this->protected(function () use ($request, $parameters): Response {
            $this->requireCsrf($request);
            $title = trim((string) ($request->post['title'] ?? ''));
            if ($title === '') {
                Session::flash('error', 'Die Angebotsbezeichnung darf nicht leer sein.');
                return Response::redirect('/projects/' . rawurlencode($parameters['id']));
            }
            $offerId = $this->offerService->createDraft($parameters['id'], $title);
            return Response::redirect('/offers/' . rawurlencode($offerId));
        }));

        $this->router->add('GET', '/offers', fn (): Response => $this->protected(
            fn (): Response => $this->view->render('offers/index', ['projects' => $this->projects->all()])
        ));

        $this->router->add('GET', '/api/offers', fn (): Response => $this->protected(
            fn (): Response => Response::json(['offers' => $this->offerService->overview()])
        ));

        $this->router->add('POST', '/api/offers', fn (Request $request): Response => $this->protected(function () use ($request): Response {
            $this->requireCsrf($request, $request->headers['x-csrf-token'] ?? null);
            try { $data=json_decode($request->body,true,flags:JSON_THROW_ON_ERROR); }
            catch(JsonException){ return Response::json(['error'=>'Ungültiger JSON-Anfrageinhalt.'],400); }
            if(!is_array($data)) return Response::json(['error'=>'Der Anfrageinhalt muss ein JSON-Objekt sein.'],400);
            $projectId=trim((string)($data['projectId']??'')); $title=trim((string)($data['title']??''));
            if($projectId===''||$title==='') return Response::json(['error'=>'Projekt und Angebotsbezeichnung sind erforderlich.'],422);
            $id=$this->offerService->createDraft($projectId,$title);
            return Response::json(['id'=>$id,'url'=>'/offers/'.rawurlencode($id)],201);
        }));

        $this->router->add('GET', '/offers/{id}', fn (Request $request, array $parameters): Response => $this->protected(function () use ($parameters): Response {
            $draft = $this->offerService->loadDraft($parameters['id']);
            return $this->view->render('offers/edit', $draft);
        }));

        $this->router->add('GET', '/api/offers/{id}', fn (Request $request, array $parameters): Response => $this->protected(function () use ($parameters): Response {
            $draft = $this->offerService->loadDraft($parameters['id']);
            return Response::json(
                ['offer' => $draft['offer'], 'etag' => $draft['etag']],
                headers: ['ETag' => '"' . $draft['etag'] . '"'],
            );
        }));

        $this->router->add('PUT', '/api/offers/{id}', fn (Request $request, array $parameters): Response => $this->protected(function () use ($request, $parameters): Response {
            $this->requireCsrf($request, $request->headers['x-csrf-token'] ?? null);
            try {
                $offer = json_decode($request->body, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return Response::json(['error' => 'Ungueltiges JSON.'], 400);
            }
            if (!is_array($offer)) {
                return Response::json(['error' => 'Der Anfrageinhalt muss ein JSON-Objekt sein.'], 400);
            }
            $etag = trim((string) ($request->headers['if-match'] ?? ''), '"');
            if ($etag === '') {
                return Response::json(['error' => 'If-Match-Header fehlt.'], 428);
            }
            $newEtag = $this->offerService->saveDraft($parameters['id'], $offer, $etag);
            return Response::json(['saved' => true, 'etag' => $newEtag], headers: ['ETag' => '"' . $newEtag . '"']);
        }));
    }

    private function protected(callable $handler): Response
    {
        if (Session::user() === null) {
            return Response::redirect('/login');
        }
        return $handler();
    }

    private function requireCsrf(Request $request, ?string $token = null): void
    {
        $token ??= isset($request->post['_csrf']) ? (string) $request->post['_csrf'] : null;
        if (!Session::verifyCsrf($token)) {
            throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
        }
    }
}
