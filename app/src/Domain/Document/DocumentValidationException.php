<?php

declare(strict_types=1);

namespace App\Domain\Document;

use RuntimeException;

/** Fachliche Fehler bei Anhängen und der Dokumentenübersicht (HTTP 422). */
final class DocumentValidationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
