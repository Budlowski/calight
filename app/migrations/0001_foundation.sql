CREATE TABLE users (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    display_name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin' CHECK (role IN ('admin', 'office', 'employee')),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE projects (
    id TEXT PRIMARY KEY,
    project_number TEXT UNIQUE,
    name TEXT NOT NULL,
    customer_name TEXT NOT NULL DEFAULT '',
    site_address TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'completed', 'archived')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE offers (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    offer_number TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'commissioned', 'rejected')),
    draft_relative_path TEXT NOT NULL,
    draft_etag TEXT NOT NULL,
    draft_version INTEGER NOT NULL DEFAULT 1 CHECK (draft_version > 0),
    latest_revision INTEGER NOT NULL DEFAULT 0 CHECK (latest_revision >= 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    UNIQUE (offer_number)
);

CREATE INDEX idx_offers_project ON offers(project_id);

-- Die Tabellen werden in Phase 1 fachlich verwendet. Ihre Struktur verhindert bereits,
-- dass verwendete Geschaeftsnummern durch Archivierung erneut frei werden.
CREATE TABLE number_ranges (
    id TEXT PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    prefix TEXT NOT NULL,
    format TEXT NOT NULL,
    minimum_length INTEGER NOT NULL DEFAULT 3 CHECK (minimum_length BETWEEN 1 AND 12),
    next_counter INTEGER NOT NULL DEFAULT 1 CHECK (next_counter > 0),
    reset_mode TEXT NOT NULL DEFAULT 'none' CHECK (reset_mode IN ('none', 'yearly', 'monthly')),
    counter_period TEXT,
    manual_numbers_allowed INTEGER NOT NULL DEFAULT 1 CHECK (manual_numbers_allowed IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE assigned_numbers (
    id TEXT PRIMARY KEY,
    number_range_id TEXT NOT NULL,
    business_number TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    counter_value INTEGER,
    assigned_manually INTEGER NOT NULL DEFAULT 0 CHECK (assigned_manually IN (0, 1)),
    assigned_at TEXT NOT NULL,
    FOREIGN KEY (number_range_id) REFERENCES number_ranges(id) ON DELETE RESTRICT,
    UNIQUE (number_range_id, business_number),
    UNIQUE (entity_type, entity_id)
);

INSERT INTO number_ranges
    (id, code, label, prefix, format, minimum_length, next_counter, reset_mode, manual_numbers_allowed, created_at, updated_at)
VALUES
    ('00000000-0000-4000-8000-000000000001', 'offer', 'Angebote', 'AG', '{K}{JJJJ}{MM}{NR}', 3, 1, 'none', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('00000000-0000-4000-8000-000000000002', 'order_confirmation', 'Auftragsbestätigungen', 'AB', '{K}{JJJJ}{MM}{NR}', 3, 1, 'none', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('00000000-0000-4000-8000-000000000003', 'measurement', 'Aufmaße', 'AM', '{K}{JJJJ}{MM}{NR}', 3, 1, 'none', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('00000000-0000-4000-8000-000000000004', 'supplement', 'Nachträge', 'NT', '{K}{JJJJ}{MM}{NR}', 3, 1, 'none', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('00000000-0000-4000-8000-000000000005', 'project', 'Projekte', 'PR', '{K}{JJJJ}{MM}{NR}', 3, 1, 'none', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('00000000-0000-4000-8000-000000000006', 'material_requirement', 'Materialbedarfslisten', 'MB', '{K}{JJJJ}{MM}{NR}', 3, 1, 'none', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('00000000-0000-4000-8000-000000000007', 'customer', 'Kunden', '', '{NR}', 5, 10001, 'none', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('00000000-0000-4000-8000-000000000008', 'supplier', 'Lieferanten', '', '{NR}', 5, 70001, 'none', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
