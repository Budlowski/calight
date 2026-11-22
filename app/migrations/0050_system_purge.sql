-- Datenbereinigung (Einstellungen → System → Datenbereinigung) und Produktivstatus.
--
-- 1. Geprüfte Vorschauen: Eine Bereinigung ist nur mit einer serverseitig erstellten, noch gültigen
--    Vorschau möglich. Der Fingerabdruck bindet die Freigabe an Auswahl, Zeitraum und genau die
--    betroffenen Datensätze; used_at verhindert wiederholte Ausführung.
-- 2. Sicherungen: vor jeder Löschung verpflichtend, geprüft und an die Vorschau gebunden.
--    content_sha256 ist der Inhaltsfingerabdruck der Datenbank zum Sicherungszeitpunkt; ändern sich
--    danach Daten, ist eine neue Sicherung nötig.
-- 3. Protokoll jeder Ausführung (Erfolg und Fehler); wird von der Bereinigung nie gelöscht.
-- 4. system_purge_guard: enthält nur innerhalb der Bereinigungstransaktion eine Zeile. Die
--    Sperr- und Unveränderlichkeitstrigger für Löschungen erlauben das Löschen ausschließlich dann
--    (andere Verbindungen sehen die nicht festgeschriebene Zeile nie). Fremdschlüssel und alle
--    übrigen Trigger bleiben unverändert aktiv.
-- 5. Produktivstatus: einmal gesetzt, sperrt er die Bereinigung dauerhaft. Trigger verhindern
--    Ändern und Löschen; es gibt keine Funktion zum Zurücksetzen.

CREATE TABLE system_purge_previews (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    selection_json TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    summary_json TEXT NOT NULL,
    executable INTEGER NOT NULL CHECK (executable IN (0, 1)),
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT
);

CREATE TABLE system_purge_backups (
    id TEXT PRIMARY KEY,
    preview_id TEXT NOT NULL REFERENCES system_purge_previews(id) ON DELETE RESTRICT,
    directory TEXT NOT NULL,
    database_sha256 TEXT NOT NULL,
    content_sha256 TEXT NOT NULL,
    file_count INTEGER NOT NULL CHECK (file_count >= 0),
    total_bytes INTEGER NOT NULL CHECK (total_bytes >= 0),
    created_by_user_id TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL
);

CREATE TABLE system_purge_runs (
    id TEXT PRIMARY KEY,
    preview_id TEXT NOT NULL REFERENCES system_purge_previews(id) ON DELETE RESTRICT,
    backup_id TEXT REFERENCES system_purge_backups(id) ON DELETE RESTRICT,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    selection_json TEXT NOT NULL,
    counts_json TEXT NOT NULL DEFAULT '{}',
    status TEXT NOT NULL CHECK (status IN ('succeeded', 'failed')),
    message TEXT NOT NULL DEFAULT '',
    file_warnings_json TEXT NOT NULL DEFAULT '[]',
    started_at TEXT NOT NULL,
    finished_at TEXT NOT NULL
);
CREATE INDEX idx_system_purge_runs_started ON system_purge_runs(started_at);

CREATE TRIGGER system_purge_runs_no_update BEFORE UPDATE ON system_purge_runs
BEGIN SELECT RAISE(ABORT, 'calight_purge_log_immutable'); END;
CREATE TRIGGER system_purge_runs_no_delete BEFORE DELETE ON system_purge_runs
BEGIN SELECT RAISE(ABORT, 'calight_purge_log_immutable'); END;

CREATE TABLE system_purge_guard (
    run_id TEXT PRIMARY KEY
);

CREATE TABLE system_production_status (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    activated_by_user_id TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    activated_at TEXT NOT NULL
);
CREATE TRIGGER system_production_status_no_update BEFORE UPDATE ON system_production_status
BEGIN SELECT RAISE(ABORT, 'calight_production_status_final'); END;
CREATE TRIGGER system_production_status_no_delete BEFORE DELETE ON system_production_status
BEGIN SELECT RAISE(ABORT, 'calight_production_status_final'); END;
-- Solange der Produktivstatus gilt, darf auch innerhalb einer Transaktion keine Bereinigung beginnen.
CREATE TRIGGER system_purge_guard_production BEFORE INSERT ON system_purge_guard
WHEN EXISTS (SELECT 1 FROM system_production_status)
BEGIN SELECT RAISE(ABORT, 'calight_production_locked'); END;

-- Lösch-Trigger: unverändert, zusätzlich nur während einer laufenden Bereinigung durchlässig.
DROP TRIGGER time_statements_no_delete;
CREATE TRIGGER time_statements_no_delete BEFORE DELETE ON time_statements
WHEN NOT EXISTS (SELECT 1 FROM system_purge_guard)
BEGIN SELECT RAISE(ABORT, 'calight_statement_immutable'); END;

DROP TRIGGER time_entries_lock_delete;
CREATE TRIGGER time_entries_lock_delete BEFORE DELETE ON time_entries
WHEN NOT EXISTS (SELECT 1 FROM system_purge_guard)
    AND EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id
    AND (l.work_date = OLD.work_date OR (OLD.ends_next_day = 1 AND l.work_date = date(OLD.work_date, '+1 day'))))
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;

DROP TRIGGER time_expenses_lock_delete;
CREATE TRIGGER time_expenses_lock_delete BEFORE DELETE ON time_expenses
WHEN NOT EXISTS (SELECT 1 FROM system_purge_guard)
    AND EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;

DROP TRIGGER time_day_details_lock_delete;
CREATE TRIGGER time_day_details_lock_delete BEFORE DELETE ON time_day_details
WHEN NOT EXISTS (SELECT 1 FROM system_purge_guard)
    AND EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;

DROP TRIGGER time_private_trips_lock_delete;
CREATE TRIGGER time_private_trips_lock_delete BEFORE DELETE ON time_private_trips
WHEN NOT EXISTS (SELECT 1 FROM system_purge_guard)
    AND EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;

DROP TRIGGER time_absences_lock_delete;
CREATE TRIGGER time_absences_lock_delete BEFORE DELETE ON time_absences
WHEN NOT EXISTS (SELECT 1 FROM system_purge_guard)
    AND EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;

DROP TRIGGER daily_report_statements_no_delete;
CREATE TRIGGER daily_report_statements_no_delete BEFORE DELETE ON daily_report_statements
WHEN NOT EXISTS (SELECT 1 FROM system_purge_guard)
BEGIN SELECT RAISE(ABORT, 'calight_statement_immutable'); END;

DROP TRIGGER daily_reports_no_delete;
CREATE TRIGGER daily_reports_no_delete BEFORE DELETE ON daily_reports
WHEN NOT EXISTS (SELECT 1 FROM system_purge_guard) AND (OLD.submitted_once = 1 OR OLD.status <> 'draft')
BEGIN SELECT RAISE(ABORT, 'calight_report_locked'); END;

DROP TRIGGER daily_report_entries_lock_delete;
CREATE TRIGGER daily_report_entries_lock_delete BEFORE DELETE ON daily_report_time_entries
WHEN NOT EXISTS (SELECT 1 FROM system_purge_guard)
    AND (SELECT status FROM daily_reports WHERE id = OLD.report_id) IN ('submitted', 'approved', 'cancelled')
BEGIN SELECT RAISE(ABORT, 'calight_report_locked'); END;
