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
use InvalidArgumentException;

final class DocumentSettingsTest extends TestCase
{
    public function testPdfFileNameUsesConfigurablePatternAndDocumentNumber():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/documents-filename-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{
            $pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$repository=new DocumentSettingsRepository($pdo);$settings=new DocumentSettingsService($repository);
            $pdfService=new OfferPdfService($pdo,$repository,new OfferValidator(),new OfferCalculator(new PriceCalculator()));
            $this->assertSame(date('Y-m-d').'_Angebot_Entwurf.pdf',$pdfService->fileName(['offerNumber'=>null]));
            $settings->save(['fileNamePattern'=>'{DOKUMENTTYP}_{DOKUMENTNUMMER}_{TT}.{MM}.{JJJJ}']);
            $this->assertSame('Angebot_AG202611001_'.date('d.m.Y').'.pdf',$pdfService->fileName(['offerNumber'=>'AG202611001']));
            $this->assertThrows(InvalidArgumentException::class,fn()=>$settings->save(['fileNamePattern'=>'{UNBEKANNT}']));
        }finally{$this->remove($tmp);}
    }
    public function testSettingsArePersistentAndPdfIsDraftWithoutInternalCosts():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/documents-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{$pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$repository=new DocumentSettingsRepository($pdo);$settings=new DocumentSettingsService($repository);$settings->save(['companyName'=>'Muster Elektro','accentColor'=>'#155a8a','introText'=>'Unser Angebot']);$this->assertSame('Muster Elektro',$settings->get()['companyName']);$this->assertTrue($settings->get()['showTitleSummary']);$project=(new ProjectRepository($pdo))->create('PDF','Kunde','Ort');$offer=['schemaVersion'=>1,'id'=>Uuid::v4(),'projectId'=>$project,'offerNumber'=>null,'revision'=>0,'status'=>'draft','title'=>'Testangebot','createdAt'=>date(DATE_ATOM),'updatedAt'=>date(DATE_ATOM),'nodes'=>[['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Montage','longText'=>'Kundentext','quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>'25.00','components'=>[]]]];$pdf=(new OfferPdfService($pdo,$repository,new OfferValidator(),new OfferCalculator(new PriceCalculator())))->render($offer);$this->assertTrue(str_starts_with($pdf,'%PDF-1.4'));$this->assertTrue(str_contains($pdf,'ENTWURF'));$this->assertTrue(str_contains($pdf,'Montage'));$this->assertTrue(str_contains($pdf,iconv('UTF-8','Windows-1252//TRANSLIT','Titelübersicht')?:'Titelbersicht'));$this->assertTrue(str_contains($pdf,'Positionen ohne Titel'));$this->assertTrue(!str_contains($pdf,'costPrice'));$this->assertTrue(!str_contains($pdf,'markupPercent'));}finally{$this->remove($tmp);}
    }
    public function testLongMultiPagePdfMarksSpecialPositionsAndExcludesThemFromSum():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/documents-long-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{$pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$repository=new DocumentSettingsRepository($pdo);$logo='data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAYEBQYFBAYGBQYHBwYIChAKCgkJChQODwwQFxQYGBcUFhYaHSUfGhsjHBYWICwgIyYnKSopGR8tMC0oMCUoKSj/2wBDAQcHBwoIChMKChMoGhYaKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCj/wAARCAAKABQDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFgEBAQEAAAAAAAAAAAAAAAAAAAUI/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AnsBpFHAAf//Z';(new DocumentSettingsService($repository))->save(['logoData'=>$logo]);$this->assertSame(180,$repository->get()['logoWidth']);$this->assertSame(100,$repository->get()['logoHeight']);$project=(new ProjectRepository($pdo))->create('Lang','Kunde','Ort');$nodes=[['id'=>Uuid::v4(),'type'=>'title','name'=>'Leerer Gliederungspunkt','description'=>'','children'=>[]]];for($i=1;$i<=40;$i++)$nodes[]=['id'=>Uuid::v4(),'type'=>'position','positionType'=>$i===2?'bedarf':($i===3?'alternative':'normal'),'shortText'=>'Ausführliche Position '.$i,'longText'=>str_repeat('Mehrzeiliger Kundentext vollständig dargestellt. ',5),'quantity'=>$i===1?'1000.000':'1.250','unit'=>'Stk.','manualUnitPrice'=>'10.00','components'=>[]];$offer=['schemaVersion'=>1,'id'=>Uuid::v4(),'projectId'=>$project,'offerNumber'=>null,'revision'=>0,'status'=>'draft','title'=>'Mehrseitig','createdAt'=>'2026-11-08T12:00:00Z','updatedAt'=>'2026-11-08T12:00:00Z','nodes'=>$nodes];$pdf=(new OfferPdfService($pdo,$repository,new OfferValidator(),new OfferCalculator(new PriceCalculator())))->render($offer);$this->assertTrue(substr_count($pdf,'/Type /Page ')>1);$this->assertSame(1,substr_count($pdf,'/Im1 Do'));$this->assertTrue(str_contains($pdf,'[Bedarf]')&&str_contains($pdf,'[Alternative]'));$this->assertTrue(str_contains($pdf,'nicht Bestandteil'));$this->assertTrue(str_contains($pdf,'1.000 Stk.'));$this->assertTrue(str_contains($pdf,'10.462,50'));$this->assertTrue(!str_contains($pdf,'Leerer Gliederungspunkt'));$this->assertTrue(!str_contains($pdf,'Kostenpreis')&&!str_contains($pdf,'markupPercent'));}finally{$this->remove($tmp);}
    }
    public function testIndividualTermsOverrideDefaultsInPdf():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/documents-terms-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{$pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$repository=new DocumentSettingsRepository($pdo);(new DocumentSettingsService($repository))->save(['introText'=>'Globale Einleitung','paymentTerms'=>'Global zahlbar','validityMode'=>'duration','validityDays'=>'30']);$project=(new ProjectRepository($pdo))->create('Bedingungen','Kunde','Ort');$offer=['schemaVersion'=>1,'id'=>Uuid::v4(),'projectId'=>$project,'offerNumber'=>null,'revision'=>0,'status'=>'draft','title'=>'Bedingungen','createdAt'=>'2026-11-08T12:00:00Z','updatedAt'=>'2026-11-08T12:00:00Z','documentTerms'=>['introText'=>'Individuelle Einleitung','closingText'=>null,'paymentTerms'=>'Individuell zahlbar','additionalTerms'=>'Zusätzlicher Hinweis','validityMode'=>'date','validityDays'=>null,'validityDate'=>'2026-12-31'],'nodes'=>[]];$pdf=(new OfferPdfService($pdo,$repository,new OfferValidator(),new OfferCalculator(new PriceCalculator())))->render($offer);$encoded=static fn(string $text):string=>iconv('UTF-8','Windows-1252//TRANSLIT',$text)?:$text;$this->assertTrue(str_contains($pdf,'Individuelle Einleitung'),'Individuelle Einleitung fehlt.');$this->assertTrue(!str_contains($pdf,'Globale Einleitung'),'Globaler Text wurde trotz Überschreibung ausgegeben.');$this->assertTrue(str_contains($pdf,$encoded('Angebotsgültigkeit: 31.12.2026')),'Gültigkeitsdatum fehlt.');$this->assertTrue(str_contains($pdf,'Individuell zahlbar')&&str_contains($pdf,$encoded('Zusätzlicher Hinweis')),'Individuelle Bedingungen fehlen.');}finally{$this->remove($tmp);}
    }
    public function testPdfSummaryListsOnlyTopLevelTitlesWithoutDoubleCounting():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/documents-summary-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{
            $pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$repository=new DocumentSettingsRepository($pdo);$project=(new ProjectRepository($pdo))->create('Zusammenfassung','Kunde','Ort');
            $position=['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Arbeit','longText'=>'','quantity'=>'2.000','unit'=>'Stk.','manualUnitPrice'=>'10.00','components'=>[]];
            $offer=['schemaVersion'=>1,'id'=>Uuid::v4(),'projectId'=>$project,'offerNumber'=>null,'revision'=>0,'status'=>'draft','title'=>'Titeltest','createdAt'=>'2026-11-08T12:00:00Z','updatedAt'=>'2026-11-08T12:00:00Z','nodes'=>[
                ['id'=>Uuid::v4(),'type'=>'title','name'=>'Haupttitel A','description'=>'','children'=>[['id'=>Uuid::v4(),'type'=>'title','name'=>'Untertitel A.1','description'=>'','children'=>[$position]]]],
                ['id'=>Uuid::v4(),'type'=>'title','name'=>'Haupttitel B','description'=>'','children'=>[['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Material','longText'=>'','quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>'5.00','components'=>[]]]],
            ]];
            $pdf=(new OfferPdfService($pdo,$repository,new OfferValidator(),new OfferCalculator(new PriceCalculator())))->render($offer);
            $this->assertTrue(str_contains($pdf,'Haupttitel A')&&str_contains($pdf,'Haupttitel B'));
            $this->assertSame(2,substr_count($pdf,'Untertitel A.1'),'Untertitel darf in der Abschlussübersicht nicht erneut erscheinen.');
            $this->assertTrue(str_contains($pdf,'25,00'));
        }finally{$this->remove($tmp);}
    }
    public function testPdfRendersExcludedTitleTextAndDiscount():void
    {
        $root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/documents-extended-'.bin2hex(random_bytes(6));mkdir($tmp,0770,true);
        try{
            $pdo=ConnectionFactory::create($tmp.'/db.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();$repository=new DocumentSettingsRepository($pdo);$project=(new ProjectRepository($pdo))->create('Erweitert','Kunde','Ort');
            $position=static fn(string $text,string $price):array=>['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>$text,'longText'=>'','quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>$price,'components'=>[]];
            $offer=['schemaVersion'=>1,'id'=>Uuid::v4(),'projectId'=>$project,'offerNumber'=>null,'revision'=>0,'status'=>'draft','title'=>'Erweitert','createdAt'=>'2026-11-08T12:00:00Z','updatedAt'=>'2026-11-08T12:00:00Z','offerAdjustment'=>['type'=>'discount','mode'=>'percentage','value'=>'10.00','label'=>'Projekt-Rabatt'],'nodes'=>[
                array_replace($position('Regulaer','100.00'),['trailingText'=>['id'=>null,'code'=>'LM','name'=>'Lieferung und Montage']]),
                ['id'=>Uuid::v4(),'type'=>'text','title'=>'Technischer Hinweis','text'=>'Dieser Text steht frei zwischen den Positionen.'],
                ['id'=>Uuid::v4(),'type'=>'title','titleType'=>'bedarf','name'=>'Optionale Arbeiten','description'=>'','children'=>[$position('Optional','50.00')]],
            ]];
            $pdf=(new OfferPdfService($pdo,$repository,new OfferValidator(),new OfferCalculator(new PriceCalculator())))->render($offer);
            $this->assertTrue(str_contains($pdf,'Technischer Hinweis')&&str_contains($pdf,'Optionale Arbeiten')&&str_contains($pdf,'Lieferung und Montage'));
            $this->assertTrue(str_contains($pdf,'Projekt-Rabatt')&&str_contains($pdf,'90,00'));
            $this->assertTrue(str_contains($pdf,'Nicht in Angebotssumme enthalten'));
        }finally{$this->remove($tmp);}
    }
    private function remove(string $path):void{if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->remove($child):unlink($child);}rmdir($path);}
}
