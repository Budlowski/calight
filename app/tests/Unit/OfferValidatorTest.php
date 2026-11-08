<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Offer\OfferValidationException;
use App\Domain\Offer\OfferValidator;
use App\Support\Uuid;
use App\Tests\TestCase;

final class OfferValidatorTest extends TestCase
{
    public function testAcceptsNestedTitlesAndAlternativePosition(): void
    {
        $offer = $this->offer();
        $offer['nodes'] = [[
            'id' => Uuid::v4(),
            'type' => 'title',
            'name' => 'Elektroinstallation',
            'description' => '',
            'children' => [[
                'id' => Uuid::v4(),
                'type' => 'position',
                'positionType' => 'alternative',
                'shortText' => 'Steckdose',
                'longText' => '',
                'quantity' => '10.000',
                'unit' => 'Stk.',
                'manualUnitPrice' => null,
                'components' => [],
            ]],
        ]];

        (new OfferValidator())->validate($offer);
        $this->assertTrue(true);
    }

    public function testRejectsDuplicateNodeIds(): void
    {
        $id = Uuid::v4();
        $offer = $this->offer();
        $offer['nodes'] = [
            ['id' => $id, 'type' => 'title', 'name' => 'A', 'description' => '', 'children' => []],
            ['id' => $id, 'type' => 'title', 'name' => 'B', 'description' => '', 'children' => []],
        ];

        $this->assertThrows(OfferValidationException::class, fn () => (new OfferValidator())->validate($offer));
    }

    public function testAcceptsLegacyAndSnapshotPriceModes(): void
    {
        $offer=$this->offer();$base=['id'=>Uuid::v4(),'name'=>'Material','calculationType'=>'material','unit'=>'Stk.','quantity'=>'1.000','costPrice'=>'2.0000','markupPercent'=>'10.00','masterDataId'=>null];
        $offer['nodes']=[['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Alt','longText'=>'','quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>null,'components'=>[$base+['priceLocked'=>false,'masterDataSnapshot'=>null]]],['id'=>Uuid::v4(),'type'=>'position','positionType'=>'normal','shortText'=>'Neu','longText'=>'','quantity'=>'1.000','unit'=>'Stk.','manualUnitPrice'=>null,'components'=>[array_replace($base,['id'=>Uuid::v4(),'priceMode'=>'manual','sourceArticleNumber'=>null])]]];
        (new OfferValidator())->validate($offer);$this->assertTrue(true);
    }

    public function testRejectsUnknownManipulatedFields():void
    {
        $offer=$this->offer();$offer['serverPath']='/etc/passwd';
        $this->assertThrows(OfferValidationException::class,fn()=>(new OfferValidator())->validate($offer));
    }

    /** @return array<string,mixed> */
    private function offer(): array
    {
        return [
            'schemaVersion' => 1,
            'id' => Uuid::v4(),
            'projectId' => Uuid::v4(),
            'offerNumber' => null,
            'revision' => 0,
            'status' => 'draft',
            'title' => 'Testangebot',
            'createdAt' => '2026-11-08T12:00:00+01:00',
            'updatedAt' => '2026-11-08T12:00:00+01:00',
            'nodes' => [],
        ];
    }
}
