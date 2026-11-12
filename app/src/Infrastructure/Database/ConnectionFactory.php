<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use Pdo\Sqlite;
use RuntimeException;

final class ConnectionFactory
{
    public static function create(string $databasePath): Sqlite
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Datenbankverzeichnis konnte nicht angelegt werden.');
        }

        $pdo = Sqlite::connect('sqlite:' . $databasePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        // SQLite vergleicht LIKE nur für ASCII ohne Groß-/Kleinschreibung. Die Listensuche
        // faltet Werte deshalb Unicode-gerecht, damit „MÜLLER“ auch „Müller“ findet.
        $pdo->createFunction(
            'calight_fold',
            static fn (mixed $value): ?string => $value === null ? null : mb_strtolower((string) $value, 'UTF-8'),
            1,
            Sqlite::DETERMINISTIC,
        );

        return $pdo;
    }
}
