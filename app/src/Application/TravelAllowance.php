<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Rechnerische Verpflegungspauschale eines Tages (Inland) nach den Betriebseinstellungen.
 *
 * - Kategorie statt Arbeitszeit: Die Pauschale wird nie aus der Nettoarbeitszeit abgeleitet.
 * - An- und Abreisetag benötigen keine Mindestabwesenheit; keine Stufen für 10 oder 14 Stunden.
 * - Gestellte Mahlzeiten kürzen um Prozentsätze der Pauschale für einen vollen Tag; das
 *   Ergebnis wird nie negativ. Dezimalarithmetik (Cent, kaufmännisch gerundet).
 * - Auslandseinsätze werden dokumentiert, aber nicht mit Inlandspauschalen berechnet.
 * Ergebnis ist ein Referenzbetrag – keine steuerliche Freigabe oder Erstattungszusage.
 */
final class TravelAllowance
{
    public const CATEGORIES = ['none', 'single_short', 'single_long', 'arrival', 'full', 'departure'];

    /** @param array<string,bool|string> $settings @return array<string,string> */
    public static function labels(array $settings): array
    {
        $threshold = str_replace('.', ',', (string) $settings['travel.single_day_threshold_hours']);
        return [
            'none' => 'Keine Auswärtstätigkeit',
            'single_short' => 'Auswärtstätigkeit bis einschließlich ' . $threshold . ' Stunden',
            'single_long' => 'Auswärtstätigkeit über ' . $threshold . ' Stunden',
            'arrival' => 'Anreisetag mit auswärtiger Übernachtung',
            'full' => 'Voller Tag mit 24 Stunden Abwesenheit',
            'departure' => 'Abreisetag nach auswärtiger Übernachtung',
        ];
    }

    /**
     * @param array{breakfast:bool,lunch:bool,dinner:bool} $meals
     * @param array<string,bool|string> $settings
     * @return array{base:?string,reduction:?string,amount:?string,note:string}
     */
    public static function calculate(string $category, bool $abroad, array $meals, array $settings): array
    {
        if (!(bool) $settings['travel.meal_allowances_enabled']) {
            return ['base' => null, 'reduction' => null, 'amount' => null, 'note' => 'Verpflegungspauschalen sind nicht aktiviert.'];
        }
        if ($category === 'none') {
            return ['base' => '0.00', 'reduction' => '0.00', 'amount' => '0.00', 'note' => ''];
        }
        if ($abroad) {
            return ['base' => null, 'reduction' => null, 'amount' => null, 'note' => 'Auslandseinsatz: keine automatische Pauschale, Prüfung durch das Büro.'];
        }
        $base = BigDecimal::of(match ($category) {
            'single_long' => (string) $settings['travel.allowance_single'],
            'arrival' => (string) $settings['travel.allowance_arrival'],
            'full' => (string) $settings['travel.allowance_full'],
            'departure' => (string) $settings['travel.allowance_departure'],
            default => '0',
        });
        $full = BigDecimal::of((string) $settings['travel.allowance_full']);
        $percent = BigDecimal::zero();
        foreach (['breakfast', 'lunch', 'dinner'] as $meal) {
            if ($meals[$meal] ?? false) {
                $percent = $percent->plus((string) $settings['travel.reduction_' . $meal . '_percent']);
            }
        }
        $reduction = $base->isZero() ? BigDecimal::zero() : $full->multipliedBy($percent)->dividedBy(100, Decimal::MONEY_SCALE, RoundingMode::HalfUp);
        $amount = $base->minus($reduction);
        if ($amount->isNegative()) {
            $amount = BigDecimal::zero();
        }
        return [
            'base' => (string) $base->toScale(Decimal::MONEY_SCALE),
            'reduction' => (string) $base->minus($amount)->toScale(Decimal::MONEY_SCALE),
            'amount' => (string) $amount->toScale(Decimal::MONEY_SCALE),
            'note' => '',
        ];
    }

    /** Kilometerbetrag (rechnerisch). @param array<string,bool|string> $settings */
    public static function kilometers(string $kilometers, array $settings): string
    {
        return (string) BigDecimal::of($kilometers)->multipliedBy((string) $settings['travel.km_rate'])->toScale(Decimal::MONEY_SCALE, RoundingMode::HalfUp);
    }
}
