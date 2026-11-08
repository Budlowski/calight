<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\OfferService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidationException;
use App\Domain\Offer\OfferValidator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Tests\TestCase;
use App\Support\Uuid;

final class OfferExchangeTest extends TestCase
{
    public function testImportRoundTripRecalculatesAndProtectsDocumentIdentity():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/exchange-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{$pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$projects=new ProjectRepository($pdo);$service=new OfferService($pdo,$projects,new OfferRepository($pdo),new OfferJsonStore($tmp.'/offers',new OfferValidator()),new OfferCalculator(new PriceCalculator()));$project=$projects->create('Import','Kunde','Ort');$id=$service->createDraft($project,'Quelle');$loaded=$service->loadDraft($id);$document=$loaded['offer'];$document['nodes']=[['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Arbeit','longText'=>'','quantity'=>'2.000','unit'=>'Stk.','manualUnitPrice'=>'12.50','components'=>[]]];$etag=$service->saveDraft($id,$document,$loaded['etag']);$export=$service->loadDraft($id)['offer'];$copy=$service->importNew($project,$export);$imported=$service->loadDraft($copy)['offer'];$this->assertSame('Quelle',$imported['title']);$this->assertSame('25.00',$imported['calculation']['netTotal']);$this->assertSame(null,$imported['offerNumber']);$this->assertTrue($imported['id']!==$export['id']);$preview=$service->previewImport($id,$export,$etag);$this->assertSame(1,$preview['changes']['nodes']['new']);$this->assertThrows(OfferValidationException::class,fn()=>$service->importNew($project,['title'=>'kaputt']));}finally{$this->remove($tmp);}
    }
    private function remove(string $path):void{if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->remove($child):unlink($child);}rmdir($path);}
}
