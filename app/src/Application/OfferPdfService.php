<?php
declare(strict_types=1);
namespace App\Application;
use App\Domain\Offer\DocumentTaxCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Infrastructure\Pdf\PdfNumberFormatter;
use App\Infrastructure\Pdf\SimplePdf;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use Brick\Math\BigDecimal;
use PDO;
use RuntimeException;
use DateTimeImmutable;

final readonly class OfferPdfService
{
 public function __construct(private PDO $pdo,private DocumentSettingsRepository $settings,private OfferValidator $validator,private OfferCalculator $calculator){}
 /** @param array<string,mixed> $offer */
 public function fileName(array $offer):string
 {
  $settings=$this->settings->get();
  $pattern=(string)($settings['fileNamePattern']??'{JJJJ}-{MM}-{TT}_{DOKUMENTTYP}_{DOKUMENTNUMMER}');
  $date=new DateTimeImmutable();
  $fileName=strtr($pattern,[
   '{JJJJ}'=>$date->format('Y'),'{MM}'=>$date->format('m'),'{TT}'=>$date->format('d'),
   '{DOKUMENTTYP}'=>'Angebot','{DOKUMENTNUMMER}'=>trim((string)($offer['offerNumber']??''))?:'Entwurf',
  ]);
  $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$fileName);
  if(is_string($ascii)&&$ascii!=='')$fileName=$ascii;
  $fileName=preg_replace('/[^A-Za-z0-9._ -]+/','_',$fileName)??'Angebot_Entwurf';
  $fileName=trim(preg_replace('/\s+/','_',$fileName)??$fileName,' ._-');
  return ($fileName!==''?$fileName:'Angebot_Entwurf').'.pdf';
 }
 /** @param array<string,mixed> $offer */
 public function render(array $offer):string
 {
  $this->validator->validate($offer);$offer=$this->calculator->calculate($offer);$project=$this->project((string)$offer['projectId']);$settings=$this->settings->get();$terms=$this->terms($offer,$settings);
  $pdf=new SimplePdf();$numbers=new PdfNumberFormatter();$accent=$this->color((string)($settings['accentColor']??'#155a8a'));$left=(float)($settings['marginLeft']??45);$right=595-(float)($settings['marginRight']??45);$top=842-(float)($settings['marginTop']??42)-25;$bottom=(float)($settings['marginBottom']??82);$font=(float)($settings['baseFontSize']??8);
  $footer=function()use($pdf,$settings,$left,$right):void{if(!($settings['showFooter']??true))return;$pdf->line($left,72,$right,72);$columns=[[$this->company($settings),trim(($settings['street']??'').' '.($settings['houseNumber']??'')),trim(($settings['postalCode']??'').' '.($settings['city']??''))],array_filter([(string)($settings['phone']??'')!==''?'Telefon '.$settings['phone']:'',(string)($settings['email']??''),(string)($settings['website']??'')]),array_filter([(string)($settings['taxNumber']??'')!==''?'Steuernummer '.$settings['taxNumber']:'',(string)($settings['vatId']??'')!==''?'USt-ID '.$settings['vatId']:'']),array_filter([(string)($settings['bankName']??''),(string)($settings['iban']??'')!==''?'IBAN '.$settings['iban']:'',(string)($settings['bic']??'')!==''?'BIC '.$settings['bic']:''])];$width=($right-$left)/4;foreach($columns as $index=>$lines){$fy=58;foreach(array_filter($lines,static fn($v)=>trim((string)$v)!=='') as $line){$pdf->text($left+$index*$width,$fy,6.5,(string)$line,$fy===58);$fy-=10;}}if(trim((string)($settings['footerText']??''))!=='')$pdf->text($left,18,6.5,(string)$settings['footerText']);};
  $newPage=function(bool $first=false)use($pdf,$footer,$settings,$left,$right,$accent):void{if(!$first)$pdf->page();$footer();if($settings['showHeader']??true){$company=$this->company($settings);$companyX=$first&&($settings['logoPosition']??'right')==='left'&&is_string($settings['logoData']??null)?$left+(float)($settings['logoWidth']??180)+12:$left;$pdf->text($companyX,800,14,$company?:'Calight',true,$accent);if($first)$this->logo($pdf,$settings,$left,$right);}};$newPage(true);
  $company=$this->company($settings);$addressY=($settings['addressMode']??'standard')==='window'?690:680;$sender=implode(' · ',array_filter([$company,trim(($settings['street']??'').' '.($settings['houseNumber']??'')),trim(($settings['postalCode']??'').' '.($settings['city']??''))]));if($sender!==''){$pdf->text($left,$addressY+18,6.5,$sender,false,'.35 .4 .45');$pdf->line($left,$addressY+12,$left+270,$addressY+12);}$recipient=(string)($project['customer_display']??'');$pdf->text($left,$addressY-7,10,$recipient?:'Kein Kunde zugeordnet',true);$pdf->text($left,$addressY-22,9,trim(($project['customer_street']??'').' '.($project['customer_house_number']??'')));$pdf->text($left,$addressY-36,9,trim(($project['customer_postal_code']??'').' '.($project['customer_city']??'')));
  if($settings['showProjectInfo']??true){$meta=[['Belegnummer','folgt bei verbindlicher Ausgabe'],['Datum',date('d.m.Y')],['Projekt',(string)($project['project_number']??'')],['Objekt',(string)($project['name']??'')]];$my=$addressY-7;foreach($meta as [$label,$value]){if(trim($value)==='')continue;$pdf->text($right-200,$my,$font,$label,false,'.35 .4 .45');$pdf->text($right-115,$my,$font,$value,true);$my-=15;}}
  $headingY=$addressY-90;$pdf->text($left,$headingY,17,'Angebot – Entwurf',true,$accent);$pdf->text($left,$headingY-21,11,(string)$offer['title'],true);$y=$headingY-50;foreach($this->wrap($terms['introText'],105) as $line){$pdf->text($left,$y,$font+1,$line);$y-=12;}$y-=8;
  $tableHeader=function()use($pdf,&$y,$left,$right,$font):void{$pdf->text($left,$y,$font,'Pos.',true);$pdf->text($left+45,$y,$font,'Bezeichnung',true);$pdf->textRight($right-150,$y,$font,'Menge',true);$pdf->textRight($right-88,$y,$font,'Einzelpreis',true);$pdf->textRight($right,$y,$font,'Gesamt',true);$y-=9;$pdf->line($left,$y,$right,$y,'0.3 0.35 0.4');$y-=14;};$tableHeader();
  $ensure=function(float $height)use($newPage,$tableHeader,&$y,$top,$bottom):void{if($y-$height>=$bottom)return;$newPage();$y=$top;$tableHeader();};$hasSpecial=false;
  $rows=$this->rows($offer['nodes'],[],(bool)($settings['showEmptyTitles']??false));
  foreach($rows as $index=>$row){
   $next=$rows[$index+1]??null;
   if($row['kind']==='title'&&$row['excluded'])$hasSpecial=true;
   if($row['kind']==='title'){$badge=$row['titleType']==='bedarf'?'[Bedarf] ':($row['titleType']==='alternative'?'[Alternative] ':'');$lines=$this->wrap($badge.$row['text'],75);$description=$this->wrap($row['description'],90);$ensure(25+(count($lines)+count($description))*10+($next!==null?30:0));$muted=$row['excluded']?'.35 .4 .45':'0 0 0';$pdf->text($left+4,$y,$font+1,$row['number'],true,$muted);foreach($lines as $line){$pdf->text($left+45+min($row['depth'],3)*7,$y,$font+1,$line,true,$muted);$y-=10;}foreach($description as $line){$pdf->text($left+52+min($row['depth'],3)*7,$y,$font-.5,$line,false,'.35 .4 .45');$y-=10;}if($row['excluded']){$pdf->text($left+52+min($row['depth'],3)*7,$y,$font-.5,'Nicht in Angebotssumme enthalten',true,'.35 .4 .45');$y-=10;}$pdf->line($left,$y,$right,$y);$y-=13;continue;}
   if($row['kind']==='text'){$titleLines=$this->wrap($row['title'],90);$textLines=$this->wrap($row['text'],105);$height=18+count($titleLines)*11+count($textLines)*10;$ensure((float)min($height,55));foreach($titleLines as $line){$ensure(22);$pdf->text($left+45+min($row['depth'],3)*7,$y,$font+1,$line,true);$y-=11;}foreach($textLines as $line){$ensure(12);$pdf->text($left+45+min($row['depth'],3)*7,$y,$font,$line,false,'.25 .3 .35');$y-=10;}$y-=8;continue;}
   if($row['kind']==='subtotal'){$different=$row['calculatedTotal']!==$row['total'];$ensure(($different?38:24)+(($next['kind']??null)==='subtotal'?24:0));if($different){$pdf->text($left+65,$y,$font,'Rechnerischer Titelwert '.$row['label']);$pdf->textRight($right,$y,$font,$numbers->money($row['calculatedTotal']));$y-=14;}$pdf->text($left+65,$y,$font,'Summe '.$row['label'].($different?' (enthalten)':''),true);$pdf->textRight($right,$y,$font,$numbers->money($row['total']),true);$y-=8;$pdf->line($left+45,$y,$right,$y);$y-=25;continue;}
   $hasSpecial=$hasSpecial||$row['special'];$badge=!$row['inheritedExcluded']?($row['positionType']==='bedarf'?'[Bedarf] ':($row['positionType']==='alternative'?'[Alternative] ':'')):'';$short=$this->wrap($badge.$row['text'],61);$description=$this->wrap($row['longText'],86);$trailing=$this->wrap($row['trailingText'],86);$positionHeight=25+(count($short)+count($description)+count($trailing))*10;if(($next['kind']??null)==='subtotal')$positionHeight+=32;$ensure($positionHeight);$baseline=$y;$muted=$row['special']?'.4 .44 .48':'0 0 0';$pdf->text($left+4,$y,$font,$row['number'],false,$muted);foreach($short as $line){$pdf->text($left+45+min($row['depth'],3)*7,$y,$font,$line,true,$muted);$y-=10;}$pdf->textRight($right-150,$baseline,$font,$numbers->quantity($row['quantity']).' '.$row['unit'],false,$muted);$pdf->textRight($right-88,$baseline,$font,$numbers->money($row['unitPrice']),false,$muted);$pdf->textRight($right,$baseline,$font,$numbers->money($row['total']),!$row['special'],$muted);$y-=3;foreach($description as $line){$ensure(15);$pdf->text($left+45+min($row['depth'],3)*7,$y,$font-.5,$line,false,'.35 .4 .45');$y-=10;}foreach($trailing as $line){$ensure(15);$pdf->text($left+45+min($row['depth'],3)*7,$y,$font-.5,$line,false,'.4 .44 .48');$y-=10;}$pdf->line($left,$y,$right,$y);$y-=13;
  }
  if($hasSpecial){$ensure(30);foreach($this->wrap('Hinweis: Bedarfs- und Alternativinhalte sind nicht Bestandteil der regulären Angebots- und Titelsummen.',130) as $line){$pdf->text($left,$y,$font,$line,true,'.35 .4 .45');$y-=10;}$y-=7;}
  $ensureFinal=$ensure;
  $summaryRows=$this->titleSummary($offer['nodes']);
  if(($settings['showTitleSummary']??true)&&$summaryRows!==[]){
   $newPage();$y=$top;
   $ensureFinal=function(float $height)use($newPage,&$y,$top,$bottom):void{if($y-$height>=$bottom)return;$newPage();$y=$top;};
   $ensureFinal(55);$pdf->text($left,$y,$font+5,'Titelübersicht',true,$accent);$y-=26;$pdf->text($left,$y,$font,'Titel',true);$pdf->textRight($right,$y,$font,'Summe netto',true);$y-=9;$pdf->line($left,$y,$right,$y,'0.3 0.35 0.4');$y-=15;
   foreach($summaryRows as $summary){$nameLines=$this->wrap($summary['name'],75);$rowHeight=14+count($nameLines)*10;$ensureFinal((float)$rowHeight);$pdf->text($left+4,$y,$font,$summary['number'],true);$baseline=$y;foreach($nameLines as $line){$pdf->text($left+45,$y,$font,$line);$y-=10;}$pdf->textRight($right,$baseline,$font,$numbers->money($summary['total']),true);$pdf->line($left,$y,$right,$y);$y-=14;}
   $y-=8;
  }
  $tax=(new DocumentTaxCalculator())->calculate((string)($offer['calculation']['netTotal']??'0.00'),(string)($settings['taxMode']??'standard'),(string)($settings['vatRate']??'19.00'),(string)($settings['taxNote']??''));
  $adjustment=is_array($offer['offerAdjustment']??null)?$offer['offerAdjustment']:['type'=>'none'];$hasAdjustment=($adjustment['type']??'none')!=='none';
  $taxNoteLines=$tax['note']!==''?$this->wrap($tax['note'],70):[];$sumHeight=72+count($taxNoteLines)*10+($tax['mode']==='standard'?20:0)+($hasAdjustment?42:0);$ensureFinal($sumHeight);
  if($hasAdjustment){$pdf->text($right-185,$y,$font+1,'Zwischensumme netto');$pdf->textRight($right,$y,$font+1,$numbers->money((string)($offer['calculation']['subtotalNet']??'0.00')));$y-=21;$adjustmentLabel=trim((string)($adjustment['label']??''));if($adjustmentLabel==='')$adjustmentLabel=($adjustment['type']==='discount'?'Rabatt':'Aufschlag').(($adjustment['mode']??'percentage')==='percentage'?' '.$numbers->percent((string)($adjustment['value']??'0')):'');$pdf->text($right-185,$y,$font+1,$adjustmentLabel);$pdf->textRight($right,$y,$font+1,$numbers->money((string)($offer['calculation']['adjustmentSigned']??'0.00')));$y-=21;}
  $pdf->text($right-185,$y,$font+1,$hasAdjustment?'Angebot netto':'Summe netto');$pdf->textRight($right,$y,$font+1,$numbers->money($tax['net']));$y-=21;if($tax['mode']==='standard'){$pdf->text($right-185,$y,$font+1,'Umsatzsteuer '.$numbers->percent($tax['rate']));$pdf->textRight($right,$y,$font+1,$numbers->money($tax['tax']));$y-=20;}$pdf->line($right-190,$y+7,$right,$y+7,'0.3 0.35 0.4');$y-=8;$pdf->text($right-185,$y,$font+3,'Gesamtbetrag',true);$pdf->textRight($right,$y,$font+3,$numbers->money($tax['gross']),true,$accent);$y-=30;foreach($taxNoteLines as $line){$pdf->text($right-260,$y,$font,$line);$y-=10;}
  $conditionBlocks=array_values(array_filter(['Angebotsgültigkeit: '.$terms['validityLabel'],$terms['paymentTerms'],$terms['additionalTerms'],$terms['closingText']],static fn(string $block):bool=>trim($block)!==''&&$block!=='Angebotsgültigkeit: '));
  $conditionLines=array_map(fn(string $block):array=>$this->wrap($block,105),$conditionBlocks);$conditionHeight=array_sum(array_map(static fn(array $lines):int=>7+count($lines)*11,$conditionLines));
  if($conditionHeight>0&&$conditionHeight<=($top-$bottom))$ensureFinal((float)$conditionHeight);
  foreach($conditionLines as $lines){$blockHeight=7+count($lines)*11;if($conditionHeight>($top-$bottom))$ensureFinal((float)$blockHeight);$y-=7;foreach($lines as $line){$pdf->text($left,$y,$font,$line);$y-=11;}}
  return $pdf->output();
 }
 /** @param list<array<string,mixed>> $nodes @param list<int> $prefix @return list<array<string,mixed>> */
 private function rows(array $nodes,array $prefix=[],bool $showEmpty=false,?string $inheritedType=null):array
 {
  $rows=[];$title=0;$position=0;
  foreach($nodes as $node){
   $type=(string)($node['type']??'');
   if($type==='title'){
    $path=[...$prefix,++$title];$has=$this->containsPosition($node['children']??[]);$hasContent=$this->containsContent($node['children']??[]);$titleType=(string)($node['titleType']??'normal');$effectiveType=$inheritedType??($titleType!=='normal'?$titleType:null);
    if($showEmpty||$hasContent)$rows[]=['kind'=>'title','number'=>implode('.',$path),'text'=>(string)$node['name'],'description'=>(string)($node['description']??''),'depth'=>count($path)-1,'titleType'=>$titleType,'excluded'=>$effectiveType!==null];
    $rows=[...$rows,...$this->rows($node['children']??[],$path,$showEmpty,$effectiveType)];
    if($has){$calculated=(string)($node['calculation']['calculatedSubtotal']??$node['calculation']['subtotal']??'0.00');$total=$titleType==='normal'?(string)($node['calculation']['subtotal']??'0.00'):$calculated;$rows[]=['kind'=>'subtotal','label'=>'Titel '.implode('.',$path).': '.$node['name'],'total'=>$total,'calculatedTotal'=>$calculated];}
    continue;
   }
   if($type==='text'){$rows[]=['kind'=>'text','title'=>(string)($node['title']??''),'text'=>(string)($node['text']??''),'depth'=>count($prefix)];continue;}
   $positionType=(string)($node['positionType']??'normal');$number=($prefix?implode('.',$prefix).'.':'').str_pad((string)++$position,2,'0',STR_PAD_LEFT);$inheritedExcluded=$inheritedType!==null;
   $rows[]=['kind'=>'position','number'=>$number,'text'=>(string)$node['shortText'],'longText'=>(string)($node['longText']??''),'trailingText'=>(string)($node['trailingText']['name']??''),'depth'=>count($prefix),'quantity'=>(string)$node['quantity'],'unit'=>(string)$node['unit'],'unitPrice'=>(string)($node['calculation']['effectiveUnitPrice']??'0'),'total'=>(string)($node['calculation']['positionTotal']??'0'),'positionType'=>$positionType,'inheritedExcluded'=>$inheritedExcluded,'special'=>$inheritedExcluded||$positionType!=='normal'];
  }
  return $rows;
 }
 /** @param list<array<string,mixed>> $nodes */ private function containsPosition(array $nodes):bool{foreach($nodes as $node){if(($node['type']??'')==='position'||(($node['type']??'')==='title'&&$this->containsPosition($node['children']??[])))return true;}return false;}
 /** @param list<array<string,mixed>> $nodes */ private function containsContent(array $nodes):bool{foreach($nodes as $node){if(in_array($node['type']??'',['position','text'],true)||(($node['type']??'')==='title'&&$this->containsContent($node['children']??[])))return true;}return false;}
 /** @param list<array<string,mixed>> $nodes @return list<array{number:string,name:string,total:string}> */
 private function titleSummary(array $nodes):array
 {
  $rows=[];$title=0;$untitled=BigDecimal::zero();
  foreach($nodes as $node){
   if(($node['type']??'')==='title'){
    $number=(string)++$title;
    if(($node['titleType']??'normal')==='normal'&&$this->containsPosition($node['children']??[]))$rows[]=['number'=>$number,'name'=>(string)$node['name'],'total'=>(string)($node['calculation']['includedSubtotal']??$node['calculation']['subtotal']??'0.00')];
    continue;
   }
   if(($node['type']??'')==='position'&&($node['positionType']??'normal')==='normal')$untitled=$untitled->plus((string)($node['calculation']['positionTotal']??'0.00'));
  }
  if(!$untitled->isZero())$rows[]=['number'=>'–','name'=>'Positionen ohne Titel','total'=>(string)$untitled];
  return $rows;
 }
 /** @return list<string> */ private function wrap(string $text,int $width):array{$text=trim($text);if($text==='')return[];$lines=[];foreach(preg_split('/\R/',$text)?:[] as $paragraph){if($paragraph===''){$lines[]='';continue;}foreach(explode("\n",wordwrap($paragraph,$width,"\n",true)) as $line)$lines[]=$line;}return $lines;}
 /** @param array<string,mixed> $settings */ private function logo(SimplePdf $pdf,array $settings,float $left,float $right):void{if(!is_string($settings['logoData']??null)||!str_starts_with($settings['logoData'],'data:image/jpeg;base64,'))return;$data=base64_decode(substr($settings['logoData'],23),true);$width=(float)($settings['logoWidth']??180);$height=(float)($settings['logoHeight']??100);$x=($settings['logoPosition']??'right')==='left'?$left:$right-$width;$headerTop=814.0;if($data!==false)$pdf->jpegContained($data,$x,$headerTop-$height,$width,$height);}
 /** @param array<string,mixed> $offer @param array<string,mixed> $settings @return array{introText:string,closingText:string,paymentTerms:string,additionalTerms:string,validityLabel:string} */
 private function terms(array $offer,array $settings):array{$individual=is_array($offer['documentTerms']??null)?$offer['documentTerms']:[];$text=static fn(string $key):string=>trim((string)($individual[$key]??''))!==''?(string)$individual[$key]:(string)($settings[$key]??'');$mode=(string)($individual['validityMode']??'default');if($mode==='default')$mode=(string)($settings['validityMode']??'duration');if($mode==='date'){$date=(string)($individual['validityDate']??'');if($date==='')$date=(string)($settings['validityDate']??'');$label=$date!==''?(new DateTimeImmutable($date))->format('d.m.Y'):'';}else{$days=(int)($individual['validityDays']??0);if($days<1)$days=(int)($settings['validityDays']??30);$label=$days.' Tage ab Angebotsdatum';}return['introText'=>$text('introText'),'closingText'=>$text('closingText'),'paymentTerms'=>$text('paymentTerms'),'additionalTerms'=>$text('additionalTerms'),'validityLabel'=>$label];}
 /** @param array<string,mixed> $settings */ private function company(array $settings):string{return trim((string)($settings['companyName']??'').' '.(string)($settings['legalForm']??''));}
 /** @return array<string,mixed> */ private function project(string $id):array{$s=$this->pdo->prepare("SELECT p.*,CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name||' '||c.last_name) END customer_display,c.street customer_street,c.house_number customer_house_number,c.postal_code customer_postal_code,c.city customer_city FROM projects p LEFT JOIN customers c ON c.id=p.customer_id WHERE p.id=:id");$s->execute(['id'=>$id]);$row=$s->fetch();if(!is_array($row))throw new RuntimeException('Projekt wurde nicht gefunden.');return $row;}
 private function color(string $hex):string{$hex=ltrim($hex,'#');if(!preg_match('/^[0-9a-fA-F]{6}$/',$hex))return'0.08 0.35 0.54';return implode(' ',array_map(static fn(string $part):string=>(string)round(hexdec($part)/255,3),str_split($hex,2)));}
}
