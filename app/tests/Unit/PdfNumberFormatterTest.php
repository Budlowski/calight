<?php
declare(strict_types=1);
namespace App\Tests\Unit;
use App\Infrastructure\Pdf\PdfNumberFormatter;
use App\Tests\TestCase;
final class PdfNumberFormatterTest extends TestCase
{
 public function testGermanQuantitiesAndMoneyAreExact():void{$f=new PdfNumberFormatter();$this->assertSame('1',$f->quantity('1.000'));$this->assertSame('0,2',$f->quantity('0.200'));$this->assertSame('1,25',$f->quantity('1.250'));$this->assertSame('1.000',$f->quantity('1000.000'));$this->assertSame('18,50 €',$f->money('18.50'));}
}
