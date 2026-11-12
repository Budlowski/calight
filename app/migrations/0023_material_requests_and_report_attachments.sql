-- Phase 3.2.2: Materialanforderungen und Anhänge zu Bautagesberichten.

-- Materialanforderungen sind eigenständige organisatorische Aufgaben mit
-- Auftragsbezug (keine Bestellungen, keine Ist-Kosten). Der auslösende
-- Bautagesbericht ist optional; wird er gelöscht, bleibt die Anforderung
-- erhalten und verliert nur den Verweis.
CREATE TABLE material_requests (
    id TEXT PRIMARY KEY,
    order_id TEXT NOT NULL,
    daily_report_id TEXT,
    description TEXT NOT NULL,
    notes TEXT NOT NULL DEFAULT '',
    needed_by TEXT,
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'done')),
    created_by_user_id TEXT NOT NULL,
    completed_by_user_id TEXT,
    completed_at TEXT,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (daily_report_id) REFERENCES daily_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK ((status = 'open' AND completed_at IS NULL AND completed_by_user_id IS NULL)
        OR (status = 'done' AND completed_at IS NOT NULL AND completed_by_user_id IS NOT NULL))
);

CREATE INDEX idx_material_requests_status_created ON material_requests(status, created_at DESC);
CREATE INDEX idx_material_requests_order ON material_requests(order_id, status);
CREATE INDEX idx_material_requests_report ON material_requests(daily_report_id);

-- Anhänge (Fotos, PDFs) eines Bautagesberichts. Die Dateien liegen unter
-- APP_DOCUMENTS_PATH; storage_path ist relativ dazu und wird nie aus
-- Benutzereingaben gebildet.
CREATE TABLE daily_report_attachments (
    id TEXT PRIMARY KEY,
    report_id TEXT NOT NULL,
    original_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL CHECK (size_bytes > 0),
    sha256 TEXT NOT NULL,
    storage_path TEXT NOT NULL UNIQUE,
    uploaded_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE INDEX idx_daily_report_attachments_report ON daily_report_attachments(report_id, created_at);
