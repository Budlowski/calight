-- Phase 3.3: Aufmaße.
--
-- Ein Aufmaß erfasst zusätzliche, tatsächlich ausgeführte Mengen verbindlicher
-- Auftragspositionen (ursprüngliche Auftragsgrundlage bzw. angenommene Nachträge).
-- Mehrere abgeschlossene Aufmaße ergeben zusammen den Leistungsfortschritt.
-- Entwürfe sind bearbeitbar; abgeschlossene Aufmaße sind unveränderlich und
-- erhalten erst beim Abschluss ihre Nummer. Korrekturen erfolgen über ein neues
-- Korrekturaufmaß (correction_of_id), das auch negative Mengen enthalten darf.
CREATE TABLE measurements (
    id TEXT PRIMARY KEY,
    order_id TEXT NOT NULL,
    measurement_number TEXT UNIQUE,
    title TEXT NOT NULL,
    measurement_date TEXT NOT NULL,
    notes TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'completed')),
    correction_of_id TEXT,
    net_total TEXT,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id TEXT NOT NULL,
    completed_by_user_id TEXT,
    completed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (correction_of_id) REFERENCES measurements(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK ((status = 'draft' AND measurement_number IS NULL AND completed_at IS NULL AND completed_by_user_id IS NULL AND net_total IS NULL)
        OR (status = 'completed' AND measurement_number IS NOT NULL AND completed_at IS NOT NULL AND completed_by_user_id IS NOT NULL AND net_total IS NOT NULL))
);

CREATE INDEX idx_measurements_order ON measurements(order_id, status, measurement_date DESC);
CREATE INDEX idx_measurements_updated ON measurements(updated_at DESC);

-- Gemessene Mengen je verbindlicher Position. Ziel ist stabil über interne IDs
-- referenziert (Quelle order|addendum, Quell-ID, Knoten-ID). Text, Einheit,
-- vereinbarte Menge und Einzelpreis werden als Snapshot gespeichert, damit das
-- Aufmaß auch für eine spätere Dokumentausgabe ohne Rückgriff vollständig ist.
CREATE TABLE measurement_items (
    id TEXT PRIMARY KEY,
    measurement_id TEXT NOT NULL,
    source_type TEXT NOT NULL CHECK (source_type IN ('order', 'addendum')),
    source_id TEXT NOT NULL,
    node_id TEXT NOT NULL,
    position_number TEXT NOT NULL,
    short_text TEXT NOT NULL,
    unit TEXT NOT NULL,
    effective_type TEXT NOT NULL CHECK (effective_type IN ('normal', 'bedarf', 'alternative')),
    agreed_quantity TEXT NOT NULL,
    unit_price TEXT NOT NULL,
    quantity TEXT NOT NULL,
    line_total TEXT NOT NULL,
    overrun_confirmed INTEGER NOT NULL DEFAULT 0 CHECK (overrun_confirmed IN (0, 1)),
    sort_order INTEGER NOT NULL,
    FOREIGN KEY (measurement_id) REFERENCES measurements(id) ON DELETE CASCADE,
    UNIQUE (measurement_id, source_type, source_id, node_id)
);

CREATE INDEX idx_measurement_items_target ON measurement_items(source_type, source_id, node_id);

-- Summen je Quelldokument: Nachlass/Aufschlag des jeweiligen Angebots bzw.
-- Nachtrags wird anteilig auf die gemessenen Normalpositionen angewendet.
CREATE TABLE measurement_source_totals (
    measurement_id TEXT NOT NULL,
    source_type TEXT NOT NULL CHECK (source_type IN ('order', 'addendum')),
    source_id TEXT NOT NULL,
    label TEXT NOT NULL,
    items_total TEXT NOT NULL,
    adjustable_total TEXT NOT NULL,
    adjustment_amount TEXT NOT NULL,
    net_total TEXT NOT NULL,
    PRIMARY KEY (measurement_id, source_type, source_id),
    FOREIGN KEY (measurement_id) REFERENCES measurements(id) ON DELETE CASCADE
);

-- Der Nummernkreis „measurement“ (Präfix AM) besteht bereits seit Migration 0001.
