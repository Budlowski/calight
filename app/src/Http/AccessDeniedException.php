<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class AccessDeniedException extends RuntimeException
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct('Keine Berechtigung');
    }
}
