<?php

declare(strict_types=1);

namespace App\Security;

final class ConfidentialDataFilter
{
    /**
     * Verkaufsseitige Werte (Preise, Summen, Aufmaßbeträge) für Benutzer ohne `commercial.read`
     * (Monteure). Beträge werden auf null gesetzt, Summentabellen geleert und die vollständige
     * Auftragsgrundlage entfernt; Leistungsbeschreibungen, Mengen und Einheiten bleiben erhalten.
     * Wird in Controllern vor der Ausgabe angewendet – PDF- und Fachdienste rechnen weiter mit
     * vollständigen Daten.
     */
    public const SALES_VALUE_KEYS = [
        'unitPrice', 'effectiveUnitPrice', 'lineTotal', 'netTotal', 'itemsTotal', 'adjustableTotal', 'adjustmentAmount', 'agreedTotal', 'totalValue',
        'completedNetTotal', 'contractNetTotal', 'originalNetTotal', 'acceptedAddendaNetTotal', 'currentContractNetTotal', 'acceptedNetTotal',
        'adjustment', 'basis', 'billingTotal',
    ];

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function salesValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::SALES_VALUE_KEYS, true)) {
                $data[$key] = null;
            } elseif ($key === 'totals' && is_array($value) && array_is_list($value)) {
                $data[$key] = [];
            } elseif (is_array($value)) {
                $data[$key] = self::salesValues($value);
            }
        }
        if (array_key_exists('canComplete', $data) || array_key_exists('measurementNumber', $data)) {
            $data['commercial'] = false;
        }
        return $data;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    public static function offer(array $document):array
    {
        $confidential=['costPrice','masterDataSnapshot','materialCosts','laborCosts','externalCosts','otherCosts','totalCosts','contributionMargin','contributionMarginRate','laborHours','analysis','componentCostPrices'];
        $walk=function(array $value)use(&$walk,$confidential):array{
            foreach($value as $key=>$item){if(is_string($key)&&in_array($key,$confidential,true)){unset($value[$key]);continue;}if(is_array($item))$value[$key]=$walk($item);}
            return$value;
        };
        return$walk($document);
    }
}
