<?php

declare(strict_types=1);

namespace App\Domain\Offer;

use App\Domain\Calculation\Decimal;
use App\Support\Uuid;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/** Canonicalizes the one and only internal/import/export offer document. */
final class OfferNormalizer
{
    /** @param array<string,mixed> $offer @return array<string,mixed> */
    public function normalize(array $offer, bool $fillMissingIds = false): array
    {
        unset($offer['calculation']);
        foreach (['createdAt', 'updatedAt'] as $field) {
            if (is_string($offer[$field] ?? null) && trim($offer[$field]) !== '') {
                try {
                    $offer[$field] = (new DateTimeImmutable($offer[$field]))
                        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                } catch (Exception) {
                    throw new OfferValidationException([$field . ' muss ein gültiger ISO-8601-Zeitstempel sein.']);
                }
            }
        }
        if(isset($offer['offerAdjustment'])&&is_array($offer['offerAdjustment'])){
            $mode=(string)($offer['offerAdjustment']['mode']??'percentage');
            if(array_key_exists('value',$offer['offerAdjustment'])&&$offer['offerAdjustment']['value']!=='')$offer['offerAdjustment']['value']=$this->decimal($offer['offerAdjustment']['value'],$mode==='absolute'?Decimal::MONEY_SCALE:Decimal::PERCENT_SCALE,'Angebotsanpassung');
        }
        if (isset($offer['nodes']) && is_array($offer['nodes'])) $offer['nodes'] = $this->nodes($offer['nodes'], $fillMissingIds);
        return $offer;
    }

    /** @param list<mixed> $nodes @return list<mixed> */
    private function nodes(array $nodes, bool $fillMissingIds): array
    {
        foreach ($nodes as &$node) {
            if (!is_array($node)) continue;
            if ($fillMissingIds && (!isset($node['id']) || $node['id'] === '')) $node['id'] = Uuid::v4();
            unset($node['calculation']);
            if (($node['type'] ?? null) === 'title') {
                $node['titleType'] ??= 'normal';
                $node['children'] = $this->nodes(is_array($node['children'] ?? null) ? $node['children'] : [], $fillMissingIds);
                continue;
            }
            if (($node['type'] ?? null) === 'text') continue;
            if (array_key_exists('trailingText', $node) && $node['trailingText'] === null) {
                unset($node['trailingText']);
            }
            if (isset($node['quantity'])) $node['quantity'] = $this->decimal($node['quantity'], Decimal::QUANTITY_SCALE, 'Menge');
            if (array_key_exists('manualUnitPrice', $node) && $node['manualUnitPrice'] !== null && $node['manualUnitPrice'] !== '') {
                $node['manualUnitPrice'] = $this->decimal($node['manualUnitPrice'], Decimal::MONEY_SCALE, 'Manueller Verkaufspreis');
            }
            $components = is_array($node['components'] ?? null) ? $node['components'] : [];
            foreach ($components as &$component) {
                if (!is_array($component)) continue;
                if ($fillMissingIds && (!isset($component['id']) || $component['id'] === '')) $component['id'] = Uuid::v4();
                if (!array_key_exists('sourceArticleNumber', $component)) {
                    $snapshot = is_array($component['masterDataSnapshot'] ?? null) ? $component['masterDataSnapshot'] : [];
                    $component['sourceArticleNumber'] = $snapshot['articleNumber'] ?? null;
                }
                if (!array_key_exists('priceMode', $component)) {
                    $component['priceMode'] = ($component['masterDataId'] ?? null) === null
                        ? 'manual'
                        : (($component['priceLocked'] ?? false) ? 'locked' : 'master');
                }
                // Der frühere Modus "locked" entspricht fachlich dem eigenen Preis.
                // Die Artikelreferenz und der gespeicherte Kostenpreis bleiben erhalten.
                if (($component['priceMode'] ?? null) === 'locked') $component['priceMode'] = 'manual';
                // Legacy-Hilfs- und Ergebnisfelder sind keine persistenten Eingaben.
                unset($component['salesPrice'], $component['priceLocked'], $component['masterDataSnapshot']);
                if (isset($component['quantity'])) $component['quantity'] = $this->decimal($component['quantity'], Decimal::QUANTITY_SCALE, 'Bausteinmenge');
                if (isset($component['costPrice'])) $component['costPrice'] = $this->decimal($component['costPrice'], Decimal::COST_SCALE, 'Kostenpreis');
                if (isset($component['markupPercent'])) $component['markupPercent'] = $this->decimal($component['markupPercent'], Decimal::PERCENT_SCALE, 'Zuschlag');
            }
            unset($component);
            if (array_key_exists('components', $node)) $node['components'] = $components;
        }
        unset($node);
        return $nodes;
    }

    private function decimal(mixed $value, int $scale, string $field): string
    {
        try {
            return (string) Decimal::parse((string) $value, $scale, $field)
                ->toScale($scale, RoundingMode::Unnecessary);
        } catch (InvalidArgumentException $exception) {
            throw new OfferValidationException([$exception->getMessage()]);
        }
    }
}
