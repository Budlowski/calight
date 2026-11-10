<?php

declare(strict_types=1);

namespace App\Security;

final class ConfidentialDataFilter
{
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
