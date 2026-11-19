<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PdfNumberFormatter
{
    public function quantity(string $value): string { return $this->format($value, 3, true); }
    public function money(string $value): string { return $this->format($value, 2, false).' €'; }
    public function percent(string $value): string { return $this->format($value, 2, true).' %'; }
    /** Einkaufspreise mit bis zu 4 Nachkommastellen, mindestens Cent (3,4567 € bzw. 22,10 €). */
    public function unitPrice(string $value): string
    {
        $text = $this->format($value, 4, true);
        $fraction = str_contains($text, ',') ? strlen(substr($text, strpos($text, ',') + 1)) : 0;
        return ($fraction >= 2 ? $text : $this->format($value, 2, false)).' €';
    }

    private function format(string $value, int $scale, bool $trim): string
    {
        $plain=(string)BigDecimal::of($value)->toScale($scale,RoundingMode::HalfUp);
        [$integer,$fraction]=array_pad(explode('.',$plain,2),2,'');
        $negative=str_starts_with($integer,'-');$digits=ltrim($integer,'-');
        $integer=($negative?'-':'').preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $digits);
        if($trim)$fraction=rtrim($fraction,'0');
        return $fraction===''?$integer:$integer.','.$fraction;
    }
}
