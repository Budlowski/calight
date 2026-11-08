<?php
declare(strict_types=1);
namespace App\Tests\Unit;
use App\Domain\Offer\DocumentTaxCalculator;
use App\Tests\TestCase;
final class DocumentTaxCalculatorTest extends TestCase
{
 public function testStandardAndExemptTaxTreatments():void{$calculator=new DocumentTaxCalculator();$standard=$calculator->calculate('100.00','standard','19.00');$this->assertSame('19.00',$standard['tax']);$this->assertSame('119.00',$standard['gross']);$exempt=$calculator->calculate('100.00','exempt','19.00','Steuerfrei');$this->assertSame('0.00',$exempt['tax']);$this->assertSame('100.00',$exempt['gross']);}
}
