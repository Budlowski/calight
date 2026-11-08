<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Offer\OfferNormalizer;
use App\Support\Uuid;
use App\Tests\TestCase;

final class OfferNormalizerTest extends TestCase
{
    public function testCanonicalDecimalsUtcAndMissingIds():void
    {
        $existing=Uuid::v4();
        $offer=[
            'createdAt'=>'2026-11-08T12:00:00+01:00','updatedAt'=>'2026-11-08T12:00:00+01:00',
            'offerAdjustment'=>['type'=>'discount','mode'=>'percentage','value'=>'5','label'=>null],
            'calculation'=>['netTotal'=>'999.00'],
            'nodes'=>[$this->title($existing)],
        ];
        $result=(new OfferNormalizer())->normalize($offer,true);
        $position=$result['nodes'][0]['children'][0];$text=$result['nodes'][0]['children'][1];
        $this->assertSame($existing,$result['nodes'][0]['id']);
        $this->assertTrue(Uuid::isValid($position['id'])&&Uuid::isValid($text['id']));
        $this->assertTrue(Uuid::isValid($position['components'][0]['id']));
        $this->assertSame('1.200',$position['quantity']);$this->assertSame('12.50',$position['manualUnitPrice']);
        $this->assertSame('0.200',$position['components'][0]['quantity']);$this->assertSame('5.0000',$position['components'][0]['costPrice']);$this->assertSame('20.00',$position['components'][0]['markupPercent']);
        $this->assertSame('5.00',$result['offerAdjustment']['value']);
        $this->assertTrue(!isset($position['calculation'])&&!isset($text['calculation'])&&!isset($position['components'][0]['salesPrice'])&&!isset($result['calculation']));
        $this->assertSame('2026-11-08T11:00:00Z',$result['createdAt']);
    }

    public function testOmitsNullTrailingTextButKeepsIndependentSnapshot(): void
    {
        $offer = [
            'nodes' => [
                ['type' => 'position', 'trailingText' => null, 'components' => []],
                [
                    'type' => 'position',
                    'trailingText' => ['code' => 'EXT', 'name' => 'Importierter Text'],
                    'components' => [],
                ],
            ],
        ];

        $result = (new OfferNormalizer())->normalize($offer);
        $this->assertTrue(!array_key_exists('trailingText', $result['nodes'][0]));
        $this->assertSame('Importierter Text', $result['nodes'][1]['trailingText']['name']);
    }

    /** @return array<string,mixed> */
    private function title(string $id):array
    {
        return ['id'=>$id,'type'=>'title','children'=>[
            ['type'=>'position','quantity'=>'1.2','manualUnitPrice'=>'12.5','calculation'=>['positionTotal'=>'999.00'],'components'=>[
                ['name'=>'M','quantity'=>'0.2','costPrice'=>'5','markupPercent'=>'20','salesPrice'=>'999.00'],
            ]],
            ['type'=>'text','title'=>'Hinweis','text'=>'Text','calculation'=>['subtotal'=>'999.00']],
        ]];
    }
}
