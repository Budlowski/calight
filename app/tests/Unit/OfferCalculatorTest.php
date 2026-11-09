<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Calculation\PriceCalculator;
use App\Domain\Offer\OfferCalculator;
use App\Domain\Offer\OfferValidationException;
use App\Tests\TestCase;

final class OfferCalculatorTest extends TestCase
{
    public function testManualPricesPositionTypesAndRecursiveSums(): void
    {
        $component=fn(string $type,string $quantity,string $cost,string $markup):array=>['calculationType'=>$type,'quantity'=>$quantity,'costPrice'=>$cost,'markupPercent'=>$markup];
        $position=fn(string $id,string $kind,string $quantity,?string $manual,array $components):array=>['id'=>$id,'type'=>'position','positionType'=>$kind,'workStatus'=>'done','shortText'=>$id,'longText'=>'','quantity'=>$quantity,'unit'=>'Stk.','manualUnitPrice'=>$manual,'components'=>$components];
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
        $this->assertSame('done',$normal['workStatus']);
        $this->assertSame('30.00',$sub['calculation']['subtotal']);
        $this->assertSame('280.00',$sub['calculation']['calculatedSubtotal']);
        $this->assertSame('75.00',$result['nodes'][0]['calculation']['subtotal']);
        $this->assertSame('325.00',$result['nodes'][0]['calculation']['calculatedSubtotal']);
        $this->assertSame('75.00',$result['calculation']['netTotal']);
        $this->assertSame('30.00',$result['calculation']['analysis']['materialCosts']);
        $this->assertSame('30.00',$result['calculation']['analysis']['laborCosts']);
        $this->assertSame('60.00',$result['calculation']['analysis']['totalCosts']);
        $this->assertSame('0.500',$result['calculation']['analysis']['laborHours']);
        $this->assertSame(false,$result['calculation']['analysis']['complete']);
        $this->assertSame(5,$result['calculation']['analysis']['positionCount']);
        $this->assertSame(3,$result['calculation']['analysis']['includedPositionCount']);
        $this->assertSame(2,$result['calculation']['analysis']['optionalPositionCount']);
        $this->assertSame(3,$result['calculation']['analysis']['incompleteCount']);
        $this->assertSame(1,$result['calculation']['analysis']['includedIncompleteCount']);
        $this->assertSame(2,$result['calculation']['analysis']['optionalIncompleteCount']);
        $this->assertSame('80.00',$result['calculation']['analysis']['costShareOfNetPercent']);
        $this->assertSame(null,$result['calculation']['analysis']['contributionMargin']);
        $this->assertSame(null,$result['nodes'][1]['calculation']);
    }

    public function testAnalysisUsesIncludedCostsManualSalesPriceAndOfferDiscount(): void
    {
        $included = [
            'id'=>'included','type'=>'position','positionType'=>'normal','workStatus'=>'done','shortText'=>'Montage','longText'=>'',
            'quantity'=>'2.000','unit'=>'Stk.','manualUnitPrice'=>'20.00','components'=>[
                ['calculationType'=>'material','quantity'=>'1.000','costPrice'=>'10.0000','markupPercent'=>'25.00'],
                ['calculationType'=>'labor','quantity'=>'0.500','costPrice'=>'60.0000','markupPercent'=>'0.00'],
            ],
        ];
        $excluded = [
            'id'=>'excluded','type'=>'position','positionType'=>'normal','workStatus'=>'open','shortText'=>'Option','longText'=>'',
            'quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>'1000.00','components'=>[
                ['calculationType'=>'material','quantity'=>'1.000','costPrice'=>'500.0000','markupPercent'=>'0.00'],
            ],
        ];
        $offer=['offerAdjustment'=>['type'=>'discount','mode'=>'percentage','value'=>'10.00','label'=>null],'nodes'=>[
            $included,
            ['id'=>'optional','type'=>'title','titleType'=>'bedarf','name'=>'Option','description'=>'','children'=>[$excluded]],
        ]];
        $analysis=(new OfferCalculator(new PriceCalculator()))->calculate($offer)['calculation']['analysis'];
        $this->assertSame('20.00',$analysis['materialCosts']);
        $this->assertSame('60.00',$analysis['laborCosts']);
        $this->assertSame('80.00',$analysis['totalCosts']);
        $this->assertSame('1.000',$analysis['laborHours']);
        $this->assertSame('-44.00',$analysis['contributionMargin']);
        $this->assertSame('-122.22',$analysis['contributionMarginPercent']);
        $this->assertSame('-55.00',$analysis['costMarkupPercent']);
        $this->assertSame(2,$analysis['positionCount']);
        $this->assertSame(1,$analysis['includedPositionCount']);
        $this->assertSame(1,$analysis['optionalPositionCount']);
        $this->assertSame(1,$analysis['statusDone']);
        $this->assertSame(1,$analysis['statusOpen']);
        $this->assertSame(2,$analysis['manualPriceCount']);
        $this->assertSame(true,$analysis['complete']);
    }

    public function testIncompletePositionBelowOptionalTitleDoesNotInvalidateRegularEconomics(): void
    {
        $offer=['nodes'=>[[
            'id'=>'optional','type'=>'title','titleType'=>'alternative','name'=>'Option','description'=>'','children'=>[[
                'id'=>'position','type'=>'position','positionType'=>'normal','workStatus'=>'open','shortText'=>'Option','longText'=>'',
                'quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>'100.00','components'=>[],
            ]],
        ]]];
        $analysis=(new OfferCalculator(new PriceCalculator()))->calculate($offer)['calculation']['analysis'];
        $this->assertSame(1,$analysis['positionCount']);
        $this->assertSame(0,$analysis['includedPositionCount']);
        $this->assertSame(1,$analysis['optionalPositionCount']);
        $this->assertSame(1,$analysis['incompleteCount']);
        $this->assertSame(0,$analysis['includedIncompleteCount']);
        $this->assertSame(1,$analysis['optionalIncompleteCount']);
        $this->assertSame(true,$analysis['complete']);
    }

    public function testNestedTitleTypesTextNodesAndAdjustments():void
    {
        $position=static fn(string $id,string $price):array=>['id'=>$id,'type'=>'position','positionType'=>'normal','shortText'=>$id,'longText'=>'','quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>$price,'components'=>[]];
        $base=['nodes'=>[
            ['id'=>'normal','type'=>'title','titleType'=>'normal','name'=>'Normal','description'=>'','children'=>[
                $position('included','100.00'),
                ['id'=>'alternative','type'=>'title','titleType'=>'alternative','name'=>'Alternative','description'=>'','children'=>[$position('excluded-nested','40.00')]],
                ['id'=>'hint','type'=>'text','title'=>'Hinweis','text'=>'Ohne Preis'],
            ]],
            ['id'=>'bedarf','type'=>'title','titleType'=>'bedarf','name'=>'Bedarf','description'=>'','children'=>[
                ['id'=>'nested-alternative','type'=>'title','titleType'=>'alternative','name'=>'Trotzdem Bedarf','description'=>'','children'=>[$position('excluded','25.00')]],
            ]],
        ]];
        $calculator=new OfferCalculator(new PriceCalculator());
        $discount=$calculator->calculate($base+['offerAdjustment'=>['type'=>'discount','mode'=>'percentage','value'=>'5.00','label'=>null]]);
        $this->assertSame('165.00',$discount['calculation']['calculatedTotal']);
        $this->assertSame('100.00',$discount['calculation']['subtotalNet']);
        $this->assertSame('100.00',$discount['calculation']['regularSubtotal']);
        $this->assertSame('25.00',$discount['calculation']['demandSubtotal']);
        $this->assertSame('40.00',$discount['calculation']['alternativeSubtotal']);
        $this->assertSame('-5.00',$discount['calculation']['adjustmentSigned']);
        $this->assertSame('95.00',$discount['calculation']['netTotal']);
        $this->assertSame('40.00',$discount['nodes'][0]['children'][1]['calculation']['subtotal']);
        $this->assertSame('0.00',$discount['nodes'][0]['children'][1]['calculation']['includedSubtotal']);
        $this->assertSame('40.00',$discount['nodes'][0]['children'][1]['calculation']['alternativeSubtotal']);
        $this->assertSame('40.00',$discount['nodes'][0]['calculation']['alternativeSubtotal']);
        $this->assertSame('25.00',$discount['nodes'][1]['calculation']['demandSubtotal']);
        $this->assertSame('0.00',$discount['nodes'][1]['calculation']['alternativeSubtotal']);
        $absolute=$calculator->calculate($base+['offerAdjustment'=>['type'=>'surcharge','mode'=>'absolute','value'=>'12.34','label'=>'Fracht']]);
        $this->assertSame('112.34',$absolute['calculation']['netTotal']);
        $this->assertThrows(OfferValidationException::class,fn()=>$calculator->calculate($base+['offerAdjustment'=>['type'=>'discount','mode'=>'absolute','value'=>'100.01','label'=>null]]));
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

    public function testRemovingManualUnitPriceImmediatelyRestoresCalculatedPrice():void
    {
        $calculator=new OfferCalculator(new PriceCalculator());$position=['id'=>'p','type'=>'position','positionType'=>'normal','shortText'=>'Preiswechsel','longText'=>'','quantity'=>'2.000','unit'=>'Stk.','manualUnitPrice'=>'20.00','components'=>[['calculationType'=>'material','quantity'=>'1.000','costPrice'=>'10.0000','markupPercent'=>'25.00']]];
        $manual=$calculator->calculate(['nodes'=>[$position]])['nodes'][0]['calculation'];$this->assertSame('20.00',$manual['effectiveUnitPrice']);$this->assertSame('40.00',$manual['positionTotal']);
        $position['manualUnitPrice']=null;$calculated=$calculator->calculate(['nodes'=>[$position]])['nodes'][0]['calculation'];$this->assertSame('12.50',$calculated['effectiveUnitPrice']);$this->assertSame('25.00',$calculated['positionTotal']);
    }
}
