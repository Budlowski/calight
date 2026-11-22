<?php

declare(strict_types=1);

namespace App\Domain\Order;

use RuntimeException;

/** Statuswechsel mit offenen Vorgängen braucht eine ausdrückliche Bestätigung (HTTP 409). */
final class OrderOpenItemsException extends RuntimeException
{
    /** @param list<array{key:string,label:string,count:int}> $items */
    public function __construct(public readonly array $items)
    {
        parent::__construct('Der Auftrag hat offene Vorgänge. Bitte den Statuswechsel ausdrücklich bestätigen.');
    }
}
