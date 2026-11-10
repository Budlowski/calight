ALTER TABLE offers ADD COLUMN document_type TEXT NOT NULL DEFAULT 'offer'
    CHECK (document_type IN ('offer','change_order'));
ALTER TABLE offers ADD COLUMN order_id TEXT REFERENCES orders(id) ON DELETE RESTRICT;

CREATE INDEX idx_offers_order ON offers(order_id,document_type,updated_at DESC);

CREATE TABLE order_addenda (
    id TEXT PRIMARY KEY,
    order_id TEXT NOT NULL,
    offer_revision_id TEXT NOT NULL UNIQUE,
    revision_root_id TEXT NOT NULL UNIQUE,
    basis_json TEXT NOT NULL,
    basis_sha256 TEXT NOT NULL,
    net_total TEXT NOT NULL,
    accepted_at TEXT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (offer_revision_id) REFERENCES offers(id) ON DELETE RESTRICT,
    FOREIGN KEY (revision_root_id) REFERENCES offers(id) ON DELETE RESTRICT
);
CREATE INDEX idx_order_addenda_order ON order_addenda(order_id,accepted_at);

INSERT INTO number_ranges
    (id,code,label,prefix,format,minimum_length,next_counter,reset_mode,manual_numbers_allowed,created_at,updated_at)
VALUES
    ('00000000-0000-4000-8000-000000000011','change_order','Nachträge','NA','{K}{JJJJ}{MM}{NR}',3,1,'none',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
