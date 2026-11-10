<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DailyReportService;
use App\Application\EmployeeService;
use App\Domain\DailyReportValidationException;
use App\Http\AccessDeniedException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\DailyReportRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Clock;
use App\Support\Uuid;
use App\Tests\TestCase;

final class DailyReportTest extends TestCase
{
    public function testEmployeeLinkTimeSnapshotStableTargetsAndExactTotals():void
    {
        [$pdo,$tmp,$ids]=$this->fixture();
        try{
            $employees=new EmployeeRepository($pdo);$employeeService=new EmployeeService($employees,new UserRepository($pdo));
            $employee=$employeeService->save(null,['displayName'=>'Monteur Max','personnelNumber'=>'M-7','userId'=>$ids['technician'],'hourlyCostRate'=>'42.3333','active'=>true]);
            $service=new DailyReportService($pdo,new DailyReportRepository($pdo),$employees,new OrderRepository($pdo));$office=['id'=>$ids['office'],'role'=>'office'];
            $targets=$service->orderTargets($ids['order'],$office);$keys=array_column($targets,'key');
            $this->assertTrue(in_array('order:'.$ids['order'].':title-1',$keys,true));
            $this->assertTrue(in_array('order:'.$ids['order'].':position-1',$keys,true));
            $this->assertTrue(in_array('addendum:'.$ids['addendum'].':add-position',$keys,true));
            $report=$service->create(['orderId'=>$ids['order'],'reportDate'=>'2026-11-10','title'=>'Montage','activities'=>'Verteiler montiert','entries'=>[['employeeId'=>$employee['id'],'durationHours'=>'1.500','targetKey'=>'order:'.$ids['order'].':position-1']]],$office);
            $this->assertSame('42.3333',$report['entries'][0]['hourly_cost_rate_snapshot']);$this->assertSame('63.50',$report['entries'][0]['labor_cost']);
            $employeeService->save($employee['id'],['displayName'=>'Monteur Max','personnelNumber'=>'M-7','userId'=>$ids['technician'],'hourlyCostRate'=>'50.0000','active'=>true]);
            $entry=$report['entries'][0];$updated=$service->update($report['id'],['version'=>$report['version'],'reportDate'=>'2026-11-10','title'=>'Montage','activities'=>'Verteiler fertig','entries'=>[['id'=>$entry['id'],'employeeId'=>$employee['id'],'workDate'=>'2026-11-10','durationHours'=>'2.000','targetKey'=>$entry['targetKey']]]],$office);
            $this->assertSame('42.3333',$updated['entries'][0]['hourly_cost_rate_snapshot']);$this->assertSame('84.67',$updated['entries'][0]['labor_cost']);$this->assertSame(['hours'=>'2.000','costs'=>'84.67'],$service->orderTotals($ids['order']));
            $officeOverview=$service->overview($office);$this->assertSame('84.67',$officeOverview[0]['total_labor_cost']);
            $technicianOverview=$service->overview(['id'=>$ids['technician'],'role'=>'technician']);$this->assertTrue(!isset($technicianOverview[0]['total_labor_cost']));
            $duplicateEntry=$updated['entries'][0];$this->assertThrows(DailyReportValidationException::class,fn()=>$service->update($report['id'],['version'=>$updated['version'],'reportDate'=>'2026-11-10','title'=>'Montage','activities'=>'Doppelte Zeile','entries'=>[['id'=>$duplicateEntry['id'],'employeeId'=>$employee['id'],'durationHours'=>'1.000','targetKey'=>$duplicateEntry['targetKey']],['id'=>$duplicateEntry['id'],'employeeId'=>$employee['id'],'durationHours'=>'1.000','targetKey'=>$duplicateEntry['targetKey']]]],$office));
            $this->assertThrows(DailyReportValidationException::class,fn()=>$service->update($report['id'],['version'=>$report['version'],'reportDate'=>'2026-11-10','title'=>'Veraltet','activities'=>'Veralteter Stand','entries'=>[]],$office));
        }finally{$this->remove($tmp);}
    }

    public function testTechnicianCanOnlyBookOwnTimeOnReleasedOrderAndCannotEditForeignReport():void
    {
        [$pdo,$tmp,$ids]=$this->fixture();
        try{
            $employees=new EmployeeRepository($pdo);$employeeService=new EmployeeService($employees,new UserRepository($pdo));$own=$employeeService->save(null,['displayName'=>'Techniker','userId'=>$ids['technician'],'hourlyCostRate'=>'30','active'=>true]);$other=$employeeService->save(null,['displayName'=>'Kollege','hourlyCostRate'=>'35','active'=>true]);
            $service=new DailyReportService($pdo,new DailyReportRepository($pdo),$employees,new OrderRepository($pdo));$office=['id'=>$ids['office'],'role'=>'office'];$tech=['id'=>$ids['technician'],'role'=>'technician'];
            $foreign=$service->create(['orderId'=>$ids['order'],'reportDate'=>'2026-11-10','activities'=>'Bürobericht','entries'=>[['employeeId'=>$other['id'],'durationHours'=>'1','targetKey'=>'order']]],$office);
            $this->assertThrows(AccessDeniedException::class,fn()=>$service->update($foreign['id'],['version'=>$foreign['version'],'reportDate'=>'2026-11-10','activities'=>'Manipuliert','entries'=>[]],$tech));
            $this->assertThrows(AccessDeniedException::class,fn()=>$service->create(['orderId'=>$ids['order'],'reportDate'=>'2026-11-10','activities'=>'Falsche Person','entries'=>[['employeeId'=>$other['id'],'durationHours'=>'1','targetKey'=>'order']]],$tech));
            $ownReport=$service->create(['orderId'=>$ids['order'],'reportDate'=>'2026-11-10','activities'=>'Eigene Zeit','entries'=>[['employeeId'=>$own['id'],'durationHours'=>'1','targetKey'=>'order']]],$tech);$this->assertTrue($ownReport['canEdit']);$this->assertTrue(!isset($ownReport['entries'][0]['labor_cost']));
            $pdo->prepare("UPDATE orders SET status='open' WHERE id=:id")->execute(['id'=>$ids['order']]);$this->assertThrows(AccessDeniedException::class,fn()=>$service->orderTargets($ids['order'],$tech));
        }finally{$this->remove($tmp);}
    }

    /** @return array{0:\PDO,1:string,2:array<string,string>} */
    private function fixture():array
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/calight-reports-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);$pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$now=Clock::now();$ids=['office'=>Uuid::v4(),'technician'=>Uuid::v4(),'customer'=>Uuid::v4(),'order'=>Uuid::v4(),'offer'=>Uuid::v4(),'addendum'=>Uuid::v4()];
        $u=$pdo->prepare('INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:username,\'hash\',:name,:role,1,:now,:now)');$u->execute(['id'=>$ids['office'],'username'=>'office','name'=>'Büro','role'=>'office','now'=>$now]);$u->execute(['id'=>$ids['technician'],'username'=>'tech','name'=>'Techniker','role'=>'technician','now'=>$now]);
        $pdo->prepare("INSERT INTO customers(id,customer_number,customer_type,company_name,active,created_at,updated_at) VALUES(:id,'10003','company','Kunde',1,:now,:now)")->execute(['id'=>$ids['customer'],'now'=>$now]);
        $basis=json_encode(['nodes'=>[['id'=>'title-1','type'=>'title','name'=>'Elektro','children'=>[['id'=>'position-1','type'=>'position','shortText'=>'Montage']]]]],JSON_THROW_ON_ERROR);$pdo->prepare("INSERT INTO orders(id,order_number,customer_id,order_type,title,order_date,status,basis_json,basis_sha256,created_at,updated_at) VALUES(:id,'AU1',:customer,'regie','Auftrag','2026-11-10','in_progress',:basis,'sha',:now,:now)")->execute(['id'=>$ids['order'],'customer'=>$ids['customer'],'basis'=>$basis,'now'=>$now]);
        $addBasis=json_encode(['nodes'=>[['id'=>'add-position','type'=>'position','shortText'=>'Nachtrag']]],JSON_THROW_ON_ERROR);$pdo->prepare("INSERT INTO offers(id,customer_id,storage_key,status,draft_relative_path,draft_etag,revision_root_id,document_type,order_id,created_at,updated_at) VALUES(:id,:customer,:id,'commissioned',:path,'etag',:id,'change_order',:order,:now,:now)")->execute(['id'=>$ids['offer'],'customer'=>$ids['customer'],'path'=>$ids['offer'].'.json','order'=>$ids['order'],'now'=>$now]);$pdo->prepare("INSERT INTO order_addenda(id,order_id,offer_revision_id,revision_root_id,basis_json,basis_sha256,net_total,accepted_at) VALUES(:id,:order,:offer,:offer,:basis,'sha','10.00',:now)")->execute(['id'=>$ids['addendum'],'order'=>$ids['order'],'offer'=>$ids['offer'],'basis'=>$addBasis,'now'=>$now]);return[$pdo,$tmp,$ids];
    }
    private function remove(string $path):void{if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->remove($child):unlink($child);}rmdir($path);}
}
