<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\DocumentSettingsService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Application\OfferPdfService;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Tests\TestCase;
use App\Support\Uuid;

final class DocumentSettingsTest extends TestCase
{
    public function testSettingsArePersistentAndPdfIsDraftWithoutInternalCosts():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/documents-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{$pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$repository=new DocumentSettingsRepository($pdo);$settings=new DocumentSettingsService($repository);$settings->save(['companyName'=>'Muster Elektro','accentColor'=>'#155a8a','introText'=>'Unser Angebot']);$this->assertSame('Muster Elektro',$settings->get()['companyName']);$project=(new ProjectRepository($pdo))->create('PDF','Kunde','Ort');$offer=['schemaVersion'=>1,'id'=>Uuid::v4(),'projectId'=>$project,'offerNumber'=>null,'revision'=>0,'status'=>'draft','title'=>'Testangebot','createdAt'=>date(DATE_ATOM),'updatedAt'=>date(DATE_ATOM),'nodes'=>[['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Montage','longText'=>'Kundentext','quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>'25.00','components'=>[]]]];$pdf=(new OfferPdfService($pdo,$repository,new OfferValidator(),new OfferCalculator(new PriceCalculator())))->render($offer);$this->assertTrue(str_starts_with($pdf,'%PDF-1.4'));$this->assertTrue(str_contains($pdf,'ENTWURF'));$this->assertTrue(str_contains($pdf,'Montage'));$this->assertTrue(!str_contains($pdf,'costPrice'));$this->assertTrue(!str_contains($pdf,'markupPercent'));}finally{$this->remove($tmp);}
    }
    public function testLongMultiPagePdfMarksSpecialPositionsAndExcludesThemFromSum():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/documents-long-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{$pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$repository=new DocumentSettingsRepository($pdo);$project=(new ProjectRepository($pdo))->create('Lang','Kunde','Ort');$nodes=[['id'=>Uuid::v4(),'type'=>'title','name'=>'Leerer Gliederungspunkt','description'=>'','children'=>[]]];for($i=1;$i<=40;$i++)$nodes[]=['id'=>Uuid::v4(),'type'=>'position','positionType'=>$i===2?'bedarf':($i===3?'alternative':'normal'),'shortText'=>'Ausführliche Position '.$i,'longText'=>str_repeat('Mehrzeiliger Kundentext vollständig dargestellt. ',5),'quantity'=>$i===1?'1000.000':'1.250','unit'=>'Stk.','manualUnitPrice'=>'10.00','components'=>[]];$offer=['schemaVersion'=>1,'id'=>Uuid::v4(),'projectId'=>$project,'offerNumber'=>null,'revision'=>0,'status'=>'draft','title'=>'Mehrseitig','createdAt'=>'2026-11-08T12:00:00Z','updatedAt'=>'2026-11-08T12:00:00Z','nodes'=>$nodes];$pdf=(new OfferPdfService($pdo,$repository,new OfferValidator(),new OfferCalculator(new PriceCalculator())))->render($offer);$this->assertTrue(substr_count($pdf,'/Type /Page ')>1);$this->assertTrue(str_contains($pdf,'BEDARF')&&str_contains($pdf,'ALTERNATIVE'));$this->assertTrue(str_contains($pdf,'nicht Bestandteil'));$this->assertTrue(str_contains($pdf,'1.000 Stk.'));$this->assertTrue(str_contains($pdf,'10.462,50'));$this->assertTrue(!str_contains($pdf,'Leerer Gliederungspunkt'));$this->assertTrue(!str_contains($pdf,'Kostenpreis')&&!str_contains($pdf,'markupPercent'));}finally{$this->remove($tmp);}
    }
    private function remove(string $path):void{if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->remove($child):unlink($child);}rmdir($path);}
}
