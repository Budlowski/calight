-- Phase 3.4.1: Bestellverwaltung (Beschaffung beim Lieferanten).
--
-- Eine Bestellung ist ein Beschaffungsvorgang, intern in Calight ausgelöst (mit Bestell-PDF)
-- oder extern bereits aufgegeben (online, telefonisch, vor Ort) und nur erfasst.
-- Bestell-, Liefer- und Zahlungsstatus sind voneinander unabhängig.
--
-- Ist-Kosten haben genau eine Quelle: entweder actual_net_total (direkt erfasst) oder die
-- Summe der Lieferantenrechnungen. Die Anwendung verhindert, dass beides gleichzeitig
-- besteht. Der erwartete Bestellwert ist nie Ist-Kosten.
--
-- Auftragskosten: Gibt es Einträge in purchase_order_allocations, gelten genau diese
-- Beträge (Summe höchstens Ist-Kosten, Rest „nicht zugeordnet“). Sonst gehen die vollen
-- Ist-Kosten an purchase_orders.order_id. Auswertung: PurchaseOrderRepository::costsByOrder.

INSERT INTO number_ranges
    (id, code, label, prefix, format, minimum_length, next_counter, reset_mode, manual_numbers_allowed, created_at, updated_at)
VALUES
    ('00000000-0000-4000-8000-000000000014', 'purchase_order', 'Bestellungen', 'BE', '{K}{JJJJ}{MM}{NR}', 3, 1, 'none', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

CREATE TABLE purchase_orders (
    id TEXT PRIMARY KEY,
    order_number TEXT NOT NULL UNIQUE,
    origin TEXT NOT NULL CHECK (origin IN ('internal', 'external')),
    status TEXT NOT NULL CHECK (status IN ('draft', 'ordered', 'cancelled')),
    delivery_status TEXT NOT NULL DEFAULT 'pending' CHECK (delivery_status IN ('pending', 'partial', 'delivered')),
    payment_status TEXT NOT NULL DEFAULT 'open' CHECK (payment_status IN ('open', 'partial', 'paid')),
    supplier_id TEXT NOT NULL,
    -- Lieferantendaten zum Zeitpunkt des Auslösens bzw. der externen Erfassung (JSON).
    supplier_snapshot TEXT,
    external_reference TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL DEFAULT '',
    order_date TEXT,
    expected_delivery_date TEXT,
    order_id TEXT,
    delivery_address TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    expected_net_total TEXT,
    actual_net_total TEXT,
    revision INTEGER NOT NULL DEFAULT 0 CHECK (revision >= 0),
    issued_at TEXT,
    issued_by_user_id TEXT,
    cancelled_at TEXT,
    cancelled_by_user_id TEXT,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (issued_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    -- Entwürfe gibt es nur bei internen Bestellungen.
    CHECK (status <> 'draft' OR origin = 'internal'),
    CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL))
);
CREATE INDEX idx_purchase_orders_supplier ON purchase_orders(supplier_id);
CREATE INDEX idx_purchase_orders_order ON purchase_orders(order_id);
CREATE INDEX idx_purchase_orders_updated ON purchase_orders(updated_at);

CREATE TABLE purchase_order_items (
    id TEXT PRIMARY KEY,
    purchase_order_id TEXT NOT NULL,
    position INTEGER NOT NULL CHECK (position > 0),
    cost_item_id TEXT,
    article_number TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL,
    quantity TEXT,
    unit TEXT NOT NULL DEFAULT '',
    unit_price TEXT,
    line_total TEXT,
    notes TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (cost_item_id) REFERENCES cost_items(id) ON DELETE SET NULL,
    UNIQUE (purchase_order_id, position)
);

-- Übernommene Materialanforderungen (Bezug bleibt erhalten; die Anforderung selbst bleibt unverändert).
CREATE TABLE purchase_order_material_requests (
    purchase_order_id TEXT NOT NULL,
    material_request_id TEXT NOT NULL,
    PRIMARY KEY (purchase_order_id, material_request_id),
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (material_request_id) REFERENCES material_requests(id) ON DELETE CASCADE
);
CREATE INDEX idx_purchase_order_requests_request ON purchase_order_material_requests(material_request_id);

-- Dokumente: eigene Bestell-PDFs (je Fassung, unveränderlich) und hochgeladene Belege.
-- Dateien liegen unter APP_DOCUMENTS_PATH/purchase-orders/<bestellung>/<dokument>.<ext>.
CREATE TABLE purchase_order_documents (
    id TEXT PRIMARY KEY,
    purchase_order_id TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('own_order', 'order_confirmation', 'supplier_confirmation', 'delivery_note', 'invoice', 'other')),
    revision INTEGER,
    original_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL CHECK (size_bytes > 0),
    sha256 TEXT NOT NULL,
    storage_path TEXT NOT NULL UNIQUE,
    uploaded_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK ((kind = 'own_order') = (revision IS NOT NULL)),
    UNIQUE (purchase_order_id, kind, revision)
);
CREATE INDEX idx_purchase_order_documents_order ON purchase_order_documents(purchase_order_id);

CREATE TABLE purchase_order_invoices (
    id TEXT PRIMARY KEY,
    purchase_order_id TEXT NOT NULL,
    invoice_number TEXT NOT NULL,
    invoice_date TEXT NOT NULL,
    net_amount TEXT NOT NULL,
    vat_amount TEXT,
    gross_amount TEXT,
    document_id TEXT,
    notes TEXT NOT NULL DEFAULT '',
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (document_id) REFERENCES purchase_order_documents(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_purchase_order_invoices_order ON purchase_order_invoices(purchase_order_id);

-- Optionale Kostenverteilung auf mehrere Aufträge (feste Nettobeträge).
CREATE TABLE purchase_order_allocations (
    id TEXT PRIMARY KEY,
    purchase_order_id TEXT NOT NULL,
    order_id TEXT NOT NULL,
    amount TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    UNIQUE (purchase_order_id, order_id)
);
CREATE INDEX idx_purchase_order_allocations_order ON purchase_order_allocations(order_id);

-- Statusverlauf: Bestell-, Liefer- und Zahlungsstatus sowie Ausgabe neuer Fassungen.
CREATE TABLE purchase_order_history (
    id TEXT PRIMARY KEY,
    purchase_order_id TEXT NOT NULL,
    field TEXT NOT NULL CHECK (field IN ('status', 'delivery', 'payment', 'revision')),
    old_value TEXT,
    new_value TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_purchase_order_history_order ON purchase_order_history(purchase_order_id, created_at);
