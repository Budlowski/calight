<?php

declare(strict_types=1);

namespace App\Domain\MasterData;

use DomainException;

final class MasterDataValidationException extends DomainException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
