<?php

declare(strict_types=1);

namespace App\Domain\Offer;

use DomainException;

final class OfferValidationException extends DomainException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
