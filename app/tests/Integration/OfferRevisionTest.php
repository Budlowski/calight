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

final class OfferRevisionTest extends TestCase
{
    public function testRevisionsKeepNumberAndArchivedFilesImmutable():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/calight-revision-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{
            $pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();
            $projects=new ProjectRepository($pdo);$repository=new OfferRepository($pdo);$validator=new OfferValidator();
            $calculator=new OfferCalculator(new PriceCalculator());$store=new OfferJsonStore($tmp.'/offers',$validator);
            $service=new OfferService($pdo,$projects,$repository,$store,$calculator,new NumberRangeRepository($pdo),
                new OfferPdfService($pdo,new DocumentSettingsRepository($pdo),$validator,$calculator));
            $project=$projects->create('Revisionen','Kunde','Baustelle');$original=$service->createDraft($project,'Angebot mit Revisionen');
            $loaded=$service->loadDraft($original);$document=$loaded['offer'];$document['nodes']=[[
                'id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','workStatus'=>'done','shortText'=>'Leistung','longText'=>'',
                'quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>'100.00','components'=>[],
            ]];
            $etag=$service->saveDraft($original,$document,$loaded['etag']);
            $submitted=$service->submitOffer($original,$etag,null,'2026-11-09',true);$number=$submitted['offerNumber'];
            $originalSnapshot=$tmp.'/offers/'.$project.'/'.$original.'/snapshot.json';$originalPdf=$tmp.'/offers/'.$project.'/'.$original.'/submitted.pdf';
            $snapshotHash=hash_file('sha256',$originalSnapshot);$pdfHash=hash_file('sha256',$originalPdf);

            $created=$service->createRevision($original);$this->assertSame(true,$created['created']);$this->assertSame(1,$created['revision']);
            $sameOpen=$service->createRevision($original);$this->assertSame(false,$sameOpen['created']);$this->assertSame($created['id'],$sameOpen['id']);
            $revision=$service->loadDraft($created['id']);$this->assertSame($number,$revision['offer']['offerNumber']);$this->assertSame(1,$revision['offer']['revision']);
            $revision['offer']['nodes'][0]['manualUnitPrice']='125.00';
            $revisionEtag=$service->saveDraft($created['id'],$revision['offer'],$revision['etag']);
            $revisionSubmitted=$service->submitOffer($created['id'],$revisionEtag,null,'2026-11-10',true);
            $this->assertSame($number,$revisionSubmitted['offerNumber']);$this->assertSame(1,$revisionSubmitted['revision']);
            $this->assertSame($snapshotHash,hash_file('sha256',$originalSnapshot));$this->assertSame($pdfHash,hash_file('sha256',$originalPdf));
            $this->assertTrue(is_file($tmp.'/offers/'.$project.'/'.$created['id'].'/snapshot.json'));
            $this->assertTrue(is_file($tmp.'/offers/'.$project.'/'.$created['id'].'/submitted.pdf'));

            $this->assertSame('commissioned',$service->changeStatus($original,'submitted','commissioned')['status']);
            $this->assertThrows(OfferValidationException::class,fn()=>$service->changeStatus($created['id'],'submitted','commissioned'));
            $this->assertSame('rejected',$service->changeStatus($original,'commissioned','rejected')['status']);
            $this->assertSame('commissioned',$service->changeStatus($created['id'],'submitted','commissioned')['status']);

            $history=$service->revisionHistory($created['id']);$this->assertSame(2,count($history['items']));
            $this->assertSame(1,$history['items'][0]['revision']);$this->assertSame(0,$history['items'][1]['revision']);
            $next=$service->createRevision($original);$this->assertSame(2,$next['revision']);
            $this->assertSame($created['id'],$repository->find($next['id'])['previous_revision_id']);
        }finally{$this->remove($tmp);}
    }

    private function remove(string $path):void
    {
        if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->remove($child):unlink($child);}rmdir($path);
    }
}
