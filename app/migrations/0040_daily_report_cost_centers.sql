-- Bautagesberichte mit gemeinsamer Zuordnung „Auftrag / Kostenstelle“.
--
-- Ein Bautagesbericht hat höchstens ein Kostenziel: Kundenauftrag ODER allgemeine Kostenstelle
-- (oder keines). Der Kunde bleibt Pflicht und ist kein Kostenziel; ein Bericht beim Kunden kann
-- z. B. auf K110 Vertrieb & Angebote laufen. Die zentralen Zeitbuchungen übernehmen das
-- Kostenziel des Berichts. Rapporte bleiben ausschließlich auftragsbezogen.
--
-- 1. daily_reports.cost_center_id (optional, nie zusammen mit order_id).
-- 2. Protokoll daily_report_order_events um die Kostenstelle erweitern (eine Historie für beide
--    Zielarten, keine zweite Tabelle).
-- 3. time_entries: Die Regel aus 0039 erlaubte Kostenstellen nur bei manuellen Buchungen. Sie
--    ist als Spaltenbedingung gespeichert und lässt sich in SQLite nur per Neuaufbau ändern.
--    Keine Tabelle verweist auf time_entries; alle Daten werden unverändert kopiert.
PRAGMA defer_foreign_keys = ON;

ALTER TABLE daily_reports ADD COLUMN cost_center_id TEXT REFERENCES cost_centers(id) ON DELETE RESTRICT
    CHECK (cost_center_id IS NULL OR order_id IS NULL);
CREATE INDEX idx_daily_reports_cost_center ON daily_reports(cost_center_id, report_date DESC);

ALTER TABLE daily_report_order_events ADD COLUMN previous_cost_center_id TEXT REFERENCES cost_centers(id) ON DELETE RESTRICT;
ALTER TABLE daily_report_order_events ADD COLUMN cost_center_id TEXT REFERENCES cost_centers(id) ON DELETE RESTRICT;

ALTER TABLE time_entries RENAME TO time_entries_before_daily_cost_centers;

CREATE TABLE time_entries (
    id TEXT PRIMARY KEY,
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL,
    source_kind TEXT NOT NULL DEFAULT 'manual' CHECK (source_kind IN ('manual', 'daily_report', 'work_report')),
    daily_report_entry_id TEXT UNIQUE,
    work_report_labor_id TEXT UNIQUE,
    start_time TEXT CHECK (start_time IS NULL OR length(start_time) = 5),
    end_time TEXT CHECK (end_time IS NULL OR length(end_time) = 5),
    ends_next_day INTEGER NOT NULL DEFAULT 0 CHECK (ends_next_day IN (0, 1)),
    break_minutes INTEGER NOT NULL DEFAULT 0 CHECK (break_minutes >= 0),
    net_minutes INTEGER NOT NULL CHECK (net_minutes > 0),
    travel_minutes INTEGER NOT NULL DEFAULT 0 CHECK (travel_minutes >= 0 AND travel_minutes <= net_minutes),
    assignment_kind TEXT CHECK (assignment_kind IS NULL OR assignment_kind IN ('regular', 'external')),
    activity TEXT NOT NULL CHECK (activity IN ('work', 'travel', 'preparation', 'internal', 'other')),
    order_id TEXT,
    notes TEXT NOT NULL DEFAULT '',
    hourly_cost_rate_snapshot TEXT,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_by_user_id TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    cost_center_id TEXT,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (daily_report_entry_id) REFERENCES daily_report_time_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (work_report_labor_id) REFERENCES work_report_labor(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    -- Genau eine Herkunft.
    CHECK ((source_kind = 'manual' AND daily_report_entry_id IS NULL AND work_report_labor_id IS NULL)
        OR (source_kind = 'daily_report' AND daily_report_entry_id IS NOT NULL AND work_report_labor_id IS NULL)
        OR (source_kind = 'work_report' AND work_report_labor_id IS NOT NULL AND daily_report_entry_id IS NULL)),
    -- Höchstens ein Kostenziel; Rapportstunden nie auf Kostenstellen.
    CHECK (cost_center_id IS NULL OR (order_id IS NULL AND source_kind IN ('manual', 'daily_report'))),
    -- Uhrzeiten nur paarweise; ohne Uhrzeiten keine Pause und kein Folgetag.
    CHECK ((start_time IS NULL) = (end_time IS NULL)),
    CHECK (start_time IS NOT NULL OR (break_minutes = 0 AND ends_next_day = 0)),
    -- Mit Uhrzeiten entspricht die Nettozeit exakt Ende − Beginn − Pause.
    CHECK (start_time IS NULL OR net_minutes + break_minutes =
        ((CAST(substr(end_time, 1, 2) AS INTEGER) * 60 + CAST(substr(end_time, 4, 2) AS INTEGER)) + ends_next_day * 1440)
        - (CAST(substr(start_time, 1, 2) AS INTEGER) * 60 + CAST(substr(start_time, 4, 2) AS INTEGER)))
);

INSERT INTO time_entries (id, employee_id, work_date, source_kind, daily_report_entry_id, work_report_labor_id, start_time, end_time, ends_next_day, break_minutes,
    net_minutes, travel_minutes, assignment_kind, activity, order_id, notes, hourly_cost_rate_snapshot, version, created_by_user_id, created_at, updated_by_user_id, updated_at, cost_center_id)
SELECT id, employee_id, work_date, source_kind, daily_report_entry_id, work_report_labor_id, start_time, end_time, ends_next_day, break_minutes,
    net_minutes, travel_minutes, assignment_kind, activity, order_id, notes, hourly_cost_rate_snapshot, version, created_by_user_id, created_at, updated_by_user_id, updated_at, cost_center_id
FROM time_entries_before_daily_cost_centers;

DROP TABLE time_entries_before_daily_cost_centers;
CREATE INDEX idx_time_entries_employee_date ON time_entries(employee_id, work_date);
CREATE INDEX idx_time_entries_order ON time_entries(order_id, work_date);
CREATE INDEX idx_time_entries_cost_center ON time_entries(cost_center_id, work_date);
