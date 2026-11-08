<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Offer\OfferStructure;
use App\Tests\TestCase;

final class OfferStructureTest extends TestCase
{
    public function testNumbersNestedTitlesAndPositionsInTheirVisibleOrder(): void
    {
        $nodes=[
            ['id'=>'t1','type'=>'title','children'=>[
                ['id'=>'p1','type'=>'position'],
                ['id'=>'text1','type'=>'text'],
                ['id'=>'t11','type'=>'title','children'=>[
                    ['id'=>'t111','type'=>'title','children'=>[
                        ['id'=>'p11101','type'=>'position'],
                        ['id'=>'p11102','type'=>'position'],
                    ]],
                    ['id'=>'p1101','type'=>'position'],
                ]],
                ['id'=>'p2','type'=>'position'],
            ]],
            ['id'=>'t2','type'=>'title','children'=>[]],
            ['id'=>'rootPosition','type'=>'position'],
        ];
        $actual=[];foreach((new OfferStructure())->numbering($nodes) as $entry)$actual[$entry['id']]=$entry['number'];
        $this->assertSame(['t1'=>'1','p1'=>'1.01','text1'=>'','t11'=>'1.1','t111'=>'1.1.1','p11101'=>'1.1.1.01','p11102'=>'1.1.1.02','p1101'=>'1.1.01','p2'=>'1.02','t2'=>'2','rootPosition'=>'01'],$actual);
    }
}
