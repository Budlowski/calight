<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

final class SimplePdf
{
    /** @var list<string> */ private array $pages=[''];
    private int $page=0;
    /** @var array{data:string,width:int,height:int}|null */ private ?array $jpeg=null;
    public function page():void{$this->pages[]='';$this->page++;}
    public function text(float $x,float $y,float $size,string $text,bool $bold=false,string $color='0 0 0'):void
    {
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT',$text)?:$text;
        $escaped=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$encoded);
        $this->pages[$this->page].="BT /".($bold?'F2':'F1')." {$size} Tf {$color} rg {$x} {$y} Td ({$escaped}) Tj ET\n";
    }
    public function line(float $x1,float $y1,float $x2,float $y2,string $color='.75 .78 .8'):void{$this->pages[$this->page].="{$color} RG {$x1} {$y1} m {$x2} {$y2} l S\n";}
    public function textRight(float $right,float $y,float $size,string $text,bool $bold=false,string $color='0 0 0'):void{$this->text($right-(strlen($text)*$size*.48),$y,$size,$text,$bold,$color);}
    public function jpeg(string $data,float $x,float $y,float $width,float $height):bool
    {
        $size=@getimagesizefromstring($data);if(!is_array($size)||($size[2]??null)!==IMAGETYPE_JPEG)return false;$this->jpeg=['data'=>$data,'width'=>(int)$size[0],'height'=>(int)$size[1]];$this->pages[$this->page].="q {$width} 0 0 {$height} {$x} {$y} cm /Im1 Do Q\n";return true;
    }
    public function output():string
    {
        $total=count($this->pages);foreach($this->pages as $index=>&$page){$watermark="q BT /F2 58 Tf .94 .95 .96 rg .707 .707 -.707 .707 155 290 Tm (ENTWURF) Tj ET Q\n";$page=$watermark.$page."BT /F1 7 Tf .35 .4 .45 rg 520 25 Td (Seite ".($index+1)." / {$total}) Tj ET\n";}unset($page);
        $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'PAGES',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];$kids=[];$next=5;$imageResource='';if($this->jpeg!==null){$imageId=$next++;$image=$this->jpeg;$objects[$imageId]='<< /Type /XObject /Subtype /Image /Width '.$image['width'].' /Height '.$image['height'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($image['data'])." >>\nstream\n".$image['data']."\nendstream";$imageResource=" /XObject << /Im1 {$imageId} 0 R >>";}
        foreach($this->pages as $content){$page=$next++;$stream=$next++;$kids[]="{$page} 0 R";$objects[$page]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>{$imageResource} >> /Contents {$stream} 0 R >>";$objects[$stream]="<< /Length ".strlen($content)." >>\nstream\n{$content}endstream";}
        $objects[2]='<< /Type /Pages /Count '.count($kids).' /Kids ['.implode(' ',$kids).'] >>';ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as $id=>$body){$offsets[$id]=strlen($pdf);$pdf.="{$id} 0 obj\n{$body}\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";for($i=1;$i<=count($objects);$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);return $pdf."trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
