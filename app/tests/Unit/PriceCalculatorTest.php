<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Calculation\PriceCalculator;
use App\Tests\TestCase;

final class PriceCalculatorTest extends TestCase
{
    public function testRoundsHalfAwayFromZeroAtDefinedPoint(): void
    {
        $result = (new PriceCalculator())->calculatePosition('2.000', [[
            'type' => 'material',
            'quantity' => '1.000',
            'costPrice' => '1.0050',
            'markupPercent' => '0.00',
        ]]);

        $this->assertSame('1.01', $result['calculatedUnitPrice']);
        $this->assertSame('2.02', $result['positionTotal']);
        $this->assertSame('2.01', $result['materialCosts']);
        $this->assertSame('0.01', $result['contributionMargin']);
    }

    public function testDoesNotRoundIndividualComponents(): void
    {
        $result = (new PriceCalculator())->calculatePosition('1.000', [
            ['type' => 'material', 'quantity' => '1.000', 'costPrice' => '0.0040', 'markupPercent' => '0.00'],
            ['type' => 'material', 'quantity' => '1.000', 'costPrice' => '0.0040', 'markupPercent' => '0.00'],
        ]);

        $this->assertSame('0.01', $result['calculatedUnitPrice']);
    }

    public function testManualPriceDoesNotChangeCosts(): void
    {
        $result = (new PriceCalculator())->calculatePosition('10.000', [[
            'type' => 'labor',
            'quantity' => '0.500',
            'costPrice' => '30.0000',
            'markupPercent' => '20.00',
        ]], '20.00');

        $this->assertSame('18.00', $result['calculatedUnitPrice']);
        $this->assertSame('20.00', $result['effectiveUnitPrice']);
        $this->assertSame('150.00', $result['laborCosts']);
        $this->assertSame('50.00', $result['contributionMargin']);
        $this->assertSame('2.00', $result['priceDeviation']);
    }
}
