-- Benutzerverwaltung über die Weboberfläche.
--
-- session_version: wird bei administrativem Passwortwechsel und Deaktivierung erhöht. Jede Sitzung
-- merkt sich den Wert bei der Anmeldung; weicht er ab, wird die Sitzung beendet. Bestehende
-- Sitzungen ohne gespeicherten Wert gelten als Version 1 und bleiben gültig.
ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1 CHECK (session_version >= 1);

-- Nachvollziehbare sicherheitsrelevante Änderungen an Benutzerkonten (ohne Passwörter oder Hashes).
-- Benutzer werden nie physisch gelöscht, daher RESTRICT.
CREATE TABLE user_account_events (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    actor_user_id TEXT,
    action TEXT NOT NULL CHECK (action IN (
        'created', 'updated', 'role_changed', 'activated', 'deactivated', 'password_reset', 'employee_linked', 'employee_unlinked'
    )),
    details TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE INDEX idx_user_account_events_user ON user_account_events(user_id, created_at);
