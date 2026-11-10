<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

final class DocumentLockException extends RuntimeException
{
    /** @param array<string,mixed> $lock */
    public function __construct(string $message,public readonly array $lock=[])
    {
        parent::__construct($message);
    }
}
