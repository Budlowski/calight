-- Auftragsstatus: Statusverlauf mit Benutzer, Art und Begründung (Systemaudit F-02).
-- change_kind: 'create' (Anlage), 'advance' (Wechsel vorwärts), 'revert' (Rücknahme durch einen
-- Administrator, Begründung Pflicht). Bestehende Einträge: Anlage bzw. Vorwärtswechsel ohne Benutzer.
ALTER TABLE order_status_history ADD COLUMN changed_by_user_id TEXT REFERENCES users(id) ON DELETE RESTRICT;
ALTER TABLE order_status_history ADD COLUMN change_kind TEXT NOT NULL DEFAULT 'advance' CHECK (change_kind IN ('create', 'advance', 'revert'));
ALTER TABLE order_status_history ADD COLUMN reason TEXT NOT NULL DEFAULT '';
-- Bestätigte offene Vorgänge beim Abschließen/Abrechnen (JSON, nur Dokumentation).
ALTER TABLE order_status_history ADD COLUMN open_items_json TEXT NOT NULL DEFAULT '[]';
UPDATE order_status_history SET change_kind = 'create' WHERE previous_status IS NULL;
CREATE TRIGGER order_status_history_revert_reason BEFORE INSERT ON order_status_history
WHEN NEW.change_kind = 'revert' AND (trim(NEW.reason) = '' OR NEW.changed_by_user_id IS NULL)
BEGIN SELECT RAISE(ABORT, 'calight_revert_reason_required'); END;
