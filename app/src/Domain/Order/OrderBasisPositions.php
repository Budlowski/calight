<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Offer\OfferStructure;

/**
 * Liest die verbindlichen Leistungspositionen eines Auftrags aus den
 * unveränderlichen Grundlagen (ursprüngliche Auftragsgrundlage und angenommene
 * Nachträge), ohne neu zu kalkulieren.
 *
 * Übernommen werden nur verkaufsseitige Werte (Menge, Einheit, verbindlicher
 * Einzelpreis); interne Kosten und Kalkulationsbestandteile werden nie
 * weitergegeben. Ziele werden stabil über "<quelle>:<quell-id>:<knoten-id>"
 * referenziert, wie bei der Zeiterfassung der Tagesberichte.
 */
final readonly class OrderBasisPositions
{
    public function __construct(private OfferStructure $structure = new OfferStructure())
    {
    }

    /**
     * @param list<array{type:string,id:string,label:string,json:string}> $sources
     * @return list<array{type:string,id:string,label:string,adjustment:array{subtotal:string,signed:string,label:string},nodes:list<array<string,mixed>>,positions:array<string,array<string,mixed>>}>
     */
    public function read(array $sources): array
    {
        $result = [];
        foreach ($sources as $source) {
            $document = json_decode($source['json'], true);
            if (!is_array($document)) {
                continue;
            }
            $nodes = is_array($document['nodes'] ?? null) ? $document['nodes'] : [];
            $numbers = [];
            foreach ($this->structure->numbering($nodes) as $entry) {
                $numbers[$entry['id']] = $entry['number'];
            }
            $positions = [];
            $tree = $this->walk($nodes, $source, $numbers, 'normal', $positions);
            $calculation = is_array($document['calculation'] ?? null) ? $document['calculation'] : [];
            $adjustment = is_array($document['offerAdjustment'] ?? null) ? $document['offerAdjustment'] : [];
            $result[] = [
                'type' => $source['type'],
                'id' => $source['id'],
                'label' => $source['label'],
                // Der Angebotsnachlass/-aufschlag wirkt nur auf die Normalpositionen (subtotalNet).
                'adjustment' => [
                    'subtotal' => (string) ($calculation['subtotalNet'] ?? '0.00'),
                    'signed' => (string) ($calculation['adjustmentSigned'] ?? '0.00'),
                    'label' => (string) ($adjustment['label'] ?? ''),
                ],
                'nodes' => $tree,
                'positions' => $positions,
            ];
        }
        return $result;
    }

    /**
     * @param list<mixed> $nodes
     * @param array{type:string,id:string} $source
     * @param array<string,string> $numbers
     * @param array<string,array<string,mixed>> $positions gesammelte messbare Positionen je Schlüssel
     * @return list<array<string,mixed>>
     */
    private function walk(array $nodes, array $source, array $numbers, string $inheritedType, array &$positions): array
    {
        $tree = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['id'], $node['type'])) {
                continue;
            }
            $id = (string) $node['id'];
            if ($node['type'] === 'title') {
                $titleType = (string) ($node['titleType'] ?? 'normal');
                // Bedarfs- und Alternativtitel übertragen ihren Typ auf alle Unterelemente.
                $effective = $inheritedType !== 'normal' ? $inheritedType : (in_array($titleType, ['bedarf', 'alternative'], true) ? $titleType : 'normal');
                $children = $this->walk(is_array($node['children'] ?? null) ? $node['children'] : [], $source, $numbers, $effective, $positions);
                if ($children !== []) {
                    $tree[] = ['kind' => 'title', 'number' => $numbers[$id] ?? '', 'name' => (string) ($node['name'] ?? 'Titel'), 'effectiveType' => $effective, 'children' => $children];
                }
                continue;
            }
            if ($node['type'] !== 'position') {
                continue;
            }
            $unitPrice = $node['calculation']['effectiveUnitPrice'] ?? null;
            if ($unitPrice === null) {
                // Ohne verbindlichen Preis ist keine Bewertung möglich.
                continue;
            }
            $positionType = (string) ($node['positionType'] ?? 'normal');
            $effective = $inheritedType !== 'normal' ? $inheritedType : (in_array($positionType, ['bedarf', 'alternative'], true) ? $positionType : 'normal');
            $key = $source['type'] . ':' . $source['id'] . ':' . $id;
            $position = [
                'kind' => 'position',
                'key' => $key,
                'sourceType' => $source['type'],
                'sourceId' => $source['id'],
                'nodeId' => $id,
                'number' => $numbers[$id] ?? '',
                'shortText' => (string) ($node['shortText'] ?? ''),
                'unit' => (string) ($node['unit'] ?? ''),
                'agreedQuantity' => (string) ($node['quantity'] ?? '0'),
                'unitPrice' => (string) $unitPrice,
                'effectiveType' => $effective,
            ];
            $positions[$key] = $position;
            $tree[] = $position;
        }
        return $tree;
    }
}
