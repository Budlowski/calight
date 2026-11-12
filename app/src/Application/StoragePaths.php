<?php

declare(strict_types=1);

namespace App\Application;

/**
 * Prüft die konfigurierten Datenverzeichnisse (APP_*_PATH).
 *
 * Anwendungsdaten müssen außerhalb des Quellcodes und vor allem außerhalb des
 * öffentlichen Webverzeichnisses liegen, sonst könnte Apache Dateien direkt
 * ausliefern. Die Prüfung verändert nichts im Dateisystem.
 */
final readonly class StoragePaths
{
    public function __construct(private Config $config, private string $applicationRoot)
    {
    }

    /** @return array<string,string> Bezeichnung => Verzeichnis */
    public function directories(): array
    {
        return [
            'Datenbank (APP_DATABASE_PATH)' => dirname($this->config->databasePath),
            'Angebote (APP_OFFERS_PATH)' => $this->config->offersPath,
            'Dokumente und Anhänge (APP_DOCUMENTS_PATH)' => $this->config->documentsPath,
            'Vorlagen (APP_TEMPLATES_PATH)' => $this->config->templatesPath,
        ];
    }

    /**
     * Sicherheitsrelevante Fehler, die einen Betrieb ausschließen.
     *
     * @return list<string>
     */
    public function blockingProblems(): array
    {
        $problems = [];
        $public = $this->normalize($this->applicationRoot . '/public');
        foreach ($this->directories() as $label => $path) {
            if (!str_starts_with($path, '/')) {
                $problems[] = "{$label}: Der Pfad muss absolut sein ({$path}).";
                continue;
            }
            if ($this->isWithin($this->normalize($path), $public)) {
                $problems[] = "{$label}: Der Pfad liegt im öffentlichen Webverzeichnis ({$path}) und könnte direkt ausgeliefert werden.";
            }
        }
        return $problems;
    }

    /**
     * Hinweise für den Betrieb (Ausgabe von „php bin/console storage:check“).
     *
     * @return list<array{label:string,path:string,exists:bool,writable:bool,warnings:list<string>}>
     */
    public function report(): array
    {
        $application = $this->normalize($this->applicationRoot);
        $seen = [];
        $result = [];
        foreach ($this->directories() as $label => $path) {
            $normalized = $this->normalize($path);
            $warnings = [];
            if ($this->isWithin($normalized, $application)) {
                $warnings[] = 'liegt im Anwendungsverzeichnis; Daten gehören in ein eigenes persistentes Verzeichnis (z. B. /var/www/data).';
            }
            if (preg_match('#/docs(/|$)#', $normalized) === 1) {
                $warnings[] = 'liegt in einem docs-Verzeichnis; dieses ist für die Projektdokumentation vorgesehen.';
            }
            foreach ($seen as $otherLabel => $other) {
                if ($this->isWithin($normalized, $other) || $this->isWithin($other, $normalized)) {
                    $warnings[] = "überschneidet sich mit {$otherLabel}.";
                }
            }
            $seen[$label] = $normalized;
            $result[] = [
                'label' => $label,
                'path' => $path,
                'exists' => is_dir($path),
                'writable' => is_dir($path) && is_writable($path),
                'warnings' => $warnings,
            ];
        }
        return $result;
    }

    /** Löst Symlinks auf, soweit der Pfad existiert; sonst rein textuelle Normalisierung. */
    private function normalize(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return rtrim($real, '/') ?: '/';
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return '/' . implode('/', $parts);
    }

    private function isWithin(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path, rtrim($parent, '/') . '/');
    }
}
