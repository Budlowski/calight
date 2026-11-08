CREATE TABLE master_categories (
    id TEXT PRIMARY KEY,
    parent_id TEXT,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES master_categories(id) ON DELETE RESTRICT,
    CHECK (parent_id IS NULL OR parent_id <> id)
);

CREATE UNIQUE INDEX uq_master_categories_parent_name
    ON master_categories(COALESCE(parent_id, ''), name COLLATE NOCASE);
CREATE INDEX idx_master_categories_parent ON master_categories(parent_id);

CREATE TABLE suppliers (
    id TEXT PRIMARY KEY,
    supplier_number TEXT COLLATE NOCASE UNIQUE,
    name TEXT NOT NULL,
    contact TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE cost_items (
    id TEXT PRIMARY KEY,
    article_number TEXT COLLATE NOCASE UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    category_id TEXT,
    calculation_type TEXT NOT NULL CHECK (calculation_type IN ('material', 'labor', 'external', 'other')),
    unit TEXT NOT NULL,
    cost_price TEXT NOT NULL,
    markup_percent TEXT NOT NULL,
    supplier_id TEXT,
    supplier_article_number TEXT NOT NULL DEFAULT '',
    copied_from_id TEXT,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (category_id) REFERENCES master_categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
    FOREIGN KEY (copied_from_id) REFERENCES cost_items(id) ON DELETE SET NULL
);

CREATE INDEX idx_cost_items_category ON cost_items(category_id);
CREATE INDEX idx_cost_items_type ON cost_items(calculation_type);
CREATE INDEX idx_cost_items_supplier ON cost_items(supplier_id);
CREATE INDEX idx_cost_items_active ON cost_items(active);

CREATE TABLE service_templates (
    id TEXT PRIMARY KEY,
    short_text TEXT NOT NULL,
    long_text TEXT NOT NULL DEFAULT '',
    unit TEXT NOT NULL,
    copied_from_id TEXT,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (copied_from_id) REFERENCES service_templates(id) ON DELETE SET NULL
);

CREATE INDEX idx_service_templates_active ON service_templates(active);

CREATE TABLE service_template_components (
    id TEXT PRIMARY KEY,
    service_template_id TEXT NOT NULL,
    master_data_id TEXT,
    name TEXT NOT NULL,
    calculation_type TEXT NOT NULL CHECK (calculation_type IN ('material', 'labor', 'external', 'other')),
    unit TEXT NOT NULL,
    quantity TEXT NOT NULL,
    cost_price TEXT NOT NULL,
    markup_percent TEXT NOT NULL,
    price_locked INTEGER NOT NULL DEFAULT 0 CHECK (price_locked IN (0, 1)),
    sort_order INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (service_template_id) REFERENCES service_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (master_data_id) REFERENCES cost_items(id) ON DELETE SET NULL,
    UNIQUE (service_template_id, sort_order)
);

CREATE INDEX idx_service_components_template ON service_template_components(service_template_id);
CREATE INDEX idx_service_components_master_data ON service_template_components(master_data_id);
