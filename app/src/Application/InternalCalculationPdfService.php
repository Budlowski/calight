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
            $this->rightText($pdf,$right,810,7,'Interne Kalkulation · Seite '.$page,true,'.45 .1 .1');
            $pdf->line($left,800,$right,800,'0.3 0.35 0.4');
        };
        $newPage();
        if(is_string($settings['logoData']??null)&&str_starts_with($settings['logoData'],'data:image/jpeg;base64,'))$y=690.0;

        $pdf->text($left,$y,17,'Interne Kalkulation – Nicht zur Weitergabe',true,'.55 .08 .08');
        $y-=24;
        $pdf->text($left,$y,12,(string)$offer['title'],true,$accent);
        $y-=17;
        $pdf->text($left,$y,$font,'Projekt: '.trim((string)($project['project_number']??'').' · '.(string)($project['name']??'')));
        $this->rightText($pdf,$right,$y,$font,'Stand: '.date('d.m.Y H:i'));
        $y-=22;

        $header=function()use($pdf,&$y,$left,$right,$font):void{
            $pdf->text($left,$y,$font,'Pos.',true);
            $pdf->text($left+42,$y,$font,'Bezeichnung',true);
            $this->rightText($pdf,$right-145,$y,$font,'Menge',true);
            $this->rightText($pdf,$right-72,$y,$font,'VK je Einheit',true);
            $this->rightText($pdf,$right,$y,$font,'Gesamt',true);
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
                $this->rightText($pdf,$right,$y,$font,$numbers->money($row['total']),true);
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
            $componentRows=[];
            foreach($components as $index=>$component){
                $label=(string)($component['name']??'Unbenannter Baustein').' · '.$this->typeLabel((string)($component['calculationType']??''));
                $lines=$this->wrap($label,38);
                $componentRows[]=['index'=>$index,'component'=>$component,'lines'=>$lines,'height'=>max(13.0,count($lines)*9.0+2.0)];
            }
            $componentsHeight=array_sum(array_column($componentRows,'height'));
            $height=52+count($titleLines)*10+$componentsHeight;
            if($height<=($top-$bottom-45))$ensure($height);
            else $ensure(55+count($titleLines)*10);
            $baseline=$y;
            $pdf->text($left+2,$y,$font,$row['number'],true);
            foreach($titleLines as $line){$pdf->text($left+42+min($row['depth'],3)*7,$y,$font,$line,true);$y-=10;}
            $this->rightText($pdf,$right-145,$baseline,$font,$numbers->quantity((string)$node['quantity']).' '.(string)$node['unit']);
            $this->rightText($pdf,$right-72,$baseline,$font,$numbers->money((string)($calculation['effectiveUnitPrice']??'0')));
            $this->rightText($pdf,$right,$baseline,$font,$numbers->money((string)($calculation['positionTotal']??'0')),true);
            $y-=2;

            $componentHeader=function()use($pdf,&$y,$left,$right,$font):void{
                $pdf->text($left+50,$y,$font-1,'Kalkulationsbaustein / Typ',true,'.35 .4 .45');
                $this->rightText($pdf,$right-210,$y,$font-1,'Menge / Einheit',true,'.35 .4 .45');
                $this->rightText($pdf,$right-135,$y,$font-1,'EK',true,'.35 .4 .45');
                $this->rightText($pdf,$right-68,$y,$font-1,'Aufschlag',true,'.35 .4 .45');
                $this->rightText($pdf,$right,$y,$font-1,'VK',true,'.35 .4 .45');
                $y-=5;$pdf->line($left+50,$y,$right,$y,'.82 .84 .86');$y-=11;
            };

            if($componentRows!==[]){
                $componentHeader();
                foreach($componentRows as $componentIndex=>$componentRow){
                    $rowHeight=$componentRow['height'];
                    $isLast=$componentIndex===array_key_last($componentRows);
                    if($y-$rowHeight-($isLast?34:0)<$bottom){
                        $newPage();$header();
                        $pdf->text($left+42,$y,$font-1,'Fortsetzung Position '.$row['number'].' · '.(string)$node['shortText'],true,'.35 .4 .45');
                        $y-=14;$componentHeader();
                    }
                    $component=$componentRow['component'];
                    $rowBaseline=$y;
                    foreach($componentRow['lines'] as $line){$pdf->text($left+50,$y,$font-1,$line);$y-=9;}
                    $this->rightText($pdf,$right-210,$rowBaseline,$font-1,$numbers->quantity((string)($component['quantity']??'0')).' '.(string)($component['unit']??''));
                    $this->rightText($pdf,$right-135,$rowBaseline,$font-1,$numbers->money((string)($component['costPrice']??'0')));
                    $this->rightText($pdf,$right-68,$rowBaseline,$font-1,$numbers->percent((string)($component['markupPercent']??'0')));
                    $this->rightText($pdf,$right,$rowBaseline,$font-1,$numbers->money((string)($calculation['componentSalesPrices'][$componentRow['index']]??'0')));
                    $y=$rowBaseline-$rowHeight;
                }
            }

            $quantity=BigDecimal::of((string)($node['quantity']??'0'));
            $positionCosts=BigDecimal::of((string)($calculation['totalCosts']??'0'))->multipliedBy($quantity)->toScale(2,RoundingMode::HalfUp);
            $sale=BigDecimal::of((string)($calculation['positionTotal']??'0'));
            $contribution=$incomplete?'nicht belastbar':$numbers->money((string)$sale->minus($positionCosts));
            $ensure(34);
            $costRight=$left+215;$saleRight=$left+365;
            $this->rightText($pdf,$costRight,$y,$font-1,'Gesamtkosten',false,'.35 .4 .45');
            $this->rightText($pdf,$saleRight,$y,$font-1,'Tatsächlicher VK',false,'.35 .4 .45');
            $this->rightText($pdf,$right,$y,$font-1,'Deckungsbeitrag',false,'.35 .4 .45');
            $y-=11;
            $this->rightText($pdf,$costRight,$y,$font,$numbers->money((string)$positionCosts),true);
            $this->rightText($pdf,$saleRight,$y,$font,$numbers->money((string)$sale),true);
            $this->rightText($pdf,$right,$y,$font,$contribution,true,$incomplete?'.55 .35 0':'0 0 0');
            $y-=9;$pdf->line($left,$y,$right,$y);$y-=17;
        }

        $calculation=$offer['calculation'];
        $analysis=$calculation['analysis'];
        $newPage();
        $y-=14;
        $this->titleOverview($pdf,$numbers,$left,$right,$y,$font,$offer['nodes']??[],$calculation,$accent,$newPage,$bottom);
        $newPage();
        $y-=14;
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
        $valueRight=min($right,$left+390);
        $metric=function(string $label,string $value,bool $bold=false)use($pdf,$left,$valueRight,&$y,$font):void{
            $pdf->text($left,$y,$font,$label,$bold);
            $this->rightText($pdf,$valueRight,$y,$font,$value,$bold);
            $y-=15;
        };
        $pdf->text($left,$y,$font+2,'Kostenaufteilung',true);$y-=18;
        $amountRight=min($right-62,$left+390);$percentRight=min($right,$left+455);
        $this->rightText($pdf,$amountRight,$y,$font-1,'Betrag',true,'.35 .4 .45');
        $this->rightText($pdf,$percentRight,$y,$font-1,'Anteil',true,'.35 .4 .45');
        $y-=12;
        foreach([['Material',$analysis['materialCosts'],$analysis['materialCostPercent']],['Lohn',$analysis['laborCosts'],$analysis['laborCostPercent']],['Fremdleistungen',$analysis['externalCosts'],$analysis['externalCostPercent']],['Sonstige',$analysis['otherCosts'],$analysis['otherCostPercent']]] as [$label,$cost,$percent]){
            $pdf->text($left,$y,$font,$label);
            $barLeft=$left+105;$barRight=$left+265;
            $width=max(0.0,min(1.0,(float)$percent/100))*($barRight-$barLeft);
            $pdf->line($barLeft,$y+2,$barRight,$y+2,'.88 .9 .92');
            if($width>0)$pdf->line($barLeft,$y+2,$barLeft+$width,$y+2,$accent);
            $this->rightText($pdf,$amountRight,$y,$font,$numbers->money((string)$cost));
            $this->rightText($pdf,$percentRight,$y,$font,$numbers->percent((string)$percent));
            $y-=15;
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

    /** @param list<array<string,mixed>> $nodes @param array<string,mixed> $calculation */
    private function titleOverview(SimplePdf $pdf,PdfNumberFormatter $numbers,float $left,float $right,float &$y,float $font,array $nodes,array $calculation,string $accent,callable $newPage,float $bottom): void
    {
        $pdf->text($left,$y,15,'Titelübersicht',true,$accent);$y-=25;
        $regularRight=$right-140;$demandRight=$right-70;
        $header=function()use($pdf,$left,$right,$regularRight,$demandRight,&$y,$font):void{
            $pdf->text($left,$y,$font,'Titel',true);
            $this->rightText($pdf,$regularRight,$y,$font,'Regulär',true);
            $this->rightText($pdf,$demandRight,$y,$font,'Bedarf',true);
            $this->rightText($pdf,$right,$y,$font,'Alternativ',true);
            $y-=8;$pdf->line($left,$y,$right,$y,'0.3 0.35 0.4');$y-=14;
        };
        $header();
        foreach($this->titleSummary($nodes) as $row){
            if($y-30<$bottom){$newPage();$y-=14;$pdf->text($left,$y,12,'Titelübersicht – Fortsetzung',true,$accent);$y-=22;$header();}
            $lines=$this->wrap($row['number'].'  '.$row['name'],58);$baseline=$y;
            foreach($lines as $line){$pdf->text($left,$y,$font,$line,true);$y-=10;}
            $this->rightText($pdf,$regularRight,$baseline,$font,$numbers->money($row['regular']));
            $this->rightText($pdf,$demandRight,$baseline,$font,$numbers->money($row['demand']));
            $this->rightText($pdf,$right,$baseline,$font,$numbers->money($row['alternative']));
            $pdf->line($left,$y,$right,$y);$y-=14;
        }
        $y-=4;$pdf->text($left,$y,$font+1,'Gesamtsummen',true);
        $this->rightText($pdf,$regularRight,$y,$font+1,$numbers->money((string)$calculation['subtotalNet']),true);
        $this->rightText($pdf,$demandRight,$y,$font+1,$numbers->money((string)$calculation['demandSubtotal']),true);
        $this->rightText($pdf,$right,$y,$font+1,$numbers->money((string)$calculation['alternativeSubtotal']),true);
    }

    /** @param list<array<string,mixed>> $nodes @return list<array{number:string,name:string,regular:string,demand:string,alternative:string}> */
    private function titleSummary(array $nodes): array
    {
        $rows=[];$title=0;$untitled=['regular'=>BigDecimal::zero(),'demand'=>BigDecimal::zero(),'alternative'=>BigDecimal::zero()];
        foreach($nodes as $node){
            if(($node['type']??'')==='title'){
                $calculation=is_array($node['calculation']??null)?$node['calculation']:[];
                $rows[]=['number'=>(string)++$title,'name'=>(string)$node['name'],'regular'=>(string)($calculation['regularSubtotal']??'0'),'demand'=>(string)($calculation['demandSubtotal']??'0'),'alternative'=>(string)($calculation['alternativeSubtotal']??'0')];
                continue;
            }
            if(($node['type']??'')!=='position')continue;
            $type=($node['positionType']??'normal')==='bedarf'?'demand':(($node['positionType']??'normal')==='alternative'?'alternative':'regular');
            $untitled[$type]=$untitled[$type]->plus((string)($node['calculation']['positionTotal']??'0'));
        }
        if(!$untitled['regular']->isZero()||!$untitled['demand']->isZero()||!$untitled['alternative']->isZero())$rows[]=['number'=>'–','name'=>'Positionen ohne Titel','regular'=>(string)$untitled['regular'],'demand'=>(string)$untitled['demand'],'alternative'=>(string)$untitled['alternative']];
        return $rows;
    }

    /** @return list<string> */
    private function wrap(string $text,int $width): array{return explode("\n",wordwrap(trim($text),$width,"\n",true));}
    private function rightText(SimplePdf $pdf,float $right,float $y,float $size,string $text,bool $bold=false,string $color='0 0 0'): void
    {
        $pdf->text($right-$this->textWidth($text,$size),$y,$size,$text,$bold,$color);
    }

    private function textWidth(string $text,float $size): float
    {
        $widths=[
            ' '=>278,'.'=>278,','=>278,':'=>278,';'=>278,'-'=>333,'–'=>556,'/'=>278,'%'=>889,'€'=>556,
            'A'=>667,'B'=>667,'C'=>722,'D'=>722,'E'=>667,'F'=>611,'G'=>778,'H'=>722,'I'=>278,'J'=>500,'K'=>667,'L'=>556,'M'=>833,'N'=>722,'O'=>778,'P'=>667,'Q'=>778,'R'=>722,'S'=>667,'T'=>611,'U'=>722,'V'=>667,'W'=>944,'X'=>667,'Y'=>667,'Z'=>611,
            'a'=>556,'b'=>556,'c'=>500,'d'=>556,'e'=>556,'f'=>278,'g'=>556,'h'=>556,'i'=>222,'j'=>222,'k'=>500,'l'=>222,'m'=>833,'n'=>556,'o'=>556,'p'=>556,'q'=>556,'r'=>333,'s'=>500,'t'=>278,'u'=>556,'v'=>500,'w'=>722,'x'=>500,'y'=>500,'z'=>500,
        ];
        $units=0;
        foreach(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[] as $character){
            $normalized=strtr($character,['Ä'=>'A','Ö'=>'O','Ü'=>'U','ä'=>'a','ö'=>'o','ü'=>'u','ß'=>'s']);
            $units+=$widths[$normalized]??556;
        }
        return $units*$size/1000;
    }
    private function typeLabel(string $type): string{return ['material'=>'Material','labor'=>'Lohn','external'=>'Fremdleistung','other'=>'Sonstige Kosten'][$type]??$type;}
    /** @param array<string,mixed> $settings */
    private function logo(SimplePdf $pdf,array $settings,float $left,float $right): void{if(!is_string($settings['logoData']??null)||!str_starts_with($settings['logoData'],'data:image/jpeg;base64,'))return;$data=base64_decode(substr($settings['logoData'],23),true);if($data===false)return;$width=(float)($settings['logoWidth']??180);$height=(float)($settings['logoHeight']??100);$x=($settings['logoPosition']??'right')==='left'?$left:$right-$width;$pdf->jpegContained($data,$x,800-$height,$width,$height);}
    /** @return array<string,mixed> */
    private function project(string $id): array{$statement=$this->pdo->prepare('SELECT * FROM projects WHERE id=:id');$statement->execute(['id'=>$id]);$row=$statement->fetch();if(!is_array($row))throw new RuntimeException('Projekt wurde nicht gefunden.');return $row;}
    private function color(string $hex): string{$hex=ltrim($hex,'#');if(!preg_match('/^[0-9a-fA-F]{6}$/',$hex))return'0.08 0.35 0.54';return implode(' ',array_map(static fn(string $part):string=>(string)round(hexdec($part)/255,3),str_split($hex,2)));}
}
