CREATE TABLE trailing_texts (
    id TEXT PRIMARY KEY,
    code TEXT NOT NULL COLLATE NOCASE UNIQUE,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

ALTER TABLE service_templates ADD COLUMN trailing_text_id TEXT REFERENCES trailing_texts(id) ON DELETE RESTRICT;
CREATE INDEX idx_service_templates_trailing_text ON service_templates(trailing_text_id);

INSERT OR IGNORE INTO trailing_texts (id, code, name, created_at, updated_at) VALUES
('10000000-0000-4000-8000-000000000001', 'L',  'Lieferung', '2026-11-08T00:00:00Z', '2026-11-08T00:00:00Z'),
('10000000-0000-4000-8000-000000000002', 'M',  'Montage', '2026-11-08T00:00:00Z', '2026-11-08T00:00:00Z'),
('10000000-0000-4000-8000-000000000003', 'LM', 'Lieferung und Montage', '2026-11-08T00:00:00Z', '2026-11-08T00:00:00Z'),
('10000000-0000-4000-8000-000000000004', 'DL', 'Dienstleistung', '2026-11-08T00:00:00Z', '2026-11-08T00:00:00Z'),
('10000000-0000-4000-8000-000000000005', 'AN', 'Abrechnung auf Nachweis', '2026-11-08T00:00:00Z', '2026-11-08T00:00:00Z');
