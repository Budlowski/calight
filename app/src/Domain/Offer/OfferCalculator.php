<?php

declare(strict_types=1);

namespace App\Domain\Offer;

use App\Domain\Calculation\Decimal;
use App\Domain\Calculation\PriceCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class OfferCalculator
{
    public function __construct(private PriceCalculator $calculator)
    {
    }

    /** @param array<string,mixed> $offer @return array<string,mixed> */
    public function calculate(array $offer): array
    {
        $net=BigDecimal::zero();
        $offer['nodes']=$this->calculateNodes($offer['nodes']??[],$net);
        $offer['calculation']=['netTotal'=>Decimal::money($net)];
        return $offer;
    }

    /** @param list<array<string,mixed>> $nodes @return list<array<string,mixed>> */
    private function calculateNodes(array $nodes, BigDecimal &$normalTotal): array
    {
        foreach($nodes as &$node){
            if(($node['type']??null)==='title'){
                $subtotal=BigDecimal::zero();
                $node['children']=$this->calculateNodes($node['children']??[],$subtotal);
                $node['calculation']=['subtotal'=>Decimal::money($subtotal)];
                $normalTotal=$normalTotal->plus($subtotal);
                continue;
            }
            $components=[];
            foreach(($node['components']??[]) as $component){$components[]=[
                'type'=>(string)($component['calculationType']??''),'quantity'=>(string)($component['quantity']??''),
                'costPrice'=>(string)($component['costPrice']??''),'markupPercent'=>(string)($component['markupPercent']??''),
            ];}
            $manual=isset($node['manualUnitPrice'])&&$node['manualUnitPrice']!==''?(string)$node['manualUnitPrice']:null;
            if($components===[]&&$manual===null){$node['calculation']=null;continue;}
            $unit=$this->calculator->calculatePosition('1.000',$components,$manual);
            $position=$this->calculator->calculatePosition((string)$node['quantity'],$components,$manual);
            $componentSales=[];foreach($components as $component)$componentSales[]=$this->calculator->calculatePosition('1.000',[$component])['positionTotal'];
            $totalCosts=BigDecimal::of($unit['totalCosts']);$calculated=BigDecimal::of($unit['calculatedUnitPrice']);
            $node['calculation']=[
                'materialCosts'=>$unit['materialCosts'],'laborCosts'=>$unit['laborCosts'],'externalCosts'=>$unit['externalCosts'],
                'otherCosts'=>$unit['otherCosts'],'totalCosts'=>$unit['totalCosts'],
                'markupTotal'=>Decimal::money($calculated->minus($totalCosts)),'calculatedUnitPrice'=>$unit['calculatedUnitPrice'],
                'effectiveUnitPrice'=>$unit['effectiveUnitPrice'],'priceDeviation'=>$unit['priceDeviation'],
                'positionTotal'=>$position['positionTotal'],'componentSalesPrices'=>$componentSales,
            ];
            if(($node['positionType']??'normal')==='normal')$normalTotal=$normalTotal->plus(BigDecimal::of($position['positionTotal']));
        }
        unset($node);
        return $nodes;
    }
}
