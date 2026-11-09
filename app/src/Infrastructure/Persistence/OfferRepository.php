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

    /** @param array{snapshotPath:string,snapshotHash:string,pdfPath:string,pdfHash:string} $files */
    public function markSubmitted(string $id,string $expectedEtag,string $number,string $submittedAt,string $documentDate,array $files):bool
    {
        $statement=$this->pdo->prepare('UPDATE offers SET offer_number=:number,status=:submitted,snapshot_relative_path=:snapshot_path,
            snapshot_etag=:snapshot_hash,submitted_pdf_relative_path=:pdf_path,submitted_pdf_sha256=:pdf_hash,
            submitted_at=:submitted_at,document_date=:document_date,updated_at=:updated_at
            WHERE id=:id AND status=:draft AND offer_number IS NULL AND draft_etag=:etag');
        $statement->execute(['number'=>$number,'submitted'=>'submitted','snapshot_path'=>$files['snapshotPath'],'snapshot_hash'=>$files['snapshotHash'],
            'pdf_path'=>$files['pdfPath'],'pdf_hash'=>$files['pdfHash'],'submitted_at'=>$submittedAt,'document_date'=>$documentDate,
            'updated_at'=>$submittedAt,'id'=>$id,'draft'=>'draft','etag'=>$expectedEtag]);
        return$statement->rowCount()===1;
    }

    public function changeStatus(string $id,string $expected,string $newStatus):bool
    {
        $statement=$this->pdo->prepare('UPDATE offers SET status=:new_status,updated_at=:updated_at WHERE id=:id AND status=:expected AND snapshot_etag IS NOT NULL');
        $statement->execute(['new_status'=>$newStatus,'updated_at'=>Clock::now(),'id'=>$id,'expected'=>$expected]);return$statement->rowCount()===1;
    }

    public function deleteDraft(string $id):bool
    {
        $statement=$this->pdo->prepare('DELETE FROM offers WHERE id=:id AND status=:status');
        $statement->execute(['id'=>$id,'status'=>'draft']);
        return $statement->rowCount()===1;
    }
}
