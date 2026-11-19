<?php
declare(strict_types=1);
namespace App\Tests\Unit;
use App\Infrastructure\Pdf\PdfNumberFormatter;
use App\Tests\TestCase;
final class PdfNumberFormatterTest extends TestCase
{
 public function testGermanQuantitiesAndMoneyAreExact():void{$f=new PdfNumberFormatter();$this->assertSame('1',$f->quantity('1.000'));$this->assertSame('0,2',$f->quantity('0.200'));$this->assertSame('1,25',$f->quantity('1.250'));$this->assertSame('1.000',$f->quantity('1000.000'));$this->assertSame('18,50 €',$f->money('18.50'));}
 public function testPurchasePricesKeepUpToFourDecimals():void{$f=new PdfNumberFormatter();$this->assertSame('3,4567 €',$f->unitPrice('3.4567'));$this->assertSame('22,10 €',$f->unitPrice('22.1000'));$this->assertSame('5,00 €',$f->unitPrice('5'));$this->assertSame('1.234,505 €',$f->unitPrice('1234.5050'));}
}
