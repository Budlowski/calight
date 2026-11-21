<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\OperationalSettingsService;
use App\Application\TravelAllowance;
use App\Tests\TestCase;

/** Rechnerische Verpflegungspauschalen, Mahlzeitenkürzungen und Kilometerbeträge (Phase B). */
final class TravelAllowanceTest extends TestCase
{
    public function testDefaultCategoriesFollowTheConfiguredReferenceValues(): void
    {
        $settings = OperationalSettingsService::resolve([]);
        $none = ['breakfast' => false, 'lunch' => false, 'dinner' => false];
        $amount = static fn (string $category, array $meals = []): ?string => TravelAllowance::calculate($category, false, $meals + $none, $settings)['amount'];
        $this->assertSame(['0.00', '0.00', '14.00', '14.00', '28.00', '14.00'], array_map($amount, TravelAllowance::CATEGORIES));
        $this->assertSame('Auswärtstätigkeit bis einschließlich 8 Stunden', TravelAllowance::labels($settings)['single_short']);
        // Kürzungen bezogen auf die volle Tagespauschale (28 €): 20 % = 5,60 €, 40 % = 11,20 €.
        $this->assertSame('22.40', $amount('full', ['breakfast' => true]));
        $this->assertSame('0.00', $amount('full', ['breakfast' => true, 'lunch' => true, 'dinner' => true]), 'Alle Mahlzeiten: null, nie negativ.');
        $this->assertSame('8.40', $amount('arrival', ['breakfast' => true]));
        $this->assertSame('2.80', $amount('departure', ['lunch' => true]));
        $result = TravelAllowance::calculate('single_long', false, ['lunch' => true, 'dinner' => true] + $none, $settings);
        $this->assertSame(['14.00', '14.00', '0.00'], [$result['base'], $result['reduction'], $result['amount']], 'Kürzung höchstens bis null.');
        $this->assertSame('0.00', $amount('single_short', ['breakfast' => true]), 'Ohne Pauschale keine Kürzung.');
        // Ausland: dokumentiert, aber keine Inlandspauschale; deaktiviert: keine Beträge.
        $this->assertSame(null, TravelAllowance::calculate('full', true, $none, $settings)['amount']);
        $this->assertSame('0.00', TravelAllowance::calculate('none', true, $none, $settings)['amount']);
        $this->assertSame(null, TravelAllowance::calculate('full', false, $none, OperationalSettingsService::resolve(['travel.meal_allowances_enabled' => '0']))['amount']);
        $this->assertSame('54.75', TravelAllowance::kilometers('182.5', $settings));
    }

    public function testConfiguredAmountsPercentagesAndRatesWithCentArithmetic(): void
    {
        $settings = OperationalSettingsService::resolve(['travel.allowance_full' => '30', 'travel.allowance_single' => '15', 'travel.reduction_breakfast_percent' => '25',
            'travel.km_rate' => '0.38', 'travel.single_day_threshold_hours' => '7.5']);
        $none = ['breakfast' => false, 'lunch' => false, 'dinner' => false];
        $this->assertSame('22.50', TravelAllowance::calculate('full', false, ['breakfast' => true] + $none, $settings)['amount']);
        $this->assertSame('7.50', TravelAllowance::calculate('single_long', false, ['breakfast' => true] + $none, $settings)['amount']);
        $this->assertSame('3.99', TravelAllowance::kilometers('10.5', $settings));
        $this->assertSame('Auswärtstätigkeit über 7,5 Stunden', TravelAllowance::labels($settings)['single_long']);
        // Kaufmännische Rundung auf Cent: 20 % von 28,33 € = 5,666 → 5,67 €.
        $odd = OperationalSettingsService::resolve(['travel.allowance_full' => '28.33']);
        $this->assertSame('22.66', TravelAllowance::calculate('full', false, ['breakfast' => true] + $none, $odd)['amount']);
        $this->assertSame('14.00', TravelAllowance::calculate('arrival', false, $none, OperationalSettingsService::resolve(['travel.allowance_arrival' => 'abc']))['amount'], 'Ungültiger Rohwert: Standardwert.');
    }
}
