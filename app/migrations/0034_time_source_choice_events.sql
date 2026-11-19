-- Zeiterfassung (Phase 3.4.3, Abschluss) – rein additiv.
--
-- Nachvollziehbarkeit der maßgeblichen Stundenquelle: time_source_choices hält nur die aktuelle
-- Wahl. Jede Änderung (setzen, wechseln, aufheben) wird hier unveränderlich protokolliert.
-- Bestehende Auswahlen werden als erstes Ereignis übernommen.

CREATE TABLE time_source_choice_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL,
    order_id TEXT NOT NULL,
    previous_source TEXT CHECK (previous_source IS NULL OR previous_source IN ('time_tracking', 'daily_report')),
    source TEXT CHECK (source IS NULL OR source IN ('time_tracking', 'daily_report')),
    changed_by_user_id TEXT NOT NULL,
    changed_at TEXT NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE INDEX time_source_choice_events_key ON time_source_choice_events (employee_id, work_date, order_id);

INSERT INTO time_source_choice_events (employee_id, work_date, order_id, previous_source, source, changed_by_user_id, changed_at)
SELECT employee_id, work_date, order_id, NULL, source, chosen_by_user_id, chosen_at FROM time_source_choices ORDER BY chosen_at;
