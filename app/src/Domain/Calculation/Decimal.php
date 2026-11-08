<?php

declare(strict_types=1);

namespace App\Domain\Calculation;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class Decimal
{
    public const COST_SCALE = 4;
    public const QUANTITY_SCALE = 3;
    public const TIME_SCALE = 3;
    public const PERCENT_SCALE = 2;
    public const MONEY_SCALE = 2;

    public static function parse(string $value, int $maximumScale, string $field): BigDecimal
    {
        $value = trim($value);
        if (!preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value)) {
            throw new InvalidArgumentException(sprintf('%s muss eine Dezimalzahl mit Punkt als Trennzeichen sein.', $field));
        }

        try {
            $decimal = BigDecimal::of($value);
        } catch (MathException $exception) {
            throw new InvalidArgumentException(sprintf('%s ist keine gueltige Dezimalzahl.', $field), 0, $exception);
        }

        if ($decimal->getScale() > $maximumScale) {
            throw new InvalidArgumentException(sprintf('%s darf maximal %d Nachkommastellen haben.', $field, $maximumScale));
        }

        return $decimal;
    }

    public static function money(BigDecimal $value): string
    {
        return (string) $value->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }
}
