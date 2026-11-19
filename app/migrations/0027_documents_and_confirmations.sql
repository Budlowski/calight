-- Phase 3.2.3: Dokumentausgabe und Kundenbestätigung.
--
-- 1. Kundenbestätigung von Rapporten mit nachvollziehbarer Art:
--    - manual             manuell dokumentiert (nur Name/Datum, kein Nachweis)
--    - signature          digital vor Ort unterschrieben (Unterschriftsbild)
--    - returned_document  unterschriebenes Dokument zurückerhalten (Upload)
--    Unterschrift bzw. Rücklauf liegen als Nachweisdatei unter APP_DOCUMENTS_PATH.
--    Die Bindung an den eingereichten Stand (confirmation_sha256 = submitted_sha256)
--    erzwingt weiterhin der CHECK aus Migration 0026.
--    Spalten werden angefügt; ein Neuaufbau der Tabelle ist nicht nötig.
ALTER TABLE work_reports ADD COLUMN confirmation_kind TEXT CHECK (confirmation_kind IN ('manual', 'signature', 'returned_document'));
ALTER TABLE work_reports ADD COLUMN confirmation_evidence_path TEXT;
ALTER TABLE work_reports ADD COLUMN confirmation_evidence_name TEXT;
ALTER TABLE work_reports ADD COLUMN confirmation_evidence_mime TEXT;
ALTER TABLE work_reports ADD COLUMN confirmation_evidence_size INTEGER;
ALTER TABLE work_reports ADD COLUMN confirmation_evidence_sha256 TEXT;
-- „Zur Bestätigung versendet“: reiner Hinweis, keine Bestätigung.
ALTER TABLE work_reports ADD COLUMN confirmation_requested_at TEXT;
ALTER TABLE work_reports ADD COLUMN confirmation_requested_by_user_id TEXT REFERENCES users(id) ON DELETE RESTRICT;

-- Bisherige Bestätigungen wurden ohne Nachweis erfasst und gelten als manuell dokumentiert.
UPDATE work_reports SET confirmation_kind = 'manual' WHERE confirmation_status = 'confirmed';

-- 2. Archiv ausgegebener verbindlicher Dokumente (Rapporte, Aufmaße).
--    source_sha256 beschreibt den ausgegebenen Stand; für denselben Stand wird
--    die gespeicherte Datei erneut ausgeliefert statt neu erzeugt. Ein neuer
--    Stand (z. B. nach Kundenbestätigung) erhält eine eigene Datei; ältere
--    Dateien bleiben erhalten.
CREATE TABLE generated_documents (
    id TEXT PRIMARY KEY,
    document_type TEXT NOT NULL CHECK (document_type IN ('work_report', 'measurement')),
    entity_id TEXT NOT NULL,
    source_sha256 TEXT NOT NULL,
    file_name TEXT NOT NULL,
    storage_path TEXT NOT NULL UNIQUE,
    sha256 TEXT NOT NULL,
    size_bytes INTEGER NOT NULL CHECK (size_bytes > 0),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE (document_type, entity_id, source_sha256)
);

CREATE INDEX idx_generated_documents_entity ON generated_documents(document_type, entity_id, created_at DESC);
