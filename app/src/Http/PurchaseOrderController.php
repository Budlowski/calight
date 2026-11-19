<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\PurchaseOrderService;
use App\Domain\Order\OrderValidationException;
use App\View\View;
use JsonException;

/** Bestellungen: Übersicht, Detailseite und JSON-API (Rechte siehe AuthorizationPolicy). */
final readonly class PurchaseOrderController
{
    public function __construct(private View $view, private PurchaseOrderService $service)
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
        $router->add('POST', '/api/purchase-orders/{id}/delivery', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->setDeliveryStatus($parameters['id'], $data, $this->actor())));
        $router->add('POST', '/api/purchase-orders/{id}/payment', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->setPaymentStatus($parameters['id'], $data, $this->actor())));
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
        $router->add('POST', '/api/purchase-orders/{id}/invoices', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->addInvoice($parameters['id'], $data, $this->actor()), 201));
        $router->add('DELETE', '/api/purchase-orders/{id}/invoices/{invoice}', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->deleteInvoice($parameters['id'], $parameters['invoice'], $data, $this->actor())));
        $router->add('PUT', '/api/purchase-orders/{id}/actual-cost', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->setActualCost($parameters['id'], $data, $this->actor())));
        $router->add('PUT', '/api/purchase-orders/{id}/allocations', fn (Request $request, array $parameters): Response => $this->mutate($request, fn (array $data): array => $this->service->saveAllocations($parameters['id'], $data, $this->actor())));
    }

    private function upload(Request $request, string $id): Response
    {
        if (!Session::verifyCsrf($request->headers['x-csrf-token'] ?? null)) {
            throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
        }
        $file = $request->files['file'] ?? null;
        $error = match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß.',
            UPLOAD_ERR_NO_FILE => 'Bitte eine Datei auswählen.',
            default => 'Die Datei konnte nicht hochgeladen werden.',
        };
        // Nur echte HTTP-Uploads akzeptieren, keine beliebigen Serverpfade.
        if ($error === null && !is_uploaded_file((string) $file['tmp_name'])) {
            $error = 'Die Datei konnte nicht hochgeladen werden.';
        }
        if ($error !== null) {
            throw new OrderValidationException([$error]);
        }
        return Response::json(['data' => $this->service->addDocument($id, (string) $file['tmp_name'], (string) $file['name'], (string) ($request->post['kind'] ?? ''), $this->actor())], 201);
    }

    /** @return array<string,mixed> */
    private function actor(): array
    {
        return Session::user() ?? throw new AccessDeniedException('purchases.read');
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
