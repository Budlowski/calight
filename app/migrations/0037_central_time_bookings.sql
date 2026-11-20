-- Zentrale Stundenbasis für Rapporte, Bautagesberichte und Zeiterfassung.
--
-- Grundsatz: time_entries ist die einzige maßgebliche Basis tatsächlich geleisteter
-- Mitarbeiterstunden. Jede Buchung hat genau eine Herkunft (source_kind):
--   manual        – direkt in der Zeiterfassung erfasst (wie bisher),
--   daily_report  – genau eine Stundenposition eines Bautagesberichts (daily_report_entry_id, UNIQUE),
--   work_report   – genau eine Stundenposition eines Rapports (work_report_labor_id, UNIQUE).
-- Bei Berichtsbuchungen stammt die Dauer (net_minutes) aus dem Bericht; Beginn, Ende, Pause,
-- Reisezeit und Einsatzart sind ergänzende Angaben und dürfen fehlen (start_time/end_time NULL).
-- Die Buchungen werden von den Fachdiensten in derselben Transaktion gepflegt
-- (ReportTimeBookings); beim Löschen der Berichtsposition entfällt die Buchung (CASCADE).
--
-- Korrekturrapporte: work_reports.superseded_by_id kennzeichnet ersetzte Fassungen. Nur die
-- gültige Fassung einer Kette hat Buchungen.
--
-- Altdaten werden nicht stillschweigend umgedeutet (legacy_state):
--   NULL      – zählt,
--   excluded  – zählt nicht (bestehende Entscheidung, z. B. frühere Quellenwahl),
--   review    – zählt (noch) nicht, einmalige Klärung durch das Büro erforderlich.
-- legacy_reason nennt den Grund. time_source_choices bleibt als Historie erhalten, steuert
-- aber keine neuen Buchungen mehr.
PRAGMA defer_foreign_keys = ON;

-- 1. Betriebseinstellungen (klein, erweiterbar; Werte als Text, Typ im Code validiert).
CREATE TABLE operational_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by_user_id TEXT,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
INSERT INTO operational_settings (setting_key, setting_value, updated_by_user_id, updated_at)
VALUES ('time_tracking.times_optional', '1', NULL, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));

-- 2. Gültigkeit von Rapportfassungen.
ALTER TABLE work_reports ADD COLUMN superseded_by_id TEXT REFERENCES work_reports(id) ON DELETE RESTRICT;
ALTER TABLE work_reports ADD COLUMN superseded_at TEXT;
CREATE INDEX idx_work_reports_superseded ON work_reports(superseded_by_id);
CREATE INDEX idx_work_reports_correction_of ON work_reports(correction_of_id, status);

-- Bestehende Korrekturketten: Eine eingereichte Korrektur ersetzt ihren Vorgänger. Gibt es zu
-- einem Rapport mehrere eingereichte Korrekturen, ersetzt ihn die zuletzt eingereichte; die
-- übrigen Korrekturen bleiben formal gültig, ihre Stunden werden aber zur Klärung markiert (s. u.).
UPDATE work_reports SET
    superseded_by_id = (SELECT c.id FROM work_reports c WHERE c.correction_of_id = work_reports.id AND c.status <> 'draft' ORDER BY c.submitted_at DESC, c.id DESC LIMIT 1),
    superseded_at = (SELECT c.submitted_at FROM work_reports c WHERE c.correction_of_id = work_reports.id AND c.status <> 'draft' ORDER BY c.submitted_at DESC, c.id DESC LIMIT 1)
WHERE EXISTS (SELECT 1 FROM work_reports c WHERE c.correction_of_id = work_reports.id AND c.status <> 'draft');

-- 3. Nachvollziehbarkeit von Stundenkorrekturen und Auftragszuordnungen bei Bautagesberichten.
ALTER TABLE daily_report_time_entries ADD COLUMN updated_by_user_id TEXT REFERENCES users(id) ON DELETE RESTRICT;
CREATE TABLE daily_report_order_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id TEXT NOT NULL,
    previous_order_id TEXT,
    order_id TEXT,
    changed_by_user_id TEXT NOT NULL,
    changed_at TEXT NOT NULL,
    FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (previous_order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_daily_report_order_events_report ON daily_report_order_events(report_id, id);
INSERT INTO daily_report_order_events (report_id, previous_order_id, order_id, changed_by_user_id, changed_at)
SELECT id, NULL, order_id, order_assigned_by_user_id, order_assigned_at FROM daily_reports WHERE order_assigned_at IS NOT NULL;

-- 4. time_entries neu aufbauen (Uhrzeiten optional, Herkunft, Altfallstatus). Keine Tabelle
--    verweist auf time_entries; die Alttabelle kann nach dem Kopieren entfernt werden.
ALTER TABLE time_entries RENAME TO time_entries_before_central;

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
    legacy_state TEXT CHECK (legacy_state IS NULL OR legacy_state IN ('excluded', 'review')),
    legacy_reason TEXT,
    legacy_resolved_by_user_id TEXT,
    legacy_resolved_at TEXT,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_by_user_id TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (daily_report_entry_id) REFERENCES daily_report_time_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (work_report_labor_id) REFERENCES work_report_labor(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (legacy_resolved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    -- Genau eine Herkunft.
    CHECK ((source_kind = 'manual' AND daily_report_entry_id IS NULL AND work_report_labor_id IS NULL)
        OR (source_kind = 'daily_report' AND daily_report_entry_id IS NOT NULL AND work_report_labor_id IS NULL)
        OR (source_kind = 'work_report' AND work_report_labor_id IS NOT NULL AND daily_report_entry_id IS NULL)),
    -- Uhrzeiten nur paarweise; ohne Uhrzeiten keine Pause und kein Folgetag.
    CHECK ((start_time IS NULL) = (end_time IS NULL)),
    CHECK (start_time IS NOT NULL OR (break_minutes = 0 AND ends_next_day = 0)),
    -- Mit Uhrzeiten entspricht die Nettozeit exakt Ende − Beginn − Pause.
    CHECK (start_time IS NULL OR net_minutes + break_minutes =
        ((CAST(substr(end_time, 1, 2) AS INTEGER) * 60 + CAST(substr(end_time, 4, 2) AS INTEGER)) + ends_next_day * 1440)
        - (CAST(substr(start_time, 1, 2) AS INTEGER) * 60 + CAST(substr(start_time, 4, 2) AS INTEGER))),
    CHECK ((legacy_state IS NULL) = (legacy_reason IS NULL) OR legacy_resolved_at IS NOT NULL)
);

INSERT INTO time_entries (id, employee_id, work_date, source_kind, start_time, end_time, ends_next_day, break_minutes, net_minutes, travel_minutes, assignment_kind,
    activity, order_id, notes, hourly_cost_rate_snapshot, version, created_by_user_id, created_at, updated_by_user_id, updated_at)
SELECT id, employee_id, work_date, 'manual', start_time, end_time, ends_next_day, break_minutes, net_minutes, travel_minutes, assignment_kind,
    activity, order_id, notes, hourly_cost_rate_snapshot, version, created_by_user_id, created_at, updated_by_user_id, updated_at
FROM time_entries_before_central;

DROP TABLE time_entries_before_central;
CREATE INDEX idx_time_entries_employee_date ON time_entries(employee_id, work_date);
CREATE INDEX idx_time_entries_order ON time_entries(order_id, work_date);
CREATE INDEX idx_time_entries_legacy ON time_entries(legacy_state);

-- 5. Buchungen aus Bautagesberichten (je Stundenposition genau eine).
--    Minuten = Stunden × 60, kaufmännisch gerundet (neue Erfassung verlangt ganze Minuten).
--    Altfallstatus:
--    - Bericht mit Auftrag, Stunden bisher nicht übernommen (labor_counts_for_order = 0)
--      → review 'order_hours_not_taken_over' (keine stille Kostenumbuchung);
--    - frühere Quellenwahl „Zeiterfassung maßgeblich“ → excluded 'source_choice_time_tracking';
--    - Zeitblöcke und Bericht ohne Quellenwahl (früherer Konflikt) → review 'source_conflict';
--    - Rundung auf Minuten ≠ dokumentierte Stunden → review 'rounded_duration';
--    - sonst zählt die Buchung (wie bisher die Berichtsstunden).
INSERT INTO time_entries (id, employee_id, work_date, source_kind, daily_report_entry_id, net_minutes, activity, order_id, notes, hourly_cost_rate_snapshot,
    legacy_state, legacy_reason, created_by_user_id, created_at, updated_by_user_id, updated_at)
SELECT lower(hex(randomblob(4))) || '-' || lower(hex(randomblob(2))) || '-4' || substr(lower(hex(randomblob(2))), 2) || '-' || substr('89ab', 1 + (abs(random()) % 4), 1) || substr(lower(hex(randomblob(2))), 2) || '-' || lower(hex(randomblob(6))),
    e.employee_id, e.work_date, 'daily_report', e.id,
    MAX(1, CAST(ROUND(CAST(e.duration_hours AS REAL) * 60) AS INTEGER)), 'work', r.order_id, '', e.hourly_cost_rate_snapshot,
    CASE
        WHEN r.order_id IS NOT NULL AND r.labor_counts_for_order = 0 THEN 'review'
        WHEN r.order_id IS NOT NULL AND EXISTS (SELECT 1 FROM time_source_choices c WHERE c.employee_id = e.employee_id AND c.work_date = e.work_date AND c.order_id = r.order_id AND c.source = 'time_tracking') THEN 'excluded'
        WHEN r.order_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM time_source_choices c WHERE c.employee_id = e.employee_id AND c.work_date = e.work_date AND c.order_id = r.order_id)
            AND EXISTS (SELECT 1 FROM time_entries t WHERE t.source_kind = 'manual' AND t.employee_id = e.employee_id AND t.work_date = e.work_date AND t.order_id = r.order_id) THEN 'review'
        WHEN abs(CAST(e.duration_hours AS REAL) * 60 - ROUND(CAST(e.duration_hours AS REAL) * 60)) > 0.0001 OR CAST(e.duration_hours AS REAL) * 60 < 0.5 THEN 'review'
        ELSE NULL
    END,
    CASE
        WHEN r.order_id IS NOT NULL AND r.labor_counts_for_order = 0 THEN 'order_hours_not_taken_over'
        WHEN r.order_id IS NOT NULL AND EXISTS (SELECT 1 FROM time_source_choices c WHERE c.employee_id = e.employee_id AND c.work_date = e.work_date AND c.order_id = r.order_id AND c.source = 'time_tracking') THEN 'source_choice_time_tracking'
        WHEN r.order_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM time_source_choices c WHERE c.employee_id = e.employee_id AND c.work_date = e.work_date AND c.order_id = r.order_id)
            AND EXISTS (SELECT 1 FROM time_entries t WHERE t.source_kind = 'manual' AND t.employee_id = e.employee_id AND t.work_date = e.work_date AND t.order_id = r.order_id) THEN 'source_conflict'
        WHEN abs(CAST(e.duration_hours AS REAL) * 60 - ROUND(CAST(e.duration_hours AS REAL) * 60)) > 0.0001 OR CAST(e.duration_hours AS REAL) * 60 < 0.5 THEN 'rounded_duration'
        ELSE NULL
    END,
    r.created_by_user_id, e.created_at, r.created_by_user_id, e.updated_at
FROM daily_report_time_entries e JOIN daily_reports r ON r.id = e.report_id
WHERE NOT EXISTS (SELECT 1 FROM time_entries t WHERE t.daily_report_entry_id = e.id);

-- Frühere Quellenwahl „Bautagesbericht maßgeblich“: Zeitblöcke desselben Mitarbeiters, Tages und
-- Auftrags zählen nicht mehr (wie bisher), bleiben aber erhalten.
UPDATE time_entries SET legacy_state = 'excluded', legacy_reason = 'source_choice_daily_report'
WHERE source_kind = 'manual' AND legacy_state IS NULL AND order_id IS NOT NULL
  AND EXISTS (SELECT 1 FROM time_source_choices c WHERE c.employee_id = time_entries.employee_id AND c.work_date = time_entries.work_date
      AND c.order_id = time_entries.order_id AND c.source = 'daily_report');

-- 6. Buchungen aus Rapporten (nur Positionen mit Mitarbeiter, nur gültige Fassungen bzw.
--    Entwürfe ohne Korrekturbezug). Rapportstunden zählten bisher nie als Ist-Stunden; bei
--    eingereichten Rapporten ist deshalb offen, ob dieselbe Arbeit zusätzlich erfasst wurde
--    → review 'historic_work_report'. Entwürfe zählen wie neue Rapporte.
--    Weitere eingereichte Korrekturen desselben Vorgängers → review 'correction_branch'.
INSERT INTO time_entries (id, employee_id, work_date, source_kind, work_report_labor_id, net_minutes, activity, order_id, notes, hourly_cost_rate_snapshot,
    legacy_state, legacy_reason, created_by_user_id, created_at, updated_by_user_id, updated_at)
SELECT lower(hex(randomblob(4))) || '-' || lower(hex(randomblob(2))) || '-4' || substr(lower(hex(randomblob(2))), 2) || '-' || substr('89ab', 1 + (abs(random()) % 4), 1) || substr(lower(hex(randomblob(2))), 2) || '-' || lower(hex(randomblob(6))),
    l.employee_id, COALESCE(l.work_date, w.work_date), 'work_report', l.id,
    MAX(1, CAST(ROUND(CAST(l.hours AS REAL) * 60) AS INTEGER)), 'work', w.order_id, '', m.hourly_cost_rate,
    CASE WHEN w.status <> 'draft' THEN 'review'
         WHEN abs(CAST(l.hours AS REAL) * 60 - ROUND(CAST(l.hours AS REAL) * 60)) > 0.0001 OR CAST(l.hours AS REAL) * 60 < 0.5 THEN 'review' ELSE NULL END,
    CASE WHEN w.status <> 'draft' AND w.correction_of_id IS NOT NULL
              AND EXISTS (SELECT 1 FROM work_reports o WHERE o.id = w.correction_of_id AND o.superseded_by_id IS NOT NULL AND o.superseded_by_id <> w.id) THEN 'correction_branch'
         WHEN w.status <> 'draft' THEN 'historic_work_report'
         WHEN abs(CAST(l.hours AS REAL) * 60 - ROUND(CAST(l.hours AS REAL) * 60)) > 0.0001 OR CAST(l.hours AS REAL) * 60 < 0.5 THEN 'rounded_duration' ELSE NULL END,
    w.created_by_user_id, w.created_at, w.created_by_user_id, w.updated_at
FROM work_report_labor l JOIN work_reports w ON w.id = l.report_id JOIN employees m ON m.id = l.employee_id
WHERE l.employee_id IS NOT NULL
  AND w.superseded_by_id IS NULL
  AND (w.status <> 'draft' OR w.correction_of_id IS NULL)
  AND NOT EXISTS (SELECT 1 FROM time_entries t WHERE t.work_report_labor_id = l.id);
