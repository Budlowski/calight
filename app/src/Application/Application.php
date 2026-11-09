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
use App\Http\OfferCalculationController;
use App\Http\SettingsController;
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
        private OfferCalculationController $offerCalculationController,
        private SettingsController $settingsController,
        private OfferPdfService $offerPdfService,
        private InternalCalculationPdfService $internalCalculationPdfService,
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
        $this->offerCalculationController->register($this->router);
        $this->settingsController->register($this->router);

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
                'offers' => array_values(array_filter(
                    $this->offerService->overview(),
                    static fn (array $offer): bool => $offer['projectId'] === $project['id'],
                )),
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
            $offer = $this->jsonBody($request);
            $etag = trim((string) ($request->headers['if-match'] ?? ''), '"');
            if ($etag === '') {
                return Response::json(['error' => 'If-Match-Header fehlt.'], 428);
            }
            $newEtag = $this->offerService->saveDraft($parameters['id'], $offer, $etag);
            return Response::json(['saved' => true, 'etag' => $newEtag], headers: ['ETag' => '"' . $newEtag . '"']);
        }));

        $this->router->add('DELETE','/api/offers/{id}',fn(Request $request,array $parameters):Response=>$this->protected(function()use($request,$parameters):Response{
            $this->requireCsrf($request,$request->headers['x-csrf-token']??null);
            return Response::json($this->offerService->deleteDraft($parameters['id']));
        }));
        $this->router->add('POST','/api/offers/{id}/copy',fn(Request $request,array $parameters):Response=>$this->protected(function()use($request,$parameters):Response{
            $this->requireCsrf($request,$request->headers['x-csrf-token']??null);
            $id=$this->offerService->copyAsDraft($parameters['id']);
            return Response::json(['id'=>$id,'url'=>'/offers/'.rawurlencode($id)],201);
        }));
        $this->router->add('POST','/offers/{id}/delete',fn(Request $request,array $parameters):Response=>$this->protected(function()use($request,$parameters):Response{
            $this->requireCsrf($request);$result=$this->offerService->deleteDraft($parameters['id']);Session::flash('success','Angebotsentwurf gelöscht.');
            return Response::redirect('/projects/'.rawurlencode($result['projectId']));
        }));

        $this->router->add('GET','/api/offers/{id}/export',fn(Request $request,array $parameters):Response=>$this->protected(function()use($parameters){$export=$this->offerService->exportDraft($parameters['id']);$json=json_encode($export['offer'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";return new Response($json,200,['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="angebot-'.$parameters['id'].'.json"','ETag'=>'"'.$export['etag'].'"']);}));
        $this->router->add('POST','/api/offers/{id}/import-preview',fn(Request $request,array $parameters):Response=>$this->protected(function()use($request,$parameters){$this->requireCsrf($request,$request->headers['x-csrf-token']??null);$data=$this->jsonBody($request);$etag=trim((string)($request->headers['if-match']??''),'"');if($etag==='')return Response::json(['error'=>'If-Match-Header fehlt.'],428);return Response::json($this->offerService->previewImport($parameters['id'],$data,$etag));}));
        $this->router->add('PUT','/api/offers/{id}/import',fn(Request $request,array $parameters):Response=>$this->protected(function()use($request,$parameters){$this->requireCsrf($request,$request->headers['x-csrf-token']??null);$data=$this->jsonBody($request);$etag=trim((string)($request->headers['if-match']??''),'"');if($etag==='')return Response::json(['error'=>'If-Match-Header fehlt.'],428);$new=$this->offerService->importExisting($parameters['id'],$data,$etag);return Response::json(['saved'=>true,'etag'=>$new],headers:['ETag'=>'"'.$new.'"']);}));
        $this->router->add('POST','/api/offers/import',fn(Request $request):Response=>$this->protected(function()use($request){$this->requireCsrf($request,$request->headers['x-csrf-token']??null);$data=$this->jsonBody($request);$project=trim((string)($data['projectId']??''));$offer=$data['offer']??null;if($project===''||!is_array($offer))return Response::json(['error'=>'Projekt und Angebotsdokument sind erforderlich.'],422);$id=$this->offerService->importNew($project,$offer);return Response::json(['id'=>$id,'url'=>'/offers/'.rawurlencode($id)],201);}));
        $this->router->add('POST','/api/offers/{id}/pdf',fn(Request $request,array $parameters):Response=>$this->protected(function()use($request,$parameters){$this->requireCsrf($request,$request->headers['x-csrf-token']??null);$offer=$this->jsonBody($request);$stored=$this->offerService->loadDraft($parameters['id'])['offer'];if(($offer['id']??null)!==$parameters['id']||($offer['projectId']??null)!==$stored['projectId'])return Response::json(['error'=>'Angebots- oder Projekt-ID stimmt nicht überein.'],422);$offer['offerNumber']=null;$offer['status']='draft';$pdf=$this->offerPdfService->render($offer);$fileName=$this->offerPdfService->fileName($offer);return new Response($pdf,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="'.$fileName.'"','Cache-Control'=>'no-store']);}));
        $this->router->add('POST','/api/offers/{id}/calculation-pdf',fn(Request $request,array $parameters):Response=>$this->protected(function()use($request,$parameters){$this->requireCsrf($request,$request->headers['x-csrf-token']??null);$offer=$this->jsonBody($request);$stored=$this->offerService->loadDraft($parameters['id'])['offer'];if(($offer['id']??null)!==$parameters['id']||($offer['projectId']??null)!==$stored['projectId'])return Response::json(['error'=>'Angebots- oder Projekt-ID stimmt nicht überein.'],422);$offer['offerNumber']=null;$offer['status']='draft';$pdf=$this->internalCalculationPdfService->render($offer);$fileName=$this->internalCalculationPdfService->fileName($offer);return new Response($pdf,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="'.$fileName.'"','Cache-Control'=>'no-store']);}));
    }

    /** @return array<string,mixed> */ private function jsonBody(Request $request):array{if(strlen($request->body)>5_000_000)throw new OfferValidationException(['JSON-Dateien dürfen höchstens 5 MB groß sein.']);try{$data=json_decode($request->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){throw new OfferValidationException(['Die Datei enthält kein gültiges JSON.']);}if(!is_array($data))throw new OfferValidationException(['Das JSON-Wurzelelement muss ein Objekt sein.']);return $data;}

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
