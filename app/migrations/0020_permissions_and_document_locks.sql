-- Die Anwendung hatte bislang keine wirksame Rollenpruefung. Deshalb behalten
-- bestehende Konten bei der Einfuehrung der Rechte als Administratoren ihren
-- bisherigen Funktionsumfang.
ALTER TABLE users RENAME TO users_before_permissions;

CREATE TABLE users (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    display_name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'office' CHECK (role IN ('admin', 'office', 'technician')),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO users (id, username, password_hash, display_name, role, active, created_at, updated_at)
SELECT id, username, password_hash, display_name, 'admin', active, created_at, updated_at
FROM users_before_permissions;

DROP TABLE users_before_permissions;

CREATE TABLE document_locks (
    document_type TEXT NOT NULL,
    document_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (document_type, document_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_document_locks_expiry ON document_locks(expires_at);
CREATE INDEX idx_document_locks_user ON document_locks(user_id);
