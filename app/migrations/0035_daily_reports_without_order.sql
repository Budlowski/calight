-- Bautagesberichte ohne bestehenden Auftrag (vor Phase 3.4.4).
--
-- Ein Bautagesbericht gehört künftig direkt zu einem Kunden (Pflicht); Projekt und Auftrag sind
-- optional, auch dauerhaft. Bestehende Berichte übernehmen Kunde und Projekt ihres Auftrags.
--
-- Organisatorische Zuordnung und Kostenrelevanz der Stunden sind getrennt:
-- - order_assigned_at / order_assigned_by_user_id: nachträgliche Zuordnung durch das Büro.
-- - labor_counts_for_order: Stunden zählen als Ist-Stunden des Auftrags. Bestehende Berichte
--   (alle mit Auftrag) = 1, d. h. die Ist-Stundenlogik bleibt unverändert. Neue Berichte ohne
--   Auftrag = 0; eine spätere Zuordnung setzt den Wert nicht automatisch, sondern erst die
--   bewusste Übernahme durch das Büro (labor_assigned_at / labor_assigned_by_user_id).
--
-- Materialanforderungen gehören ebenfalls direkt zu einem Kunden; der Auftrag wird optional.
--
-- SQLite kann NOT NULL nicht entfernen, daher Neuaufbau nach dem Vorbild von 0026: Beim
-- Umbenennen schreibt SQLite die Verweise der Kindtabellen (Zeiten, Anhänge, Material-
-- anforderungen, Bestellverknüpfungen) auf den neuen Namen um. Deshalb werden auch diese
-- Tabellen neu aufgebaut und kopiert; die alten Kindtabellen werden VOR den alten Eltern-
-- tabellen entfernt. Die Fremdschlüsselprüfung erfolgt am Ende der Transaktion.
PRAGMA defer_foreign_keys = ON;

ALTER TABLE daily_reports RENAME TO daily_reports_before_customers;
ALTER TABLE daily_report_time_entries RENAME TO daily_report_time_entries_before_customers;
ALTER TABLE daily_report_attachments RENAME TO daily_report_attachments_before_customers;
ALTER TABLE material_requests RENAME TO material_requests_before_customers;
ALTER TABLE purchase_order_material_requests RENAME TO purchase_order_material_requests_before_customers;

CREATE TABLE daily_reports (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL,
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

INSERT INTO daily_reports (id, customer_id, project_id, order_id, labor_counts_for_order, report_date, title, activities, notes, created_by_user_id, version, created_at, updated_at)
SELECT r.id, o.customer_id, o.project_id, r.order_id, 1, r.report_date, r.title, r.activities, r.notes, r.created_by_user_id, r.version, r.created_at, r.updated_at
FROM daily_reports_before_customers r JOIN orders o ON o.id = r.order_id;

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
    FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    CHECK ((target_kind='order' AND target_basis_type IS NULL AND target_basis_id IS NULL AND target_node_id IS NULL)
        OR (target_kind IN ('title','position') AND target_basis_type IS NOT NULL AND target_basis_id IS NOT NULL AND target_node_id IS NOT NULL))
);

INSERT INTO daily_report_time_entries (id, report_id, employee_id, work_date, duration_hours, description, target_kind, target_basis_type, target_basis_id, target_node_id,
    hourly_cost_rate_snapshot, labor_cost, created_at, updated_at)
SELECT id, report_id, employee_id, work_date, duration_hours, description, target_kind, target_basis_type, target_basis_id, target_node_id,
    hourly_cost_rate_snapshot, labor_cost, created_at, updated_at
FROM daily_report_time_entries_before_customers;

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
SELECT id, report_id, original_name, mime_type, size_bytes, sha256, storage_path, uploaded_by_user_id, created_at
FROM daily_report_attachments_before_customers;

CREATE TABLE material_requests (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL,
    order_id TEXT,
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
    FOREIGN KEY (daily_report_id) REFERENCES daily_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK ((status = 'open' AND completed_at IS NULL AND completed_by_user_id IS NULL)
        OR (status = 'done' AND completed_at IS NOT NULL AND completed_by_user_id IS NOT NULL))
);

INSERT INTO material_requests (id, customer_id, order_id, daily_report_id, description, notes, needed_by, status, created_by_user_id, completed_by_user_id, completed_at,
    version, created_at, updated_at)
SELECT m.id, o.customer_id, m.order_id, m.daily_report_id, m.description, m.notes, m.needed_by, m.status, m.created_by_user_id, m.completed_by_user_id, m.completed_at,
    m.version, m.created_at, m.updated_at
FROM material_requests_before_customers m JOIN orders o ON o.id = m.order_id;

CREATE TABLE purchase_order_material_requests (
    purchase_order_id TEXT NOT NULL,
    material_request_id TEXT NOT NULL,
    PRIMARY KEY (purchase_order_id, material_request_id),
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (material_request_id) REFERENCES material_requests(id) ON DELETE CASCADE
);

INSERT INTO purchase_order_material_requests (purchase_order_id, material_request_id)
SELECT purchase_order_id, material_request_id FROM purchase_order_material_requests_before_customers;

-- Alte Kindtabellen zuerst entfernen, dann die alten Elterntabellen (keine Kaskaden auf neue Zeilen).
DROP TABLE purchase_order_material_requests_before_customers;
DROP TABLE daily_report_time_entries_before_customers;
DROP TABLE daily_report_attachments_before_customers;
DROP TABLE material_requests_before_customers;
DROP TABLE daily_reports_before_customers;

CREATE INDEX idx_daily_reports_order_date ON daily_reports(order_id, report_date DESC, created_at DESC);
CREATE INDEX idx_daily_reports_date ON daily_reports(report_date DESC);
CREATE INDEX idx_daily_reports_updated ON daily_reports(updated_at DESC);
CREATE INDEX idx_daily_reports_customer ON daily_reports(customer_id, report_date DESC);
CREATE INDEX idx_daily_reports_project ON daily_reports(project_id, report_date DESC);
CREATE INDEX idx_daily_report_entries_report ON daily_report_time_entries(report_id, work_date, created_at);
CREATE INDEX idx_daily_report_entries_employee_date ON daily_report_time_entries(employee_id, work_date);
CREATE INDEX idx_daily_report_attachments_report ON daily_report_attachments(report_id, created_at);
CREATE INDEX idx_material_requests_status_created ON material_requests(status, created_at DESC);
CREATE INDEX idx_material_requests_order ON material_requests(order_id, status);
CREATE INDEX idx_material_requests_report ON material_requests(daily_report_id);
CREATE INDEX idx_material_requests_customer ON material_requests(customer_id, status);
CREATE INDEX idx_purchase_order_requests_request ON purchase_order_material_requests(material_request_id);
