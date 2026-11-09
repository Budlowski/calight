<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Offer\OfferSubmissionValidator;
use App\Support\Uuid;
use App\Tests\TestCase;

final class OfferSubmissionValidatorTest extends TestCase
{
    public function testFreeRegularPositionProducesWarningsButNoBlockingError():void
    {
        $offer=$this->offer([$this->position('normal','0.00','1.000')]);
        $result=(new OfferSubmissionValidator())->inspect($offer,$this->calculated($offer,'0.00'),['customer_id'=>null,'customer_name'=>'Bestandskunde']);
        $this->assertSame([], $result['errors']);
        $this->assertTrue(in_array('zero_sales_price',array_column($result['warnings'],'code'),true));
        $this->assertTrue(in_array('incomplete_cost_basis',array_column($result['warnings'],'code'),true));
        $this->assertTrue(in_array('unfinished_position',array_column($result['warnings'],'code'),true));
    }

    public function testOptionalPositionStillRequiresPriceAndRegularPosition():void
    {
        $offer=$this->offer([$this->position('normal','10.00','1.000'),$this->position('bedarf',null,'1.000')]);
        $calculated=$this->calculated($offer,'10.00');$calculated['nodes'][1]['calculation']=null;
        $result=(new OfferSubmissionValidator())->inspect($offer,$calculated,['customer_id'=>Uuid::v4(),'customer_name'=>'']);
        $this->assertTrue(in_array('missing_sales_price',array_column($result['errors'],'code'),true));
        $onlyOptional=$this->offer([$this->position('alternative','10.00','1.000')]);
        $result=(new OfferSubmissionValidator())->inspect($onlyOptional,$this->calculated($onlyOptional,'0.00'),['customer_id'=>Uuid::v4()]);
        $this->assertTrue(in_array('missing_regular_position',array_column($result['errors'],'code'),true));
    }

    public function testZeroQuantityAndMissingCustomerBlockSubmission():void
    {
        $offer=$this->offer([$this->position('normal','10.00','0.000')]);
        $result=(new OfferSubmissionValidator())->inspect($offer,$this->calculated($offer,'0.00'),['customer_id'=>null,'customer_name'=>'']);
        $codes=array_column($result['errors'],'code');
        $this->assertTrue(in_array('invalid_quantity',$codes,true));
        $this->assertTrue(in_array('missing_customer',$codes,true));
    }

    /** @param list<array<string,mixed>> $nodes @return array<string,mixed> */
    private function offer(array $nodes):array{return['schemaVersion'=>1,'id'=>Uuid::v4(),'projectId'=>Uuid::v4(),'offerNumber'=>null,'revision'=>0,'status'=>'draft','title'=>'Prüfangebot','createdAt'=>'2026-11-09T10:00:00+01:00','updatedAt'=>'2026-11-09T10:00:00+01:00','nodes'=>$nodes];}
    /** @return array<string,mixed> */
    private function position(string $type,?string $manual,string $quantity):array{return['id'=>Uuid::v4(),'type'=>'position','positionType'=>$type,'workStatus'=>'open','shortText'=>'Testposition','longText'=>'','quantity'=>$quantity,'unit'=>'Stk.','manualUnitPrice'=>$manual,'components'=>[]];}
    /** @param array<string,mixed> $offer @return array<string,mixed> */
    private function calculated(array $offer,string $net):array{foreach($offer['nodes'] as &$node){$node['calculation']=$node['manualUnitPrice']===null?null:['effectiveUnitPrice'=>$node['manualUnitPrice'],'positionTotal'=>$node['manualUnitPrice']];}unset($node);$offer['calculation']=['netTotal'=>$net];return$offer;}
}
