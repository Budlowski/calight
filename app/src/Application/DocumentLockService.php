<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\DocumentLockException;
use App\Infrastructure\Persistence\DocumentLockRepository;
use App\Support\Clock;
use DateTimeImmutable;
use PDO;
use Throwable;

final readonly class DocumentLockService
{
    private const TTL_SECONDS=120;

    public function __construct(private PDO $pdo,private DocumentLockRepository $locks) {}

    /** @return array<string,mixed> */
    public function acquire(string $type,string $id,array $user,bool $takeOver=false):array
    {
        $this->assertDocumentExists($type,$id);$now=Clock::now();$this->pdo->exec('BEGIN IMMEDIATE');
        try{
            $this->locks->deleteExpired($now);$current=$this->locks->find($type,$id);
            if($current!==null&&(string)$current['user_id']!==(string)$user['id']&&!$takeOver){$this->pdo->commit();return['granted'=>false,'owner'=>$this->owner($current),'expiresAt'=>$current['expires_at']];}
            if($takeOver&&($user['role']??'')!=='admin')throw new DocumentLockException('Nur Administratoren dürfen eine Bearbeitungssperre übernehmen.');
            $token=bin2hex(random_bytes(32));$expires=$this->expires($now);$this->locks->replace($type,$id,(string)$user['id'],hash('sha256',$token),$expires,$now);$this->pdo->commit();
            return['granted'=>true,'token'=>$token,'expiresAt'=>$expires,'owner'=>['id'=>$user['id'],'displayName'=>$user['displayName']??$user['username']??'Benutzer']];
        }catch(Throwable $exception){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$exception;}
    }

    /** @return array<string,mixed> */
    public function renew(string $type,string $id,array $user,string $token):array
    {
        $now=Clock::now();$expires=$this->expires($now);if($token===''||!$this->locks->renew($type,$id,(string)$user['id'],hash('sha256',$token),$expires,$now))throw new DocumentLockException('Die Bearbeitungssperre ist abgelaufen oder wurde übernommen.',$this->status($type,$id));
        return['granted'=>true,'expiresAt'=>$expires];
    }

    public function release(string $type,string $id,array $user,string $token):array
    {
        return['released'=>$token!==''&&$this->locks->release($type,$id,(string)$user['id'],hash('sha256',$token))];
    }

    public function assertOwned(string $type,string $id,array $user,string $token):void
    {
        $now=Clock::now();$current=$this->locks->find($type,$id);
        if($current===null||(string)$current['expires_at']<=$now||(string)$current['user_id']!==(string)$user['id']||$token===''||!hash_equals((string)$current['token_hash'],hash('sha256',$token)))throw new DocumentLockException('Für diese Änderung ist eine gültige Bearbeitungssperre erforderlich.',$current===null?[]:['owner'=>$this->owner($current),'expiresAt'=>$current['expires_at']]);
    }

    /** @return array<string,mixed> */
    public function status(string $type,string $id):array
    {
        $current=$this->locks->find($type,$id);if($current===null||(string)$current['expires_at']<=Clock::now())return['locked'=>false];
        return['locked'=>true,'owner'=>$this->owner($current),'expiresAt'=>$current['expires_at']];
    }

    private function assertDocumentExists(string $type,string $id):void
    {
        if($type!=='offer')throw new DocumentLockException('Dieser Dokumenttyp unterstützt keine Bearbeitungssperre.');
        $statement=$this->pdo->prepare('SELECT 1 FROM offers WHERE id=:id');$statement->execute(['id'=>$id]);if(!$statement->fetchColumn())throw new DocumentLockException('Dokument wurde nicht gefunden.');
    }

    private function expires(string $now):string{return(new DateTimeImmutable($now))->modify('+'.self::TTL_SECONDS.' seconds')->format('Y-m-d\TH:i:s\Z');}
    /** @param array<string,mixed> $row @return array{id:string,displayName:string} */
    private function owner(array $row):array{return['id'=>(string)$row['user_id'],'displayName'=>(string)($row['display_name']?:$row['username'])];}
}
