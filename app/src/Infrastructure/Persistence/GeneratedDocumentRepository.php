<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final readonly class GeneratedDocumentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(string $type, string $entityId, string $sourceHash): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM generated_documents WHERE document_type = :type AND entity_id = :entity AND source_sha256 = :source');
        $statement->execute(['type' => $type, 'entity' => $entityId, 'source' => $sourceHash]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function forEntity(string $type, string $entityId): array
    {
        $statement = $this->pdo->prepare('SELECT id, file_name, sha256, size_bytes, created_at FROM generated_documents
            WHERE document_type = :type AND entity_id = :entity ORDER BY created_at DESC, id');
        $statement->execute(['type' => $type, 'entity' => $entityId]);
        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): void
    {
        $this->pdo->prepare('INSERT INTO generated_documents
            (id, document_type, entity_id, source_sha256, file_name, storage_path, sha256, size_bytes, created_by_user_id, created_at)
            VALUES (:id, :document_type, :entity_id, :source_sha256, :file_name, :storage_path, :sha256, :size_bytes, :created_by_user_id, :created_at)')
            ->execute($data);
    }
}
