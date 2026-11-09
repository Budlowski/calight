<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\OfferPdfService;
use App\Application\OfferService;
use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidationException;
use App\Domain\Offer\OfferValidator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Support\Uuid;
use App\Tests\TestCase;

final class OfferSubmissionTest extends TestCase
{
    public function testSubmissionArchivesImmutableSnapshotPdfAndChangesStatus():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/calight-submit-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{
            $pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();
            $projects=new ProjectRepository($pdo);$offers=new OfferRepository($pdo);$validator=new OfferValidator();
            $calculator=new OfferCalculator(new PriceCalculator());$store=new OfferJsonStore($tmp.'/offers',$validator);
            $pdf=new OfferPdfService($pdo,new DocumentSettingsRepository($pdo),$validator,$calculator);
            $service=new OfferService($pdo,$projects,$offers,$store,$calculator,new NumberRangeRepository($pdo),$pdf);
            $project=$projects->create('Abschlussprojekt','Kunde','Baustelle');$id=$service->createDraft($project,'Verbindliches Angebot');
            $loaded=$service->loadDraft($id);$offer=$loaded['offer'];$offer['nodes']=[[
                'id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Montage','longText'=>'','quantity'=>'2.000','unit'=>'Stk.',
                'manualUnitPrice'=>'25.00','components'=>[],
            ]];
            $etag=$service->saveDraft($id,$offer,$loaded['etag']);
            $warning=$service->submitOffer($id,$etag,null,'2026-11-09',false);
            $this->assertSame(false,$warning['submitted']);$this->assertSame('incomplete_calculation',$warning['warning']['code']);
            $result=$service->submitOffer($id,$etag,null,'2026-11-09',true);
            $this->assertSame(true,$result['submitted']);$this->assertSame('submitted',$result['status']);
            $this->assertTrue(str_starts_with((string)$result['offerNumber'],'AG'));
            $metadata=$offers->find($id);$this->assertSame('submitted',$metadata['status']);$this->assertSame('2026-11-09',$metadata['document_date']);
            $this->assertTrue(is_file($tmp.'/offers/'.$project.'/'.$id.'/snapshot.json'));
            $this->assertTrue(is_file($tmp.'/offers/'.$project.'/'.$id.'/submitted.pdf'));
            $final=$service->loadDraft($id);$this->assertSame('submitted',$final['offer']['status']);$this->assertSame('50.00',$final['offer']['calculation']['netTotal']);
            $this->assertThrows(\RuntimeException::class,fn()=>$service->saveDraft($id,$final['offer'],$final['etag']));
            $repeat=$service->submitOffer($id,$etag,null,'2026-11-09',true);$this->assertSame(true,$repeat['idempotent']);$this->assertSame($result['offerNumber'],$repeat['offerNumber']);
            $this->assertSame('commissioned',$service->changeStatus($id,'submitted','commissioned')['status']);
            $this->assertSame('rejected',$service->changeStatus($id,'commissioned','rejected')['status']);
            $archive=$service->submittedPdf($id);$this->assertTrue(str_starts_with($archive['pdf'],'%PDF-1.4'));
            $collision=$service->createDraft($project,'Nummernkollision');$collisionDraft=$service->loadDraft($collision);
            $collisionOffer=$collisionDraft['offer'];$collisionOffer['nodes']=[[
                'id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','workStatus'=>'done','shortText'=>'Kollision','longText'=>'','quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>'10.00','components'=>[],
            ]];
            $collisionEtag=$service->saveDraft($collision,$collisionOffer,$collisionDraft['etag']);
            $this->assertThrows(OfferValidationException::class,fn()=>$service->submitOffer($collision,$collisionEtag,$result['offerNumber'],'2026-11-09',true));
            $this->assertSame('draft',$offers->find($collision)['status']);
            chmod($tmp.'/offers/'.$project.'/'.$id.'/snapshot.json',0640);file_put_contents($tmp.'/offers/'.$project.'/'.$id.'/snapshot.json',"\n",FILE_APPEND);
            $this->assertThrows(\RuntimeException::class,fn()=>$service->loadDraft($id));
        }finally{$this->remove($tmp);}
    }

    private function remove(string $path):void
    {
        if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->remove($child):unlink($child);}rmdir($path);
    }
}
