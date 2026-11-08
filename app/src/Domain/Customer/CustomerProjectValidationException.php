<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use RuntimeException;

final class CustomerProjectValidationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
