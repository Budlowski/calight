<?php

declare(strict_types=1);

namespace App\Application;

use PDO;
use Pdo\Sqlite;
use RuntimeException;

/**
 * Verpflichtende Sicherung vor einer Datenbereinigung.
 *
 * - Datenbank über SQLite „VACUUM INTO“ (konsistente Kopie einer laufenden Datenbank, auch im
 *   WAL-Modus), anschließend PRAGMA integrity_check und Vergleich des Inhaltsfingerabdrucks mit
 *   der Live-Datenbank.
 * - Dokumente/Anhänge/PDF-Archive (APP_DOCUMENTS_PATH) und Angebotsdateien (APP_OFFERS_PATH)
 *   werden vollständig kopiert und je Datei per SHA-256 geprüft.
 * - manifest.json mit den für eine Wiederherstellung nötigen Metadaten (Quellpfade, Migrationen,
 *   Prüfsummen); keine Zugangsdaten.
 *
 * Die Sicherung liegt außerhalb der zu bereinigenden Verzeichnisse. Eine automatische
 * Wiederherstellung gibt es nicht; sie erfolgt manuell (siehe docs/system-purge.md). Sicherungen
 * werden von Calight nie gelöscht.
 */
final readonly class SystemPurgeBackup
{
    /** Diese Tabellen ändern sich durch den Bereinigungsablauf selbst bzw. sind flüchtig. */
    private const VOLATILE_TABLES = ['system_purge_previews', 'system_purge_backups', 'system_purge_runs', 'system_purge_guard', 'document_locks'];

    public function __construct(
        private PDO $pdo,
        private string $databasePath,
        private string $documentsPath,
        private string $offersPath,
        private string $backupsPath,
    ) {
    }

    /**
     * Erstellt und prüft eine Sicherung. Bei jedem Fehler wird das unvollständige Verzeichnis
     * entfernt und eine Ausnahme geworfen.
     *
     * @param array<string,mixed> $metadata
     * @return array{directory:string,databaseSha256:string,contentSha256:string,fileCount:int,totalBytes:int}
     */
    public function create(string $id, array $metadata): array
    {
        // Systemaudit F-05: Alles, was hier entsteht (Verzeichnisse, VACUUM-INTO-Datei, Kopien, Manifest,
        // temporäre Dateien), wird bereits beim Anlegen nur für den Webserver-Benutzer lesbar.
        $previousUmask = umask(0077);
        try {
            return $this->createPrivate($id, $metadata);
        } finally {
            umask($previousUmask);
        }
    }

    /** @param array<string,mixed> $metadata @return array{directory:string,databaseSha256:string,contentSha256:string,fileCount:int,totalBytes:int} */
    private function createPrivate(string $id, array $metadata): array
    {
        $root = $this->checkedBackupRoot();
        $sourceBytes = (is_file($this->databasePath) ? (int) filesize($this->databasePath) : 0) + $this->directorySize($this->documentsPath) + $this->directorySize($this->offersPath);
        $free = @disk_free_space($root);
        if ($free !== false && $free < $sourceBytes * 1.2 + 10 * 1024 * 1024) {
            throw new RuntimeException('Nicht genügend freier Speicherplatz für die Sicherung.');
        }
        $directory = $root . '/calight-purge-' . gmdate('Ymd-His') . '-' . substr($id, 0, 8);
        if (file_exists($directory) || !@mkdir($directory, 0700)) {
            throw new RuntimeException('Sicherungsverzeichnis konnte nicht angelegt werden.');
        }
        try {
            $before = $this->contentHash($this->pdo);
            $database = $directory . '/database.sqlite';
            $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($database));
            $after = $this->contentHash($this->pdo);
            if ($before !== $after) {
                throw new RuntimeException('Die Daten haben sich während der Sicherung geändert. Bitte erneut versuchen.');
            }
            $copy = Sqlite::connect('sqlite:' . $database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            if ((string) $copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('Die gesicherte Datenbank ist nicht konsistent.');
            }
            if ($this->contentHash($copy) !== $after) {
                throw new RuntimeException('Die gesicherte Datenbank stimmt nicht mit dem aktuellen Datenstand überein.');
            }
            $migrations = array_map('strval', $copy->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
            $copy = null;
            $files = array_merge($this->copyTree($this->documentsPath, $directory . '/documents', 'documents'), $this->copyTree($this->offersPath, $directory . '/offers', 'offers'));
            $databaseSha = (string) hash_file('sha256', $database);
            $total = (int) filesize($database) + array_sum(array_column($files, 'size'));
            $manifest = [
                'format' => 'calight-purge-backup-1',
                'id' => $id,
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'database' => ['file' => 'database.sqlite', 'sha256' => $databaseSha, 'contentSha256' => $after, 'sourcePath' => $this->databasePath, 'migrations' => $migrations],
                'directories' => ['documents' => $this->documentsPath, 'offers' => $this->offersPath],
                'files' => $files,
                'restore' => 'Calight anhalten; database.sqlite nach APP_DATABASE_PATH sowie documents/ und offers/ in die genannten Verzeichnisse zurückkopieren (vorher die WAL-/SHM-Dateien der alten Datenbank entfernen); siehe docs/system-purge.md.',
            ] + $metadata;
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($directory . '/manifest.json', $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('Sicherungsprotokoll konnte nicht geschrieben werden.');
            }
            $this->assertPrivate($directory);
            return ['directory' => $directory, 'databaseSha256' => $databaseSha, 'contentSha256' => $after, 'fileCount' => count($files), 'totalBytes' => $total + strlen($json)];
        } catch (\Throwable $exception) {
            $this->removeTree($directory);
            throw $exception instanceof RuntimeException ? $exception : new RuntimeException('Sicherung fehlgeschlagen.', 0, $exception);
        }
    }

    /** Prüft, ob eine erstellte Sicherung noch vollständig vorhanden ist. */
    public function stillPresent(string $directory, string $databaseSha256): bool
    {
        $database = $directory . '/database.sqlite';
        return is_file($directory . '/manifest.json') && is_file($database) && hash_file('sha256', $database) === $databaseSha256;
    }

    /**
     * Inhaltsfingerabdruck aller fachlichen Tabellen (ohne flüchtige und ablaufbezogene Tabellen).
     */
    public function contentHash(PDO $pdo): string
    {
        $hash = hash_init('sha256');
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (in_array($table, self::VOLATILE_TABLES, true)) {
                continue;
            }
            hash_update($hash, "\x1d" . $table);
            $rows = $pdo->query('SELECT * FROM ' . SystemPurgePlanner::quote((string) $table) . ' ORDER BY rowid');
            while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\x1e");
            }
        }
        return hash_final($hash);
    }

    /** Sicherungsziel: absolut, beschreibbar, nicht in bzw. über den bereinigten Verzeichnissen. */
    private function checkedBackupRoot(): string
    {
        $root = rtrim($this->backupsPath, '/');
        if ($root === '' || !str_starts_with($root, '/')) {
            throw new RuntimeException('Das Sicherungsverzeichnis (APP_BACKUPS_PATH) muss ein absoluter Pfad sein.');
        }
        // Lage prüfen, bevor etwas angelegt wird (auch über den nächsten vorhandenen Elternpfad).
        $assertOutside = function (string $path): void {
            foreach ([$this->documentsPath, $this->offersPath] as $source) {
                $sourceReal = realpath($source) ?: rtrim($source, '/');
                if (self::within($path, $sourceReal) || self::within($sourceReal, $path)) {
                    throw new RuntimeException('Das Sicherungsverzeichnis darf nicht in den bereinigten Verzeichnissen liegen und diese nicht enthalten.');
                }
            }
        };
        $existing = $root;
        while (!file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        $assertOutside((realpath($existing) ?: $existing) . substr($root, strlen($existing)));
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Das Sicherungsverzeichnis ' . $root . ' konnte nicht angelegt werden. Bitte das Verzeichnis anlegen und dem Webserver-Benutzer Schreibrechte geben (APP_BACKUPS_PATH).');
        }
        if (!is_writable($root)) {
            throw new RuntimeException('Das Sicherungsverzeichnis ' . $root . ' ist nicht beschreibbar. Bitte dem Webserver-Benutzer Schreibrechte geben (APP_BACKUPS_PATH).');
        }
        $real = realpath($root) ?: $root;
        $assertOutside($real);
        // Für andere Benutzer lesbares Sicherungsverzeichnis: auf 0700 setzen oder verständlich abbrechen.
        $mode = (int) (@fileperms($real) ?: 0) & 0777;
        if (($mode & 0077) !== 0 && !@chmod($real, 0700)) {
            throw new RuntimeException(sprintf('Das Sicherungsverzeichnis %s ist für andere Benutzer zugänglich (Rechte %04o) und kann nicht auf 0700 gesetzt werden. Bitte Eigentümer und Rechte prüfen (siehe docs/system-purge.md).', $real, $mode));
        }
        return $real;
    }

    /** Prüft, dass keine Datei und kein Verzeichnis der Sicherung für Gruppe oder andere zugänglich ist. */
    private function assertPrivate(string $directory): void
    {
        $paths = [$directory];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            /** @var \SplFileInfo $item */
            $paths[] = $item->getPathname();
        }
        foreach ($paths as $path) {
            $mode = (int) (@fileperms($path) ?: 0) & 0777;
            if (($mode & 0077) !== 0 && !@chmod($path, is_dir($path) ? 0700 : 0600)) {
                throw new RuntimeException(sprintf('Rechte der Sicherung konnten nicht eingeschränkt werden (%s, %04o).', basename($path), $mode));
            }
        }
    }

    private static function within(string $path, string $base): bool
    {
        return $path === $base || str_starts_with($path . '/', rtrim($base, '/') . '/');
    }

    /** @return list<array{path:string,size:int,sha256:string}> */
    private function copyTree(string $source, string $target, string $prefix): array
    {
        $source = rtrim($source, '/');
        if (!is_dir($source)) {
            return [];
        }
        if (!@mkdir($target, 0700, true) && !is_dir($target)) {
            throw new RuntimeException('Sicherungsverzeichnis für Dateien konnte nicht angelegt werden.');
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;
            if ($item->isLink()) {
                continue;
            }
            if ($item->isDir()) {
                if (!is_dir($destination) && !@mkdir($destination, 0700, true) && !is_dir($destination)) {
                    throw new RuntimeException('Sicherungsverzeichnis für Dateien konnte nicht angelegt werden.');
                }
                continue;
            }
            $hash = hash_file('sha256', $item->getPathname());
            if ($hash === false || !@copy($item->getPathname(), $destination) || hash_file('sha256', $destination) !== $hash) {
                throw new RuntimeException('Datei konnte nicht vollständig gesichert werden: ' . $prefix . '/' . $relative);
            }
            $files[] = ['path' => $prefix . '/' . $relative, 'size' => (int) filesize($destination), 'sha256' => $hash];
        }
        return $files;
    }

    private function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile() && !$item->isLink()) {
                $size += $item->getSize();
            }
        }
        return $size;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
