<?php

declare(strict_types=1);

namespace App\Tests;

use RuntimeException;
use Throwable;

abstract class TestCase
{
    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message ?: sprintf(
                'Erwartet %s, erhalten %s.',
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'Bedingung ist nicht erfuellt.'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Bisherige Auftragssichtbarkeit für Monteure (alle freigegebenen Aufträge) – für Tests, die die
     * Freigaberegel ohne Mitarbeiterzuordnung prüfen. Die Standardregel „nur zugewiesene Aufträge“
     * prüft OrderVisibilityTest.
     */
    protected static function useReleasedOrderVisibility(\PDO $pdo): void
    {
        $pdo->exec("INSERT OR REPLACE INTO operational_settings (setting_key, setting_value, updated_by_user_id, updated_at)
            VALUES ('orders.technician_visibility', 'released', NULL, '2026-01-01T00:00:00Z')");
    }

    /** @param class-string<Throwable> $class */
    protected function assertThrows(string $class, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            if ($exception instanceof $class) {
                return;
            }
            throw new RuntimeException('Falsche Exception: ' . $exception::class, 0, $exception);
        }
        throw new RuntimeException('Erwartete Exception wurde nicht ausgeloest: ' . $class);
    }
}
