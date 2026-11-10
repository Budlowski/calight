<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final readonly class DocumentLockRepository
{
    public function __construct(private PDO $pdo) {}

    public function deleteExpired(string $now): void
    {
        $statement=$this->pdo->prepare('DELETE FROM document_locks WHERE expires_at<=:now');
        $statement->execute(['now'=>$now]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $type,string $id):?array
    {
        $statement=$this->pdo->prepare('SELECT l.*,u.display_name,u.username FROM document_locks l JOIN users u ON u.id=l.user_id WHERE l.document_type=:type AND l.document_id=:id');
        $statement->execute(['type'=>$type,'id'=>$id]);$row=$statement->fetch();return is_array($row)?$row:null;
    }

    public function replace(string $type,string $id,string $userId,string $tokenHash,string $expiresAt,string $now):void
    {
        $statement=$this->pdo->prepare('INSERT INTO document_locks(document_type,document_id,user_id,token_hash,expires_at,created_at,updated_at) VALUES(:type,:id,:user_id,:token_hash,:expires_at,:now,:now) ON CONFLICT(document_type,document_id) DO UPDATE SET user_id=excluded.user_id,token_hash=excluded.token_hash,expires_at=excluded.expires_at,updated_at=excluded.updated_at');
        $statement->execute(['type'=>$type,'id'=>$id,'user_id'=>$userId,'token_hash'=>$tokenHash,'expires_at'=>$expiresAt,'now'=>$now]);
    }

    public function renew(string $type,string $id,string $userId,string $tokenHash,string $expiresAt,string $now):bool
    {
        $statement=$this->pdo->prepare('UPDATE document_locks SET expires_at=:expires_at,updated_at=:now WHERE document_type=:type AND document_id=:id AND user_id=:user_id AND token_hash=:token_hash AND expires_at>:now');
        $statement->execute(['expires_at'=>$expiresAt,'now'=>$now,'type'=>$type,'id'=>$id,'user_id'=>$userId,'token_hash'=>$tokenHash]);return $statement->rowCount()===1;
    }

    public function release(string $type,string $id,string $userId,string $tokenHash):bool
    {
        $statement=$this->pdo->prepare('DELETE FROM document_locks WHERE document_type=:type AND document_id=:id AND user_id=:user_id AND token_hash=:token_hash');
        $statement->execute(['type'=>$type,'id'=>$id,'user_id'=>$userId,'token_hash'=>$tokenHash]);return $statement->rowCount()===1;
    }
}
