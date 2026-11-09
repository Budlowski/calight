<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\OfferConflictException;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Domain\Offer\OfferValidationException;
use App\Domain\Offer\OfferNormalizer;
use App\Support\Clock;
use App\Support\Uuid;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use DateTimeImmutable;

final readonly class OfferService
{
    public function __construct(
        private PDO $pdo,
        private ProjectRepository $projects,
        private OfferRepository $offers,
        private OfferJsonStore $store,
        private OfferCalculator $calculator,
        private ?NumberRangeRepository $numberRanges=null,
        private ?OfferPdfService $pdfService=null,
    ) {
    }

    public function createDraft(string $projectId, string $title): string
    {
        if ($this->projects->find($projectId) === null) {
            throw new RuntimeException('Projekt wurde nicht gefunden.');
        }

        $offerId = Uuid::v4();
        $offer = [
            'schemaVersion' => 1,
            'id' => $offerId,
            'projectId' => $projectId,
            'offerNumber' => null,
            'revision' => 0,
            'status' => 'draft',
            'title' => $title,
            'offerAdjustment' => ['type'=>'none','mode'=>'percentage','value'=>'0.00','label'=>null],
            'createdAt' => Clock::now(),
            'updatedAt' => Clock::now(),
            'nodes' => [],
        ];

        $etag = $this->store->saveDraft($projectId, $offerId, $offer, null);
        try {
            $this->offers->create($offerId, $projectId, $this->store->relativeDraftPath($projectId, $offerId), $etag);
        } catch (Throwable $exception) {
            // Eine verwaiste JSON-Datei ist sicherer als ein DB-Eintrag ohne Datei und wird
            // durch die spaetere Konsistenzpruefung erkennbar. Archivierte Daten werden nie geloescht.
            throw new RuntimeException('Angebotsmetadaten konnten nicht gespeichert werden.', 0, $exception);
        }

        return $offerId;
    }

    public function copyAsDraft(string $offerId): string
    {
        $source = $this->loadDraft($offerId)['offer'];
        $source['title'] = 'Kopie von ' . (string) $source['title'];
        $this->assignNewNodeIds($source['nodes']);

        return $this->importNew((string) $source['projectId'], $source);
    }

    /** @return array{deleted:true,projectId:string} */
    public function deleteDraft(string $offerId):array
    {
        $metadata=$this->offers->find($offerId);
        if($metadata===null)throw new RuntimeException('Angebotsentwurf wurde nicht gefunden.');
        if(($metadata['status']??null)!=='draft'||($metadata['offer_number']??null)!==null)throw new OfferValidationException(['Nur Angebotsentwürfe ohne Angebotsnummer können gelöscht werden.']);
        $projectId=(string)$metadata['project_id'];$staged=null;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try{
            $staged=$this->store->stageDraftDeletion($projectId,$offerId);
            if(!$this->offers->deleteDraft($offerId))throw new RuntimeException('Angebotsentwurf konnte nicht aus der Datenbank gelöscht werden.');
            $this->pdo->commit();
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if($staged!==null){try{$this->store->restoreStagedDeletion($staged,$projectId,$offerId);}catch(Throwable $restoreException){error_log($restoreException->getMessage());}}
            throw$exception;
        }
        try{$this->store->finalizeStagedDeletion((string)$staged);}catch(Throwable $exception){error_log('Vorbereitete Angebotsdateien konnten nicht abschließend entfernt werden: '.$exception->getMessage());}
        return['deleted'=>true,'projectId'=>$projectId];
    }

    /** @return list<array<string,mixed>> */
    public function overview(): array
    {
        $result=[];
        foreach($this->offers->all() as $metadata){
            $calculated=$this->loadDraft((string)$metadata['id'])['offer'];
            $result[]=['id'=>$metadata['id'],'projectId'=>$metadata['project_id'],'projectName'=>$metadata['project_name'],
                'projectNumber'=>$metadata['project_number'],'offerNumber'=>$metadata['offer_number'],'status'=>$metadata['status'],
                'title'=>$calculated['title'],'updatedAt'=>$metadata['updated_at'],'submittedAt'=>$metadata['submitted_at']??null,
                'documentDate'=>$metadata['document_date']??null,'netTotal'=>$calculated['calculation']['netTotal']??'0.00'];
        }
        return $result;
    }

    /** @return array{metadata:array<string,mixed>,offer:array<string,mixed>,etag:string} */
    public function loadDraft(string $offerId): array
    {
        $metadata = $this->offers->find($offerId);
        if ($metadata === null) {
            throw new RuntimeException('Angebot wurde nicht gefunden.');
        }
        if($metadata['status']!=='draft'&&($metadata['snapshot_etag']??null)!==null){
            $snapshot=$this->store->loadSnapshot((string)$metadata['project_id'],$offerId,(string)$metadata['snapshot_etag']);
            return['metadata'=>$metadata,'offer'=>$snapshot,'etag'=>(string)$metadata['snapshot_etag']];
        }
        $draft = $this->store->loadDraft((string) $metadata['project_id'], $offerId);
        if (!hash_equals((string) $metadata['draft_etag'], $draft['etag'])) {
            throw new RuntimeException('Angebotsdatei und Datenbankstand stimmen nicht ueberein.');
        }
        return ['metadata' => $metadata, 'offer' => $this->calculator->calculate($draft['offer']), 'etag' => $draft['etag']];
    }

    /** @return array{offer: array<string,mixed>, etag: string} */
    public function exportDraft(string $offerId): array
    {
        $draft = $this->loadDraft($offerId);
        return [
            'offer' => ($draft['metadata']['status']??'draft')==='draft'?(new OfferNormalizer())->normalize($draft['offer']):$draft['offer'],
            'etag' => $draft['etag'],
        ];
    }

    /** @param array<string,mixed> $offer */
    public function saveDraft(string $offerId, array $offer, string $expectedEtag): string
    {
        $metadata = $this->offers->find($offerId);
        if ($metadata === null || $metadata['status'] !== 'draft') {
            throw new RuntimeException('Bearbeitbarer Angebotsentwurf wurde nicht gefunden.');
        }
        if (!hash_equals((string) $metadata['draft_etag'], $expectedEtag)) {
            throw new OfferConflictException('Der Entwurf wurde zwischenzeitlich geaendert. Bitte neu laden.');
        }
        if(($offer['id']??null)!==$offerId||($offer['projectId']??null)!==$metadata['project_id']||($offer['status']??null)!=='draft'||($offer['offerNumber']??null)!==null
            ||($offer['revision']??null)!==0||array_key_exists('submittedAt',$offer)||array_key_exists('documentDate',$offer)){
            throw new OfferValidationException(['Status, Angebotsnummer, Revision und Abschlussdaten dürfen nicht über die Entwurfsspeicherung verändert werden.']);
        }

        $previousDraft = $this->store->loadDraft((string) $metadata['project_id'], $offerId);
        $newEtag = null;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $offer = (new OfferNormalizer())->normalize($offer);
            $offer['updatedAt'] = Clock::now();
            (new OfferValidator())->validate($offer);
            $this->calculator->calculate($offer); // Fachliche Berechnung einschließlich negativer Nettosumme prüfen.
            $newEtag = $this->store->saveDraft((string) $metadata['project_id'], $offerId, $offer, $expectedEtag);
            if (!$this->offers->updateEtag($offerId, $expectedEtag, $newEtag)) {
                throw new OfferConflictException('Der Entwurf wurde zwischenzeitlich geaendert. Bitte neu laden.');
            }
            $this->pdo->commit();
            return $newEtag;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Das Dateisystem und SQLite teilen keine Transaktion. Wurde die Datei bereits
            // ersetzt, stellen wir unter derselben exklusiven Dateisperre den vorherigen,
            // validierten Inhalt wieder her. Schlaegt auch dies fehl, meldet loadDraft beim
            // naechsten Zugriff die ETag-Abweichung statt inkonsistente Daten auszuliefern.
            if ($newEtag !== null) {
                try {
                    $this->store->saveDraft(
                        (string) $metadata['project_id'],
                        $offerId,
                        $previousDraft['offer'],
                        $newEtag,
                    );
                } catch (Throwable $rollbackException) {
                    error_log('Angebotsdatei konnte nach DB-Fehler nicht wiederhergestellt werden: ' . $rollbackException->getMessage());
                }
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function submitOffer(string $offerId,string $expectedEtag,?string $manualNumber,string $documentDate,bool $confirmIncomplete):array
    {
        if($this->numberRanges===null||$this->pdfService===null)throw new RuntimeException('Angebotsabschluss ist nicht konfiguriert.');
        if($manualNumber!==null&&mb_strlen(trim($manualNumber))>100)throw new OfferValidationException(['Die manuelle Angebotsnummer darf höchstens 100 Zeichen lang sein.']);
        $metadata=$this->offers->find($offerId);if($metadata===null)throw new RuntimeException('Angebot wurde nicht gefunden.');
        if($metadata['status']!=='draft'){
            if(($metadata['snapshot_etag']??null)!==null)return$this->submissionResult($metadata,true);
            throw new OfferConflictException('Der bestehende Abschluss besitzt keinen unveränderlichen Snapshot.');
        }
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$documentDate);
        if($date===false||$date->format('Y-m-d')!==$documentDate)throw new OfferValidationException(['Das Dokumentdatum ist ungültig.']);
        $projectId=(string)$metadata['project_id'];
        $this->pdo->exec('BEGIN IMMEDIATE');$published=false;
        try{
            return$this->store->withExclusiveLock($projectId,$offerId,function()use($offerId,$expectedEtag,$manualNumber,$documentDate,$confirmIncomplete,$projectId,&$published):array{
                $metadata=$this->offers->find($offerId);
                if($metadata===null)throw new RuntimeException('Angebot wurde nicht gefunden.');
                if($metadata['status']!=='draft'){
                    if(($metadata['snapshot_etag']??null)!==null){$this->pdo->commit();return$this->submissionResult($metadata,true);}
                    throw new OfferConflictException('Der bestehende Abschluss besitzt keinen unveränderlichen Snapshot.');
                }
                if(!hash_equals((string)$metadata['draft_etag'],$expectedEtag))throw new OfferConflictException('Der Entwurf wurde zwischenzeitlich geändert. Bitte neu laden.');
                $draft=$this->store->loadDraft($projectId,$offerId);
                if(!hash_equals($expectedEtag,$draft['etag']))throw new OfferConflictException('Der Entwurf wurde zwischenzeitlich geändert. Bitte neu laden.');
                $calculated=$this->calculator->calculate($draft['offer']);
                $incomplete=$this->includedIncompletePositions($calculated['nodes']??[]);
                if($incomplete!==[]&&!$confirmIncomplete){$this->pdo->rollBack();return['submitted'=>false,'warning'=>['code'=>'incomplete_calculation','message'=>'Regulär enthaltene Positionen besitzen keine vollständige Kostenbasis.','count'=>count($incomplete),'positions'=>$incomplete]];}
                $now=new DateTimeImmutable();$number=trim((string)$manualNumber)!==''
                    ?$this->numberRanges->reserveManual('offer','offer',$offerId,Uuid::v4(),trim((string)$manualNumber),$now)
                    :$this->numberRanges->allocate('offer','offer',$offerId,Uuid::v4(),$now);
                $calculated['offerNumber']=$number;$calculated['status']='submitted';$calculated['revision']=0;
                $calculated['documentDate']=$documentDate;$calculated['submittedAt']=$now->format(DATE_ATOM);$calculated['updatedAt']=$now->format(DATE_ATOM);
                $calculated['documentTerms']=$this->pdfService->resolvedDocumentTerms($calculated);
                $calculated=$this->calculator->calculate($calculated);(new OfferValidator())->validate($calculated);
                $pdf=$this->pdfService->render($calculated);
                $files=$this->store->publishSubmission($projectId,$offerId,$calculated,$pdf);$published=true;
                if(!$this->offers->markSubmitted($offerId,$expectedEtag,$number,$calculated['submittedAt'],$documentDate,$files))throw new OfferConflictException('Das Angebot wurde zwischenzeitlich abgeschlossen.');
                $this->pdo->commit();return['submitted'=>true,'idempotent'=>false,'offerNumber'=>$number,'status'=>'submitted','submittedAt'=>$calculated['submittedAt'],'documentDate'=>$documentDate,'snapshotSha256'=>$files['snapshotHash'],'pdfSha256'=>$files['pdfHash']];
            });
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if($published)$this->store->removePublishedSubmission($projectId,$offerId);if($e instanceof PDOException&&str_starts_with((string)$e->getCode(),'23'))throw new OfferValidationException(['Die Angebotsnummer ist bereits vergeben.']);throw$e;}
    }

    /** @return array<string,mixed> */
    public function changeStatus(string $offerId,string $expectedStatus,string $newStatus):array
    {
        $allowed=['submitted'=>['commissioned','rejected'],'commissioned'=>['rejected'],'rejected'=>['commissioned']];
        if(!in_array($newStatus,$allowed[$expectedStatus]??[],true))throw new OfferValidationException(['Dieser Statuswechsel ist nicht zulässig.']);
        $this->pdo->exec('BEGIN IMMEDIATE');try{if(!$this->offers->changeStatus($offerId,$expectedStatus,$newStatus))throw new OfferConflictException('Der Angebotsstatus wurde zwischenzeitlich geändert.');$this->pdo->commit();return['status'=>$newStatus];}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    /** @return array{pdf:string,fileName:string} */
    public function submittedPdf(string $offerId):array
    {
        if($this->pdfService===null)throw new RuntimeException('PDF-Erzeugung ist nicht konfiguriert.');
        $metadata=$this->offers->find($offerId);if($metadata===null||($metadata['submitted_pdf_sha256']??null)===null)throw new RuntimeException('Archivierte Angebots-PDF wurde nicht gefunden.');
        $pdf=$this->store->loadSubmittedPdf((string)$metadata['project_id'],$offerId,(string)$metadata['submitted_pdf_sha256']);
        return['pdf'=>$pdf,'fileName'=>$this->pdfService->fileName(['offerNumber'=>$metadata['offer_number'],'documentDate'=>$metadata['document_date']])];
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function submissionResult(array $metadata,bool $idempotent):array{return['submitted'=>true,'idempotent'=>$idempotent,'offerNumber'=>$metadata['offer_number'],'status'=>$metadata['status'],'submittedAt'=>$metadata['submitted_at'],'documentDate'=>$metadata['document_date'],'snapshotSha256'=>$metadata['snapshot_etag'],'pdfSha256'=>$metadata['submitted_pdf_sha256']];}

    /** @param list<array<string,mixed>> $nodes @return list<array{id:string,shortText:string}> */
    private function includedIncompletePositions(array $nodes,bool $excluded=false):array
    {
        $result=[];foreach($nodes as $node){if(($node['type']??null)==='title'){$titleExcluded=$excluded||($node['titleType']??'normal')!=='normal';$result=[...$result,...$this->includedIncompletePositions($node['children']??[],$titleExcluded)];continue;}if(($node['type']??null)==='position'&&!$excluded&&($node['positionType']??'normal')==='normal'&&($node['components']??[])===[])$result[]=['id'=>(string)$node['id'],'shortText'=>(string)$node['shortText']];}return$result;
    }

    /** @return array<string,mixed> */
    public function previewImport(string $offerId, array $imported, string $expectedEtag): array
    {
        $current = $this->loadDraft($offerId);
        if (!hash_equals($current['etag'], $expectedEtag)) {
            throw new OfferConflictException('Der Entwurf wurde zwischenzeitlich geändert. Bitte neu laden.');
        }
        $prepared = $this->prepareImport(
            $imported,
            (string) $current['offer']['projectId'],
            $offerId,
            (string) $current['offer']['createdAt'],
        );
        $calculated = $this->calculator->calculate($prepared);
        return [
            'offer' => $calculated,
            'changes' => [
                'title' => ['old' => $current['offer']['title'], 'new' => $prepared['title']],
                'nodes' => [
                    'old' => $this->countNodes($current['offer']['nodes']),
                    'new' => $this->countNodes($prepared['nodes']),
                ],
                'netTotal' => [
                    'old' => $current['offer']['calculation']['netTotal'] ?? '0.00',
                    'new' => $calculated['calculation']['netTotal'] ?? '0.00',
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $imported */
    public function importExisting(string $offerId, array $imported, string $expectedEtag): string
    {
        $preview = $this->previewImport($offerId, $imported, $expectedEtag);
        return $this->saveDraft($offerId, $preview['offer'], $expectedEtag);
    }

    /** @param array<string,mixed> $imported */
    public function importNew(string $projectId, array $imported): string
    {
        if ($this->projects->find($projectId) === null) {
            throw new RuntimeException('Projekt wurde nicht gefunden.');
        }
        $id = Uuid::v4();
        $prepared = $this->prepareImport($imported, $projectId, $id, Clock::now());
        $etag = $this->store->saveDraft($projectId, $id, $prepared, null);
        $this->offers->create($id, $projectId, $this->store->relativeDraftPath($projectId, $id), $etag);
        return $id;
    }

    /** @param array<string,mixed> $offer @return array<string,mixed> */
    private function prepareImport(array $offer, string $projectId, string $offerId, string $createdAt): array
    {
        $offer['schemaVersion'] = 1;
        $offer['id'] = $offerId;
        $offer['projectId'] = $projectId;
        $offer['offerNumber'] = null;
        $offer['revision'] = 0;
        $offer['status'] = 'draft';
        $offer['createdAt'] = $createdAt;
        $offer['updatedAt'] = Clock::now();
        unset($offer['submittedAt'],$offer['documentDate']);
        $offer = (new OfferNormalizer())->normalize($offer, true);
        (new OfferValidator())->validate($offer);
        $this->calculator->calculate($offer);
        return $offer;
    }

    /** @param list<array<string,mixed>> $nodes */
    private function countNodes(array $nodes): int
    {
        $count = count($nodes);
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'title') {
                $count += $this->countNodes($node['children']);
            }
        }
        return $count;
    }

    /** @param list<array<string,mixed>> $nodes */
    private function assignNewNodeIds(array &$nodes): void
    {
        foreach ($nodes as &$node) {
            $node['id'] = Uuid::v4();
            if (($node['type'] ?? '') === 'title') {
                $this->assignNewNodeIds($node['children']);
            } elseif (($node['type'] ?? '') === 'position') {
                foreach ($node['components'] as &$component) {
                    $component['id'] = Uuid::v4();
                }
                unset($component);
            }
        }
        unset($node);
    }
}
