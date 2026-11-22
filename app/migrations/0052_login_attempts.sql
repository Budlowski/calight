-- Schutz gegen Passwort-Raten (Systemaudit F-04). Jeder Anmeldeversuch wird vor der Passwortprüfung
-- als „pending“ erfasst (auch parallele Versuche zählen) und danach als „failed“ bzw. „succeeded“
-- abgeschlossen; abgewiesene Versuche während einer Sperre als „blocked“. Der Benutzername wird nur
-- als SHA-256 des normalisierten Namens gespeichert, Passwörter nie. Sperren sind zeitlich begrenzt
-- (Zeitfenster in Security\LoginThrottle); alte Einträge werden nach 30 Tagen entfernt.
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username_hash TEXT NOT NULL CHECK (length(username_hash) = 64),
    ip_address TEXT NOT NULL,
    outcome TEXT NOT NULL CHECK (outcome IN ('pending', 'failed', 'succeeded', 'blocked')),
    attempted_at TEXT NOT NULL
);
CREATE INDEX idx_login_attempts_username ON login_attempts(username_hash, attempted_at);
CREATE INDEX idx_login_attempts_ip ON login_attempts(ip_address, attempted_at);
