<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DocumentLockService;
use App\Application\UserAccessService;
use App\Domain\DocumentLockException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\DocumentLockRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Clock;
use App\Support\Uuid;
use App\Tests\TestCase;

final class PermissionsAndLocksTest extends TestCase
{
    public function testExistingUsersBecomeAdministratorsDuringMigration():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/calight-permissions-'.bin2hex(random_bytes(6));$before=$tmp.'/before';mkdir($before,0770,true);
        try{
            foreach(glob($root.'/migrations/*.sql')?:[] as $file)if(basename($file)<'0020_permissions_and_document_locks.sql')copy($file,$before.'/'.basename($file));
            $pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$before))->migrate();$now=Clock::now();
            $legacyId=Uuid::v4();$pdo->prepare("INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,'alt','hash','Alt','employee',1,:now,:now)")->execute(['id'=>$legacyId,'now'=>$now]);
            copy($root.'/migrations/0020_permissions_and_document_locks.sql',$before.'/0020_permissions_and_document_locks.sql');
            $this->assertSame(['0020_permissions_and_document_locks'],(new Migrator($pdo,$before))->migrate());
            $this->assertSame('admin',(string)$pdo->query("SELECT role FROM users WHERE username='alt'")->fetchColumn());
            $this->assertSame(1,(int)$pdo->query("SELECT active FROM users WHERE username='alt'")->fetchColumn());
            $repository=new UserRepository($pdo);$repository->create(Uuid::v4(),'zweiter-admin','hash','Zweiter Admin',Clock::now());$access=new UserAccessService($repository);$access->update($legacyId,['role'=>'technician','active'=>false],'different-user');
            $this->assertSame(null,$repository->findActiveById($legacyId));
        }finally{$this->remove($tmp);}
    }

    public function testLockContentionRenewalExpiryTakeoverAndWriteProtection():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/calight-locks-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{
            $pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$now=Clock::now();$admin=Uuid::v4();$office=Uuid::v4();$customer=Uuid::v4();$offer=Uuid::v4();
            $insertUser=$pdo->prepare('INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:username,\'hash\',:name,:role,1,:now,:now)');
            $insertUser->execute(['id'=>$admin,'username'=>'admin','name'=>'Admin','role'=>'admin','now'=>$now]);$insertUser->execute(['id'=>$office,'username'=>'office','name'=>'Büro','role'=>'office','now'=>$now]);
            $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,created_at,updated_at) VALUES(:id,'10003','company','Kunde',:now,:now)")->execute(['id'=>$customer,'now'=>$now]);
            $pdo->prepare("INSERT INTO offers(id,customer_id,storage_key,status,draft_relative_path,draft_etag,revision_root_id,created_at,updated_at) VALUES(:id,:customer,:id,'draft',:path,'etag',:id,:now,:now)")->execute(['id'=>$offer,'customer'=>$customer,'path'=>$offer.'.json','now'=>$now]);
            $service=new DocumentLockService($pdo,new DocumentLockRepository($pdo));$adminUser=['id'=>$admin,'username'=>'admin','displayName'=>'Admin','role'=>'admin'];$officeUser=['id'=>$office,'username'=>'office','displayName'=>'Büro','role'=>'office'];
            $first=$service->acquire('offer',$offer,$officeUser);$this->assertSame(true,$first['granted']);$this->assertSame(false,$service->acquire('offer',$offer,$adminUser)['granted']);
            $service->assertOwned('offer',$offer,$officeUser,$first['token']);$this->assertSame(true,$service->renew('offer',$offer,$officeUser,$first['token'])['granted']);
            $takeover=$service->acquire('offer',$offer,$adminUser,true);$this->assertSame(true,$takeover['granted']);$this->assertThrows(DocumentLockException::class,fn()=>$service->assertOwned('offer',$offer,$officeUser,$first['token']));$service->assertOwned('offer',$offer,$adminUser,$takeover['token']);
            $pdo->exec("UPDATE document_locks SET expires_at='2000-01-01T00:00:00Z'");$afterExpiry=$service->acquire('offer',$offer,$officeUser);$this->assertSame(true,$afterExpiry['granted']);
            $this->assertSame(true,$service->release('offer',$offer,$officeUser,$afterExpiry['token'])['released']);
        }finally{$this->remove($tmp);}
    }

    private function remove(string $path):void{if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->remove($child):unlink($child);}rmdir($path);}
}
