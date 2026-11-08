<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\Clock;
use PDO;

final readonly class OfferRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM offers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $offer = $statement->fetch();

        return is_array($offer) ? $offer : null;
    }

    /** @return list<array<string,mixed>> */
    public function forProject(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM offers WHERE project_id = :project_id ORDER BY created_at DESC');
        $statement->execute(['project_id' => $projectId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT o.*, p.name AS project_name, p.project_number
            FROM offers o JOIN projects p ON p.id=o.project_id ORDER BY o.updated_at DESC')->fetchAll();
    }

    public function create(string $id, string $projectId, string $relativePath, string $etag): void
    {
        $now = Clock::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO offers (id, project_id, draft_relative_path, draft_etag, created_at, updated_at)
             VALUES (:id, :project_id, :draft_relative_path, :draft_etag, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'project_id' => $projectId,
            'draft_relative_path' => $relativePath,
            'draft_etag' => $etag,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updateEtag(string $id, string $oldEtag, string $newEtag): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE offers
             SET draft_etag = :new_etag, draft_version = draft_version + 1, updated_at = :updated_at
             WHERE id = :id AND draft_etag = :old_etag AND status = :status'
        );
        $statement->execute([
            'new_etag' => $newEtag,
            'updated_at' => Clock::now(),
            'id' => $id,
            'old_etag' => $oldEtag,
            'status' => 'draft',
        ]);
        return $statement->rowCount() === 1;
    }
}
