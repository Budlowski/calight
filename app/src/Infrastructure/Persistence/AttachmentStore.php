<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use RuntimeException;

/**
 * Dateiablage für Anhänge unterhalb von APP_DOCUMENTS_PATH.
 *
 * Speicherpfade werden ausschließlich aus internen IDs gebildet; Originalnamen
 * aus dem Upload werden nur als Metadaten gespeichert, nie als Dateiname.
 */
final readonly class AttachmentStore
{
    public function __construct(private string $basePath)
    {
    }

    /** Übernimmt eine (hochgeladene) Datei und liefert den relativen Speicherpfad. */
    public function store(string $sourcePath, string $folder, string $id, string $extension): string
    {
        // Erlaubt: "<bereich>/<uuid>" bzw. "<bereich>/<uuid>/<uuid>", z. B. daily-reports/<bericht>/<anhang>.
        if (preg_match('/^[a-z0-9-]+(\/[a-f0-9-]{36})?\/[a-f0-9-]{36}$/D', $folder . '/' . $id) !== 1 || preg_match('/^[a-z0-9]{1,5}$/D', $extension) !== 1) {
            throw new RuntimeException('Ungültiger Speicherort für den Anhang.');
        }
        $relative = $folder . '/' . $id . '.' . $extension;
        $target = $this->absolute($relative);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Ablageverzeichnis für Anhänge konnte nicht angelegt werden.');
        }
        if (file_exists($target)) {
            throw new RuntimeException('Anhang existiert bereits.');
        }
        // rename() funktioniert auch über Dateisystemgrenzen (z. B. /tmp -> Volume).
        if (!@rename($sourcePath, $target)) {
            throw new RuntimeException('Anhang konnte nicht gespeichert werden.');
        }
        @chmod($target, 0640);
        return $relative;
    }

    public function absolute(string $relative): string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')
            || str_contains($relative, '\\') || str_contains($relative, "\0")
            || preg_match('#^[a-z0-9-]+(/[a-f0-9-]{36}){1,2}\.[a-z0-9]{1,5}$#D', $relative) !== 1) {
            throw new RuntimeException('Ungültiger Anhangspfad.');
        }
        $path = rtrim($this->basePath, '/') . '/' . $relative;
        // Auch über Symlinks darf kein Zugriff außerhalb der Ablage entstehen.
        $base = realpath($this->basePath);
        $real = realpath($path);
        if ($base !== false && $real !== false && !str_starts_with($real, rtrim($base, '/') . '/')) {
            throw new RuntimeException('Ungültiger Anhangspfad.');
        }
        return $path;
    }

    /** Entfernt eine Datei; fehlende Dateien gelten als bereits entfernt. */
    public function delete(string $relative): void
    {
        $path = $this->absolute($relative);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Anhang konnte nicht entfernt werden.');
        }
    }
}
