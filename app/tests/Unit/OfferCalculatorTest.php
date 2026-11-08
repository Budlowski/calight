<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Tests\TestCase;

final class OfferCalculatorTest extends TestCase
{
    public function testManualPricesPositionTypesAndRecursiveSums(): void
    {
        $component=fn(string $type,string $quantity,string $cost,string $markup):array=>['calculationType'=>$type,'quantity'=>$quantity,'costPrice'=>$cost,'markupPercent'=>$markup];
        $position=fn(string $id,string $kind,string $quantity,?string $manual,array $components):array=>['id'=>$id,'type'=>'position','positionType'=>$kind,'shortText'=>$id,'longText'=>'','quantity'=>$quantity,'unit'=>'Stk.','manualUnitPrice'=>$manual,'components'=>$components];
        $offer=['nodes'=>[
            ['id'=>'t1','type'=>'title','name'=>'T1','description'=>'','children'=>[
                $position('normal','normal','3.000','15.00',[$component('material','2.000','5.0000','20.00')]),
                ['id'=>'t11','type'=>'title','name'=>'T11','description'=>'','children'=>[
                    $position('labor','normal','1.000',null,[$component('labor','0.500','60.0000','0.00')]),
                    $position('bedarf','bedarf','2.000','100.00',[]),
                    $position('alternative','alternative','1.000','50.00',[]),
                ]],
            ]],
            $position('unpriced','normal','1.000',null,[]),
        ]];
        $result=(new OfferCalculator(new PriceCalculator()))->calculate($offer);
        $normal=$result['nodes'][0]['children'][0];$sub=$result['nodes'][0]['children'][1];
        $this->assertSame('10.00',$normal['calculation']['totalCosts']);
        $this->assertSame('2.00',$normal['calculation']['markupTotal']);
        $this->assertSame('12.00',$normal['calculation']['calculatedUnitPrice']);
        $this->assertSame('15.00',$normal['calculation']['effectiveUnitPrice']);
        $this->assertSame('3.00',$normal['calculation']['priceDeviation']);
        $this->assertSame('45.00',$normal['calculation']['positionTotal']);
        $this->assertSame('30.00',$sub['calculation']['subtotal']);
        $this->assertSame('75.00',$result['nodes'][0]['calculation']['subtotal']);
        $this->assertSame('75.00',$result['calculation']['netTotal']);
        $this->assertSame(null,$result['nodes'][1]['calculation']);
    }

    public function testRoundsOnlyAtDefinedSalesPricePoints(): void
    {
        $offer=['nodes'=>[['id'=>'p','type'=>'position','positionType'=>'normal','shortText'=>'Rundung','longText'=>'','quantity'=>'1.333','unit'=>'m','manualUnitPrice'=>null,'components'=>[
            ['calculationType'=>'material','quantity'=>'0.333','costPrice'=>'0.1000','markupPercent'=>'50.00'],
        ]]]];
        $result=(new OfferCalculator(new PriceCalculator()))->calculate($offer)['nodes'][0]['calculation'];
        $this->assertSame('0.05',$result['calculatedUnitPrice']);
        $this->assertSame('0.07',$result['positionTotal']);
    }
}
