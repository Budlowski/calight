<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Offer\OfferValidator;
use App\Support\Uuid;
use JsonException;
use RuntimeException;

final readonly class OfferJsonStore
{
    public function __construct(private string $basePath, private OfferValidator $validator)
    {
    }

    /** @param array<string, mixed> $offer */
    public function saveDraft(string $projectId, string $offerId, array $offer, ?string $expectedEtag): string
    {
        $this->assertIdentifiers($projectId, $offerId);
        if (($offer['id'] ?? null) !== $offerId || ($offer['projectId'] ?? null) !== $projectId) {
            throw new RuntimeException('Pfad-IDs und Angebotsinhalt stimmen nicht ueberein.');
        }
        $this->validator->validate($offer);

        $directory = $this->offerDirectory($projectId, $offerId);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Angebotsverzeichnis konnte nicht angelegt werden.');
        }

        $path = $directory . '/draft.json';
        $lock = fopen($directory . '/.draft.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Angebotsentwurf konnte nicht gesperrt werden.');
        }

        try {
            $currentEtag = is_file($path) ? hash_file('sha256', $path) : null;
            if ($expectedEtag !== $currentEtag) {
                throw new OfferConflictException('Der Entwurf wurde zwischenzeitlich geaendert. Bitte neu laden.');
            }

            $json = json_encode($offer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $temporaryPath = tempnam($directory, '.draft-');
            if ($temporaryPath === false) {
                throw new RuntimeException('Temporaere Angebotsdatei konnte nicht angelegt werden.');
            }
            try {
                if (file_put_contents($temporaryPath, $json, LOCK_EX) !== strlen($json)) {
                    throw new RuntimeException('Angebotsentwurf konnte nicht vollstaendig geschrieben werden.');
                }
                $written = json_decode((string) file_get_contents($temporaryPath), true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($written)) {
                    throw new RuntimeException('Geschriebener Angebotsentwurf ist ungueltig.');
                }
                $this->validator->validate($written);
                if (!rename($temporaryPath, $path)) {
                    throw new RuntimeException('Angebotsentwurf konnte nicht atomar ersetzt werden.');
                }
            } finally {
                if (isset($temporaryPath) && is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }

            $etag = hash_file('sha256', $path);
            if ($etag === false) {
                throw new RuntimeException('Pruefsumme des Angebots konnte nicht gebildet werden.');
            }
            return $etag;
        } catch (JsonException $exception) {
            throw new RuntimeException('Angebot konnte nicht als JSON gespeichert werden.', 0, $exception);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{offer:array<string,mixed>,etag:string} */
    public function loadDraft(string $projectId, string $offerId): array
    {
        $this->assertIdentifiers($projectId, $offerId);
        $path = $this->offerDirectory($projectId, $offerId) . '/draft.json';
        if (!is_file($path)) {
            throw new RuntimeException('Angebotsentwurf wurde nicht gefunden.');
        }
        try {
            $offer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Angebotsentwurf enthaelt ungueltiges JSON.', 0, $exception);
        }
        if (!is_array($offer)) {
            throw new RuntimeException('Angebotsentwurf ist ungueltig.');
        }
        $this->validator->validate($offer);
        $etag = hash_file('sha256', $path);
        if ($etag === false) {
            throw new RuntimeException('Pruefsumme des Angebots konnte nicht gebildet werden.');
        }
        return ['offer' => $offer, 'etag' => $etag];
    }

    public function relativeDraftPath(string $projectId, string $offerId): string
    {
        $this->assertIdentifiers($projectId, $offerId);
        return $projectId . '/' . $offerId . '/draft.json';
    }

    public function stageDraftDeletion(string $projectId,string $offerId):string
    {
        $this->assertIdentifiers($projectId,$offerId);
        $directory=$this->offerDirectory($projectId,$offerId);
        if(!is_dir($directory))throw new RuntimeException('Angebotsentwurf wurde nicht gefunden.');
        $staged=dirname($directory).'/.deleted-'.$offerId.'-'.bin2hex(random_bytes(6));
        if(!rename($directory,$staged))throw new RuntimeException('Angebotsentwurf konnte nicht zum Löschen vorbereitet werden.');
        return$staged;
    }

    public function restoreStagedDeletion(string $stagedPath,string $projectId,string $offerId):void
    {
        $this->assertIdentifiers($projectId,$offerId);$target=$this->offerDirectory($projectId,$offerId);
        if(is_dir($target)||!is_dir($stagedPath)||!rename($stagedPath,$target))throw new RuntimeException('Angebotsentwurf konnte nach einem Datenbankfehler nicht wiederhergestellt werden.');
    }

    public function finalizeStagedDeletion(string $stagedPath):void
    {
        if(!is_dir($stagedPath))return;
        foreach(scandir($stagedPath)?:[] as $item){if($item==='.'||$item==='..')continue;$path=$stagedPath.'/'.$item;if(is_dir($path))throw new RuntimeException('Unerwartetes Unterverzeichnis im Angebotsentwurf.');if(!unlink($path))throw new RuntimeException('Datei des Angebotsentwurfs konnte nicht gelöscht werden.');}
        if(!rmdir($stagedPath))throw new RuntimeException('Verzeichnis des Angebotsentwurfs konnte nicht gelöscht werden.');
    }

    private function offerDirectory(string $projectId, string $offerId): string
    {
        return rtrim($this->basePath, '/') . '/' . $projectId . '/' . $offerId;
    }

    private function assertIdentifiers(string $projectId, string $offerId): void
    {
        if (!Uuid::isValid($projectId) || !Uuid::isValid($offerId)) {
            throw new RuntimeException('Ungueltige interne ID.');
        }
    }
}
