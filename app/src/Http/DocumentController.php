<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\DocumentService;
use App\Domain\Document\DocumentValidationException;
use App\View\View;

/** Zentrale Dokumentenübersicht (nur lesend) und allgemeine Anhänge (Rechte siehe DocumentService). */
final readonly class DocumentController
{
    public function __construct(private View $view, private DocumentService $service)
    {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/documents', fn (): Response => $this->view->render('documents/index'));
        $router->add('GET', '/api/documents/reference-data', fn (): Response => Response::json(['data' => $this->service->referenceData($this->actor())]));
        $router->add('GET', '/api/documents', fn (Request $request): Response => Response::json(['data' => $this->service->page($request->query, $this->actor())]));
        $router->add('POST', '/api/attachments', fn (Request $request): Response => $this->upload($request));
        $router->add('GET', '/api/attachments/{id}', function (Request $request, array $parameters): Response {
            $file = $this->service->file($parameters['id'], $this->actor());
            return FileResponse::make($file['contents'], $file['name'], $file['mimeType'], $file['inline']);
        });
        $router->add('DELETE', '/api/attachments/{id}/links/{type}/{target}', function (Request $request, array $parameters): Response {
            $this->verifyCsrf($request);
            return Response::json(['data' => $this->service->unlink($parameters['id'], $parameters['type'], $parameters['target'], $this->actor())]);
        });
    }

    private function upload(Request $request): Response
    {
        $this->verifyCsrf($request);
        $file = $request->files['file'] ?? null;
        $error = match (is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß.',
            UPLOAD_ERR_NO_FILE => 'Bitte eine Datei auswählen.',
            default => 'Die Datei konnte nicht hochgeladen werden.',
        };
        // Nur echte HTTP-Uploads, keine beliebigen Serverpfade.
        if ($error === null && !is_uploaded_file((string) $file['tmp_name'])) {
            $error = 'Die Datei konnte nicht hochgeladen werden.';
        }
        if ($error !== null) {
            throw new DocumentValidationException([$error]);
        }
        // Zuordnungen als „typ:id“ (Feld targets[]).
        $targets = [];
        foreach ((array) ($request->post['targets'] ?? []) as $target) {
            [$type, $id] = explode(':', (string) $target, 2) + [1 => ''];
            $targets[] = ['type' => $type, 'id' => $id];
        }
        return Response::json(['data' => $this->service->upload((string) $file['tmp_name'], (string) $file['name'],
            (string) ($request->post['title'] ?? ''), (string) ($request->post['description'] ?? ''), $targets, $this->actor())], 201);
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
        return Session::user() ?? throw new AccessDeniedException('documents.read');
    }
}
