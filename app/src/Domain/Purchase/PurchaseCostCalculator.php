<?php

declare(strict_types=1);

namespace App\Domain\Purchase;

use App\Domain\Calculation\Decimal;
use Brick\Math\BigDecimal;

/**
 * Kostenwirkung der Lieferantenbelege einer Bestellung (einzige Berechnungsstelle).
 *
 * Alle Belegbeträge werden positiv erfasst. Regeln:
 * - Rechnung, Teilrechnung, Schlussrechnung „zusätzlicher Betrag“: erhöhen die Kosten.
 * - Gutschrift: verringert die Kosten.
 * - Stornobeleg: verringert den wirksamen Betrag seines Originalbelegs (ganz oder teilweise);
 *   er wirkt nur über diesen Beleg und wird nicht zusätzlich gezählt.
 * - Schlussrechnung „Gesamtrechnung“: weist den Gesamtwert inklusive aller Teilrechnungen aus.
 *   Ihre Kostenwirkung ist daher Gesamtbetrag minus wirksame Teilrechnungen – solange sie nicht
 *   vollständig storniert ist. Dadurch werden Teilrechnungen nie doppelt gezählt.
 *
 * Bruttowerte werden nach denselben Regeln berechnet, aber nur, wenn alle beteiligten Belege
 * einen Bruttobetrag haben; sonst ist die Bruttosumme unbekannt (null).
 */
final class PurchaseCostCalculator
{
    public const KINDS = ['invoice', 'partial', 'final', 'credit', 'reversal'];

    /**
     * @param list<array{id:string,kind:string,final_mode:?string,related_invoice_id:?string,net_amount:string,gross_amount:?string}> $documents
     * @return array{
     *     total:?string,
     *     grossTotal:?string,
     *     effects:array<string,string>,
     *     remaining:array<string,string>,
     *     activeTotalFinalId:?string
     * }
     *   total: Ist-Kosten netto aus Belegen (null ohne Belege); effects: Kostenwirkung je Beleg
     *   (Stornobelege informativ als Gegenwert); remaining: noch nicht stornierter Betrag je Beleg.
     */
    public static function calculate(array $documents): array
    {
        if ($documents === []) {
            return ['total' => null, 'grossTotal' => null, 'effects' => [], 'remaining' => [], 'activeTotalFinalId' => null];
        }
        $net = self::run($documents, 'net_amount');
        $gross = self::grossKnown($documents) ? self::run($documents, 'gross_amount') : null;
        return [
            'total' => Decimal::money($net['total']),
            'grossTotal' => $gross !== null ? Decimal::money($gross['total']) : null,
            'effects' => array_map(static fn (BigDecimal $value): string => Decimal::money($value), $net['effects']),
            'remaining' => array_map(static fn (BigDecimal $value): string => Decimal::money($value), $net['remaining']),
            'activeTotalFinalId' => $net['activeTotalFinalId'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $documents
     * @return array{total:BigDecimal,effects:array<string,BigDecimal>,remaining:array<string,BigDecimal>,activeTotalFinalId:?string}
     */
    private static function run(array $documents, string $field): array
    {
        $reversed = [];
        foreach ($documents as $document) {
            if ($document['kind'] === 'reversal' && $document['related_invoice_id'] !== null) {
                $target = (string) $document['related_invoice_id'];
                $reversed[$target] = ($reversed[$target] ?? BigDecimal::zero())->plus((string) $document[$field]);
            }
        }
        $remaining = [];
        $partials = BigDecimal::zero();
        $activeTotalFinalId = null;
        foreach ($documents as $document) {
            if ($document['kind'] === 'reversal') {
                continue;
            }
            $id = (string) $document['id'];
            $remaining[$id] = BigDecimal::of((string) $document[$field])->minus($reversed[$id] ?? BigDecimal::zero());
            if ($document['kind'] === 'partial') {
                $partials = $partials->plus($remaining[$id]);
            }
            if ($document['kind'] === 'final' && $document['final_mode'] === 'total' && !$remaining[$id]->isZero()) {
                $activeTotalFinalId = $id;
            }
        }

        $effects = [];
        $total = BigDecimal::zero();
        $byId = [];
        foreach ($documents as $document) {
            $byId[(string) $document['id']] = $document;
        }
        foreach ($documents as $document) {
            $id = (string) $document['id'];
            if ($document['kind'] === 'reversal') {
                // Gegenwert zum Originalbeleg (informativ, bereits in dessen Wirkung enthalten).
                $target = $byId[(string) $document['related_invoice_id']] ?? null;
                $amount = BigDecimal::of((string) $document[$field]);
                $effects[$id] = $target !== null && $target['kind'] === 'credit' ? $amount : $amount->negated();
                continue;
            }
            $effect = match (true) {
                $document['kind'] === 'credit' => $remaining[$id]->negated(),
                $id === $activeTotalFinalId => $remaining[$id]->minus($partials),
                // Vollständig stornierte Gesamt-Schlussrechnung: wirkt nicht, Teilrechnungen zählen wieder.
                default => $remaining[$id],
            };
            $effects[$id] = $effect;
            $total = $total->plus($effect);
        }
        return ['total' => $total, 'effects' => $effects, 'remaining' => $remaining, 'activeTotalFinalId' => $activeTotalFinalId];
    }

    /** @param list<array<string,mixed>> $documents */
    private static function grossKnown(array $documents): bool
    {
        foreach ($documents as $document) {
            if ($document['gross_amount'] === null || $document['gross_amount'] === '') {
                return false;
            }
        }
        return true;
    }
}
