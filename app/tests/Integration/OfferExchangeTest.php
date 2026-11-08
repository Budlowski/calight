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
    public function testImportFillsOnlyMissingIdsAndDiscardsForeignCalculations():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/exchange-ai-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{$pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$projects=new ProjectRepository($pdo);$service=new OfferService($pdo,$projects,new OfferRepository($pdo),new OfferJsonStore($tmp.'/offers',new OfferValidator()),new OfferCalculator(new PriceCalculator()));$project=$projects->create('KI','Kunde','Ort');$kept=Uuid::v4();$document=['schemaVersion'=>1,'title'=>'KI-Angebot','nodes'=>[['id'=>$kept,'type'=>'title','name'=>'Titel','description'=>'','children'=>[['type'=>'position','positionType'=>'normal','shortText'=>'Position','longText'=>'','quantity'=>'2','unit'=>'Stk.','manualUnitPrice'=>'10','components'=>[],'calculation'=>['positionTotal'=>'999.00']]]]],'calculation'=>['netTotal'=>'999.00']];$id=$service->importNew($project,$document);$imported=$service->loadDraft($id)['offer'];$this->assertSame($kept,$imported['nodes'][0]['id']);$this->assertTrue(Uuid::isValid($imported['nodes'][0]['children'][0]['id']));$this->assertSame('2.000',$imported['nodes'][0]['children'][0]['quantity']);$this->assertSame('20.00',$imported['calculation']['netTotal']);$this->assertTrue(str_ends_with($imported['createdAt'],'Z')&&str_ends_with($imported['updatedAt'],'Z'));}finally{$this->remove($tmp);}
    }
    public function testLegacyStoredDocumentIsNormalizedOnlyWhenSavedAndThenExported():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/exchange-legacy-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{$pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$projects=new ProjectRepository($pdo);$repository=new OfferRepository($pdo);$store=new OfferJsonStore($tmp.'/offers',new OfferValidator());$service=new OfferService($pdo,$projects,$repository,$store,new OfferCalculator(new PriceCalculator()));$project=$projects->create('Alt','Kunde','Ort');$id=$service->createDraft($project,'Altbestand');$loaded=$service->loadDraft($id);$legacy=$loaded['offer'];$componentId=Uuid::v4();$legacy['createdAt']='2026-11-08T12:00:00+01:00';$legacy['nodes']=[['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Altposition','longText'=>'','quantity'=>'20','unit'=>'Stk.','manualUnitPrice'=>'200.00','components'=>[['id'=>$componentId,'name'=>'Altbaustein','calculationType'=>'material','unit'=>'Stk.','quantity'=>'1','costPrice'=>'85','markupPercent'=>'10','masterDataId'=>null,'priceMode'=>'manual','sourceArticleNumber'=>'ALT-1','salesPrice'=>'6.50']]]];$legacyEtag=$store->saveDraft($project,$id,$legacy,$loaded['etag']);$this->assertTrue($repository->updateEtag($id,$loaded['etag'],$legacyEtag));$before=$service->loadDraft($id);$this->assertSame('20',$before['offer']['nodes'][0]['quantity']);$this->assertTrue(isset($before['offer']['nodes'][0]['components'][0]['salesPrice']));$savedEtag=$service->saveDraft($id,$before['offer'],$before['etag']);$export=$service->loadDraft($id);$component=$export['offer']['nodes'][0]['components'][0];$this->assertSame($savedEtag,$export['etag']);$this->assertSame('20.000',$export['offer']['nodes'][0]['quantity']);$this->assertSame('1.000',$component['quantity']);$this->assertSame('85.0000',$component['costPrice']);$this->assertSame('10.00',$component['markupPercent']);$this->assertSame('200.00',$export['offer']['nodes'][0]['manualUnitPrice']);$this->assertSame($componentId,$component['id']);$this->assertSame('ALT-1',$component['sourceArticleNumber']);$this->assertTrue(!isset($component['salesPrice']));$this->assertSame('2026-11-08T11:00:00Z',$export['offer']['createdAt']);}finally{$this->remove($tmp);}
    }
    private function remove(string $path):void{if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->remove($child):unlink($child);}rmdir($path);}
}
