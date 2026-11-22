<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

/** Vorschau oder Sicherung passen nicht mehr zum Datenstand (HTTP 409). */
final class SystemPurgeConflictException extends RuntimeException
{
}
