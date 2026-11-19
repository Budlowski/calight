<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\MasterDataService;
use App\Application\PurchaseOrderService;
use App\Domain\Order\OrderValidationException;
use App\View\View;
use JsonException;

/** Bestellungen: Übersicht, Detailseite und JSON-API (Rechte siehe AuthorizationPolicy). */
final readonly class PurchaseOrderController
{
    public function __construct(private View $view, private PurchaseOrderService $service, private MasterDataService $masterData)
    {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/purchase-orders', fn (): Response => $this->view->render('purchase-orders/index', [
            'canWrite' => Session::can('purchases.write'),
            'canCosts' => Session::can('costs.read'),
        ]));
        $router->add('GET', '/purchase-orders/{id}', fn (Request $request, array $parameters): Response => $this->view->render('purchase-orders/show', [
            'purchaseOrderId' => $parameters['id'],
        ]));
        // Feste Pfade vor /{id} registrieren.
        $router->add('GET', '/api/purchase-orders/reference-data', fn (): Response => Response::json(['data' => $this->service->referenceData($this->actor())]));
        $router->add('GET', '/api/purchase-orders/catalog', fn (Request $request): Response => Response::json(['data' => $this->service->catalog(
            (string) ($request->query['q'] ?? ''),
            ($request->query['supplier'] ?? '') !== '' ? (string) $request->query['supplier'] : null,
            $this->actor(),
        )]));
        // Lieferant direkt aus dem Bestelldialog anlegen: gleiches Stammdatenmodell, keine Doppelanlage gleichen Namens.
        $router->add('POST', '/api/purchase-orders/suppliers', fn (Request $request): Response => $this->mutate($request, fn (array $data): array => $this->masterData->createSupplier($data, true), 201));
        $router->add('GET', '/api/purchase-orders', fn (Request $request): Response => Response::json(['data' => $this->service->page($request->query, $this->actor())]));
        $router->add('GET', '/api/purchase-orders/{id}', fn (Request $request, array $parameters): Response => Response::json(['data' => $this->service->detail($parameters['id'], $this->actor())]));
        $router->add('POST', '/api/purchase-orders', fn (Request $request): Response => $this->mutate($request, fn (array $data): array => ($data['origin'] ?? '') === 'external'
            ? $this->service->createExternal($data, $this->actor())
            : $this->service->createInternal($data, $this->actor()), 201));
        $router->add('PUT', '/api/purchase-orders/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->update($parameters['id'], $data, $this->actor())));
        $router->add('DELETE', '/api/purchase-orders/{id}', fn (Request $request, array $parameters): Response => $this->mutate($request, function (array $data) use ($parameters): array {
            $this->service->delete($parameters['id'], (int) ($data['version'] ?? 0), $this->actor());
            return ['deleted' => true];
        }));
        $router->add('POST', '/api/purchase-orders/{id}/issue', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->issue($parameters['id'], $data, $this->actor())));
        $router->add('POST', '/api/purchase-orders/{id}/revision', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->newRevision($parameters['id'], $data, $this->actor())));
        $router->add('POST', '/api/purchase-orders/{id}/cancel', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->cancel($parameters['id'], $data, $this->actor())));
        // Vorgänge: optional mit Beleg (multipart/form-data, Feld „file“) oder als JSON.
        $router->add('POST', '/api/purchase-orders/{id}/confirmations', fn (Request $request, array $parameters): Response => $this->event($request, fn (array $data, ?array $upload): array => $this->service->addConfirmation($parameters['id'], $data, $upload, $this->actor())));
        $router->add('POST', '/api/purchase-orders/{id}/confirmations/{confirmation}/current', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->markCurrentConfirmation($parameters['id'], $parameters['confirmation'], $data, $this->actor())));
        $router->add('POST', '/api/purchase-orders/{id}/deliveries', fn (Request $request, array $parameters): Response => $this->event($request, fn (array $data, ?array $upload): array => $this->service->addDelivery($parameters['id'], $data, $upload, $this->actor())));
        $router->add('POST', '/api/purchase-orders/{id}/payments', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->addPayment($parameters['id'], $data, $this->actor()), 201));
        $router->add('GET', '/api/purchase-orders/{id}/pdf', function (Request $request, array $parameters): Response {
            $document = $this->service->pdf($parameters['id'], $this->actor());
            return FileResponse::pdf($document['pdf'], $document['fileName']);
        });
        $router->add('POST', '/api/purchase-orders/{id}/documents', fn (Request $request, array $parameters): Response => $this->upload($request, $parameters['id']));
        $router->add('GET', '/api/purchase-orders/{id}/documents/{document}', function (Request $request, array $parameters): Response {
            $file = $this->service->documentFile($parameters['id'], $parameters['document'], $this->actor());
            return FileResponse::make($file['contents'], $file['name'], $file['mimeType'], $file['inline']);
        });
        $router->add('DELETE', '/api/purchase-orders/{id}/documents/{document}', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (): array => $this->service->deleteDocument($parameters['id'], $parameters['document'], $this->actor())));
        // Belege werden nie gelöscht; Korrektur über Gutschrift oder Stornobeleg.
        $router->add('POST', '/api/purchase-orders/{id}/invoices', fn (Request $request, array $parameters): Response => $this->event($request, fn (array $data, ?array $upload): array => $this->service->addInvoice($parameters['id'], $data, $upload, $this->actor())));
        $router->add('PUT', '/api/purchase-orders/{id}/actual-cost', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->setActualCost($parameters['id'], $data, $this->actor())));
        $router->add('PUT', '/api/purchase-orders/{id}/allocations', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->saveAllocations($parameters['id'], $data, $this->actor())));
    }

    private function upload(Request $request, string $id): Response
    {
        $this->verifyCsrf($request);
        $file = $this->uploadedFile($request) ?? throw new OrderValidationException(['Bitte eine Datei auswählen.']);
        return Response::json(['data' => $this->service->addDocument($id, $file['path'], $file['name'], (string) ($request->post['kind'] ?? ''), $this->actor())], 201);
    }

    /**
     * Vorgang mit optionalem Beleg: Formularfelder aus multipart/form-data (oder JSON-Body)
     * und höchstens eine Datei im Feld „file“.
     *
     * @param callable(array<string,mixed>, array{path:string,name:string}|null): array<string,mixed> $operation
     */
    private function event(Request $request, callable $operation): Response
    {
        $this->verifyCsrf($request);
        $data = $request->post;
        if ($data === [] && trim($request->body) !== '') {
            $data = $this->json($request);
        }
        return Response::json(['data' => $operation($data, $this->uploadedFile($request))], 201);
    }

    /** @return array{path:string,name:string}|null */
    private function uploadedFile(Request $request): ?array
    {
        $file = $request->files['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $error = match ($file['error']) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß.',
            default => 'Die Datei konnte nicht hochgeladen werden.',
        };
        // Nur echte HTTP-Uploads akzeptieren, keine beliebigen Serverpfade.
        if ($error === null && !is_uploaded_file((string) $file['tmp_name'])) {
            $error = 'Die Datei konnte nicht hochgeladen werden.';
        }
        if ($error !== null) {
            throw new OrderValidationException([$error]);
        }
        return ['path' => (string) $file['tmp_name'], 'name' => (string) $file['name']];
    }

    private function verifyCsrf(Request $request): void
    {
        if (!Session::verifyCsrf($request->headers['x-csrf-token'] ?? null)) {
            throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
        }
    }

    /** @return array<string,mixed> */
    private function json(Request $request): array
    {
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

    /** @return array<string,mixed> */
    private function actor(): array
    {
        return Session::user() ?? throw new AccessDeniedException('purchases.read');
    }

    private function mutate(Request $request, callable $operation, int $status = 200): Response
    {
        $this->verifyCsrf($request);
        return Response::json(['data' => $operation($this->json($request))], $status);
    }
}
