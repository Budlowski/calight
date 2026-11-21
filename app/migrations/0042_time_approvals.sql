-- Zeiterfassung: Einreichung, Büroprüfung, Festschreibung, Wiederöffnung und archivierte
-- PDF-Stundennachweise (Phase A).
--
-- Verbindlich ist die Sperre je Mitarbeiter und Kalendertag (time_day_locks). Wochen und Monate
-- sind Sammelaktionen: Eine Einreichung sperrt die noch offenen Tage ihres Zeitraums; bereits
-- eingereichte oder festgeschriebene Tage bleiben unverändert. Kein Eintrag = offen.
--   submitted – eingereicht, Bearbeitung gesperrt, das Büro prüft
--   approved  – festgeschrieben (Nachweis archiviert)
--   reopened  – zur Korrektur geöffnet bzw. zurückgegeben; bearbeitbar, erneute Einreichung nötig
--
-- Die Sperre greift zentral in der Datenbank (Trigger): Zeitbuchungen aller Herkünfte
-- (manuell, Tagesbericht, Rapport – auch Kaskaden beim Löschen von Berichtspositionen),
-- Tagesangaben und Auslagen gesperrter Tage können von keinem Modul und keiner Rolle geändert
-- werden. Bei Buchungen über Mitternacht zählt auch der Folgetag; bei Verschiebungen alter und
-- neuer Tag. Die Anwendung übersetzt den Abbruch (calight_time_locked) in eine Meldung.
PRAGMA defer_foreign_keys = ON;

CREATE TABLE time_submissions (
    id TEXT PRIMARY KEY,
    employee_id TEXT NOT NULL,
    period_type TEXT NOT NULL CHECK (period_type IN ('week', 'month')),
    period_key TEXT NOT NULL,
    from_date TEXT NOT NULL,
    to_date TEXT NOT NULL CHECK (to_date >= from_date),
    status TEXT NOT NULL CHECK (status IN ('submitted', 'approved', 'returned')),
    -- Prüfergebnis bei Einreichung (Warnungen, Summen) als JSON.
    summary_json TEXT NOT NULL,
    submitted_by_user_id TEXT NOT NULL,
    submitted_at TEXT NOT NULL,
    decided_by_user_id TEXT,
    decided_at TEXT,
    decision_comment TEXT NOT NULL DEFAULT '',
    warnings_acknowledged INTEGER NOT NULL DEFAULT 0 CHECK (warnings_acknowledged IN (0, 1)),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (decided_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK ((status = 'submitted') = (decided_at IS NULL))
);
CREATE INDEX idx_time_submissions_status ON time_submissions(status, submitted_at);
CREATE INDEX idx_time_submissions_employee ON time_submissions(employee_id, from_date);

CREATE TABLE time_day_locks (
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL CHECK (length(work_date) = 10),
    status TEXT NOT NULL CHECK (status IN ('submitted', 'approved', 'reopened')),
    submission_id TEXT,
    updated_by_user_id TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (employee_id, work_date),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (submission_id) REFERENCES time_submissions(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (status = 'reopened' OR submission_id IS NOT NULL)
);
CREATE INDEX idx_time_day_locks_submission ON time_day_locks(submission_id);

-- Protokoll: Einreichung, Festschreibung, Zurückgabe, Wiederöffnung (mit Begründung).
CREATE TABLE time_lock_events (
    id TEXT PRIMARY KEY,
    employee_id TEXT NOT NULL,
    from_date TEXT NOT NULL,
    to_date TEXT NOT NULL,
    action TEXT NOT NULL CHECK (action IN ('submit', 'approve', 'return', 'reopen')),
    submission_id TEXT,
    reason TEXT NOT NULL DEFAULT '',
    user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (submission_id) REFERENCES time_submissions(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (action NOT IN ('return', 'reopen') OR length(trim(reason)) > 0)
);
CREATE INDEX idx_time_lock_events_employee ON time_lock_events(employee_id, from_date);

-- Archivierte Stundennachweise: fester Datenstand (snapshot_json) und unveränderliche PDF-Datei.
-- Je Mitarbeiter und Zeitraum höchstens eine aktuelle Revision; ersetzte bleiben erhalten.
CREATE TABLE time_statements (
    id TEXT PRIMARY KEY,
    employee_id TEXT NOT NULL,
    period_type TEXT NOT NULL CHECK (period_type IN ('week', 'month')),
    period_key TEXT NOT NULL,
    from_date TEXT NOT NULL,
    to_date TEXT NOT NULL,
    revision INTEGER NOT NULL CHECK (revision > 0),
    status TEXT NOT NULL CHECK (status IN ('current', 'superseded')),
    submission_id TEXT,
    snapshot_json TEXT NOT NULL,
    file_name TEXT NOT NULL,
    storage_path TEXT NOT NULL UNIQUE,
    sha256 TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    superseded_at TEXT,
    superseded_by_user_id TEXT,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (submission_id) REFERENCES time_submissions(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (superseded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE (employee_id, period_type, period_key, revision),
    CHECK ((status = 'superseded') = (superseded_at IS NOT NULL))
);
CREATE UNIQUE INDEX idx_time_statements_current ON time_statements(employee_id, period_type, period_key) WHERE status = 'current';
CREATE INDEX idx_time_statements_employee ON time_statements(employee_id, from_date);

-- Archivierte Nachweise sind unveränderlich (nur der Status „ersetzt“ wird gesetzt).
CREATE TRIGGER time_statements_immutable BEFORE UPDATE ON time_statements
WHEN NEW.snapshot_json IS NOT OLD.snapshot_json OR NEW.storage_path IS NOT OLD.storage_path OR NEW.sha256 IS NOT OLD.sha256
    OR NEW.revision IS NOT OLD.revision OR NEW.from_date IS NOT OLD.from_date OR NEW.to_date IS NOT OLD.to_date OR OLD.status = 'superseded'
BEGIN SELECT RAISE(ABORT, 'calight_statement_immutable'); END;
CREATE TRIGGER time_statements_no_delete BEFORE DELETE ON time_statements
BEGIN SELECT RAISE(ABORT, 'calight_statement_immutable'); END;

-- Zentrale Sperre der Zeitbuchungen (alle Herkünfte, auch Kaskaden).
CREATE TRIGGER time_entries_lock_insert BEFORE INSERT ON time_entries
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = NEW.employee_id
    AND (l.work_date = NEW.work_date OR (NEW.ends_next_day = 1 AND l.work_date = date(NEW.work_date, '+1 day'))))
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;

CREATE TRIGGER time_entries_lock_update BEFORE UPDATE ON time_entries
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND (
    (l.employee_id = OLD.employee_id AND (l.work_date = OLD.work_date OR (OLD.ends_next_day = 1 AND l.work_date = date(OLD.work_date, '+1 day'))))
    OR (l.employee_id = NEW.employee_id AND (l.work_date = NEW.work_date OR (NEW.ends_next_day = 1 AND l.work_date = date(NEW.work_date, '+1 day'))))))
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;

CREATE TRIGGER time_entries_lock_delete BEFORE DELETE ON time_entries
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id
    AND (l.work_date = OLD.work_date OR (OLD.ends_next_day = 1 AND l.work_date = date(OLD.work_date, '+1 day'))))
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;

-- Tagesangaben (Reisekosten) und Auslagen gesperrter Tage.
CREATE TRIGGER time_day_details_lock_insert BEFORE INSERT ON time_day_details
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = NEW.employee_id AND l.work_date = NEW.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
CREATE TRIGGER time_day_details_lock_update BEFORE UPDATE ON time_day_details
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND ((l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date) OR (l.employee_id = NEW.employee_id AND l.work_date = NEW.work_date)))
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
CREATE TRIGGER time_day_details_lock_delete BEFORE DELETE ON time_day_details
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;

CREATE TRIGGER time_expenses_lock_insert BEFORE INSERT ON time_expenses
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = NEW.employee_id AND l.work_date = NEW.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
CREATE TRIGGER time_expenses_lock_update BEFORE UPDATE ON time_expenses
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND ((l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date) OR (l.employee_id = NEW.employee_id AND l.work_date = NEW.work_date)))
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
CREATE TRIGGER time_expenses_lock_delete BEFORE DELETE ON time_expenses
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
