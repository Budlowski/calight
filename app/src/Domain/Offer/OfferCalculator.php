<?php

declare(strict_types=1);

namespace App\Domain\Offer;

use App\Domain\Calculation\Decimal;
use App\Domain\Calculation\PriceCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class OfferCalculator
{
    public function __construct(private PriceCalculator $calculator) {}

    /** @param array<string,mixed> $offer @return array<string,mixed> */
    public function calculate(array $offer): array
    {
        $totals=['calculated'=>BigDecimal::zero(),'included'=>BigDecimal::zero()];
        $offer['nodes']=$this->calculateNodes($offer['nodes']??[],$totals);
        $subtotal=$totals['included'];
        $adjustment=$this->calculateAdjustment($offer['offerAdjustment']??null,$subtotal);
        $net=$subtotal->plus($adjustment['signed']);
        if($net->isNegative())throw new OfferValidationException(['Der Rabatt darf die reguläre Netto-Zwischensumme nicht überschreiten.']);
        $offer['calculation']=[
            'calculatedTotal'=>Decimal::money($totals['calculated']),
            'subtotalNet'=>Decimal::money($subtotal),
            'adjustmentAmount'=>Decimal::money($adjustment['amount']),
            'adjustmentSigned'=>Decimal::money($adjustment['signed']),
            'netTotal'=>Decimal::money($net),
        ];
        return $offer;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param array{calculated:BigDecimal,included:BigDecimal} $totals
     * @return list<array<string,mixed>>
     */
    private function calculateNodes(array $nodes,array &$totals):array
    {
        foreach($nodes as &$node){
            if(($node['type']??null)==='title'){
                $childTotals=['calculated'=>BigDecimal::zero(),'included'=>BigDecimal::zero()];
                $node['children']=$this->calculateNodes($node['children']??[],$childTotals);
                $included=($node['titleType']??'normal')==='normal'?$childTotals['included']:BigDecimal::zero();
                $node['calculation']=[
                    'calculatedSubtotal'=>Decimal::money($childTotals['calculated']),
                    'subtotal'=>Decimal::money($childTotals['included']),
                    'includedSubtotal'=>Decimal::money($included),
                ];
                $totals['calculated']=$totals['calculated']->plus($childTotals['calculated']);
                $totals['included']=$totals['included']->plus($included);
                continue;
            }
            if(($node['type']??null)==='text'){$node['calculation']=null;continue;}
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
            $positionTotal=BigDecimal::of($position['positionTotal']);
            $totals['calculated']=$totals['calculated']->plus($positionTotal);
            if(($node['positionType']??'normal')==='normal')$totals['included']=$totals['included']->plus($positionTotal);
        }
        unset($node);
        return $nodes;
    }

    /** @return array{amount:BigDecimal,signed:BigDecimal} */
    private function calculateAdjustment(mixed $input,BigDecimal $subtotal):array
    {
        if(!is_array($input)||($input['type']??'none')==='none')return['amount'=>BigDecimal::zero(),'signed'=>BigDecimal::zero()];
        $type=(string)$input['type'];$mode=(string)($input['mode']??'percentage');
        $value=$mode==='percentage'
            ?Decimal::parse((string)($input['value']??'0'),Decimal::PERCENT_SCALE,'Angebotsanpassung')->multipliedBy($subtotal)->dividedBy('100',Decimal::MONEY_SCALE,RoundingMode::HalfUp)
            :Decimal::parse((string)($input['value']??'0'),Decimal::MONEY_SCALE,'Angebotsanpassung');
        $amount=$value->abs();$signed=$type==='discount'?$amount->negated():$amount;
        return['amount'=>$amount,'signed'=>$signed];
    }
}
