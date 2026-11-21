-- Lebenszyklus der Tagesberichte: Entwurf → eingereicht → festgeschrieben, Wiederöffnung zur
-- Korrektur, Stornierung. Festgeschriebene Fassungen werden als unveränderliches PDF mit festem
-- Datenstand archiviert (Revisionen, wie bei den Stundennachweisen der Zeiterfassung).
--
-- - status: draft | submitted | approved | reopened | cancelled. Bestehende Berichte bleiben
--   Entwürfe (Testbetrieb, keine Altdatenkonvertierung).
-- - submitted_once: Nur nie eingereichte Entwürfe dürfen physisch gelöscht werden (Trigger).
-- - Berichtssperre in der Datenbank: Stundenpositionen eingereichter, festgeschriebener oder
--   stornierter Berichte sowie deren Kostenziel sind unveränderlich (calight_report_locked).
--   Die zentralen Zeitbuchungen (time_entries) behalten zusätzlich ihre Tagessperre (0042).
-- - Die Positionszuordnung je Stundenzeile (target_*) wird nicht mehr verwendet; alle Stunden
--   folgen dem Kostenziel des Berichts. Die Spalten bleiben aus Schemagründen bestehen.
PRAGMA defer_foreign_keys = ON;

ALTER TABLE daily_reports ADD COLUMN status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'approved', 'reopened', 'cancelled'));
ALTER TABLE daily_reports ADD COLUMN submitted_once INTEGER NOT NULL DEFAULT 0 CHECK (submitted_once IN (0, 1));
CREATE INDEX idx_daily_reports_status ON daily_reports(status, report_date DESC);

UPDATE daily_report_time_entries SET target_kind = 'order', target_basis_type = NULL, target_basis_id = NULL, target_node_id = NULL WHERE target_kind <> 'order';

CREATE TABLE daily_report_events (
    id TEXT PRIMARY KEY,
    report_id TEXT NOT NULL,
    action TEXT NOT NULL CHECK (action IN ('submit', 'return', 'approve', 'reopen', 'cancel')),
    reason TEXT NOT NULL DEFAULT '',
    user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (action NOT IN ('return', 'reopen', 'cancel') OR length(trim(reason)) > 0)
);
CREATE INDEX idx_daily_report_events_report ON daily_report_events(report_id, created_at);

CREATE TABLE daily_report_statements (
    id TEXT PRIMARY KEY,
    report_id TEXT NOT NULL,
    revision INTEGER NOT NULL CHECK (revision > 0),
    status TEXT NOT NULL CHECK (status IN ('current', 'superseded')),
    snapshot_json TEXT NOT NULL,
    file_name TEXT NOT NULL,
    storage_path TEXT NOT NULL UNIQUE,
    sha256 TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    superseded_at TEXT,
    superseded_by_user_id TEXT,
    FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (superseded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE (report_id, revision),
    CHECK ((status = 'superseded') = (superseded_at IS NOT NULL))
);
CREATE UNIQUE INDEX idx_daily_report_statements_current ON daily_report_statements(report_id) WHERE status = 'current';

CREATE TRIGGER daily_report_statements_immutable BEFORE UPDATE ON daily_report_statements
WHEN NEW.snapshot_json IS NOT OLD.snapshot_json OR NEW.storage_path IS NOT OLD.storage_path OR NEW.sha256 IS NOT OLD.sha256
    OR NEW.revision IS NOT OLD.revision OR NEW.report_id IS NOT OLD.report_id OR OLD.status = 'superseded'
BEGIN SELECT RAISE(ABORT, 'calight_statement_immutable'); END;
CREATE TRIGGER daily_report_statements_no_delete BEFORE DELETE ON daily_report_statements
BEGIN SELECT RAISE(ABORT, 'calight_statement_immutable'); END;

-- Nur nie eingereichte Entwürfe dürfen gelöscht werden.
CREATE TRIGGER daily_reports_no_delete BEFORE DELETE ON daily_reports
WHEN OLD.submitted_once = 1 OR OLD.status <> 'draft'
BEGIN SELECT RAISE(ABORT, 'calight_report_locked'); END;

-- Kostenziel gesperrter Berichte unveränderlich.
CREATE TRIGGER daily_reports_target_lock BEFORE UPDATE OF order_id, cost_center_id ON daily_reports
WHEN OLD.status IN ('submitted', 'approved', 'cancelled')
    AND (NEW.order_id IS NOT OLD.order_id OR NEW.cost_center_id IS NOT OLD.cost_center_id)
BEGIN SELECT RAISE(ABORT, 'calight_report_locked'); END;

-- Stundenpositionen gesperrter Berichte unveränderlich.
CREATE TRIGGER daily_report_entries_lock_insert BEFORE INSERT ON daily_report_time_entries
WHEN (SELECT status FROM daily_reports WHERE id = NEW.report_id) IN ('submitted', 'approved', 'cancelled')
BEGIN SELECT RAISE(ABORT, 'calight_report_locked'); END;
CREATE TRIGGER daily_report_entries_lock_update BEFORE UPDATE ON daily_report_time_entries
WHEN (SELECT status FROM daily_reports WHERE id = OLD.report_id) IN ('submitted', 'approved', 'cancelled')
BEGIN SELECT RAISE(ABORT, 'calight_report_locked'); END;
CREATE TRIGGER daily_report_entries_lock_delete BEFORE DELETE ON daily_report_time_entries
WHEN (SELECT status FROM daily_reports WHERE id = OLD.report_id) IN ('submitted', 'approved', 'cancelled')
BEGIN SELECT RAISE(ABORT, 'calight_report_locked'); END;
