<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Offer\OfferValidator;
use App\Support\Uuid;
use JsonException;
use RuntimeException;
use Throwable;

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

    public function withExclusiveLock(string $projectId,string $offerId,callable $operation):mixed
    {
        $this->assertIdentifiers($projectId,$offerId);
        $directory=$this->offerDirectory($projectId,$offerId);
        if(!is_dir($directory))throw new RuntimeException('Angebotsverzeichnis wurde nicht gefunden.');
        $lock=fopen($directory.'/.draft.lock','c');
        if($lock===false||!flock($lock,LOCK_EX))throw new RuntimeException('Angebot konnte nicht gesperrt werden.');
        try{return$operation();}finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** @param array<string,mixed> $snapshot @return array{snapshotPath:string,snapshotHash:string,pdfPath:string,pdfHash:string} */
    public function publishSubmission(string $projectId,string $offerId,array $snapshot,string $pdf):array
    {
        $this->assertIdentifiers($projectId,$offerId);$directory=$this->offerDirectory($projectId,$offerId);
        $snapshotPath=$directory.'/snapshot.json';$pdfPath=$directory.'/submitted.pdf';
        // Dateien ohne zugehörigen DB-Abschluss stammen von einem abgebrochenen vorherigen Versuch.
        foreach([$snapshotPath,$pdfPath] as $orphan)if(is_file($orphan)&&!unlink($orphan))throw new RuntimeException('Verwaiste Abschlussdatei konnte nicht bereinigt werden.');
        try{$json=json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";}catch(JsonException $e){throw new RuntimeException('Abschluss-Snapshot konnte nicht erzeugt werden.',0,$e);}
        $snapshotTemp=tempnam($directory,'.snapshot-');$pdfTemp=tempnam($directory,'.submitted-');
        if($snapshotTemp===false||$pdfTemp===false){if(is_string($snapshotTemp)&&is_file($snapshotTemp))unlink($snapshotTemp);if(is_string($pdfTemp)&&is_file($pdfTemp))unlink($pdfTemp);throw new RuntimeException('Temporäre Abschlussdateien konnten nicht angelegt werden.');}
        $published=[];
        try{
            if(file_put_contents($snapshotTemp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('Snapshot konnte nicht vollständig geschrieben werden.');
            $decoded=json_decode((string)file_get_contents($snapshotTemp),true,flags:JSON_THROW_ON_ERROR);
            if(!is_array($decoded))throw new RuntimeException('Snapshot ist ungültig.');
            $this->validator->validate($decoded);
            if(!str_starts_with($pdf,'%PDF-1.')||file_put_contents($pdfTemp,$pdf,LOCK_EX)!==strlen($pdf))throw new RuntimeException('Abschluss-PDF konnte nicht vollständig geschrieben werden.');
            if(!link($snapshotTemp,$snapshotPath))throw new RuntimeException('Snapshot konnte nicht unveränderlich bereitgestellt werden.');$published[]=$snapshotPath;
            if(!link($pdfTemp,$pdfPath))throw new RuntimeException('Abschluss-PDF konnte nicht unveränderlich bereitgestellt werden.');$published[]=$pdfPath;
            chmod($snapshotPath,0440);chmod($pdfPath,0440);
            $snapshotHash=hash_file('sha256',$snapshotPath);$pdfHash=hash_file('sha256',$pdfPath);
            if($snapshotHash===false||$pdfHash===false)throw new RuntimeException('Integritätsprüfung der Abschlussdateien ist fehlgeschlagen.');
            return['snapshotPath'=>$this->relativePath($snapshotPath),'snapshotHash'=>$snapshotHash,'pdfPath'=>$this->relativePath($pdfPath),'pdfHash'=>$pdfHash];
        }catch(Throwable $e){foreach($published as $path)if(is_file($path))unlink($path);if($e instanceof JsonException)throw new RuntimeException('Geschriebener Snapshot ist ungültig.',0,$e);throw$e;
        }finally{foreach([$snapshotTemp,$pdfTemp] as $temp)if(is_file($temp))unlink($temp);}
    }

    public function removePublishedSubmission(string $projectId,string $offerId):void
    {
        $directory=$this->offerDirectory($projectId,$offerId);foreach([$directory.'/snapshot.json',$directory.'/submitted.pdf'] as $path)if(is_file($path))unlink($path);
    }

    /** @return array<string,mixed> */
    public function loadSnapshot(string $projectId,string $offerId,string $expectedHash):array
    {
        $path=$this->offerDirectory($projectId,$offerId).'/snapshot.json';
        if(!is_file($path)||!hash_equals($expectedHash,(string)hash_file('sha256',$path)))throw new RuntimeException('Abschluss-Snapshot fehlt oder wurde verändert.');
        try{$snapshot=json_decode((string)file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new RuntimeException('Abschluss-Snapshot ist ungültig.',0,$e);}
        if(!is_array($snapshot))throw new RuntimeException('Abschluss-Snapshot ist ungültig.');$this->validator->validate($snapshot);return$snapshot;
    }

    public function loadSubmittedPdf(string $projectId,string $offerId,string $expectedHash):string
    {
        $path=$this->offerDirectory($projectId,$offerId).'/submitted.pdf';
        if(!is_file($path)||!hash_equals($expectedHash,(string)hash_file('sha256',$path)))throw new RuntimeException('Archivierte Angebots-PDF fehlt oder wurde verändert.');
        return(string)file_get_contents($path);
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

    private function relativePath(string $path):string{return ltrim(substr($path,strlen(rtrim($this->basePath,'/'))),'/');}

    private function assertIdentifiers(string $projectId, string $offerId): void
    {
        if (!Uuid::isValid($projectId) || !Uuid::isValid($offerId)) {
            throw new RuntimeException('Ungueltige interne ID.');
        }
    }
}
