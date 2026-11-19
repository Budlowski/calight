-- Zeiterfassung (Phase 3.4.3), fachliche Korrekturen – rein additiv zu 0032.
--
-- 1. Einsatzart je Zeitblock statt je Tag. Eine bisherige Tagesangabe wird nur auf Blöcke
--    desselben Tages übertragen, die noch keine eigene Angabe haben (Spalte ist neu, daher
--    keine widersprüchlichen Einzelangaben). time_day_details.assignment_kind bleibt als
--    historischer Wert erhalten, wird aber nicht mehr bearbeitet.
-- 2. Reisezeit als Teil der Nettozeit (travel_minutes ≤ net_minutes). Bisherige Blöcke der
--    Tätigkeit „travel“ bleiben unverändert und erhalten ihre gesamte Nettozeit als Reisezeit –
--    so zählt Reisezeit weiterhin genau einmal. Neue Blöcke verwenden „travel“ nicht mehr.
-- 3. Beginn und Ende der Abwesenheit je Tag (optional, nur Dokumentation).
-- 4. Maßgebliche Stundenquelle, wenn für Mitarbeiter + Tag + Auftrag sowohl Zeitblöcke als
--    auch Bautagesbericht-Zeiten bestehen. Ohne Auswahl gilt das als Konflikt und geht nicht
--    in die Ist-Stunden ein (TimeTrackingService::actualHoursForOrder).

ALTER TABLE time_entries ADD COLUMN assignment_kind TEXT CHECK (assignment_kind IS NULL OR assignment_kind IN ('regular', 'external'));
ALTER TABLE time_entries ADD COLUMN travel_minutes INTEGER NOT NULL DEFAULT 0 CHECK (travel_minutes >= 0 AND travel_minutes <= net_minutes);

UPDATE time_entries SET travel_minutes = net_minutes WHERE activity = 'travel';

UPDATE time_entries SET assignment_kind = (
    SELECT d.assignment_kind FROM time_day_details d WHERE d.employee_id = time_entries.employee_id AND d.work_date = time_entries.work_date
)
WHERE assignment_kind IS NULL AND EXISTS (
    SELECT 1 FROM time_day_details d WHERE d.employee_id = time_entries.employee_id AND d.work_date = time_entries.work_date AND d.assignment_kind IS NOT NULL
);

ALTER TABLE time_day_details ADD COLUMN absence_start TEXT CHECK (absence_start IS NULL OR length(absence_start) = 5);
ALTER TABLE time_day_details ADD COLUMN absence_end TEXT CHECK (absence_end IS NULL OR length(absence_end) = 5);

CREATE TABLE time_source_choices (
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL,
    order_id TEXT NOT NULL,
    source TEXT NOT NULL CHECK (source IN ('time_tracking', 'daily_report')),
    chosen_by_user_id TEXT NOT NULL,
    chosen_at TEXT NOT NULL,
    PRIMARY KEY (employee_id, work_date, order_id),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (chosen_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
