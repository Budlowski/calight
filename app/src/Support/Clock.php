<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

final class Clock
{
    public static function now(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
