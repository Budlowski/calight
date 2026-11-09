<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Infrastructure\Pdf\PdfNumberFormatter;
use App\Infrastructure\Pdf\SimplePdf;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PDO;
use RuntimeException;

final readonly class InternalCalculationPdfService
{
    public function __construct(
        private PDO $pdo,
        private DocumentSettingsRepository $settings,
        private OfferValidator $validator,
        private OfferCalculator $calculator,
    ) {}

    /** @param array<string,mixed> $offer */
    public function fileName(array $offer): string
    {
        $number=trim((string)($offer['offerNumber']??''))?:'Entwurf';
        return date('Y-m-d').'_Interne_Kalkulation_'.$number.'.pdf';
    }

    /** @param array<string,mixed> $offer */
    public function render(array $offer): string
    {
        $this->validator->validate($offer);
        $offer=$this->calculator->calculate($offer);
        $project=$this->project((string)$offer['projectId']);
        $settings=$this->settings->get();
        $pdf=new SimplePdf();
        $numbers=new PdfNumberFormatter();
        $accent=$this->color((string)($settings['accentColor']??'#155a8a'));
        $left=(float)($settings['marginLeft']??45);
        $right=595-(float)($settings['marginRight']??45);
        $top=790.0;
        $bottom=55.0;
        $font=max(7.0,(float)($settings['baseFontSize']??8));
        $page=0;
        $y=$top;

        $newPage=function()use($pdf,$settings,$left,$right,$accent,&$page,&$y,$top):void{
            if($page>0)$pdf->page();
            $page++;
            $y=$top;
            $company=trim((string)($settings['companyName']??'').' '.(string)($settings['legalForm']??''));
            $pdf->text($left,810,13,$company?:'Calight',true,$accent);
            if($page===1)$this->logo($pdf,$settings,$left,$right);
            $pdf->textRight($right,810,7,'Interne Kalkulation · Seite '.$page,true,'.45 .1 .1');
            $pdf->line($left,800,$right,800,'0.3 0.35 0.4');
        };
        $newPage();
        if(is_string($settings['logoData']??null)&&str_starts_with($settings['logoData'],'data:image/jpeg;base64,'))$y=690.0;

        $pdf->text($left,$y,17,'Interne Kalkulation – Nicht zur Weitergabe',true,'.55 .08 .08');
        $y-=24;
        $pdf->text($left,$y,12,(string)$offer['title'],true,$accent);
        $y-=17;
        $pdf->text($left,$y,$font,'Projekt: '.trim((string)($project['project_number']??'').' · '.(string)($project['name']??'')));
        $pdf->textRight($right,$y,$font,'Stand: '.date('d.m.Y H:i'));
        $y-=22;

        $header=function()use($pdf,&$y,$left,$right,$font):void{
            $pdf->text($left,$y,$font,'Pos.',true);
            $pdf->text($left+42,$y,$font,'Bezeichnung',true);
            $pdf->textRight($right-145,$y,$font,'Menge',true);
            $pdf->textRight($right-72,$y,$font,'VK je Einheit',true);
            $pdf->textRight($right,$y,$font,'Gesamt',true);
            $y-=8;$pdf->line($left,$y,$right,$y,'0.3 0.35 0.4');$y-=13;
        };
        $header();
        $ensure=function(float $height)use($newPage,$header,&$y,$bottom):void{if($y-$height>=$bottom)return;$newPage();$header();};

        foreach($this->rows($offer['nodes']??[]) as $row){
            if($row['kind']==='title'){
                $ensure(32);
                $badge=$row['special']?'['.ucfirst($row['effectiveType']).'] ':'';
                $pdf->text($left+2,$y,$font+1,$row['number'],true,$row['special']?'.4 .44 .48':'0 0 0');
                $pdf->text($left+42+min($row['depth'],3)*7,$y,$font+1,$badge.$row['name'],true,$row['special']?'.4 .44 .48':'0 0 0');
                $y-=9;$pdf->line($left,$y,$right,$y);$y-=13;
                continue;
            }
            if($row['kind']==='text')continue;
            if($row['kind']==='subtotal'){
                $ensure(28);
                $pdf->text($left+42,$y,$font,'Summe '.$row['label'],true);
                $pdf->textRight($right,$y,$font,$numbers->money($row['total']),true);
                $y-=8;$pdf->line($left+42,$y,$right,$y);$y-=19;
                continue;
            }

            $node=$row['node'];
            $components=is_array($node['components']??null)?$node['components']:[];
            $calculation=is_array($node['calculation']??null)?$node['calculation']:[];
            $manual=isset($node['manualUnitPrice'])&&$node['manualUnitPrice']!=='';
            $incomplete=$components===[];
            $markers=[];
            if($row['special'])$markers[]='['.ucfirst($row['effectiveType']).']';
            if($manual)$markers[]='[Manueller VK]';
            if($incomplete)$markers[]='[Kalkulation unvollständig]';
            $title=trim(implode(' ',$markers).' '.(string)$node['shortText']);
            $titleLines=$this->wrap($title,70);
            $height=46+count($titleLines)*10+count($components)*13;
            $ensure(min($height,100.0));
            $baseline=$y;
            $pdf->text($left+2,$y,$font,$row['number'],true);
            foreach($titleLines as $line){$pdf->text($left+42+min($row['depth'],3)*7,$y,$font,$line,true);$y-=10;}
            $pdf->textRight($right-145,$baseline,$font,$numbers->quantity((string)$node['quantity']).' '.(string)$node['unit']);
            $pdf->textRight($right-72,$baseline,$font,$numbers->money((string)($calculation['effectiveUnitPrice']??'0')));
            $pdf->textRight($right,$baseline,$font,$numbers->money((string)($calculation['positionTotal']??'0')),true);
            $y-=2;

            if($components!==[]){
                $ensure(25);
                $pdf->text($left+50,$y,$font-1,'Kalkulationsbaustein / Typ',true,'.35 .4 .45');
                $pdf->textRight($right-210,$y,$font-1,'Menge / Einheit',true,'.35 .4 .45');
                $pdf->textRight($right-135,$y,$font-1,'EK',true,'.35 .4 .45');
                $pdf->textRight($right-68,$y,$font-1,'Aufschlag',true,'.35 .4 .45');
                $pdf->textRight($right,$y,$font-1,'VK',true,'.35 .4 .45');
                $y-=10;
                foreach($components as $index=>$component){
                    $ensure(15);
                    $name=(string)($component['name']??'Unbenannter Baustein');
                    $type=$this->typeLabel((string)($component['calculationType']??''));
                    $pdf->text($left+50,$y,$font-1,$this->shorten($name,42).' · '.$type);
                    $pdf->textRight($right-210,$y,$font-1,$numbers->quantity((string)($component['quantity']??'0')).' '.(string)($component['unit']??''));
                    $pdf->textRight($right-135,$y,$font-1,$numbers->money((string)($component['costPrice']??'0')));
                    $pdf->textRight($right-68,$y,$font-1,$numbers->percent((string)($component['markupPercent']??'0')));
                    $pdf->textRight($right,$y,$font-1,$numbers->money((string)($calculation['componentSalesPrices'][$index]??'0')));
                    $y-=13;
                }
            }

            $quantity=BigDecimal::of((string)($node['quantity']??'0'));
            $positionCosts=BigDecimal::of((string)($calculation['totalCosts']??'0'))->multipliedBy($quantity)->toScale(2,RoundingMode::HalfUp);
            $sale=BigDecimal::of((string)($calculation['positionTotal']??'0'));
            $contribution=$incomplete?'nicht belastbar':$numbers->money((string)$sale->minus($positionCosts));
            $ensure(24);
            $pdf->text($left+50,$y,$font-1,'Gesamtkosten: '.$numbers->money((string)$positionCosts),true);
            $pdf->text($left+205,$y,$font-1,'Tatsächlicher VK: '.$numbers->money((string)$sale),true);
            $pdf->textRight($right,$y,$font-1,'Deckungsbeitrag: '.$contribution,true,$incomplete?'.55 .35 0':'0 0 0');
            $y-=9;$pdf->line($left,$y,$right,$y);$y-=15;
        }

        $calculation=$offer['calculation'];
        $analysis=$calculation['analysis'];
        $newPage();
        $pdf->text($left,$y,15,'Kalkulationsübersicht',true,$accent);$y-=25;
        $this->summaryBlock($pdf,$numbers,$left,$right,$y,$font,$calculation,$analysis,$accent);
        return $pdf->output();
    }

    /** @param list<array<string,mixed>> $nodes @param list<int> $prefix @return list<array<string,mixed>> */
    private function rows(array $nodes,array $prefix=[],?string $inheritedType=null): array
    {
        $rows=[];$title=0;$position=0;
        foreach($nodes as $node){
            $type=(string)($node['type']??'');
            if($type==='title'){
                $path=[...$prefix,++$title];$titleType=(string)($node['titleType']??'normal');$effective=$inheritedType??($titleType!=='normal'?$titleType:null);
                $rows[]=['kind'=>'title','number'=>implode('.',$path),'name'=>(string)$node['name'],'depth'=>count($path)-1,'special'=>$effective!==null,'effectiveType'=>$effective??'normal'];
                $rows=[...$rows,...$this->rows($node['children']??[],$path,$effective)];
                if($this->containsPosition($node['children']??[])){
                    $total=$effective!==null
                        ?(string)($node['calculation']['calculatedSubtotal']??'0')
                        :(string)($node['calculation']['includedSubtotal']??$node['calculation']['subtotal']??'0');
                    $rows[]=['kind'=>'subtotal','label'=>'Titel '.implode('.',$path).': '.$node['name'],'total'=>$total];
                }
                continue;
            }
            if($type==='text'){$rows[]=['kind'=>'text'];continue;}
            $positionType=(string)($node['positionType']??'normal');$effective=$inheritedType??($positionType!=='normal'?$positionType:null);
            $rows[]=['kind'=>'position','number'=>($prefix?implode('.',$prefix).'.':'').str_pad((string)++$position,2,'0',STR_PAD_LEFT),'depth'=>count($prefix),'special'=>$effective!==null,'effectiveType'=>$effective??'normal','node'=>$node];
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $nodes */
    private function containsPosition(array $nodes): bool
    {
        foreach($nodes as $node)if(($node['type']??'')==='position'||(($node['type']??'')==='title'&&$this->containsPosition($node['children']??[])))return true;
        return false;
    }

    /** @param array<string,mixed> $calculation @param array<string,mixed> $analysis */
    private function summaryBlock(SimplePdf $pdf,PdfNumberFormatter $numbers,float $left,float $right,float &$y,float $font,array $calculation,array $analysis,string $accent): void
    {
        $metric=function(string $label,string $value,bool $bold=false)use($pdf,$left,$right,&$y,$font):void{$pdf->text($left,$y,$font,$label,$bold);$pdf->textRight($right,$y,$font,$value,$bold);$y-=15;};
        $pdf->text($left,$y,$font+2,'Kostenaufteilung',true);$y-=18;
        foreach([['Material',$analysis['materialCosts'],$analysis['materialCostPercent']],['Lohn',$analysis['laborCosts'],$analysis['laborCostPercent']],['Fremdleistungen',$analysis['externalCosts'],$analysis['externalCostPercent']],['Sonstige',$analysis['otherCosts'],$analysis['otherCostPercent']]] as [$label,$cost,$percent]){
            $metric($label,$numbers->money((string)$cost).' · '.$numbers->percent((string)$percent));
            $width=max(0.0,min(1.0,(float)$percent/100))*180;$pdf->line($left+125,$y+19,$left+305,$y+19,'.88 .9 .92');if($width>0)$pdf->line($left+125,$y+19,$left+125+$width,$y+19,$accent);
        }
        $y-=6;$metric('Gesamte kalkulierte Kosten',$numbers->money((string)$analysis['totalCosts']),true);
        $metric('Kalkulierte Arbeitsstunden',$numbers->quantity((string)$analysis['laborHours']).' h');
        $y-=10;$pdf->text($left,$y,$font+2,'Wirtschaftlichkeit',true);$y-=18;
        $metric('Angebotssumme netto',$numbers->money((string)$calculation['netTotal']),true);
        $metric('Rabatt / Zuschlag',$numbers->money((string)$calculation['adjustmentSigned']));
        $metric('Deckungsbeitrag',$analysis['complete']?$numbers->money((string)$analysis['contributionMargin']):'Nicht belastbar',true);
        $metric('Deckungsbeitragsquote',$analysis['complete']&&$analysis['contributionMarginPercent']!==null?$numbers->percent((string)$analysis['contributionMarginPercent']):'–');
        $metric('Aufschlag auf Kosten',$analysis['complete']&&$analysis['costMarkupPercent']!==null?$numbers->percent((string)$analysis['costMarkupPercent']):'–');
        $metric('Bedarfspositionen',$numbers->money((string)$calculation['demandSubtotal']));
        $metric('Alternativpositionen',$numbers->money((string)$calculation['alternativeSubtotal']));
        $y-=10;$pdf->text($left,$y,$font+2,'Kalkulationsvollständigkeit',true);$y-=18;
        $metric('Positionen gesamt',(string)$analysis['positionCount']);
        $metric('Regulär enthalten / Optional',$analysis['includedPositionCount'].' / '.$analysis['optionalPositionCount']);
        $metric('Vollständig / Unvollständig',($analysis['positionCount']-$analysis['incompleteCount']).' / '.$analysis['incompleteCount'],true);
        $metric('Offen / In Bearbeitung / Fertig',$analysis['statusOpen'].' / '.$analysis['statusInProgress'].' / '.$analysis['statusDone']);
        $metric('Manuell übersteuerter Verkaufspreis',(string)$analysis['manualPriceCount']);
    }

    /** @return list<string> */
    private function wrap(string $text,int $width): array{return explode("\n",wordwrap(trim($text),$width,"\n",true));}
    private function shorten(string $text,int $length): string{return mb_strlen($text)<=$length?$text:mb_substr($text,0,$length-1).'…';}
    private function typeLabel(string $type): string{return ['material'=>'Material','labor'=>'Lohn','external'=>'Fremdleistung','other'=>'Sonstige Kosten'][$type]??$type;}
    /** @param array<string,mixed> $settings */
    private function logo(SimplePdf $pdf,array $settings,float $left,float $right): void{if(!is_string($settings['logoData']??null)||!str_starts_with($settings['logoData'],'data:image/jpeg;base64,'))return;$data=base64_decode(substr($settings['logoData'],23),true);if($data===false)return;$width=(float)($settings['logoWidth']??180);$height=(float)($settings['logoHeight']??100);$x=($settings['logoPosition']??'right')==='left'?$left:$right-$width;$pdf->jpegContained($data,$x,800-$height,$width,$height);}
    /** @return array<string,mixed> */
    private function project(string $id): array{$statement=$this->pdo->prepare('SELECT * FROM projects WHERE id=:id');$statement->execute(['id'=>$id]);$row=$statement->fetch();if(!is_array($row))throw new RuntimeException('Projekt wurde nicht gefunden.');return $row;}
    private function color(string $hex): string{$hex=ltrim($hex,'#');if(!preg_match('/^[0-9a-fA-F]{6}$/',$hex))return'0.08 0.35 0.54';return implode(' ',array_map(static fn(string $part):string=>(string)round(hexdec($part)/255,3),str_split($hex,2)));}
}
