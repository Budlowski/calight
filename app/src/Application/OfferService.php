<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\OfferConflictException;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Domain\Offer\OfferNormalizer;
use App\Support\Clock;
use App\Support\Uuid;
use PDO;
use RuntimeException;
use Throwable;

final readonly class OfferService
{
    public function __construct(
        private PDO $pdo,
        private ProjectRepository $projects,
        private OfferRepository $offers,
        private OfferJsonStore $store,
        private OfferCalculator $calculator,
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

    /** @return list<array<string,mixed>> */
    public function overview(): array
    {
        $result=[];
        foreach($this->offers->all() as $metadata){
            $draft=$this->store->loadDraft((string)$metadata['project_id'],(string)$metadata['id']);
            $result[]=['id'=>$metadata['id'],'projectId'=>$metadata['project_id'],'projectName'=>$metadata['project_name'],
                'projectNumber'=>$metadata['project_number'],'offerNumber'=>$metadata['offer_number'],'status'=>$metadata['status'],
                'title'=>$draft['offer']['title'],'updatedAt'=>$metadata['updated_at'],'draftVersion'=>(int)$metadata['draft_version']];
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
        $draft = $this->store->loadDraft((string) $metadata['project_id'], $offerId);
        if (!hash_equals((string) $metadata['draft_etag'], $draft['etag'])) {
            throw new RuntimeException('Angebotsdatei und Datenbankstand stimmen nicht ueberein.');
        }
        return ['metadata' => $metadata, 'offer' => $draft['offer'], 'etag' => $draft['etag']];
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

        $previousDraft = $this->store->loadDraft((string) $metadata['project_id'], $offerId);
        $newEtag = null;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $offer = (new OfferNormalizer())->normalize($offer);
            $offer['updatedAt'] = Clock::now();
            $offer = $this->calculator->calculate($offer);
            (new OfferValidator())->validate($offer);
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
    public function previewImport(string $offerId,array $imported,string $expectedEtag):array
    {
        $current=$this->loadDraft($offerId);if(!hash_equals($current['etag'],$expectedEtag))throw new OfferConflictException('Der Entwurf wurde zwischenzeitlich geändert. Bitte neu laden.');
        $prepared=$this->prepareImport($imported,(string)$current['offer']['projectId'],$offerId,(string)$current['offer']['createdAt']);
        return ['offer'=>$prepared,'changes'=>['title'=>['old'=>$current['offer']['title'],'new'=>$prepared['title']],'nodes'=>['old'=>$this->countNodes($current['offer']['nodes']),'new'=>$this->countNodes($prepared['nodes'])],'netTotal'=>['old'=>$current['offer']['calculation']['netTotal']??'0.00','new'=>$prepared['calculation']['netTotal']??'0.00']]];
    }

    /** @param array<string,mixed> $imported */
    public function importExisting(string $offerId,array $imported,string $expectedEtag):string
    {
        $preview=$this->previewImport($offerId,$imported,$expectedEtag);return $this->saveDraft($offerId,$preview['offer'],$expectedEtag);
    }

    /** @param array<string,mixed> $imported */
    public function importNew(string $projectId,array $imported):string
    {
        $id=Uuid::v4();$prepared=$this->prepareImport($imported,$projectId,$id,Clock::now());
        if($this->projects->find($projectId)===null)throw new RuntimeException('Projekt wurde nicht gefunden.');
        $etag=$this->store->saveDraft($projectId,$id,$prepared,null);
        $this->offers->create($id,$projectId,$this->store->relativeDraftPath($projectId,$id),$etag);return $id;
    }

    /** @param array<string,mixed> $offer @return array<string,mixed> */
    private function prepareImport(array $offer,string $projectId,string $offerId,string $createdAt):array
    {
        $offer['schemaVersion']=1;$offer['id']=$offerId;$offer['projectId']=$projectId;$offer['offerNumber']=null;$offer['revision']=0;$offer['status']='draft';$offer['createdAt']=$createdAt;$offer['updatedAt']=Clock::now();
        $offer=(new OfferNormalizer())->normalize($offer,true);$validator=new OfferValidator();$validator->validate($offer);
        $offer=$this->calculator->calculate($offer);$validator->validate($offer);return $offer;
    }
    /** @param list<array<string,mixed>> $nodes */ private function countNodes(array $nodes):int{$count=count($nodes);foreach($nodes as $node)if(($node['type']??'')==='title')$count+=$this->countNodes($node['children']);return $count;}
}
