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
        $statement = $this->pdo->prepare('SELECT o.*,
            COALESCE(o.offer_number, root.offer_number) AS effective_offer_number
            FROM offers o LEFT JOIN offers root ON root.id = COALESCE(o.revision_root_id, o.id)
            WHERE o.id = :id');
        $statement->execute(['id' => $id]);
        $offer = $statement->fetch();

        return is_array($offer) ? $offer : null;
    }

    /** @return list<array<string,mixed>> */
    public function forProject(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT o.*,
            COALESCE(o.offer_number, root.offer_number) AS effective_offer_number
            FROM offers o LEFT JOIN offers root ON root.id = COALESCE(o.revision_root_id, o.id)
            WHERE o.project_id = :project_id
              AND NOT EXISTS (SELECT 1 FROM offers newer
                WHERE COALESCE(newer.revision_root_id,newer.id)=COALESCE(o.revision_root_id,o.id)
                  AND newer.revision_number>o.revision_number)
            ORDER BY o.created_at DESC');
        $statement->execute(['project_id' => $projectId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT o.*, p.name AS project_name, p.project_number,
            COALESCE(o.offer_number, root.offer_number) AS effective_offer_number
            FROM offers o JOIN projects p ON p.id=o.project_id
            LEFT JOIN offers root ON root.id=COALESCE(o.revision_root_id,o.id)
            WHERE NOT EXISTS (SELECT 1 FROM offers newer
                WHERE COALESCE(newer.revision_root_id,newer.id)=COALESCE(o.revision_root_id,o.id)
                  AND newer.revision_number>o.revision_number)
            ORDER BY o.updated_at DESC')->fetchAll();
    }

    public function create(string $id, string $projectId, string $relativePath, string $etag): void
    {
        $now = Clock::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO offers (id, project_id, draft_relative_path, draft_etag, revision_root_id, revision_number, created_at, updated_at)
             VALUES (:id, :project_id, :draft_relative_path, :draft_etag, :id, 0, :created_at, :updated_at)'
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

    public function createRevision(string $id,string $projectId,string $relativePath,string $etag,string $rootId,int $revision,string $previousId):void
    {
        $now=Clock::now();
        $statement=$this->pdo->prepare('INSERT INTO offers
            (id,project_id,draft_relative_path,draft_etag,revision_root_id,revision_number,previous_revision_id,created_at,updated_at)
            VALUES (:id,:project_id,:path,:etag,:root,:revision,:previous,:created,:updated)');
        $statement->execute(['id'=>$id,'project_id'=>$projectId,'path'=>$relativePath,'etag'=>$etag,'root'=>$rootId,
            'revision'=>$revision,'previous'=>$previousId,'created'=>$now,'updated'=>$now]);
    }

    /** @return list<array<string,mixed>> */
    public function revisions(string $rootId):array
    {
        $statement=$this->pdo->prepare('SELECT o.*,COALESCE(o.offer_number,root.offer_number) AS effective_offer_number
            FROM offers o LEFT JOIN offers root ON root.id=:joined_root
            WHERE COALESCE(o.revision_root_id,o.id)=:filtered_root ORDER BY o.revision_number DESC');
        $statement->execute(['joined_root'=>$rootId,'filtered_root'=>$rootId]);return$statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function latestRevision(string $rootId):?array
    {
        $items=$this->revisions($rootId);return$items[0]??null;
    }

    /** @return array<string,mixed>|null */
    public function openRevision(string $rootId):?array
    {
        $statement=$this->pdo->prepare('SELECT o.*,COALESCE(o.offer_number,root.offer_number) AS effective_offer_number
            FROM offers o LEFT JOIN offers root ON root.id=:joined_root
            WHERE COALESCE(o.revision_root_id,o.id)=:filtered_root AND o.status=:draft LIMIT 1');
        $statement->execute(['joined_root'=>$rootId,'filtered_root'=>$rootId,'draft'=>'draft']);$row=$statement->fetch();return is_array($row)?$row:null;
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
        $statement=$this->pdo->prepare('UPDATE offers SET offer_number=CASE WHEN revision_number=0 THEN :number ELSE NULL END,status=:submitted,snapshot_relative_path=:snapshot_path,
            snapshot_etag=:snapshot_hash,submitted_pdf_relative_path=:pdf_path,submitted_pdf_sha256=:pdf_hash,
            submitted_at=:submitted_at,document_date=:document_date,updated_at=:updated_at
            WHERE id=:id AND status=:draft AND draft_etag=:etag');
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
