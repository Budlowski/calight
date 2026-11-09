<?php

declare(strict_types=1);

namespace App\Domain\Offer;

use App\Domain\Calculation\Decimal;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class OfferSubmissionValidator
{
    /**
     * @param array<string,mixed> $offer
     * @param array<string,mixed>|null $calculated
     * @param array<string,mixed>|null $project
     * @param list<string> $calculationErrors
     * @return array{errors:list<array<string,mixed>>,warnings:list<array<string,mixed>>}
     */
    public function inspect(array $offer,?array $calculated,?array $project,array $calculationErrors=[]):array
    {
        $errors=[];$warnings=[];
        try{(new OfferValidator())->validate($offer);}catch(OfferValidationException $exception){
            foreach($exception->errors as $message)$errors[]=$this->problem('invalid_structure',$message);
        }
        if(trim((string)($offer['title']??''))==='')$errors[]=$this->problem('missing_title','Die Angebotsbezeichnung fehlt.');
        if($project===null)$errors[]=$this->problem('missing_project','Das Angebot ist keinem vorhandenen Projekt zugeordnet.');
        elseif(trim((string)($project['customer_id']??''))===''&&trim((string)($project['customer_name']??''))==='')$errors[]=$this->problem('missing_customer','Dem Projekt ist kein Kunde zugeordnet.');
        foreach($calculationErrors as $message)$errors[]=$this->problem('invalid_calculation',$message);
        if($calculated!==null){
            $regular=0;$this->inspectNodes($calculated['nodes']??[],$errors,$warnings,$regular,false);
            if($regular===0)$errors[]=$this->problem('missing_regular_position','Das Angebot enthält keine regulär enthaltene Leistungsposition.');
            try{if(BigDecimal::of((string)($calculated['calculation']['netTotal']??''))->isNegative())$errors[]=$this->problem('negative_total','Die Gesamtangebotssumme darf nicht negativ sein.');}
            catch(\Throwable){$errors[]=$this->problem('invalid_total','Die Gesamtangebotssumme ist nicht berechenbar.');}
        }
        return['errors'=>$this->unique($errors),'warnings'=>$this->unique($warnings)];
    }

    /** @param list<array<string,mixed>> $nodes @param list<array<string,mixed>> $errors @param list<array<string,mixed>> $warnings */
    private function inspectNodes(array $nodes,array &$errors,array &$warnings,int &$regular,bool $excluded):void
    {
        foreach($nodes as $node){
            if(($node['type']??null)==='title'){
                $this->inspectNodes($node['children']??[],$errors,$warnings,$regular,$excluded||($node['titleType']??'normal')!=='normal');
                continue;
            }
            if(($node['type']??null)!=='position')continue;
            $ref=['positionId'=>(string)($node['id']??''),'position'=>(string)($node['shortText']??'Unbenannte Position')];
            $isRegular=!$excluded&&($node['positionType']??'normal')==='normal';if($isRegular)$regular++;
            try{$quantity=Decimal::parse((string)($node['quantity']??''),Decimal::QUANTITY_SCALE,'Positionsmenge');if($quantity->isLessThanOrEqualTo('0'))$errors[]=$this->problem('invalid_quantity','Die Menge muss größer als 0 sein.',$ref);}
            catch(InvalidArgumentException $exception){$errors[]=$this->problem('invalid_quantity',$exception->getMessage(),$ref);}
            $unitPrice=$node['calculation']['effectiveUnitPrice']??null;
            if($unitPrice===null||$unitPrice==='')$errors[]=$this->problem('missing_sales_price','Für die Position fehlt ein Verkaufspreis.',$ref);
            else try{$price=BigDecimal::of((string)$unitPrice);if($price->isNegative())$errors[]=$this->problem('invalid_sales_price','Der Verkaufspreis darf nicht negativ sein.',$ref);elseif($price->isZero())$warnings[]=$this->problem('zero_sales_price','Die Position besitzt bewusst einen Verkaufspreis von 0,00 € und ist kostenlos.',$ref);}
            catch(\Throwable){$errors[]=$this->problem('invalid_sales_price','Der Verkaufspreis ist nicht berechenbar.',$ref);}
            if($isRegular&&($node['components']??[])===[])$warnings[]=$this->problem('incomplete_cost_basis','Die regulär enthaltene Position besitzt keine vollständige Kostenbasis.',$ref);
            $workStatus=$node['workStatus']??'open';if($workStatus==='open'||$workStatus==='in_progress')$warnings[]=$this->problem('unfinished_position',$workStatus==='open'?'Die Position ist noch offen.':'Die Position ist noch in Bearbeitung.',$ref);
        }
    }

    /** @param array<string,string> $reference @return array<string,mixed> */
    private function problem(string $code,string $message,array $reference=[]):array{return['code'=>$code,'message'=>$message,...$reference];}
    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function unique(array $items):array{$seen=[];$result=[];foreach($items as $item){$key=json_encode($item);if(isset($seen[$key]))continue;$seen[$key]=true;$result[]=$item;}return$result;}
}
