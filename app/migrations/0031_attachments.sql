-- Phase 3.4.2: Geschäftsbezogene Anhänge (kein DMS).
--
-- Allgemeine Anhänge werden einmal gespeichert (APP_DOCUMENTS_PATH/attachments/JJJJ/MM/<uuid>.<ext>)
-- und über attachment_links direkt einem oder mehreren Geschäftsobjekten zugeordnet.
-- Vorhandene Fachdokumente (Angebots-PDFs, Bestelldokumente, Rapporte, Aufmaße,
-- Bautagesberichte) bleiben unverändert in ihren Modulen; die zentrale Übersicht liest sie nur.
--
-- Zuordnungen sind polymorph (target_type/target_id). Existenz und Rechte prüft
-- AttachmentService serverseitig. Zuordnungen auf später gelöschte Objekte (z. B. ein
-- gelöschter Angebotsentwurf) werden ignoriert; ein Anhang ohne verbleibendes Objekt
-- ist nirgends mehr sichtbar, die Datei bleibt aber erhalten.

CREATE TABLE attachments (
    id TEXT PRIMARY KEY,
    original_name TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL CHECK (size_bytes > 0),
    sha256 TEXT NOT NULL,
    storage_path TEXT NOT NULL UNIQUE,
    uploaded_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_attachments_created ON attachments(created_at);

CREATE TABLE attachment_links (
    attachment_id TEXT NOT NULL,
    target_type TEXT NOT NULL CHECK (target_type IN ('customer', 'contact', 'project', 'offer', 'order')),
    target_id TEXT NOT NULL,
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (attachment_id, target_type, target_id),
    FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_attachment_links_target ON attachment_links(target_type, target_id);
