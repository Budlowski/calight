<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\GeneratedDocumentRepository;
use App\Support\Clock;
use App\Support\Uuid;
use PDOException;
use Throwable;

/**
 * Archiv ausgegebener verbindlicher Dokumente.
 *
 * Für einen fachlichen Stand (Prüfsumme) wird das PDF einmal erzeugt und unter
 * APP_DOCUMENTS_PATH gespeichert; spätere Abrufe liefern genau diese Datei.
 * Ein neuer Stand erhält eine neue Datei, ältere bleiben erhalten.
 */
final readonly class DocumentArchive
{
    private const FOLDERS = ['work_report' => 'work-reports', 'measurement' => 'measurements'];

    public function __construct(private GeneratedDocumentRepository $documents, private AttachmentStore $store)
    {
    }

    /**
     * @param callable():string $render erzeugt das PDF nur, wenn der Stand noch nicht archiviert ist
     * @return array{pdf:string,fileName:string,archivedAt:string}
     */
    public function fetchOrCreate(string $type, string $entityId, string $sourceHash, string $fileName, callable $render, string $userId): array
    {
        $existing = $this->documents->find($type, $entityId, $sourceHash);
        if ($existing !== null) {
            return ['pdf' => $this->store->read((string) $existing['storage_path']), 'fileName' => (string) $existing['file_name'], 'archivedAt' => (string) $existing['created_at']];
        }
        $pdf = $render();
        $id = Uuid::v4();
        $path = $this->store->storeContents($pdf, self::FOLDERS[$type] . '/' . $entityId, $id, 'pdf');
        $now = Clock::now();
        try {
            $this->documents->insert([
                'id' => $id, 'document_type' => $type, 'entity_id' => $entityId, 'source_sha256' => $sourceHash,
                'file_name' => $fileName, 'storage_path' => $path, 'sha256' => hash('sha256', $pdf), 'size_bytes' => strlen($pdf),
                'created_by_user_id' => $userId, 'created_at' => $now,
            ]);
        } catch (PDOException $exception) {
            // Gleichzeitige Erzeugung desselben Stands: die zuerst archivierte Datei gilt.
            $this->discard($path);
            $winner = $this->documents->find($type, $entityId, $sourceHash);
            if ($winner === null) {
                throw $exception;
            }
            return ['pdf' => $this->store->read((string) $winner['storage_path']), 'fileName' => (string) $winner['file_name'], 'archivedAt' => (string) $winner['created_at']];
        } catch (Throwable $exception) {
            $this->discard($path);
            throw $exception;
        }
        return ['pdf' => $pdf, 'fileName' => $fileName, 'archivedAt' => $now];
    }

    private function discard(string $path): void
    {
        try {
            $this->store->delete($path);
        } catch (Throwable) {
            // Verwaiste Datei ist unkritisch.
        }
    }
}
