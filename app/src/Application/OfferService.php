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
use App\Domain\Offer\OfferSubmissionValidator;
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

    public function createDraft(?string $projectId,string $title,?string $customerId=null): string
    {
        $project=$projectId!==null?$this->projects->find($projectId):null;
        if($projectId!==null&&$project===null)throw new RuntimeException('Projekt wurde nicht gefunden.');
        if($projectId!==null&&$customerId!==null&&$customerId!==''&&(string)($project['customer_id']??'')!==$customerId)throw new OfferValidationException(['Das Projekt gehört nicht zum ausgewählten Kunden.']);
        $customerId=$projectId!==null?(string)($project['customer_id']??''):(string)$customerId;
        if($customerId===''||!$this->customerExists($customerId))throw new OfferValidationException(['Ein gültiger Kunde ist erforderlich.']);
        $storageKey=$projectId??$customerId;

        $offerId = Uuid::v4();
        $offer = [
            'schemaVersion' => 1,
            'id' => $offerId,
            'projectId' => $projectId,
            'customerId'=>$customerId,
            'documentType'=>'offer',
            'orderId'=>null,
            'offerNumber' => null,
            'revision' => 0,
            'status' => 'draft',
            'title' => $title,
            'offerAdjustment' => ['type'=>'none','mode'=>'percentage','value'=>'0.00','label'=>null],
            'createdAt' => Clock::now(),
            'updatedAt' => Clock::now(),
            'nodes' => [],
        ];

        $etag = $this->store->saveDraft($storageKey, $offerId, $offer, null);
        try {
            $this->offers->create($offerId,$projectId,$customerId,$storageKey,$this->store->relativeDraftPath($storageKey,$offerId),$etag);
        } catch (Throwable $exception) {
            // Eine verwaiste JSON-Datei ist sicherer als ein DB-Eintrag ohne Datei und wird
            // durch die spaetere Konsistenzpruefung erkennbar. Archivierte Daten werden nie geloescht.
            throw new RuntimeException('Angebotsmetadaten konnten nicht gespeichert werden.', 0, $exception);
        }

        return $offerId;
    }

    public function createChangeOrder(string $orderId,string $title):string
    {
        $statement=$this->pdo->prepare('SELECT * FROM orders WHERE id=:id');$statement->execute(['id'=>$orderId]);$order=$statement->fetch();
        if(!is_array($order))throw new OfferValidationException(['Auftrag wurde nicht gefunden.']);
        $title=trim($title);if($title==='')throw new OfferValidationException(['Die Nachtragsbezeichnung ist erforderlich.']);
        $offerId=Uuid::v4();$projectId=$order['project_id']!==null?(string)$order['project_id']:null;$customerId=(string)$order['customer_id'];$storageKey=$projectId??$customerId;
        $offer=['schemaVersion'=>1,'id'=>$offerId,'projectId'=>$projectId,'customerId'=>$customerId,'documentType'=>'change_order','orderId'=>$orderId,
            'offerNumber'=>null,'revision'=>0,'status'=>'draft','title'=>$title,
            'offerAdjustment'=>['type'=>'none','mode'=>'percentage','value'=>'0.00','label'=>null],
            'createdAt'=>Clock::now(),'updatedAt'=>Clock::now(),'nodes'=>[]];
        $etag=$this->store->saveDraft($storageKey,$offerId,$offer,null);
        $this->offers->create($offerId,$projectId,$customerId,$storageKey,$this->store->relativeDraftPath($storageKey,$offerId),$etag,'change_order',$orderId);
        return$offerId;
    }

    public function copyAsDraft(string $offerId): string
    {
        $source = $this->loadDraft($offerId)['offer'];
        $source['title'] = 'Kopie von ' . (string) $source['title'];
        $this->assignNewNodeIds($source['nodes']);

        return $this->importNew($source['projectId']!==null?(string)$source['projectId']:null,$source,(string)($source['customerId']??''),(string)($source['documentType']??'offer'),$source['orderId']??null);
    }

    /** @return array{deleted:true,projectId:string} */
    public function deleteDraft(string $offerId):array
    {
        $metadata=$this->offers->find($offerId);
        if($metadata===null)throw new RuntimeException('Angebotsentwurf wurde nicht gefunden.');
        if(($metadata['status']??null)!=='draft'||($metadata['offer_number']??null)!==null)throw new OfferValidationException(['Nur Angebotsentwürfe ohne Angebotsnummer können gelöscht werden.']);
        $storageKey=(string)$metadata['storage_key'];$projectId=$metadata['project_id']!==null?(string)$metadata['project_id']:'';$staged=null;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try{
            $staged=$this->store->stageDraftDeletion($storageKey,$offerId);
            if(!$this->offers->deleteDraft($offerId))throw new RuntimeException('Angebotsentwurf konnte nicht aus der Datenbank gelöscht werden.');
            $this->pdo->commit();
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if($staged!==null){try{$this->store->restoreStagedDeletion($staged,$storageKey,$offerId);}catch(Throwable $restoreException){error_log($restoreException->getMessage());}}
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
                'projectNumber'=>$metadata['project_number'],'customerId'=>$metadata['customer_id'],'customerName'=>$metadata['customer_name']??'',
                'offerNumber'=>$metadata['effective_offer_number']??$metadata['offer_number'],'status'=>$metadata['status'],
                'title'=>$calculated['title'],'updatedAt'=>$metadata['updated_at'],'submittedAt'=>$metadata['submitted_at']??null,
                'documentDate'=>$metadata['document_date']??null,'revision'=>(int)($metadata['revision_number']??0),
                'documentType'=>$metadata['document_type']??'offer','orderId'=>$metadata['order_id']??null,'orderNumber'=>$metadata['order_number']??null,
                'netTotal'=>$calculated['calculation']['netTotal']??'0.00'];
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
            $snapshot=$this->store->loadSnapshot((string)$metadata['storage_key'],$offerId,(string)$metadata['snapshot_etag']);
            return['metadata'=>$metadata,'offer'=>$snapshot,'etag'=>(string)$metadata['snapshot_etag']];
        }
        $draft = $this->store->loadDraft((string)$metadata['storage_key'],$offerId);
        if (!hash_equals((string) $metadata['draft_etag'], $draft['etag'])) {
            throw new RuntimeException('Angebotsdatei und Datenbankstand stimmen nicht ueberein.');
        }
        $document=$draft['offer'];if(!array_key_exists('customerId',$document))$document['customerId']=$metadata['customer_id'];
        if(!array_key_exists('documentType',$document))$document['documentType']=$metadata['document_type']??'offer';
        if(!array_key_exists('orderId',$document))$document['orderId']=$metadata['order_id']??null;
        return ['metadata'=>$metadata,'offer'=>$this->calculator->calculate($document),'etag'=>$draft['etag']];
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
    public function saveDraft(string $offerId, array $offer, string $expectedEtag,?callable $writeGuard=null): string
    {
        $metadata = $this->offers->find($offerId);
        if ($metadata === null || $metadata['status'] !== 'draft') {
            throw new RuntimeException('Bearbeitbarer Angebotsentwurf wurde nicht gefunden.');
        }
        if (!hash_equals((string) $metadata['draft_etag'], $expectedEtag)) {
            throw new OfferConflictException('Der Entwurf wurde zwischenzeitlich geaendert. Bitte neu laden.');
        }
        $revision=(int)($metadata['revision_number']??0);
        $expectedNumber=$revision===0?null:(string)($metadata['effective_offer_number']??'');
        if(($offer['id']??null)!==$offerId||($offer['projectId']??null)!==$metadata['project_id']||($offer['customerId']??null)!==$metadata['customer_id']
            ||($offer['documentType']??'offer')!==($metadata['document_type']??'offer')||($offer['orderId']??null)!==$metadata['order_id']||($offer['status']??null)!=='draft'
            ||($offer['offerNumber']??null)!==$expectedNumber||($offer['revision']??null)!==$revision
            ||array_key_exists('submittedAt',$offer)||array_key_exists('documentDate',$offer)){
            throw new OfferValidationException(['Dokumentart, Zuordnungen, Status, Angebotsnummer, Revision und Abschlussdaten dürfen nicht über die Entwurfsspeicherung verändert werden.']);
        }

        $previousDraft = $this->store->loadDraft((string)$metadata['storage_key'],$offerId);
        $newEtag = null;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if($writeGuard!==null)$writeGuard();
            $offer = (new OfferNormalizer())->normalize($offer);
            $offer['updatedAt'] = Clock::now();
            (new OfferValidator())->validate($offer);
            $this->calculator->calculate($offer); // Fachliche Berechnung einschließlich negativer Nettosumme prüfen.
            $newEtag = $this->store->saveDraft((string)$metadata['storage_key'],$offerId,$offer,$expectedEtag);
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
                        (string)$metadata['storage_key'],
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

    /** @return array{id:string,url:string,created:bool,revision:int} */
    public function createRevision(string $offerId):array
    {
        $metadata=$this->offers->find($offerId);
        if($metadata===null)throw new RuntimeException('Angebot wurde nicht gefunden.');
        if(($metadata['status']??'draft')==='draft')throw new OfferValidationException(['Eine Revision kann nur aus einem abgegebenen Angebotsstand erstellt werden.']);
        $rootId=(string)($metadata['revision_root_id']??$metadata['id']);
        if(($metadata['document_type']??'offer')==='change_order'){
            $accepted=$this->pdo->prepare('SELECT 1 FROM order_addenda WHERE revision_root_id=:root LIMIT 1');
            $accepted->execute(['root'=>$rootId]);
            if($accepted->fetchColumn()!==false)throw new OfferValidationException(['Ein bereits in den Auftrag übernommener Nachtrag kann nicht mehr revidiert werden. Bitte legen Sie einen neuen Nachtrag an.']);
        }
        $newId='';$projectId=$metadata['project_id']!==null?(string)$metadata['project_id']:null;$storageKey=(string)$metadata['storage_key'];$customerId=(string)$metadata['customer_id'];$documentType=(string)($metadata['document_type']??'offer');$orderId=$metadata['order_id']!==null?(string)$metadata['order_id']:null;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try{
            $open=$this->offers->openRevision($rootId);
            if($open!==null){$this->pdo->commit();return['id'=>(string)$open['id'],'url'=>'/offers/'.rawurlencode((string)$open['id']),'created'=>false,'revision'=>(int)$open['revision_number']];}
            $latest=$this->offers->latestRevision($rootId);
            if($latest===null||($latest['status']??'draft')==='draft'||($latest['snapshot_etag']??null)===null)throw new OfferConflictException('Der letzte abgegebene Angebotsstand ist nicht verfügbar.');
            $source=$this->store->loadSnapshot($storageKey,(string)$latest['id'],(string)$latest['snapshot_etag']);
            $revision=(int)$latest['revision_number']+1;$newId=Uuid::v4();$now=Clock::now();
            $source['id']=$newId;$source['projectId']=$projectId;$source['customerId']=$customerId;$source['documentType']=$documentType;$source['orderId']=$orderId;$source['offerNumber']=(string)$latest['effective_offer_number'];
            $source['revision']=$revision;$source['status']='draft';$source['createdAt']=$now;$source['updatedAt']=$now;
            unset($source['submittedAt'],$source['documentDate']);
            $source=(new OfferNormalizer())->normalize($source);(new OfferValidator())->validate($source);$this->calculator->calculate($source);
            $etag=$this->store->saveDraft($storageKey,$newId,$source,null);
            $this->offers->createRevision($newId,$projectId,$customerId,$storageKey,$this->store->relativeDraftPath($storageKey,$newId),$etag,$rootId,$revision,(string)$latest['id'],$documentType,$orderId);
            $this->pdo->commit();return['id'=>$newId,'url'=>'/offers/'.rawurlencode($newId),'created'=>true,'revision'=>$revision];
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if($newId!==''){try{$staged=$this->store->stageDraftDeletion($storageKey,$newId);$this->store->finalizeStagedDeletion($staged);}catch(Throwable $cleanup){error_log($cleanup->getMessage());}}
            if($exception instanceof PDOException&&str_starts_with((string)$exception->getCode(),'23'))throw new OfferConflictException('Für dieses Angebot besteht bereits eine offene Revision.');
            throw$exception;
        }
    }

    /** @return array{rootId:string,currentRevision:int,openRevision:?array<string,mixed>,items:list<array<string,mixed>>} */
    public function revisionHistory(string $offerId):array
    {
        $metadata=$this->offers->find($offerId);if($metadata===null)throw new RuntimeException('Angebot wurde nicht gefunden.');
        $rootId=(string)($metadata['revision_root_id']??$metadata['id']);$items=[];$open=null;
        foreach($this->offers->revisions($rootId) as $item){
            $entry=['id'=>(string)$item['id'],'revision'=>(int)$item['revision_number'],'status'=>(string)$item['status'],
                'offerNumber'=>$item['effective_offer_number']??null,'submittedAt'=>$item['submitted_at']??null,
                'documentDate'=>$item['document_date']??null,'updatedAt'=>(string)$item['updated_at'],
                'hasPdf'=>($item['submitted_pdf_sha256']??null)!==null,'current'=>(string)$item['id']===$offerId];
            if($entry['status']==='draft')$open=$entry;$items[]=$entry;
        }
        return['rootId'=>$rootId,'currentRevision'=>(int)($metadata['revision_number']??0),'openRevision'=>$open,'items'=>$items];
    }

    /** @return array<string,mixed> */
    public function submitOffer(string $offerId,string $expectedEtag,?string $manualNumber,string $documentDate,bool $confirmIncomplete,?callable $writeGuard=null):array
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
        $projectId=(string)$metadata['storage_key'];
        $this->pdo->exec('BEGIN IMMEDIATE');$published=false;
        try{
            if($writeGuard!==null)$writeGuard();
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
                $calculated=null;$calculationErrors=[];
                try{$calculated=$this->calculator->calculate($draft['offer']);}catch(Throwable $exception){$calculationErrors[]=$exception->getMessage()?:'Die Angebotskalkulation ist nicht berechenbar.';}
                $validation=(new OfferSubmissionValidator())->inspect($draft['offer'],$calculated,$metadata['project_id']!==null?$this->projects->find((string)$metadata['project_id']):null,$calculationErrors,$this->customer((string)$metadata['customer_id']));
                if($validation['errors']!==[]||($validation['warnings']!==[]&&!$confirmIncomplete)){
                    $this->pdo->rollBack();
                    $hasIncomplete=array_filter($validation['warnings'],fn(array $item):bool=>$item['code']==='incomplete_cost_basis')!==[];
                    return['submitted'=>false,'validation'=>$validation,'warning'=>$validation['warnings']!==[]?[
                        'code'=>$hasIncomplete?'incomplete_calculation':'submission_warnings',
                        'message'=>'Der Angebotsabschluss erfordert eine ausdrückliche Bestätigung.',
                        'count'=>count($validation['warnings']),
                        'positions'=>array_values(array_filter(array_map(fn(array $item):?array=>isset($item['positionId'])?['id'=>$item['positionId'],'shortText'=>$item['position']]:null,$validation['warnings']))),
                    ]:null];
                }
                if($calculated===null)throw new OfferValidationException(['Die Angebotskalkulation ist nicht berechenbar.']);
                $now=new DateTimeImmutable();$revision=(int)($metadata['revision_number']??0);
                if($revision>0){
                    $number=(string)($metadata['effective_offer_number']??'');
                    if($number==='')throw new OfferConflictException('Die Angebotsnummer der Revisionskette fehlt.');
                    if(trim((string)$manualNumber)!==''&&trim((string)$manualNumber)!==$number)throw new OfferValidationException(['Die Angebotsnummer kann bei einer Revision nicht geändert werden.']);
                }else{$range=($metadata['document_type']??'offer')==='change_order'?'change_order':'offer';$entityType=$range;
                    $number=trim((string)$manualNumber)!==''
                    ?$this->numberRanges->reserveManual($range,$entityType,$offerId,Uuid::v4(),trim((string)$manualNumber),$now)
                    :$this->numberRanges->allocate($range,$entityType,$offerId,Uuid::v4(),$now);}
                $calculated['offerNumber']=$number;$calculated['status']='submitted';$calculated['revision']=$revision;
                $calculated['documentDate']=$documentDate;$calculated['submittedAt']=$now->format(DATE_ATOM);$calculated['updatedAt']=$now->format(DATE_ATOM);
                $calculated['documentTerms']=$this->pdfService->resolvedDocumentTerms($calculated);
                $calculated=$this->calculator->calculate($calculated);(new OfferValidator())->validate($calculated);
                $pdf=$this->pdfService->render($calculated);
                $files=$this->store->publishSubmission($projectId,$offerId,$calculated,$pdf);$published=true;
                if(!$this->offers->markSubmitted($offerId,$expectedEtag,$number,$calculated['submittedAt'],$documentDate,$files))throw new OfferConflictException('Das Angebot wurde zwischenzeitlich abgeschlossen.');
                $this->pdo->commit();return['submitted'=>true,'idempotent'=>false,'offerNumber'=>$number,'revision'=>$revision,'status'=>'submitted','submittedAt'=>$calculated['submittedAt'],'documentDate'=>$documentDate,'snapshotSha256'=>$files['snapshotHash'],'pdfSha256'=>$files['pdfHash']];
            });
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if($published)$this->store->removePublishedSubmission($projectId,$offerId);if($e instanceof PDOException&&str_starts_with((string)$e->getCode(),'23'))throw new OfferValidationException(['Die Angebotsnummer ist bereits vergeben.']);throw$e;}
    }

    /** @return array<string,mixed> */
    public function changeStatus(string $offerId,string $expectedStatus,string $newStatus):array
    {
        $allowed=['submitted'=>['commissioned','rejected'],'commissioned'=>['rejected'],'rejected'=>['commissioned']];
        if(!in_array($newStatus,$allowed[$expectedStatus]??[],true))throw new OfferValidationException(['Dieser Statuswechsel ist nicht zulässig.']);
        $metadata=$this->offers->find($offerId);if($metadata===null)throw new OfferValidationException(['Angebot wurde nicht gefunden.']);
        if(($metadata['document_type']??'offer')==='change_order'&&($newStatus==='commissioned'||$expectedStatus==='commissioned'))throw new OfferValidationException(['Nachträge werden ausschließlich über den zugehörigen Auftrag angenommen und können danach nicht zurückgesetzt werden.']);
        $this->pdo->exec('BEGIN IMMEDIATE');try{if(!$this->offers->changeStatus($offerId,$expectedStatus,$newStatus))throw new OfferConflictException('Der Angebotsstatus wurde zwischenzeitlich geändert.');$this->pdo->commit();return['status'=>$newStatus];}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if($e instanceof PDOException&&str_starts_with((string)$e->getCode(),'23')&&$newStatus==='commissioned')throw new OfferValidationException(['In dieser Revisionshistorie ist bereits eine andere Revision als angenommen markiert.']);throw$e;}
    }

    /** @return array{pdf:string,fileName:string} */
    public function submittedPdf(string $offerId):array
    {
        if($this->pdfService===null)throw new RuntimeException('PDF-Erzeugung ist nicht konfiguriert.');
        $metadata=$this->offers->find($offerId);if($metadata===null||($metadata['submitted_pdf_sha256']??null)===null)throw new RuntimeException('Archivierte Angebots-PDF wurde nicht gefunden.');
        $pdf=$this->store->loadSubmittedPdf((string)$metadata['storage_key'],$offerId,(string)$metadata['submitted_pdf_sha256']);
        return['pdf'=>$pdf,'fileName'=>$this->pdfService->fileName(['offerNumber'=>$metadata['effective_offer_number']??$metadata['offer_number'],'documentDate'=>$metadata['document_date'],'revision'=>(int)($metadata['revision_number']??0),'documentType'=>$metadata['document_type']??'offer'])];
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function submissionResult(array $metadata,bool $idempotent):array{return['submitted'=>true,'idempotent'=>$idempotent,'offerNumber'=>$metadata['effective_offer_number']??$metadata['offer_number'],'revision'=>(int)($metadata['revision_number']??0),'status'=>$metadata['status'],'submittedAt'=>$metadata['submitted_at'],'documentDate'=>$metadata['document_date'],'snapshotSha256'=>$metadata['snapshot_etag'],'pdfSha256'=>$metadata['submitted_pdf_sha256']];}

    /** @return array<string,mixed> */
    public function previewImport(string $offerId, array $imported, string $expectedEtag): array
    {
        $current = $this->loadDraft($offerId);
        if (!hash_equals($current['etag'], $expectedEtag)) {
            throw new OfferConflictException('Der Entwurf wurde zwischenzeitlich geändert. Bitte neu laden.');
        }
        $prepared = $this->prepareImport(
            $imported,
            $current['offer']['projectId']!==null?(string)$current['offer']['projectId']:null,
            (string)$current['offer']['customerId'],
            $offerId,
            (string) $current['offer']['createdAt'],
            (string)($current['offer']['documentType']??'offer'),
            $current['offer']['orderId']??null,
        );
        // Beim Import in eine offene Revision bleibt die serverseitig verwaltete
        // Dokumentidentität erhalten; fremde Nummern und Revisionen werden ignoriert.
        $prepared['offerNumber']=$current['offer']['offerNumber']??null;
        $prepared['revision']=(int)($current['offer']['revision']??0);
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
    public function importExisting(string $offerId, array $imported, string $expectedEtag,?callable $writeGuard=null): string
    {
        $preview = $this->previewImport($offerId, $imported, $expectedEtag);
        return $this->saveDraft($offerId, $preview['offer'], $expectedEtag,$writeGuard);
    }

    /** @param array<string,mixed> $imported */
    public function importNew(?string $projectId,array $imported,?string $customerId=null,string $documentType='offer',?string $orderId=null): string
    {
        $project=$projectId!==null?$this->projects->find($projectId):null;if($projectId!==null&&$project===null)throw new RuntimeException('Projekt wurde nicht gefunden.');if($projectId!==null&&$customerId!==null&&$customerId!==''&&(string)($project['customer_id']??'')!==$customerId)throw new OfferValidationException(['Das Projekt gehört nicht zum ausgewählten Kunden.']);
        $customerId=$projectId!==null?(string)($project['customer_id']??''):(string)$customerId;if($customerId===''||!$this->customerExists($customerId))throw new OfferValidationException(['Ein gültiger Kunde ist erforderlich.']);
        $storageKey=$projectId??$customerId;
        $id = Uuid::v4();
        if(!in_array($documentType,['offer','change_order'],true)||($documentType==='change_order'&&$orderId===null))throw new OfferValidationException(['Ungültige Dokumentart oder Auftragszuordnung.']);
        $prepared=$this->prepareImport($imported,$projectId,$customerId,$id,Clock::now(),$documentType,$orderId);
        $etag=$this->store->saveDraft($storageKey,$id,$prepared,null);
        $this->offers->create($id,$projectId,$customerId,$storageKey,$this->store->relativeDraftPath($storageKey,$id),$etag,$documentType,$orderId);
        return $id;
    }

    /** @param array<string,mixed> $offer @return array<string,mixed> */
    private function prepareImport(array $offer,?string $projectId,string $customerId,string $offerId,string $createdAt,string $documentType='offer',?string $orderId=null):array
    {
        $offer['schemaVersion'] = 1;
        $offer['id'] = $offerId;
        $offer['projectId'] = $projectId;
        $offer['customerId']=$customerId;
        $offer['documentType']=$documentType;
        $offer['orderId']=$orderId;
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

    private function customerExists(string $id):bool{return $this->customer($id)!==null;}
    /** @return array<string,mixed>|null */
    private function customer(string $id):?array{$statement=$this->pdo->prepare('SELECT * FROM customers WHERE id=:id');$statement->execute(['id'=>$id]);$row=$statement->fetch();return is_array($row)?$row:null;}

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
