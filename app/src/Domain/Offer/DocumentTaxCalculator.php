<?php

declare(strict_types=1);

namespace App\Domain\Offer;

use App\Domain\Calculation\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class DocumentTaxCalculator
{
    /** @return array{mode:string,rate:string,net:string,tax:string,gross:string,note:string} */
    public function calculate(string $net, string $mode, string $rate, string $note = ''): array
    {
        $netValue = Decimal::parse($net, Decimal::MONEY_SCALE, 'Nettosumme');
        if (!in_array($mode, ['standard', 'exempt'], true)) throw new InvalidArgumentException('Unbekannte steuerliche Behandlung.');
        $rateValue = $mode === 'exempt' ? BigDecimal::zero() : Decimal::parse($rate, Decimal::PERCENT_SCALE, 'Umsatzsteuersatz');
        $tax = $netValue->multipliedBy($rateValue)->dividedBy('100', Decimal::MONEY_SCALE, RoundingMode::HalfUp);
        return ['mode'=>$mode,'rate'=>(string)$rateValue->toScale(Decimal::PERCENT_SCALE, RoundingMode::Unnecessary),
            'net'=>Decimal::money($netValue),'tax'=>Decimal::money($tax),'gross'=>Decimal::money($netValue->plus($tax)),'note'=>trim($note)];
    }
}
