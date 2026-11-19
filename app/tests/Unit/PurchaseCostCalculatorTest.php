<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Purchase\PurchaseCostCalculator;
use App\Tests\TestCase;

/** Phase 3.4.1.1: Kostenwirkung von Rechnungen, Teil-/Schlussrechnungen, Gutschriften und Stornos. */
final class PurchaseCostCalculatorTest extends TestCase
{
    public function testWithoutDocumentsThereAreNoCosts(): void
    {
        $this->assertSame(null, PurchaseCostCalculator::calculate([])['total']);
    }

    public function testInvoicesAddUpAndCreditsReduce(): void
    {
        $result = PurchaseCostCalculator::calculate([
            self::doc('a', 'invoice', '200.00', '238.00'),
            self::doc('b', 'invoice', '150.55', '179.15'),
            self::doc('c', 'credit', '50.55', '60.15'),
        ]);
        $this->assertSame('300.00', $result['total']);
        $this->assertSame('357.00', $result['grossTotal']);
        $this->assertSame('-50.55', $result['effects']['c']);
    }

    public function testFinalInvoiceAsAdditionalAmountIsAdded(): void
    {
        $result = PurchaseCostCalculator::calculate([
            self::doc('p1', 'partial', '300.00'),
            self::doc('p2', 'partial', '200.00'),
            self::doc('f', 'final', '120.00', null, 'additional'),
        ]);
        $this->assertSame('620.00', $result['total']);
        $this->assertSame(null, $result['grossTotal'], 'Ohne Bruttobeträge keine Bruttosumme.');
    }

    public function testFinalInvoiceAsTotalDeductsPartialInvoices(): void
    {
        $result = PurchaseCostCalculator::calculate([
            self::doc('p1', 'partial', '300.00'),
            self::doc('p2', 'partial', '200.00'),
            self::doc('f', 'final', '620.00', null, 'total'),
        ]);
        $this->assertSame('620.00', $result['total'], 'Teilrechnungen werden nicht doppelt gezählt.');
        $this->assertSame('120.00', $result['effects']['f']);
        $this->assertSame('f', $result['activeTotalFinalId']);
    }

    public function testReversalNeutralisesTheOriginalOnly(): void
    {
        $partial = PurchaseCostCalculator::calculate([
            self::doc('r1', 'invoice', '400.00'),
            self::doc('s1', 'reversal', '150.00', null, null, 'r1'),
        ]);
        $this->assertSame('250.00', $partial['total'], 'Teilstorno mindert die Rechnung.');
        $this->assertSame('250.00', $partial['remaining']['r1']);
        $this->assertSame('-150.00', $partial['effects']['s1'], 'Gegenwert informativ.');

        $full = PurchaseCostCalculator::calculate([
            self::doc('r1', 'invoice', '400.00'),
            self::doc('s1', 'reversal', '400.00', null, null, 'r1'),
            self::doc('r2', 'invoice', '390.00'),
        ]);
        $this->assertSame('390.00', $full['total'], 'Stornierte Rechnung zählt nicht, Ersatzrechnung schon.');
    }

    public function testFullyReversedTotalFinalLetsPartialsCountAgain(): void
    {
        $result = PurchaseCostCalculator::calculate([
            self::doc('p1', 'partial', '300.00'),
            self::doc('f', 'final', '500.00', null, 'total'),
            self::doc('s', 'reversal', '500.00', null, null, 'f'),
        ]);
        $this->assertSame('300.00', $result['total']);
        $this->assertSame(null, $result['activeTotalFinalId']);
    }

    public function testReversedCreditRestoresCosts(): void
    {
        $result = PurchaseCostCalculator::calculate([
            self::doc('r', 'invoice', '100.00'),
            self::doc('g', 'credit', '30.00'),
            self::doc('s', 'reversal', '30.00', null, null, 'g'),
        ]);
        $this->assertSame('100.00', $result['total']);
        $this->assertSame('30.00', $result['effects']['s']);
    }

    public function testAcceptanceCasesAToC(): void
    {
        // A: Teilrechnung 200 € + Gesamt-Schlussrechnung 500 € = 500 €, nicht 700 €.
        $this->assertSame('500.00', PurchaseCostCalculator::calculate([self::doc('t', 'partial', '200.00'), self::doc('s', 'final', '500.00', null, 'total')])['total']);
        // B: Rechnung 500 € − Gutschrift 100 € = 400 €.
        $this->assertSame('400.00', PurchaseCostCalculator::calculate([self::doc('r', 'invoice', '500.00'), self::doc('g', 'credit', '100.00')])['total']);
        // C: Rechnung 500 € mit vollständigem Stornobeleg = 0 €.
        $this->assertSame('0.00', PurchaseCostCalculator::calculate([self::doc('r', 'invoice', '500.00'), self::doc('s', 'reversal', '500.00', null, null, 'r')])['total']);
    }

    public function testReversedPartialUnderTotalFinalDoesNotChangeTheTotal(): void
    {
        // Gesamt-Schlussrechnung deckt alles ab: ein Teilstorno einer Teilrechnung ändert die Summe nicht.
        $result = PurchaseCostCalculator::calculate([
            self::doc('t', 'partial', '200.00'),
            self::doc('s', 'final', '500.00', null, 'total'),
            self::doc('x', 'reversal', '50.00', null, null, 't'),
        ]);
        $this->assertSame('500.00', $result['total']);
        $this->assertSame('350.00', $result['effects']['s']);
    }

    /** @return array{id:string,kind:string,final_mode:?string,related_invoice_id:?string,net_amount:string,gross_amount:?string} */
    private static function doc(string $id, string $kind, string $net, ?string $gross = null, ?string $mode = null, ?string $related = null): array
    {
        return ['id' => $id, 'kind' => $kind, 'final_mode' => $mode, 'related_invoice_id' => $related, 'net_amount' => $net, 'gross_amount' => $gross];
    }
}
