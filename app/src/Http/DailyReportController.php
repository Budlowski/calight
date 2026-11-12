<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\DailyReportService;
use App\Application\MaterialRequestService;
use App\Domain\DailyReportValidationException;
use App\View\View;
use JsonException;

final readonly class DailyReportController
{
    public function __construct(private View $view,private DailyReportService $service,private ?MaterialRequestService $materialRequests=null) {}
    public function register(Router $router):void
    {
        $router->add('GET','/daily-reports',fn(Request $request):Response=>$this->view->render('daily-reports/index',['initialOrderId'=>(string)($request->query['create_for']??''),'initialReportId'=>(string)($request->query['open']??'')]));
        $router->add('GET','/daily-reports/{id}',fn(Request $request,array $parameters):Response=>$this->view->render('daily-reports/show',['reportId'=>$parameters['id']]));
        $router->add('GET','/api/daily-reports/reference-data',fn():Response=>Response::json($this->service->referenceData($this->actor())));
        $router->add('GET','/api/daily-reports',fn(Request $request):Response=>Response::json(['data'=>$this->service->page($request->query,$this->actor())]));
        $router->add('GET','/api/daily-reports/order/{id}/targets',fn(Request $request,array $parameters):Response=>$this->respond(fn()=>$this->service->orderTargets($parameters['id'],$this->actor())));
        $router->add('GET','/api/daily-reports/order/{id}/totals',fn(Request $request,array $parameters):Response=>$this->totals($parameters['id']));
        $router->add('GET','/api/daily-reports/{id}',fn(Request $request,array $parameters):Response=>$this->respond(fn()=>$this->detail($parameters['id'])));
        $router->add('POST','/api/daily-reports/{id}/attachments',fn(Request $request,array $parameters):Response=>$this->upload($request,$parameters['id']));
        $router->add('GET','/api/daily-reports/{id}/attachments/{attachment}',fn(Request $request,array $parameters):Response=>$this->download($parameters['id'],$parameters['attachment']));
        $router->add('DELETE','/api/daily-reports/{id}/attachments/{attachment}',fn(Request $request,array $parameters):Response=>$this->mutate($request,function()use($parameters){$this->service->deleteAttachment($parameters['id'],$parameters['attachment'],$this->actor());return['deleted'=>true];}));
        $router->add('POST','/api/daily-reports',fn(Request $request):Response=>$this->mutate($request,fn(array $data)=>$this->service->create($data,$this->actor()),201));
        $router->add('PUT','/api/daily-reports/{id}',fn(Request $request,array $parameters):Response=>$this->mutate($request,fn(array $data)=>$this->service->update($parameters['id'],$data,$this->actor())));
        $router->add('DELETE','/api/daily-reports/{id}',fn(Request $request,array $parameters):Response=>$this->mutate($request,function(array $data)use($parameters){$this->service->delete($parameters['id'],(int)($data['version']??0),$this->actor());return['deleted'=>true];}));
    }
    /** Berichtsdetail inklusive der aus diesem Bericht gemeldeten Materialanforderungen. */
    private function detail(string $id): array
    {
        $report = $this->service->detail($id, $this->actor());
        $report['materialRequests'] = $this->materialRequests?->forReport($id, $this->actor()) ?? [];
        return $report;
    }

    private function upload(Request $request, string $reportId): Response
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
            return Response::json(['error' => $error, 'details' => [$error]], 422);
        }
        return $this->respond(fn () => $this->service->addAttachment($reportId, (string) $file['tmp_name'], (string) $file['name'], $this->actor()), 201);
    }

    private function download(string $reportId, string $attachmentId): Response
    {
        try {
            $file = $this->service->attachmentFile($reportId, $attachmentId, $this->actor());
        } catch (DailyReportValidationException $exception) {
            return new Response('Anhang wurde nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['name']) ?: 'anhang';
        return new Response((string) file_get_contents($file['path']), 200, [
            'Content-Type' => $file['mimeType'],
            'Content-Disposition' => ($file['inline'] ? 'inline' : 'attachment') . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']),
            'Content-Length' => (string) filesize($file['path']),
            'Cache-Control' => 'private, max-age=3600',
            // Ausgelieferte Dateien dürfen keine Skripte ausführen.
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ]);
    }

    private function actor():array{return Session::user()??throw new AccessDeniedException('reports.read');}
    private function mutate(Request $request,callable $operation,int $status=200):Response
    {
        if(!Session::verifyCsrf($request->headers['x-csrf-token']??null))throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');try{$data=$request->body===''?[]:json_decode($request->body,true,flags:JSON_THROW_ON_ERROR);}catch(JsonException){return Response::json(['error'=>'Ungültiger JSON-Anfrageinhalt.'],400);}if(!is_array($data))return Response::json(['error'=>'JSON-Objekt erwartet.'],400);return$this->respond(fn()=>$operation($data),$status);
    }
    private function respond(callable $operation,int $status=200):Response{try{return Response::json(['data'=>$operation()],$status);}catch(DailyReportValidationException $e){return Response::json(['error'=>$e->getMessage(),'details'=>$e->errors],422);}}
    private function totals(string $orderId):Response{$this->service->orderTargets($orderId,$this->actor());$totals=$this->service->orderTotals($orderId);if(!Session::can('costs.read'))unset($totals['costs']);return Response::json(['data'=>$totals]);}
}
