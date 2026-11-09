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
        $totals=$this->emptyTotals();
        $offer['nodes']=$this->calculateNodes($offer['nodes']??[],$totals);
        $subtotal=$totals['included'];
        $adjustment=$this->calculateAdjustment($offer['offerAdjustment']??null,$subtotal);
        $net=$subtotal->plus($adjustment['signed']);
        if($net->isNegative())throw new OfferValidationException(['Der Rabatt darf die reguläre Netto-Zwischensumme nicht überschreiten.']);
        $costs=$totals['materialCosts']->plus($totals['laborCosts'])->plus($totals['externalCosts'])->plus($totals['otherCosts']);
        $complete=$totals['includedIncomplete']===0;
        $contribution=$complete?$net->minus($costs):null;
        $offer['calculation']=[
            'calculatedTotal'=>Decimal::money($totals['calculated']),
            'subtotalNet'=>Decimal::money($subtotal),
            'regularSubtotal'=>Decimal::money($totals['regular']),
            'demandSubtotal'=>Decimal::money($totals['demand']),
            'alternativeSubtotal'=>Decimal::money($totals['alternative']),
            'adjustmentAmount'=>Decimal::money($adjustment['amount']),
            'adjustmentSigned'=>Decimal::money($adjustment['signed']),
            'netTotal'=>Decimal::money($net),
            'analysis'=>[
                'materialCosts'=>Decimal::money($totals['materialCosts']),
                'laborCosts'=>Decimal::money($totals['laborCosts']),
                'externalCosts'=>Decimal::money($totals['externalCosts']),
                'otherCosts'=>Decimal::money($totals['otherCosts']),
                'totalCosts'=>Decimal::money($costs),
                'laborHours'=>(string)$totals['laborHours']->toScale(Decimal::TIME_SCALE,RoundingMode::HalfUp),
                'complete'=>$complete,
                'contributionMargin'=>$contribution===null?null:Decimal::money($contribution),
                'contributionMarginPercent'=>$contribution===null||$net->isZero()?null:$this->percent($contribution,$net),
                'costMarkupPercent'=>$contribution===null||$costs->isZero()?null:$this->percent($net->minus($costs),$costs),
                'materialCostPercent'=>$costs->isZero()?'0.00':$this->percent($totals['materialCosts'],$costs),
                'laborCostPercent'=>$costs->isZero()?'0.00':$this->percent($totals['laborCosts'],$costs),
                'externalCostPercent'=>$costs->isZero()?'0.00':$this->percent($totals['externalCosts'],$costs),
                'otherCostPercent'=>$costs->isZero()?'0.00':$this->percent($totals['otherCosts'],$costs),
                'costShareOfNetPercent'=>!$net->isZero()?$this->percent($costs,$net):null,
                'contributionShareOfNetPercent'=>$contribution!==null&&!$net->isZero()?$this->percent($contribution,$net):null,
                'positionCount'=>$totals['positionCount'],
                'includedPositionCount'=>$totals['includedPositionCount'],
                'optionalPositionCount'=>$totals['positionCount']-$totals['includedPositionCount'],
                'statusOpen'=>$totals['statusOpen'],
                'statusInProgress'=>$totals['statusInProgress'],
                'statusDone'=>$totals['statusDone'],
                'manualPriceCount'=>$totals['manualPriceCount'],
                'incompleteCount'=>$totals['incompleteCount'],
                'includedIncompleteCount'=>$totals['includedIncomplete'],
                'optionalIncompleteCount'=>$totals['incompleteCount']-$totals['includedIncomplete'],
            ],
        ];
        return $offer;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param array{calculated:BigDecimal,included:BigDecimal,regular:BigDecimal,demand:BigDecimal,alternative:BigDecimal} $totals
     * @return list<array<string,mixed>>
     */
    private function calculateNodes(array $nodes,array &$totals):array
    {
        foreach($nodes as &$node){
            if(($node['type']??null)==='title'){
                $childTotals=$this->emptyTotals();
                $node['children']=$this->calculateNodes($node['children']??[],$childTotals);
                $titleType=$node['titleType']??'normal';
                $contribution=$childTotals;
                if($titleType!=='normal'){
                    $contribution['regular']=BigDecimal::zero();
                    $contribution['demand']=BigDecimal::zero();
                    $contribution['alternative']=BigDecimal::zero();
                    $contribution[$titleType==='bedarf'?'demand':'alternative']=$childTotals['calculated'];
                    $contribution['included']=BigDecimal::zero();
                    foreach(['materialCosts','laborCosts','externalCosts','otherCosts','laborHours'] as $key)$contribution[$key]=BigDecimal::zero();
                    $contribution['includedPositionCount']=0;
                    $contribution['includedIncomplete']=0;
                }
                $node['calculation']=[
                    'calculatedSubtotal'=>Decimal::money($childTotals['calculated']),
                    'subtotal'=>Decimal::money($childTotals['included']),
                    'includedSubtotal'=>Decimal::money($contribution['included']),
                    'regularSubtotal'=>Decimal::money($contribution['regular']),
                    'demandSubtotal'=>Decimal::money($contribution['demand']),
                    'alternativeSubtotal'=>Decimal::money($contribution['alternative']),
                ];
                $totals['calculated']=$totals['calculated']->plus($childTotals['calculated']);
                foreach(['included','regular','demand','alternative','materialCosts','laborCosts','externalCosts','otherCosts','laborHours'] as $key)$totals[$key]=$totals[$key]->plus($contribution[$key]);
                foreach(['positionCount','includedPositionCount','statusOpen','statusInProgress','statusDone','manualPriceCount','incompleteCount','includedIncomplete'] as $key)$totals[$key]+=$contribution[$key];
                continue;
            }
            if(($node['type']??null)==='text'){$node['calculation']=null;continue;}
            $components=[];
            foreach(($node['components']??[]) as $component){$components[]=[
                'type'=>(string)($component['calculationType']??''),'quantity'=>(string)($component['quantity']??''),
                'costPrice'=>(string)($component['costPrice']??''),'markupPercent'=>(string)($component['markupPercent']??''),
            ];}
            $manual=isset($node['manualUnitPrice'])&&$node['manualUnitPrice']!==''?(string)$node['manualUnitPrice']:null;
            $positionType=$node['positionType']??'normal';
            $incomplete=$components===[];
            $totals['positionCount']++;
            $status=in_array($node['workStatus']??'open',['open','in_progress','done'],true)?$node['workStatus']:'open';
            $totals[$status==='done'?'statusDone':($status==='in_progress'?'statusInProgress':'statusOpen')]++;
            if($manual!==null)$totals['manualPriceCount']++;
            if($incomplete){$totals['incompleteCount']++;if($positionType==='normal')$totals['includedIncomplete']++;}
            if($positionType==='normal')$totals['includedPositionCount']++;
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
            $category=$positionType==='bedarf'?'demand':($positionType==='alternative'?'alternative':'regular');
            $totals[$category]=$totals[$category]->plus($positionTotal);
            if($positionType==='normal'){
                $totals['included']=$totals['included']->plus($positionTotal);
                foreach(['materialCosts','laborCosts','externalCosts','otherCosts'] as $key)$totals[$key]=$totals[$key]->plus($position[$key]);
                $positionQuantity=Decimal::parse((string)$node['quantity'],Decimal::QUANTITY_SCALE,'Positionsmenge');
                foreach(($node['components']??[]) as $component)if(($component['calculationType']??null)==='labor')$totals['laborHours']=$totals['laborHours']->plus(Decimal::parse((string)$component['quantity'],Decimal::QUANTITY_SCALE,'Lohnzeit')->multipliedBy($positionQuantity));
            }
        }
        unset($node);
        return $nodes;
    }

    /** @return array{calculated:BigDecimal,included:BigDecimal,regular:BigDecimal,demand:BigDecimal,alternative:BigDecimal} */
    private function emptyTotals():array
    {
        return[
            'calculated'=>BigDecimal::zero(),'included'=>BigDecimal::zero(),'regular'=>BigDecimal::zero(),'demand'=>BigDecimal::zero(),'alternative'=>BigDecimal::zero(),
            'materialCosts'=>BigDecimal::zero(),'laborCosts'=>BigDecimal::zero(),'externalCosts'=>BigDecimal::zero(),'otherCosts'=>BigDecimal::zero(),'laborHours'=>BigDecimal::zero(),
            'positionCount'=>0,'includedPositionCount'=>0,'statusOpen'=>0,'statusInProgress'=>0,'statusDone'=>0,'manualPriceCount'=>0,'incompleteCount'=>0,'includedIncomplete'=>0,
        ];
    }

    private function percent(BigDecimal $value,BigDecimal $base):string
    {
        return(string)$value->multipliedBy('100')->dividedBy($base,Decimal::PERCENT_SCALE,RoundingMode::HalfUp);
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
