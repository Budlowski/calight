-- Nachbesserung Phase 3.4: Rapporte ohne Auftrag.
--
-- Ein Rapport gehört künftig direkt zu einem Kunden (Pflicht); der Auftrag ist
-- optional und kann vom Büro nachträglich zugeordnet werden (order_assigned_*).
-- Bestehende Rapporte übernehmen den Kunden ihres Auftrags; Nummern, Status,
-- Prüfsummen, Bestätigungen, Korrekturen und alle Kindzeilen bleiben erhalten.
--
-- SQLite kann NOT NULL nicht entfernen, daher Neuaufbau. Achtung: Bei aktiven
-- Fremdschlüsseln schreibt SQLite beim Umbenennen die Verweise der Kindtabellen
-- (Stunden, Material, Abrechnung; ON DELETE CASCADE) auf den neuen Namen um.
-- Würde die umbenannte Alttabelle gelöscht, gingen alle Kindzeilen verloren.
-- Deshalb werden auch die Kindtabellen neu aufgebaut und kopiert; die alten
-- Kindtabellen werden VOR der alten Rapporttabelle entfernt, sodass beim
-- Löschen nichts mehr auf sie verweist. Die Fremdschlüsselprüfung erfolgt am
-- Ende der Transaktion (defer_foreign_keys).
PRAGMA defer_foreign_keys = ON;

ALTER TABLE work_reports RENAME TO work_reports_before_customers;
ALTER TABLE work_report_labor RENAME TO work_report_labor_before_customers;
ALTER TABLE work_report_materials RENAME TO work_report_materials_before_customers;
ALTER TABLE work_report_billing_items RENAME TO work_report_billing_items_before_customers;

CREATE TABLE work_reports (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL,
    order_id TEXT,
    order_assigned_at TEXT,
    order_assigned_by_user_id TEXT,
    report_number TEXT UNIQUE,
    work_date TEXT NOT NULL,
    work_date_to TEXT,
    contact_person TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'processed')),
    correction_of_id TEXT,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    submitted_by_user_id TEXT,
    submitted_at TEXT,
    submitted_sha256 TEXT,
    processed_by_user_id TEXT,
    processed_at TEXT,
    net_total TEXT,
    confirmation_status TEXT NOT NULL DEFAULT 'none' CHECK (confirmation_status IN ('none', 'confirmed')),
    confirmation_method TEXT CHECK (confirmation_method IN ('on_site', 'later')),
    confirmed_by_name TEXT,
    confirmed_at TEXT,
    confirmation_recorded_by_user_id TEXT,
    confirmation_sha256 TEXT,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (correction_of_id) REFERENCES work_reports(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (processed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (confirmation_recorded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK ((status = 'draft' AND report_number IS NULL AND submitted_at IS NULL AND submitted_sha256 IS NULL)
        OR (status IN ('submitted', 'processed') AND report_number IS NOT NULL AND submitted_at IS NOT NULL
            AND submitted_by_user_id IS NOT NULL AND submitted_sha256 IS NOT NULL)),
    CHECK ((status = 'processed') = (processed_at IS NOT NULL AND processed_by_user_id IS NOT NULL AND net_total IS NOT NULL)),
    CHECK ((confirmation_status = 'none' AND confirmed_at IS NULL AND confirmation_sha256 IS NULL)
        OR (confirmation_status = 'confirmed' AND status <> 'draft' AND confirmed_at IS NOT NULL AND confirmed_by_name IS NOT NULL
            AND confirmation_method IS NOT NULL AND confirmation_sha256 = submitted_sha256)),
    -- Eine nachträgliche Zuordnung ist nur zusammen mit Auftrag, Zeitpunkt und Benutzer gültig.
    CHECK ((order_assigned_at IS NULL AND order_assigned_by_user_id IS NULL)
        OR (order_id IS NOT NULL AND order_assigned_at IS NOT NULL AND order_assigned_by_user_id IS NOT NULL))
);

INSERT INTO work_reports
    (id, customer_id, order_id, report_number, work_date, work_date_to, contact_person, description, notes, status,
     correction_of_id, version, created_by_user_id, created_at, updated_at, submitted_by_user_id, submitted_at, submitted_sha256,
     processed_by_user_id, processed_at, net_total, confirmation_status, confirmation_method, confirmed_by_name, confirmed_at,
     confirmation_recorded_by_user_id, confirmation_sha256)
SELECT w.id, o.customer_id, w.order_id, w.report_number, w.work_date, w.work_date_to, w.contact_person, w.description, w.notes, w.status,
       w.correction_of_id, w.version, w.created_by_user_id, w.created_at, w.updated_at, w.submitted_by_user_id, w.submitted_at, w.submitted_sha256,
       w.processed_by_user_id, w.processed_at, w.net_total, w.confirmation_status, w.confirmation_method, w.confirmed_by_name, w.confirmed_at,
       w.confirmation_recorded_by_user_id, w.confirmation_sha256
FROM work_reports_before_customers w JOIN orders o ON o.id = w.order_id;

CREATE TABLE work_report_labor (
    id TEXT PRIMARY KEY,
    report_id TEXT NOT NULL,
    employee_id TEXT,
    worker_name TEXT NOT NULL,
    hours TEXT NOT NULL,
    labor_item_id TEXT,
    labor_item_name TEXT NOT NULL DEFAULT '',
    activity TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL,
    FOREIGN KEY (report_id) REFERENCES work_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (labor_item_id) REFERENCES cost_items(id) ON DELETE SET NULL
);
INSERT INTO work_report_labor (id, report_id, employee_id, worker_name, hours, labor_item_id, labor_item_name, activity, sort_order)
SELECT id, report_id, employee_id, worker_name, hours, labor_item_id, labor_item_name, activity, sort_order FROM work_report_labor_before_customers;

CREATE TABLE work_report_materials (
    id TEXT PRIMARY KEY,
    report_id TEXT NOT NULL,
    quantity TEXT NOT NULL,
    unit TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL,
    sort_order INTEGER NOT NULL,
    FOREIGN KEY (report_id) REFERENCES work_reports(id) ON DELETE CASCADE
);
INSERT INTO work_report_materials (id, report_id, quantity, unit, description, sort_order)
SELECT id, report_id, quantity, unit, description, sort_order FROM work_report_materials_before_customers;

CREATE TABLE work_report_billing_items (
    id TEXT PRIMARY KEY,
    report_id TEXT NOT NULL,
    item_type TEXT NOT NULL CHECK (item_type IN ('labor', 'material', 'travel', 'external', 'flat', 'other')),
    description TEXT NOT NULL,
    quantity TEXT NOT NULL,
    unit TEXT NOT NULL DEFAULT '',
    unit_price TEXT NOT NULL,
    line_total TEXT NOT NULL,
    cost_item_id TEXT,
    sort_order INTEGER NOT NULL,
    FOREIGN KEY (report_id) REFERENCES work_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (cost_item_id) REFERENCES cost_items(id) ON DELETE SET NULL
);
INSERT INTO work_report_billing_items (id, report_id, item_type, description, quantity, unit, unit_price, line_total, cost_item_id, sort_order)
SELECT id, report_id, item_type, description, quantity, unit, unit_price, line_total, cost_item_id, sort_order FROM work_report_billing_items_before_customers;

-- Alte Kindtabellen zuerst entfernen; danach verweist nichts mehr auf die alte Rapporttabelle.
DROP TABLE work_report_labor_before_customers;
DROP TABLE work_report_materials_before_customers;
DROP TABLE work_report_billing_items_before_customers;
-- Selbstverweise der Korrekturen innerhalb der Alttabelle lösen (RESTRICT beim impliziten Löschen).
UPDATE work_reports_before_customers SET correction_of_id = NULL;
DROP TABLE work_reports_before_customers;

CREATE INDEX idx_work_report_labor_report ON work_report_labor(report_id, sort_order);
CREATE INDEX idx_work_report_materials_report ON work_report_materials(report_id, sort_order);
CREATE INDEX idx_work_report_billing_report ON work_report_billing_items(report_id, sort_order);
CREATE INDEX idx_work_reports_customer ON work_reports(customer_id, work_date DESC);
CREATE INDEX idx_work_reports_order ON work_reports(order_id, status, work_date DESC);
CREATE INDEX idx_work_reports_updated ON work_reports(updated_at DESC);
CREATE INDEX idx_work_reports_status ON work_reports(status, updated_at DESC);
CREATE INDEX idx_work_reports_creator ON work_reports(created_by_user_id, updated_at DESC);
