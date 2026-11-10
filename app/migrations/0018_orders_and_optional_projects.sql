ALTER TABLE offers RENAME TO offers_before_optional_projects;

CREATE TABLE offers (
    id TEXT PRIMARY KEY,
    project_id TEXT,
    customer_id TEXT,
    storage_key TEXT NOT NULL,
    offer_number TEXT UNIQUE,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted','commissioned','rejected')),
    draft_relative_path TEXT NOT NULL,
    draft_etag TEXT NOT NULL,
    draft_version INTEGER NOT NULL DEFAULT 1 CHECK (draft_version > 0),
    latest_revision INTEGER NOT NULL DEFAULT 0 CHECK (latest_revision >= 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    snapshot_relative_path TEXT,
    snapshot_etag TEXT,
    submitted_pdf_relative_path TEXT,
    submitted_pdf_sha256 TEXT,
    submitted_at TEXT,
    document_date TEXT,
    revision_root_id TEXT REFERENCES offers(id) ON DELETE RESTRICT,
    revision_number INTEGER NOT NULL DEFAULT 0 CHECK (revision_number >= 0),
    previous_revision_id TEXT REFERENCES offers(id) ON DELETE RESTRICT,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
);

INSERT INTO offers
    (id,project_id,customer_id,storage_key,offer_number,status,draft_relative_path,draft_etag,draft_version,
     latest_revision,created_at,updated_at,snapshot_relative_path,snapshot_etag,submitted_pdf_relative_path,
     submitted_pdf_sha256,submitted_at,document_date,revision_root_id,revision_number,previous_revision_id)
SELECT o.id,o.project_id,p.customer_id,o.project_id,o.offer_number,o.status,o.draft_relative_path,o.draft_etag,o.draft_version,
       o.latest_revision,o.created_at,o.updated_at,o.snapshot_relative_path,o.snapshot_etag,o.submitted_pdf_relative_path,
       o.submitted_pdf_sha256,o.submitted_at,o.document_date,o.revision_root_id,o.revision_number,o.previous_revision_id
FROM offers_before_optional_projects o JOIN projects p ON p.id=o.project_id;

DROP TABLE offers_before_optional_projects;
CREATE INDEX idx_offers_project ON offers(project_id);
CREATE INDEX idx_offers_customer ON offers(customer_id);
CREATE UNIQUE INDEX idx_offers_revision_number ON offers(revision_root_id,revision_number);
CREATE UNIQUE INDEX idx_offers_single_open_revision ON offers(revision_root_id) WHERE status='draft';
CREATE UNIQUE INDEX idx_offers_single_commissioned_revision ON offers(revision_root_id) WHERE status='commissioned';
CREATE INDEX idx_offers_revision_root ON offers(revision_root_id,revision_number DESC);

CREATE TABLE orders (
    id TEXT PRIMARY KEY,
    order_number TEXT NOT NULL UNIQUE,
    customer_id TEXT NOT NULL,
    project_id TEXT,
    source_offer_id TEXT UNIQUE,
    order_type TEXT NOT NULL CHECK (order_type IN ('offer','regie')),
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    order_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','in_progress','completed','invoiced')),
    basis_json TEXT,
    basis_sha256 TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    FOREIGN KEY (source_offer_id) REFERENCES offers(id) ON DELETE RESTRICT,
    CHECK ((order_type='offer' AND source_offer_id IS NOT NULL AND basis_json IS NOT NULL AND basis_sha256 IS NOT NULL)
        OR (order_type='regie' AND source_offer_id IS NULL))
);
CREATE INDEX idx_orders_customer ON orders(customer_id);
CREATE INDEX idx_orders_project ON orders(project_id);
CREATE INDEX idx_orders_status ON orders(status,updated_at DESC);

CREATE TABLE order_status_history (
    id TEXT PRIMARY KEY,
    order_id TEXT NOT NULL,
    previous_status TEXT,
    new_status TEXT NOT NULL CHECK (new_status IN ('open','in_progress','completed','invoiced')),
    changed_at TEXT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
);
CREATE INDEX idx_order_status_history_order ON order_status_history(order_id,changed_at DESC);

INSERT INTO number_ranges
    (id,code,label,prefix,format,minimum_length,next_counter,reset_mode,manual_numbers_allowed,created_at,updated_at)
VALUES
    ('00000000-0000-4000-8000-000000000010','order','Aufträge','AU','{K}{JJJJ}{MM}{NR}',3,1,'none',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
