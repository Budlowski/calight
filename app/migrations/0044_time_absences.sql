-- Abwesenheiten in der Zeiterfassung (Urlaub, Feiertag, Krankheit, Zeitausgleich, Arbeitsfrei,
-- Sonstige). Getrennt von tatsächlich geleisteten Arbeitsstunden (time_entries): keine Addition
-- zu Ist-Stunden, kein Auftrag und keine Kostenstelle, keine Personalkosten, keine
-- Arbeitszeitgrenzen. Ganztägig (ohne Stundenzahl – es wird keine Sollzeit angenommen) oder mit
-- konkreter Dauer in Minuten. Keine Diagnosen oder medizinischen Angaben.
-- Abwesenheiten unterliegen derselben Tagessperre wie Arbeitszeiten (0042).
PRAGMA defer_foreign_keys = ON;

CREATE TABLE time_absences (
    id TEXT PRIMARY KEY,
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL CHECK (length(work_date) = 10),
    absence_type TEXT NOT NULL CHECK (absence_type IN ('vacation', 'holiday', 'sick', 'compensatory', 'day_off', 'other')),
    full_day INTEGER NOT NULL CHECK (full_day IN (0, 1)),
    minutes INTEGER,
    notes TEXT NOT NULL DEFAULT '',
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_by_user_id TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK ((full_day = 1 AND minutes IS NULL) OR (full_day = 0 AND minutes BETWEEN 1 AND 1440))
);
CREATE INDEX idx_time_absences_employee_date ON time_absences(employee_id, work_date);

CREATE TRIGGER time_absences_lock_insert BEFORE INSERT ON time_absences
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = NEW.employee_id AND l.work_date = NEW.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
CREATE TRIGGER time_absences_lock_update BEFORE UPDATE ON time_absences
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND ((l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date) OR (l.employee_id = NEW.employee_id AND l.work_date = NEW.work_date)))
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
CREATE TRIGGER time_absences_lock_delete BEFORE DELETE ON time_absences
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
