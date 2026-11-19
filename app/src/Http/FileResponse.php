<?php

declare(strict_types=1);

namespace App\Http;

/** Einheitliche, sichere Auslieferung von Dateien (PDF, Nachweise) über geschützte Routen. */
final class FileResponse
{
    public static function make(string $contents, string $fileName, string $mimeType, bool $inline = false): Response
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName) ?: 'dokument';
        return new Response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($fileName),
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-store',
            // Ausgelieferte Dateien dürfen keine Skripte ausführen.
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ]);
    }

    public static function pdf(string $contents, string $fileName): Response
    {
        // PDFs werden heruntergeladen; eine Sandbox-CSP würde die Inline-Anzeige in manchen Browsern verhindern.
        return self::make($contents, $fileName, 'application/pdf', false);
    }
}
