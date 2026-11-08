CREATE TABLE customers (
    id TEXT PRIMARY KEY,
    customer_number TEXT COLLATE NOCASE UNIQUE,
    customer_type TEXT NOT NULL CHECK (customer_type IN ('private', 'company')),
    company_name TEXT NOT NULL DEFAULT '',
    first_name TEXT NOT NULL DEFAULT '',
    last_name TEXT NOT NULL DEFAULT '',
    street TEXT NOT NULL DEFAULT '',
    house_number TEXT NOT NULL DEFAULT '',
    postal_code TEXT NOT NULL DEFAULT '',
    city TEXT NOT NULL DEFAULT '',
    country TEXT NOT NULL DEFAULT 'Deutschland',
    email TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    website TEXT NOT NULL DEFAULT '',
    vat_id TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    copied_from_id TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (copied_from_id) REFERENCES customers(id) ON DELETE SET NULL
);

CREATE TABLE customer_contacts (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL,
    salutation TEXT NOT NULL DEFAULT '',
    first_name TEXT NOT NULL DEFAULT '',
    last_name TEXT NOT NULL DEFAULT '',
    function TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    mobile TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE INDEX idx_customer_contacts_customer ON customer_contacts(customer_id, sort_order);

CREATE TABLE customer_addresses (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL,
    address_type TEXT NOT NULL CHECK (address_type IN ('invoice', 'site', 'other')),
    label TEXT NOT NULL DEFAULT '',
    street TEXT NOT NULL DEFAULT '',
    house_number TEXT NOT NULL DEFAULT '',
    postal_code TEXT NOT NULL DEFAULT '',
    city TEXT NOT NULL DEFAULT '',
    country TEXT NOT NULL DEFAULT 'Deutschland',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE INDEX idx_customer_addresses_customer ON customer_addresses(customer_id, sort_order);

-- Die vorhandenen Projekte und Angebotsreferenzen werden beim Neuaufbau vollständig übernommen.
ALTER TABLE offers RENAME TO offers_before_customer_projects;
ALTER TABLE projects RENAME TO projects_before_customer_projects;

CREATE TABLE projects (
    id TEXT PRIMARY KEY,
    project_number TEXT COLLATE NOCASE UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    customer_id TEXT,
    contact_id TEXT,
    customer_name TEXT NOT NULL DEFAULT '',
    site_street TEXT NOT NULL DEFAULT '',
    site_house_number TEXT NOT NULL DEFAULT '',
    site_postal_code TEXT NOT NULL DEFAULT '',
    site_city TEXT NOT NULL DEFAULT '',
    site_country TEXT NOT NULL DEFAULT 'Deutschland',
    site_address TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'completed', 'archived')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (contact_id) REFERENCES customer_contacts(id) ON DELETE SET NULL
);

INSERT INTO projects
    (id, project_number, name, customer_name, site_address, status, created_at, updated_at)
SELECT id, project_number, name, customer_name, site_address, status, created_at, updated_at
FROM projects_before_customer_projects;

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

INSERT INTO offers SELECT * FROM offers_before_customer_projects;

DROP TABLE offers_before_customer_projects;
DROP TABLE projects_before_customer_projects;
CREATE INDEX idx_offers_project ON offers(project_id);

UPDATE number_ranges
SET next_counter = 10003, updated_at = CURRENT_TIMESTAMP
WHERE code = 'customer'
  AND next_counter < 10003
  AND NOT EXISTS (
      SELECT 1 FROM assigned_numbers a
      WHERE a.number_range_id = number_ranges.id
  );
