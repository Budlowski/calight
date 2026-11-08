<?php

declare(strict_types=1);

namespace App\Domain\Calculation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class PriceCalculator
{
    /**
     * @param list<array{type:string, quantity:string, costPrice:string, markupPercent:string}> $components
     * @return array<string, string>
     */
    public function calculatePosition(string $positionQuantity, array $components, ?string $manualUnitPrice = null): array
    {
        $quantity = Decimal::parse($positionQuantity, Decimal::QUANTITY_SCALE, 'Positionsmenge');
        if ($quantity->isNegative()) {
            throw new InvalidArgumentException('Positionsmengen duerfen nicht negativ sein.');
        }

        $calculatedUnitPrice = BigDecimal::zero();
        $costs = [
            'material' => BigDecimal::zero(),
            'labor' => BigDecimal::zero(),
            'external' => BigDecimal::zero(),
            'other' => BigDecimal::zero(),
        ];

        foreach ($components as $index => $component) {
            $prefix = 'Baustein ' . ($index + 1);
            if (!array_key_exists($component['type'], $costs)) {
                throw new InvalidArgumentException($prefix . ': ungueltiger Kalkulationstyp.');
            }

            $componentQuantity = Decimal::parse($component['quantity'], Decimal::QUANTITY_SCALE, $prefix . ' Menge');
            $costPrice = Decimal::parse($component['costPrice'], Decimal::COST_SCALE, $prefix . ' Kostenpreis');
            $markup = Decimal::parse($component['markupPercent'], Decimal::PERCENT_SCALE, $prefix . ' Zuschlag');
            if ($componentQuantity->isNegative() || $costPrice->isNegative()) {
                throw new InvalidArgumentException($prefix . ': Menge und Kostenpreis duerfen nicht negativ sein.');
            }

            $costPerUnit = $componentQuantity->multipliedBy($costPrice);
            $salesPerUnit = $costPerUnit->multipliedBy(
                BigDecimal::one()->plus($markup->dividedBy('100', 12, RoundingMode::Unnecessary))
            );
            $calculatedUnitPrice = $calculatedUnitPrice->plus($salesPerUnit);
            $costs[$component['type']] = $costs[$component['type']]->plus($costPerUnit->multipliedBy($quantity));
        }

        // Erst der Einheitspreis wird kaufmaennisch auf zwei Stellen gerundet.
        $roundedCalculatedUnitPrice = $calculatedUnitPrice->toScale(Decimal::MONEY_SCALE, RoundingMode::HalfUp);
        $effectiveUnitPrice = $manualUnitPrice === null || trim($manualUnitPrice) === ''
            ? $roundedCalculatedUnitPrice
            : Decimal::parse($manualUnitPrice, Decimal::MONEY_SCALE, 'Manueller Verkaufseinheitspreis');
        $positionTotal = $effectiveUnitPrice->multipliedBy($quantity)->toScale(Decimal::MONEY_SCALE, RoundingMode::HalfUp);
        $totalCosts = array_reduce($costs, static fn (BigDecimal $sum, BigDecimal $cost): BigDecimal => $sum->plus($cost), BigDecimal::zero());

        return [
            'calculatedUnitPrice' => Decimal::money($roundedCalculatedUnitPrice),
            'effectiveUnitPrice' => Decimal::money($effectiveUnitPrice),
            'positionTotal' => Decimal::money($positionTotal),
            'materialCosts' => Decimal::money($costs['material']),
            'laborCosts' => Decimal::money($costs['labor']),
            'externalCosts' => Decimal::money($costs['external']),
            'otherCosts' => Decimal::money($costs['other']),
            'totalCosts' => Decimal::money($totalCosts),
            'contributionMargin' => Decimal::money($positionTotal->minus($totalCosts)),
            'priceDeviation' => Decimal::money($effectiveUnitPrice->minus($roundedCalculatedUnitPrice)),
        ];
    }
}
