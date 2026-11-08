<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\MasterDataService;
use App\Application\OfferCalculationService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\MasterDataRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Support\Uuid;
use App\Tests\TestCase;

final class OfferCalculationTest extends TestCase
{
    public function testSnapshotsUpdatesProtectionAndSavingAsMasterData(): void
    {
        [$service,$master,$temporary]=$this->service();
        try{
            $cost=$master->createCostItem(['articleNumber'=>'M-1','name'=>'Kabel','calculationType'=>'material','unit'=>'m','costPrice'=>'5.0000','markupPercent'=>'20.00']);
            $template=$master->createService(['autoArticleNumber'=>true,'shortText'=>'Kabel verlegen','longText'=>'Liefern und verlegen','unit'=>'m','components'=>[['masterDataId'=>$cost['id'],'sourceArticleNumber'=>'M-1','name'=>'Kabel','calculationType'=>'material','unit'=>'m','quantity'=>'1.000','costPrice'=>'5.0000','markupPercent'=>'20.00','priceMode'=>'master']]]);
            $library=$service->library();$this->assertSame(1,count($library['services']));$this->assertSame(1,count($library['costItems']));
            $position=['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Individuelle Kabelposition','longText'=>'','quantity'=>'2.000','unit'=>'m','manualUnitPrice'=>null,'sourceServiceId'=>$template['id'],'sourceServiceNumber'=>$template['articleNumber'],'components'=>array_map(function($c){$c['id']=Uuid::v4();return$c;},$template['components'])];
            $offer=['schemaVersion'=>1,'id'=>Uuid::v4(),'projectId'=>Uuid::v4(),'offerNumber'=>null,'revision'=>0,'status'=>'draft','title'=>'Test','createdAt'=>'2026-11-08T12:00:00+01:00','updatedAt'=>'2026-11-08T12:00:00+01:00','nodes'=>[$position]];
            $calculated=$service->calculate($offer);$this->assertSame('6.00',$calculated['nodes'][0]['calculation']['calculatedUnitPrice']);$this->assertSame('12.00',$calculated['nodes'][0]['calculation']['positionTotal']);
            $master->updateCostItem($cost['id'],['articleNumber'=>'M-1','name'=>'Kabel','calculationType'=>'material','unit'=>'m','costPrice'=>'6.0000','markupPercent'=>'20.00']);
            $unchanged=$service->calculate($offer);$this->assertSame('6.00',$unchanged['nodes'][0]['calculation']['calculatedUnitPrice']);
            $preview=$service->previewPriceUpdates(['components'=>$position['components']]);$this->assertSame(1,count($preview['changes']));$this->assertSame('6.0000',$preview['updates'][0]['costPrice']);
            $locked=$position['components'];$locked[0]['priceMode']='locked';$this->assertSame(0,count($service->previewPriceUpdates(['components'=>$locked])['changes']));
            $manual=$position['components'];$manual[0]['priceMode']='manual';$this->assertSame(0,count($service->previewPriceUpdates(['components'=>$manual])['changes']));
            $saved=$service->savePosition(['position'=>$position,'confirmDuplicate'=>true]);$this->assertSame('LP00002',$saved['saved']['articleNumber']);
            $duplicate=$service->savePosition(['position'=>$position]);$this->assertSame(null,$duplicate['saved']);$this->assertTrue(count($duplicate['duplicates'])>=1);
            $updated=$service->savePosition(['position'=>$position,'updateExistingId'=>$saved['saved']['id']]);
            $this->assertSame('LP00002',$updated['saved']['articleNumber']);
        }finally{$this->removeTree($temporary);}
    }
    private function service():array{$root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/offer-calc-'.bin2hex(random_bytes(8));mkdir($tmp,0770,true);$pdo=ConnectionFactory::create($tmp.'/app.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$price=new PriceCalculator();$master=new MasterDataService(new MasterDataRepository($pdo),$price,new NumberRangeRepository($pdo));return[new OfferCalculationService($master,new OfferValidator(),new OfferCalculator($price)),$master,$tmp];}
    private function removeTree(string $path):void{if(!is_dir($path))return;foreach(scandir($path)?:[]as$i){if($i==='.'||$i==='..')continue;$c=$path.'/'.$i;is_dir($c)?$this->removeTree($c):unlink($c);}rmdir($path);}
}
