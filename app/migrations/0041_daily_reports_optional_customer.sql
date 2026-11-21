-- Tagesberichte (vormals Bautagesberichte): Kunde optional.
--
-- Ein Tagesbericht dokumentiert Tätigkeiten und tatsächliche Mitarbeiterstunden – für Kunden-
-- aufträge ebenso wie für interne Tätigkeiten. Kostenziel optional (Auftrag ODER Kostenstelle);
-- der Kunde ist optionale Kontextinformation (bei Auftrag immer der Auftragskunde).
--
-- 1. daily_reports.customer_id wird optional.
-- 2. material_requests.customer_id wird optional; neu optional cost_center_id (Kostenziel eines
--    internen Tagesberichts, nie zusammen mit order_id). Materialanforderungen bleiben reine
--    Bedarfsmeldungen ohne Kosten.
--
-- SQLite kann NOT NULL nicht entfernen; Neuaufbau nach dem Muster von 0026/0035. Bei aktiven
-- Fremdschlüsseln schreibt SQLite beim Umbenennen die Verweise aller Kindtabellen um – und
-- legacy_alter_table hilft dabei nicht, foreign_keys lässt sich in der Migrationstransaktion
-- nicht abschalten. Deshalb werden alle abhängigen Tabellen mit umbenannt, neu angelegt und
-- kopiert (Stundenpositionen, zentrale Zeitbuchungen, Anhänge, Zuordnungsprotokoll,
-- Materialanforderungen, Bestellverknüpfungen). Alle Daten und IDs bleiben unverändert; die
-- alten Kindtabellen werden vor den alten Elterntabellen entfernt. Sonst keine Schemaänderung.
PRAGMA defer_foreign_keys = ON;

ALTER TABLE daily_reports RENAME TO daily_reports_before_0041;
ALTER TABLE daily_report_time_entries RENAME TO daily_report_time_entries_before_0041;
ALTER TABLE daily_report_attachments RENAME TO daily_report_attachments_before_0041;
ALTER TABLE daily_report_order_events RENAME TO daily_report_order_events_before_0041;
ALTER TABLE material_requests RENAME TO material_requests_before_0041;
ALTER TABLE purchase_order_material_requests RENAME TO purchase_order_material_requests_before_0041;
ALTER TABLE time_entries RENAME TO time_entries_before_0041;

CREATE TABLE daily_reports (
    id TEXT PRIMARY KEY,
    customer_id TEXT,
    project_id TEXT,
    order_id TEXT,
    order_assigned_at TEXT,
    order_assigned_by_user_id TEXT,
    labor_counts_for_order INTEGER NOT NULL DEFAULT 0 CHECK (labor_counts_for_order IN (0, 1)),
    labor_assigned_at TEXT,
    labor_assigned_by_user_id TEXT,
    report_date TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    activities TEXT NOT NULL,
    notes TEXT NOT NULL DEFAULT '',
    created_by_user_id TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    cost_center_id TEXT REFERENCES cost_centers(id) ON DELETE RESTRICT
    CHECK (cost_center_id IS NULL OR order_id IS NULL),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (labor_assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (order_id IS NOT NULL OR labor_counts_for_order = 0),
    CHECK ((order_assigned_at IS NULL) = (order_assigned_by_user_id IS NULL)),
    CHECK ((labor_assigned_at IS NULL) = (labor_assigned_by_user_id IS NULL))
);
INSERT INTO daily_reports (id, customer_id, project_id, order_id, order_assigned_at, order_assigned_by_user_id, labor_counts_for_order, labor_assigned_at, labor_assigned_by_user_id, report_date, title, activities, notes, created_by_user_id, version, created_at, updated_at, cost_center_id)
SELECT id, customer_id, project_id, order_id, order_assigned_at, order_assigned_by_user_id, labor_counts_for_order, labor_assigned_at, labor_assigned_by_user_id, report_date, title, activities, notes, created_by_user_id, version, created_at, updated_at, cost_center_id FROM daily_reports_before_0041;

CREATE TABLE daily_report_time_entries (
    id TEXT PRIMARY KEY,
    report_id TEXT NOT NULL,
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL,
    duration_hours TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    target_kind TEXT NOT NULL DEFAULT 'order' CHECK (target_kind IN ('order','title','position')),
    target_basis_type TEXT CHECK (target_basis_type IN ('order','addendum')),
    target_basis_id TEXT,
    target_node_id TEXT,
    hourly_cost_rate_snapshot TEXT NOT NULL,
    labor_cost TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by_user_id TEXT REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    CHECK ((target_kind='order' AND target_basis_type IS NULL AND target_basis_id IS NULL AND target_node_id IS NULL)
        OR (target_kind IN ('title','position') AND target_basis_type IS NOT NULL AND target_basis_id IS NOT NULL AND target_node_id IS NOT NULL))
);
INSERT INTO daily_report_time_entries (id, report_id, employee_id, work_date, duration_hours, description, target_kind, target_basis_type, target_basis_id, target_node_id, hourly_cost_rate_snapshot, labor_cost, created_at, updated_at, updated_by_user_id)
SELECT id, report_id, employee_id, work_date, duration_hours, description, target_kind, target_basis_type, target_basis_id, target_node_id, hourly_cost_rate_snapshot, labor_cost, created_at, updated_at, updated_by_user_id FROM daily_report_time_entries_before_0041;

CREATE TABLE daily_report_attachments (
    id TEXT PRIMARY KEY,
    report_id TEXT NOT NULL,
    original_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL CHECK (size_bytes > 0),
    sha256 TEXT NOT NULL,
    storage_path TEXT NOT NULL UNIQUE,
    uploaded_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
INSERT INTO daily_report_attachments (id, report_id, original_name, mime_type, size_bytes, sha256, storage_path, uploaded_by_user_id, created_at)
SELECT id, report_id, original_name, mime_type, size_bytes, sha256, storage_path, uploaded_by_user_id, created_at FROM daily_report_attachments_before_0041;

CREATE TABLE daily_report_order_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id TEXT NOT NULL,
    previous_order_id TEXT,
    order_id TEXT,
    changed_by_user_id TEXT NOT NULL,
    changed_at TEXT NOT NULL,
    previous_cost_center_id TEXT REFERENCES cost_centers(id) ON DELETE RESTRICT,
    cost_center_id TEXT REFERENCES cost_centers(id) ON DELETE RESTRICT,
    FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (previous_order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
INSERT INTO daily_report_order_events (id, report_id, previous_order_id, order_id, changed_by_user_id, changed_at, previous_cost_center_id, cost_center_id)
SELECT id, report_id, previous_order_id, order_id, changed_by_user_id, changed_at, previous_cost_center_id, cost_center_id FROM daily_report_order_events_before_0041;

CREATE TABLE material_requests (
    id TEXT PRIMARY KEY,
    customer_id TEXT,
    order_id TEXT,
    cost_center_id TEXT,
    daily_report_id TEXT,
    description TEXT NOT NULL,
    notes TEXT NOT NULL DEFAULT '',
    needed_by TEXT,
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'done')),
    created_by_user_id TEXT NOT NULL,
    completed_by_user_id TEXT,
    completed_at TEXT,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE RESTRICT,
    CHECK (cost_center_id IS NULL OR order_id IS NULL),
    FOREIGN KEY (daily_report_id) REFERENCES daily_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK ((status = 'open' AND completed_at IS NULL AND completed_by_user_id IS NULL)
        OR (status = 'done' AND completed_at IS NOT NULL AND completed_by_user_id IS NOT NULL))
);
INSERT INTO material_requests (id, customer_id, order_id, daily_report_id, description, notes, needed_by, status, created_by_user_id, completed_by_user_id, completed_at, version, created_at, updated_at, cost_center_id)
SELECT id, customer_id, order_id, daily_report_id, description, notes, needed_by, status, created_by_user_id, completed_by_user_id, completed_at, version, created_at, updated_at, NULL FROM material_requests_before_0041;

CREATE TABLE purchase_order_material_requests (
    purchase_order_id TEXT NOT NULL,
    material_request_id TEXT NOT NULL,
    PRIMARY KEY (purchase_order_id, material_request_id),
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (material_request_id) REFERENCES material_requests(id) ON DELETE CASCADE
);
INSERT INTO purchase_order_material_requests (purchase_order_id, material_request_id)
SELECT purchase_order_id, material_request_id FROM purchase_order_material_requests_before_0041;

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
INSERT INTO time_entries (id, employee_id, work_date, source_kind, daily_report_entry_id, work_report_labor_id, start_time, end_time, ends_next_day, break_minutes, net_minutes, travel_minutes, assignment_kind, activity, order_id, notes, hourly_cost_rate_snapshot, version, created_by_user_id, created_at, updated_by_user_id, updated_at, cost_center_id)
SELECT id, employee_id, work_date, source_kind, daily_report_entry_id, work_report_labor_id, start_time, end_time, ends_next_day, break_minutes, net_minutes, travel_minutes, assignment_kind, activity, order_id, notes, hourly_cost_rate_snapshot, version, created_by_user_id, created_at, updated_by_user_id, updated_at, cost_center_id FROM time_entries_before_0041;

DROP TABLE time_entries_before_0041;
DROP TABLE purchase_order_material_requests_before_0041;
DROP TABLE daily_report_order_events_before_0041;
DROP TABLE daily_report_attachments_before_0041;
DROP TABLE daily_report_time_entries_before_0041;
DROP TABLE material_requests_before_0041;
DROP TABLE daily_reports_before_0041;

CREATE INDEX idx_daily_reports_cost_center ON daily_reports(cost_center_id, report_date DESC);
CREATE INDEX idx_daily_reports_customer ON daily_reports(customer_id, report_date DESC);
CREATE INDEX idx_daily_reports_date ON daily_reports(report_date DESC);
CREATE INDEX idx_daily_reports_order_date ON daily_reports(order_id, report_date DESC, created_at DESC);
CREATE INDEX idx_daily_reports_project ON daily_reports(project_id, report_date DESC);
CREATE INDEX idx_daily_reports_updated ON daily_reports(updated_at DESC);
CREATE INDEX idx_daily_report_entries_employee_date ON daily_report_time_entries(employee_id, work_date);
CREATE INDEX idx_daily_report_entries_report ON daily_report_time_entries(report_id, work_date, created_at);
CREATE INDEX idx_daily_report_attachments_report ON daily_report_attachments(report_id, created_at);
CREATE INDEX idx_daily_report_order_events_report ON daily_report_order_events(report_id, id);
CREATE INDEX idx_material_requests_customer ON material_requests(customer_id, status);
CREATE INDEX idx_material_requests_order ON material_requests(order_id, status);
CREATE INDEX idx_material_requests_report ON material_requests(daily_report_id);
CREATE INDEX idx_material_requests_status_created ON material_requests(status, created_at DESC);
CREATE INDEX idx_purchase_order_requests_request ON purchase_order_material_requests(material_request_id);
CREATE INDEX idx_time_entries_cost_center ON time_entries(cost_center_id, work_date);
CREATE INDEX idx_time_entries_employee_date ON time_entries(employee_id, work_date);
CREATE INDEX idx_time_entries_order ON time_entries(order_id, work_date);
CREATE INDEX idx_material_requests_cost_center ON material_requests(cost_center_id, status);
