<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\OfferConflictException;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\ProjectRepository;
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
}
