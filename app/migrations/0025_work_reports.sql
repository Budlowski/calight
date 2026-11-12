-- Phase 3.4: Rapporte (Regieberichte).
--
-- Ein Rapport ist ein Leistungsnachweis für Regiearbeiten zu einem Auftrag.
-- Status: draft (Monteur erfasst) -> submitted (Leistungsnachweis verbindlich,
-- nummeriert und per SHA-256 festgeschrieben) -> processed (Büro hat die
-- Abrechnungspositionen abgeschlossen). Die kaufmännischen Positionen sind eine
-- eigene Ebene und verändern den Leistungsnachweis nie.
-- Eine Kundenbestätigung ist unabhängig vom Bearbeitungsstatus und immer an
-- die Prüfsumme des eingereichten Leistungsnachweises gebunden.
-- Korrekturen sind neue Rapporte mit correction_of_id; das Original bleibt.
CREATE TABLE work_reports (
    id TEXT PRIMARY KEY,
    order_id TEXT NOT NULL,
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
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (correction_of_id) REFERENCES work_reports(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (processed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (confirmation_recorded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK ((status = 'draft' AND report_number IS NULL AND submitted_at IS NULL AND submitted_sha256 IS NULL)
        OR (status IN ('submitted', 'processed') AND report_number IS NOT NULL AND submitted_at IS NOT NULL
            AND submitted_by_user_id IS NOT NULL AND submitted_sha256 IS NOT NULL)),
    CHECK ((status = 'processed') = (processed_at IS NOT NULL AND processed_by_user_id IS NOT NULL AND net_total IS NOT NULL)),
    -- Eine Bestätigung gilt nur für genau den eingereichten Leistungsstand.
    CHECK ((confirmation_status = 'none' AND confirmed_at IS NULL AND confirmation_sha256 IS NULL)
        OR (confirmation_status = 'confirmed' AND status <> 'draft' AND confirmed_at IS NOT NULL AND confirmed_by_name IS NOT NULL
            AND confirmation_method IS NOT NULL AND confirmation_sha256 = submitted_sha256))
);

CREATE INDEX idx_work_reports_order ON work_reports(order_id, status, work_date DESC);
CREATE INDEX idx_work_reports_updated ON work_reports(updated_at DESC);
CREATE INDEX idx_work_reports_status ON work_reports(status, updated_at DESC);

-- Leistungsnachweis: Stunden je Mitarbeiter (aus dem Mitarbeiterstamm oder frei benannt),
-- optional mit Lohnkostengruppe (Artikel vom Typ labor). Keine Preise.
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

CREATE INDEX idx_work_report_labor_report ON work_report_labor(report_id, sort_order);

-- Leistungsnachweis: Material und sonstige Leistungen als Freitext mit Menge.
CREATE TABLE work_report_materials (
    id TEXT PRIMARY KEY,
    report_id TEXT NOT NULL,
    quantity TEXT NOT NULL,
    unit TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL,
    sort_order INTEGER NOT NULL,
    FOREIGN KEY (report_id) REFERENCES work_reports(id) ON DELETE CASCADE
);

CREATE INDEX idx_work_report_materials_report ON work_report_materials(report_id, sort_order);

-- Kaufmännische Abrechnungspositionen (nur Büro/Admin). Bezeichnung, Einheit und
-- Verkaufspreis sind Snapshots; ein optionaler Stammdatenbezug dient nur der Herkunft.
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

CREATE INDEX idx_work_report_billing_report ON work_report_billing_items(report_id, sort_order);

INSERT INTO number_ranges
    (id, code, label, prefix, format, minimum_length, next_counter, reset_mode, manual_numbers_allowed, created_at, updated_at)
VALUES
    ('00000000-0000-4000-8000-000000000013', 'work_report', 'Rapporte', 'RP', '{K}{JJJJ}{MM}{NR}', 3, 1, 'none', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
