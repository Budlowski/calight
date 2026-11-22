<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Document\DocumentValidationException;
use App\Http\AccessDeniedException;
use App\Security\RolePermissions;
use App\Support\Clock;
use App\Support\Uuid;
use PDO;
use Throwable;

/**
 * Datenbereinigung vor der Produktivnahme (Einstellungen → System → Datenbereinigung).
 *
 * Ablauf: Auswahl prüfen (serverseitige Vorschau mit Fingerabdruck) → verpflichtende, geprüfte
 * Sicherung → Ausführung mit Administratorpasswort, Bestätigungstext und ausdrücklicher
 * Bestätigung. Die Ausführung läuft in einer Transaktion (BEGIN IMMEDIATE), prüft Inhalt gegen die
 * Sicherung und Fingerabdruck gegen die Vorschau, löscht nur die analysierten Zeilen in
 * fremdschlüsselgerechter Reihenfolge, prüft danach die Integrität und protokolliert das Ergebnis.
 * Dateien werden erst nach erfolgreichem Festschreiben entfernt.
 *
 * Nach Aktivierung des Produktivstatus ist die Bereinigung dauerhaft gesperrt.
 */
final readonly class SystemPurgeService
{
    public const CONFIRMATION = 'DATEN LÖSCHEN';
    public const PRODUCTION_CONFIRMATION = 'PRODUKTIVBETRIEB';
    private const PREVIEW_MINUTES = 30;
    private const BACKUP_MINUTES = 30;

    public function __construct(private PDO $pdo, private SystemPurgePlanner $planner, private SystemPurgeBackup $backup)
    {
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function overview(array $actor): array
    {
        $this->assertAllowed($actor, false);
        $areas = [];
        foreach (SystemPurgePlanner::AREAS as $key => [$group, $label, $period]) {
            $areas[] = ['key' => $key, 'group' => $group, 'label' => $label, 'period' => $period, 'periodReason' => $period ? '' : SystemPurgePlanner::PERIOD_REASON,
                'default' => $group === 'business'];
        }
        $runs = $this->pdo->query('SELECT r.id, r.status, r.message, r.counts_json, r.selection_json, r.started_at, r.finished_at, u.display_name, b.directory
            FROM system_purge_runs r JOIN users u ON u.id = r.user_id LEFT JOIN system_purge_backups b ON b.id = r.backup_id ORDER BY r.started_at DESC LIMIT 10')->fetchAll();
        return [
            'areas' => $areas,
            'production' => $this->production(),
            'confirmation' => self::CONFIRMATION,
            'productionConfirmation' => self::PRODUCTION_CONFIRMATION,
            'preserved' => SystemPurgePlanner::PRESERVED,
            'runs' => array_map(static fn (array $run): array => [
                'id' => $run['id'], 'status' => $run['status'], 'message' => $run['message'], 'user' => $run['display_name'],
                'startedAt' => $run['started_at'], 'finishedAt' => $run['finished_at'], 'backup' => $run['directory'],
                'total' => array_sum(json_decode((string) $run['counts_json'], true) ?: []),
                'areas' => array_map(static fn (string $key): string => SystemPurgePlanner::AREAS[$key][1] ?? $key, json_decode((string) $run['selection_json'], true)['areas'] ?? []),
            ], $runs),
        ];
    }

    /**
     * Analysiert die Auswahl und speichert eine Vorschau. Verändert keine Geschäftsdaten.
     *
     * @param array<string,mixed> $input @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function preview(array $input, array $actor): array
    {
        $this->assertAllowed($actor, true);
        $selection = SystemPurgePlanner::selection($input);
        $analysis = $this->planner->analyze($selection);
        $executable = $analysis['blockers'] === [] && ($analysis['total'] > 0);
        $id = Uuid::v4();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $summary = self::summary($analysis);
        $this->pdo->prepare('INSERT INTO system_purge_previews (id, user_id, selection_json, fingerprint, summary_json, executable, created_at, expires_at)
            VALUES (:id, :user, :selection, :fingerprint, :summary, :executable, :created, :expires)')->execute([
            'id' => $id, 'user' => (string) $actor['id'], 'selection' => json_encode($selection, JSON_THROW_ON_ERROR),
            'fingerprint' => $analysis['fingerprint'], 'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'executable' => $executable ? 1 : 0, 'created' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires' => $now->modify('+' . self::PREVIEW_MINUTES . ' minutes')->format('Y-m-d\TH:i:s\Z'),
        ]);
        return $summary + ['previewId' => $id, 'executable' => $executable, 'expiresAt' => $now->modify('+' . self::PREVIEW_MINUTES . ' minutes')->format('Y-m-d\TH:i:s\Z')];
    }

    /**
     * Erstellt die verpflichtende Sicherung für eine gültige Vorschau.
     *
     * @param array<string,mixed> $actor @return array<string,mixed>
     */
    public function createBackup(string $previewId, array $actor): array
    {
        $this->assertAllowed($actor, true);
        $preview = $this->usablePreview($previewId);
        $selection = json_decode((string) $preview['selection_json'], true, flags: JSON_THROW_ON_ERROR);
        if ($this->planner->analyze($selection)['fingerprint'] !== $preview['fingerprint']) {
            throw new SystemPurgeConflictException('Die Daten haben sich seit der Vorschau geändert. Bitte die Auswahl erneut prüfen.');
        }
        $id = Uuid::v4();
        try {
            $result = $this->backup->create($id, ['previewId' => $previewId, 'createdByUserId' => (string) $actor['id'], 'selection' => $selection]);
        } catch (\RuntimeException $exception) {
            throw new DocumentValidationException(['Sicherung fehlgeschlagen: ' . $exception->getMessage() . ' Es wurden keine Daten gelöscht.']);
        }
        $now = Clock::now();
        $this->pdo->prepare('INSERT INTO system_purge_backups (id, preview_id, directory, database_sha256, content_sha256, file_count, total_bytes, created_by_user_id, created_at)
            VALUES (:id, :preview, :directory, :database, :content, :files, :bytes, :user, :now)')->execute([
            'id' => $id, 'preview' => $previewId, 'directory' => $result['directory'], 'database' => $result['databaseSha256'], 'content' => $result['contentSha256'],
            'files' => $result['fileCount'], 'bytes' => $result['totalBytes'], 'user' => (string) $actor['id'], 'now' => $now,
        ]);
        return ['backupId' => $id, 'directory' => $result['directory'], 'fileCount' => $result['fileCount'], 'totalBytes' => $result['totalBytes'], 'createdAt' => $now,
            'databaseSha256' => $result['databaseSha256']];
    }

    /**
     * Führt die bestätigte Bereinigung aus.
     *
     * @param array<string,mixed> $input previewId, backupId, password, confirmation, confirmed
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function execute(array $input, array $actor): array
    {
        $this->assertAllowed($actor, true);
        $errors = [];
        if (($input['confirmed'] ?? false) !== true) {
            $errors[] = 'Bitte die endgültige Löschung ausdrücklich bestätigen.';
        }
        if (!is_string($input['confirmation'] ?? null) || $input['confirmation'] !== self::CONFIRMATION) {
            $errors[] = 'Bitte den Bestätigungstext „' . self::CONFIRMATION . '“ exakt eingeben.';
        }
        if (!$this->passwordMatches($actor, $input['password'] ?? null)) {
            $errors[] = 'Das Passwort ist nicht korrekt.';
        }
        if ($errors !== []) {
            throw new DocumentValidationException($errors);
        }
        $previewId = (string) ($input['previewId'] ?? '');
        $preview = $this->usablePreview($previewId);
        $backup = $this->usableBackup((string) ($input['backupId'] ?? ''), $previewId);
        $selection = json_decode((string) $preview['selection_json'], true, flags: JSON_THROW_ON_ERROR);
        $runId = Uuid::v4();
        $started = Clock::now();
        $counts = [];
        $analysis = null;
        try {
            $this->pdo->exec('BEGIN IMMEDIATE');
            if ($this->backup->contentHash($this->pdo) !== $backup['content_sha256']) {
                throw new SystemPurgeConflictException('Seit der Sicherung wurden Daten geändert. Bitte die Auswahl erneut prüfen und eine neue Sicherung erstellen.');
            }
            $analysis = $this->planner->analyze($selection);
            if ($analysis['fingerprint'] !== $preview['fingerprint'] || $analysis['blockers'] !== []) {
                throw new SystemPurgeConflictException('Die Vorschau ist nicht mehr gültig. Bitte die Auswahl erneut prüfen.');
            }
            $counts = $analysis['counts'];
            $violationsBefore = $this->foreignKeyViolations();
            $this->pdo->prepare('INSERT INTO system_purge_guard (run_id) VALUES (:id)')->execute(['id' => $runId]);
            $this->pdo->exec('PRAGMA defer_foreign_keys = ON');
            $this->deleteSelectedRows();
            foreach ($analysis['numberRanges'] as $range) {
                if ($range['resetTo'] !== null) {
                    $this->pdo->prepare('UPDATE number_ranges SET next_counter = :next, updated_at = :now WHERE code = :code AND next_counter = :current')
                        ->execute(['next' => $range['resetTo'], 'now' => Clock::now(), 'code' => $range['code'], 'current' => $range['next']]);
                }
            }
            $this->pdo->prepare('DELETE FROM system_purge_guard WHERE run_id = :id')->execute(['id' => $runId]);
            if ($this->foreignKeyViolations() !== $violationsBefore) {
                throw new \RuntimeException('Nach der Bereinigung wären Verweise ungültig.');
            }
            $used = $this->pdo->prepare('UPDATE system_purge_previews SET used_at = :now WHERE id = :id AND used_at IS NULL');
            $used->execute(['now' => Clock::now(), 'id' => $previewId]);
            if ($used->rowCount() !== 1) {
                throw new SystemPurgeConflictException('Diese Vorschau wurde bereits verwendet.');
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $message = $exception instanceof SystemPurgeConflictException ? $exception->getMessage()
                : 'Die Bereinigung wurde abgebrochen; es wurden keine Daten gelöscht.' . (str_contains($exception->getMessage(), 'calight_production_locked') ? ' Der Produktivstatus ist aktiv.' : '');
            $this->log($runId, $previewId, (string) $backup['id'], $actor, $selection, $counts, 'failed', $message . ' (' . self::shortError($exception) . ')', [], $started);
            if ($exception instanceof SystemPurgeConflictException) {
                throw $exception;
            }
            throw new DocumentValidationException([$message . ' Die Sicherung bleibt erhalten.']);
        }

        // Dateien erst nach erfolgreichem Festschreiben; Fehler werden protokolliert, nicht verschwiegen.
        $warnings = [];
        $deleted = 0;
        foreach ($analysis['files']['delete'] as $file) {
            if (!is_file($file['path'])) {
                continue;
            }
            if (@unlink($file['path'])) {
                $deleted++;
            } else {
                $warnings[] = 'Datei konnte nicht gelöscht werden: ' . basename($file['path']);
            }
        }
        foreach ($analysis['files']['offerDirectories'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            if (is_file($directory . '/.draft.lock')) {
                @unlink($directory . '/.draft.lock');
            }
            if ((scandir($directory) ?: []) === ['.', '..']) {
                @rmdir($directory);
            } else {
                $warnings[] = 'Angebotsverzeichnis enthält unbekannte Dateien und bleibt erhalten: ' . basename(dirname($directory)) . '/' . basename($directory);
            }
        }
        $message = sprintf('%d Datensätze und %d Dateien entfernt.', array_sum($counts), $deleted);
        $this->log($runId, $previewId, (string) $backup['id'], $actor, $selection, $counts, 'succeeded', $message, $warnings, $started);
        return ['runId' => $runId, 'counts' => $counts, 'total' => array_sum($counts), 'filesDeleted' => $deleted, 'warnings' => $warnings,
            'backup' => $backup['directory'], 'message' => $message];
    }

    /**
     * Setzt den Produktivstatus (endgültig; keine Rücknahme über die Anwendung).
     *
     * @param array<string,mixed> $input password, confirmation @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function activateProduction(array $input, array $actor): array
    {
        $this->assertAllowed($actor, false);
        if ($this->production() !== null) {
            throw new DocumentValidationException(['Der Produktivstatus ist bereits aktiv.']);
        }
        $errors = [];
        if (!is_string($input['confirmation'] ?? null) || $input['confirmation'] !== self::PRODUCTION_CONFIRMATION) {
            $errors[] = 'Bitte den Bestätigungstext „' . self::PRODUCTION_CONFIRMATION . '“ exakt eingeben.';
        }
        if (!$this->passwordMatches($actor, $input['password'] ?? null)) {
            $errors[] = 'Das Passwort ist nicht korrekt.';
        }
        if ($errors !== []) {
            throw new DocumentValidationException($errors);
        }
        $this->pdo->prepare('INSERT INTO system_production_status (id, activated_by_user_id, activated_at) VALUES (1, :user, :now)')
            ->execute(['user' => (string) $actor['id'], 'now' => Clock::now()]);
        return ['production' => $this->production()];
    }

    /** @return array{activatedAt:string,user:string}|null */
    public function production(): ?array
    {
        $row = $this->pdo->query('SELECT p.activated_at, u.display_name FROM system_production_status p JOIN users u ON u.id = p.activated_by_user_id WHERE p.id = 1')->fetch();
        return $row === false ? null : ['activatedAt' => (string) $row['activated_at'], 'user' => (string) $row['display_name']];
    }

    // -------------------------------------------------------------------------

    /**
     * Löscht genau die analysierten Zeilen. Eine Zeile wird erst entfernt, wenn keine andere
     * Zeile mehr über einen nicht kaskadierenden Fremdschlüssel auf sie verweist (Kinder zuerst,
     * auch bei Selbstverweisen und Ketten wie Korrekturen oder Revisionen).
     */
    private function deleteSelectedRows(): void
    {
        $incoming = $this->planner->incomingForeignKeys();
        $tables = $this->planner->selectedTables();
        for ($pass = 0; $pass < 100; $pass++) {
            $progress = false;
            $remaining = 0;
            foreach ($tables as $table) {
                $conditions = [];
                foreach ($incoming[$table] ?? [] as [$child, $column, $parentColumn, $action]) {
                    if ($action !== 'CASCADE') {
                        // Ein Verweis einer Zeile auf sich selbst (z. B. erste Revision) blockiert nicht.
                        $conditions[] = sprintf('NOT EXISTS (SELECT 1 FROM %1$s c WHERE c.%2$s = %3$s.%4$s%5$s)', SystemPurgePlanner::quote($child), SystemPurgePlanner::quote($column),
                            SystemPurgePlanner::quote($table), SystemPurgePlanner::quote($parentColumn), $child === $table ? sprintf(' AND c.rowid <> %s.rowid', SystemPurgePlanner::quote($table)) : '');
                    }
                }
                $sql = sprintf('DELETE FROM %1$s WHERE rowid IN (SELECT rid FROM temp.purge_set WHERE tbl = %2$s)%3$s', SystemPurgePlanner::quote($table),
                    $this->pdo->quote($table), $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions));
                $progress = $this->pdo->exec($sql) > 0 || $progress;
                $remaining += (int) $this->pdo->query(sprintf('SELECT COUNT(*) FROM %s WHERE rowid IN (SELECT rid FROM temp.purge_set WHERE tbl = %s)',
                    SystemPurgePlanner::quote($table), $this->pdo->quote($table)))->fetchColumn();
            }
            if ($remaining === 0) {
                return;
            }
            if (!$progress) {
                throw new \RuntimeException('Die Löschreihenfolge konnte nicht aufgelöst werden.');
            }
        }
        throw new \RuntimeException('Die Löschreihenfolge konnte nicht aufgelöst werden.');
    }

    /** @return list<array<string,mixed>> */
    private function foreignKeyViolations(): array
    {
        return $this->pdo->query('PRAGMA foreign_key_check')->fetchAll();
    }

    /** @return array<string,mixed> */
    private function usablePreview(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM system_purge_previews WHERE id = :id');
        $statement->execute(['id' => $id]);
        $preview = $statement->fetch();
        if ($preview === false) {
            throw new SystemPurgeConflictException('Die Vorschau wurde nicht gefunden. Bitte die Auswahl erneut prüfen.');
        }
        if ($preview['used_at'] !== null) {
            throw new SystemPurgeConflictException('Diese Vorschau wurde bereits ausgeführt. Bitte die Auswahl erneut prüfen.');
        }
        if ((string) $preview['expires_at'] < Clock::now()) {
            throw new SystemPurgeConflictException('Die Vorschau ist abgelaufen. Bitte die Auswahl erneut prüfen.');
        }
        if ((int) $preview['executable'] !== 1) {
            throw new DocumentValidationException(['Diese Auswahl kann wegen blockierender Abhängigkeiten nicht ausgeführt werden.']);
        }
        return $preview;
    }

    /** @return array<string,mixed> */
    private function usableBackup(string $id, string $previewId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM system_purge_backups WHERE id = :id AND preview_id = :preview');
        $statement->execute(['id' => $id, 'preview' => $previewId]);
        $backup = $statement->fetch();
        if ($backup === false) {
            throw new DocumentValidationException(['Vor der Löschung ist eine erfolgreiche Sicherung für diese Vorschau erforderlich.']);
        }
        $limit = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . self::BACKUP_MINUTES . ' minutes')->format('Y-m-d\TH:i:s\Z');
        if ((string) $backup['created_at'] < $limit) {
            throw new SystemPurgeConflictException('Die Sicherung ist älter als ' . self::BACKUP_MINUTES . ' Minuten. Bitte eine neue Sicherung erstellen.');
        }
        if (!$this->backup->stillPresent((string) $backup['directory'], (string) $backup['database_sha256'])) {
            throw new DocumentValidationException(['Die Sicherung ist nicht mehr vollständig vorhanden. Bitte eine neue Sicherung erstellen.']);
        }
        return $backup;
    }

    /** @param array<string,mixed> $actor */
    private function passwordMatches(array $actor, mixed $password): bool
    {
        if (!is_string($password) || $password === '') {
            return false;
        }
        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id AND active = 1');
        $statement->execute(['id' => (string) ($actor['id'] ?? '')]);
        $hash = $statement->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    }

    /** @param array<string,mixed> $actor */
    private function assertAllowed(array $actor, bool $destructive): void
    {
        if (!RolePermissions::allows((string) ($actor['role'] ?? ''), 'system.purge') || ($actor['role'] ?? '') !== 'admin') {
            throw new AccessDeniedException('system.purge');
        }
        if ($destructive && $this->production() !== null) {
            throw new DocumentValidationException(['Der Produktivstatus ist aktiv. Die Datenbereinigung ist dauerhaft gesperrt.']);
        }
    }

    /**
     * @param array<string,mixed> $actor @param array<string,mixed> $selection @param array<string,int> $counts @param list<string> $warnings
     */
    private function log(string $id, string $previewId, ?string $backupId, array $actor, array $selection, array $counts, string $status, string $message, array $warnings, string $started): void
    {
        $this->pdo->prepare('INSERT INTO system_purge_runs (id, preview_id, backup_id, user_id, selection_json, counts_json, status, message, file_warnings_json, started_at, finished_at)
            VALUES (:id, :preview, :backup, :user, :selection, :counts, :status, :message, :warnings, :started, :finished)')->execute([
            'id' => $id, 'preview' => $previewId, 'backup' => $backupId, 'user' => (string) $actor['id'], 'selection' => json_encode($selection, JSON_THROW_ON_ERROR),
            'counts' => json_encode((object) $counts, JSON_THROW_ON_ERROR), 'status' => $status, 'message' => $message,
            'warnings' => json_encode($warnings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'started' => $started, 'finished' => Clock::now(),
        ]);
    }

    private static function shortError(Throwable $exception): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '', 0, 300);
    }

    /**
     * Darstellung der Vorschau ohne Dateipfade.
     *
     * @param array<string,mixed> $analysis @return array<string,mixed>
     */
    private static function summary(array $analysis): array
    {
        return [
            'selection' => $analysis['selection'],
            'areas' => $analysis['areas'],
            'total' => $analysis['total'],
            'files' => ['count' => $analysis['files']['count'], 'bytes' => $analysis['files']['bytes'], 'missing' => $analysis['files']['missing'], 'kept' => $analysis['files']['kept']],
            'numberRanges' => $analysis['numberRanges'],
            'blockers' => $analysis['blockers'],
            'preserved' => $analysis['preserved'],
        ];
    }
}
