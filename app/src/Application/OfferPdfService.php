<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidator;
use App\Infrastructure\Pdf\SimplePdf;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PDO;
use RuntimeException;

final readonly class OfferPdfService
{
    public function __construct(private PDO $pdo,private DocumentSettingsRepository $settings,private OfferValidator $validator,private OfferCalculator $calculator){}

    /** @param array<string,mixed> $offer */
    public function render(array $offer):string
    {
        $this->validator->validate($offer);$offer=$this->calculator->calculate($offer);$project=$this->project((string)$offer['projectId']);$settings=$this->settings->get();$pdf=new SimplePdf();$accent=$this->color((string)($settings['accentColor']??'#155a8a'));
        $footer=function()use($pdf,$settings):void{$pdf->line(45,72,550,72);$company=trim((string)($settings['companyName']??'').' '.(string)($settings['legalForm']??''));$pdf->text(45,58,7,$company,true);$pdf->text(45,47,7,trim(($settings['street']??'').' '.($settings['houseNumber']??'')));$pdf->text(45,37,7,trim(($settings['postalCode']??'').' '.($settings['city']??'')));$pdf->text(180,58,7,'Telefon '.(string)($settings['phone']??''));$pdf->text(180,47,7,(string)($settings['email']??''));$pdf->text(180,37,7,(string)($settings['website']??''));$pdf->text(315,58,7,'Steuernummer '.(string)($settings['taxNumber']??''));$pdf->text(315,47,7,'USt-ID '.(string)($settings['vatId']??''));$pdf->text(440,58,7,(string)($settings['bankName']??''));$pdf->text(440,47,7,'IBAN '.(string)($settings['iban']??''));$pdf->text(440,37,7,'BIC '.(string)($settings['bic']??''));};
        $footer();$this->logo($pdf,$settings);$company=trim((string)($settings['companyName']??'').' '.(string)($settings['legalForm']??''));$pdf->text(45,800,15,$company?:'Calight',true,$accent);
        $sender=implode(' · ',array_filter([$company,trim(($settings['street']??'').' '.($settings['houseNumber']??'')),trim(($settings['postalCode']??'').' '.($settings['city']??''))]));$pdf->text(45,690,6.5,$sender,false,'.35 .4 .45');$pdf->line(45,684,315,684);
        $recipient=(string)($project['customer_display']??$project['customer_name']??'');$pdf->text(45,665,10,$recipient?:'Kein Kunde zugeordnet',true);$pdf->text(45,650,9,trim(($project['customer_street']??'').' '.($project['customer_house_number']??'')));$pdf->text(45,636,9,trim(($project['customer_postal_code']??'').' '.($project['customer_city']??'')));
        $meta=[['Belegnummer','folgt bei verbindlicher Ausgabe'],['Datum',date('d.m.Y')],['Projekt',(string)($project['project_number']??'')],['Objekt',(string)($project['name']??'')]];$my=665;foreach($meta as [$label,$value]){$pdf->text(350,$my,8,$label,false,'.35 .4 .45');$pdf->text(430,$my,8,$value,true);$my-=15;}$site=trim(($project['site_street']??'').' '.($project['site_house_number']??''));$siteLine2=trim(($project['site_postal_code']??'').' '.($project['site_city']??''));if($site===''&&trim((string)($project['site_address']??''))!=='')$site=(string)$project['site_address'];if($site!==''){$pdf->text(430,$my,8,$site);if($siteLine2!=='')$pdf->text(430,$my-13,8,$siteLine2);}
        $pdf->text(45,535,17,'Angebot – Entwurf',true,$accent);$pdf->text(45,514,11,(string)$offer['title'],true);$y=480;foreach($this->wrap((string)($settings['introText']??''),96) as $line){$pdf->text(45,$y,9,$line);$y-=12;}$y-=8;
        $tableHeader=function()use($pdf,&$y):void{$pdf->text(45,$y,8,'Pos.',true);$pdf->text(90,$y,8,'Bezeichnung',true);$pdf->textRight(400,$y,8,'Menge',true);$pdf->textRight(455,$y,8,'Einzelpreis',true);$pdf->textRight(495,$y,8,'USt.',true);$pdf->textRight(550,$y,8,'Gesamt',true);$y-=9;$pdf->line(45,$y,550,$y,'0.3 0.35 0.4');$y-=14;};$tableHeader();
        $ensure=function(float $height)use($pdf,$footer,$tableHeader,&$y):void{if($y-$height>=88)return;$pdf->page();$footer();$y=790;$tableHeader();};
        foreach($this->rows($offer['nodes']) as $row){if($row['kind']==='title'){$ensure(28);$pdf->text(50,$y,9,$row['number'],true);$pdf->text(90+($row['depth']*8),$y,9,$row['text'],true);$y-=12;if($row['description']!=='')foreach($this->wrap($row['description'],82) as $line){$pdf->text(98+($row['depth']*8),$y,7.5,$line,false,'.35 .4 .45');$y-=10;}$pdf->line(45,$y,550,$y);$y-=13;continue;}if($row['kind']==='subtotal'){$ensure(24);$pdf->text(110,$y,8,'Summe '.($row['label']?:'Titel'),true);$pdf->textRight(550,$y,8,$this->money($row['total']),true);$y-=8;$pdf->line(90,$y,550,$y);$y-=15;continue;}$short=$this->wrap($row['text'],52);$description=$this->wrap($row['longText'],76);$height=26+((count($short)-1)*10)+(count($description)*10);$ensure($height);$baseline=$y;$pdf->text(50,$y,8,$row['number']);foreach($short as $line){$pdf->text(90+($row['depth']*8),$y,8,$line,true);$y-=10;}$pdf->textRight(400,$baseline,8,$row['quantity'].' '.$row['unit']);$pdf->textRight(455,$baseline,8,$this->money($row['unitPrice']));$pdf->textRight(495,$baseline,8,$this->percent($settings['vatRate']??'19'));$pdf->textRight(550,$baseline,8,$this->money($row['total']),true);$y-=3;foreach($description as $line){$pdf->text(90+($row['depth']*8),$y,7.5,$line,false,'.35 .4 .45');$y-=10;}$pdf->line(45,$y,550,$y);$y-=13;}
        $ensure(110);$net=BigDecimal::of((string)($offer['calculation']['netTotal']??'0'));$rate=BigDecimal::of((string)($settings['vatRate']??'19'));$vat=$net->multipliedBy($rate)->dividedBy('100',2,RoundingMode::HalfUp);$gross=$net->plus($vat);$pdf->text(365,$y,9,'Summe netto');$pdf->textRight(550,$y,9,$this->money((string)$net));$y-=18;$pdf->text(365,$y,9,'Umsatzsteuer '.$this->percent((string)$rate));$pdf->textRight(550,$y,9,$this->money((string)$vat));$y-=9;$pdf->line(360,$y,550,$y,'0.3 0.35 0.4');$y-=17;$pdf->text(365,$y,11,'Gesamtbetrag',true);$pdf->textRight(550,$y,11,$this->money((string)$gross),true,$accent);$y-=32;
        foreach($this->wrap((string)($settings['paymentTerms']??''),96) as $line){$ensure(12);$pdf->text(45,$y,8,$line);$y-=11;}$y-=7;foreach($this->wrap((string)($settings['closingText']??''),96) as $line){$ensure(12);$pdf->text(45,$y,8,$line);$y-=11;}return $pdf->output();
    }

    /** @param list<array<string,mixed>> $nodes @param list<int> $prefix @return list<array<string,mixed>> */
    private function rows(array $nodes,array $prefix=[]):array
    {
        $rows=[];$title=0;$position=0;foreach($nodes as $node){if(($node['type']??'')==='title'){$path=[...$prefix,++$title];$rows[]=['kind'=>'title','number'=>implode('.',$path),'text'=>(string)$node['name'],'description'=>(string)($node['description']??''),'depth'=>count($path)-1];$rows=[...$rows,...$this->rows($node['children']??[],$path)];if((float)($node['calculation']['subtotal']??0)!==0.0)$rows[]=['kind'=>'subtotal','label'=>'Titel '.implode('.',$path).': '.$node['name'],'total'=>$node['calculation']['subtotal']];continue;}$number=($prefix?implode('.',$prefix).'.':'').str_pad((string)++$position,2,'0',STR_PAD_LEFT);$type=match($node['positionType']??'normal'){'bedarf'=>'Bedarf: ','alternative'=>'Alternative: ',default=>''};$rows[]=['kind'=>'position','number'=>$number,'text'=>$type.(string)$node['shortText'],'longText'=>(string)($node['longText']??''),'depth'=>count($prefix),'quantity'=>(string)$node['quantity'],'unit'=>(string)$node['unit'],'unitPrice'=>$node['calculation']['effectiveUnitPrice']??'0','total'=>$node['calculation']['positionTotal']??'0'];}return $rows;
    }
    /** @return list<string> */ private function wrap(string $text,int $width):array{$lines=[];foreach(preg_split('/\R/',trim($text))?:[] as $paragraph){if($paragraph===''){$lines[]='';continue;}foreach(explode("\n",wordwrap($paragraph,$width,"\n",true)) as $line)$lines[]=$line;}return $lines;}
    /** @param array<string,mixed> $settings */ private function logo(SimplePdf $pdf,array $settings):void{if(!is_string($settings['logoData']??null)||!str_starts_with($settings['logoData'],'data:image/jpeg;base64,'))return;$data=base64_decode(substr($settings['logoData'],23),true);if($data!==false)$pdf->jpeg($data,430,755,120,58);}
    /** @return array<string,mixed> */ private function project(string $id):array{$s=$this->pdo->prepare("SELECT p.*,CASE WHEN c.customer_type='company' THEN c.company_name ELSE trim(c.first_name||' '||c.last_name) END customer_display,c.street customer_street,c.house_number customer_house_number,c.postal_code customer_postal_code,c.city customer_city FROM projects p LEFT JOIN customers c ON c.id=p.customer_id WHERE p.id=:id");$s->execute(['id'=>$id]);$row=$s->fetch();if(!is_array($row))throw new RuntimeException('Projekt wurde nicht gefunden.');return $row;}
    private function money(mixed $value):string{return number_format((float)$value,2,',','.').' €';}
    private function percent(mixed $value):string{return rtrim(rtrim(number_format((float)$value,2,',','.'),'0'),',').' %';}
    private function color(string $hex):string{$hex=ltrim($hex,'#');if(!preg_match('/^[0-9a-fA-F]{6}$/',$hex))return'0.08 0.35 0.54';return implode(' ',array_map(static fn(string $part):string=>(string)round(hexdec($part)/255,3),str_split($hex,2)));}
}
