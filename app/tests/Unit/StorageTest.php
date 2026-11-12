<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Config;
use App\Application\StoragePaths;
use App\Infrastructure\Persistence\AttachmentStore;
use App\Support\Uuid;
use App\Tests\TestCase;
use RuntimeException;

/** Sichere Datenablage: Pfadauflösung, Speichern/Löschen und Prüfung der Konfiguration. */
final class StorageTest extends TestCase
{
    public function testAttachmentPathsAreRestrictedToGeneratedNames(): void
    {
        $store = new AttachmentStore('/var/www/data/documents');
        $report = Uuid::v4();
        $attachment = Uuid::v4();
        $this->assertSame("/var/www/data/documents/daily-reports/{$report}/{$attachment}.pdf", $store->absolute("daily-reports/{$report}/{$attachment}.pdf"));

        foreach ([
            '', '../database/app.sqlite', "daily-reports/{$report}/../../x.pdf", '/etc/passwd', 'daily-reports\\x.pdf',
            "daily-reports/{$report}/{$attachment}.pdf\0.php", "daily-reports/{$report}/anhang.pdf", "daily-reports/{$report}/{$attachment}.phtml5",
            "Daily-Reports/{$report}/{$attachment}.pdf",
        ] as $invalid) {
            $this->assertThrows(RuntimeException::class, fn () => $store->absolute($invalid));
        }
        $this->assertThrows(RuntimeException::class, fn () => $store->store('/tmp/x', 'daily-reports/../x', $attachment, 'pdf'));
        $this->assertThrows(RuntimeException::class, fn () => $store->store('/tmp/x', "daily-reports/{$report}", 'datei', 'pdf'));
        $this->assertThrows(RuntimeException::class, fn () => $store->store('/tmp/x', "daily-reports/{$report}", $attachment, 'p/df'));
    }

    public function testStoreAndDeleteStayInsideTheConfiguredDirectory(): void
    {
        $tmp = sys_get_temp_dir() . '/calight-storage-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/documents', 0770, true);
        try {
            $store = new AttachmentStore($tmp . '/documents');
            $source = $tmp . '/upload';
            file_put_contents($source, 'Inhalt');
            $report = Uuid::v4();
            $attachment = Uuid::v4();

            $relative = $store->store($source, 'daily-reports/' . $report, $attachment, 'txt');
            $this->assertSame("daily-reports/{$report}/{$attachment}.txt", $relative, 'Gespeichert wird ein relativer Pfad.');
            $this->assertTrue(!is_file($source) && is_file($tmp . '/documents/' . $relative));
            $this->assertThrows(RuntimeException::class, fn () => $store->store($tmp . '/upload', 'daily-reports/' . $report, $attachment, 'txt'));

            $store->delete($relative);
            $this->assertTrue(!is_file($tmp . '/documents/' . $relative));
            $store->delete($relative); // bereits entfernt: kein Fehler

            // Ein Symlink in der Ablage darf nicht auf Dateien außerhalb zeigen.
            $outside = $tmp . '/secret.txt';
            file_put_contents($outside, 'geheim');
            $linked = Uuid::v4();
            symlink($outside, $tmp . "/documents/daily-reports/{$report}/{$linked}.txt");
            $this->assertThrows(RuntimeException::class, fn () => $store->absolute("daily-reports/{$report}/{$linked}.txt"));
            $this->assertThrows(RuntimeException::class, fn () => $store->delete("daily-reports/{$report}/{$linked}.txt"));
            $this->assertTrue(is_file($outside), 'Dateien außerhalb der Ablage bleiben unberührt.');
        } finally {
            $this->remove($tmp);
        }
    }

    public function testStoragePathsRejectPublicDirectoryAndWarnAboutProjectFolders(): void
    {
        $safe = new StoragePaths($this->config('/var/www/data/documents'), '/var/www/app');
        $this->assertSame([], $safe->blockingProblems());
        $this->assertSame([], array_merge(...array_column($safe->report(), 'warnings')));

        $public = new StoragePaths($this->config('/var/www/app/public/uploads'), '/var/www/app');
        $this->assertSame(1, count($public->blockingProblems()), 'Datenpfade im Webverzeichnis sind nicht zulässig.');
        $traversal = new StoragePaths($this->config('/var/www/app/var/../public/files'), '/var/www/app');
        $this->assertSame(1, count($traversal->blockingProblems()));
        $relative = new StoragePaths($this->config('data/documents'), '/var/www/app');
        $this->assertSame(1, count($relative->blockingProblems()));

        $docs = new StoragePaths($this->config('/srv/project/docs'), '/var/www/app');
        $this->assertSame([], $docs->blockingProblems());
        $warnings = array_merge(...array_column($docs->report(), 'warnings'));
        $this->assertTrue(count($warnings) === 1 && str_contains($warnings[0], 'docs'));

        $overlap = new StoragePaths($this->config('/var/www/data/offers/documents'), '/var/www/app');
        $this->assertTrue(str_contains(implode(' ', array_merge(...array_column($overlap->report(), 'warnings'))), 'überschneidet'));
    }

    private function config(string $documentsPath): Config
    {
        return new Config('/var/www/data/database/app.sqlite', '/var/www/data/offers', $documentsPath, '/var/www/data/templates', 'test', false, 'Europe/Berlin');
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->remove($path . '/' . $item);
            }
        }
        rmdir($path);
    }
}
