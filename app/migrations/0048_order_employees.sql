-- Mitarbeiterzuordnung zu Aufträgen (n:m) für die Auftragssichtbarkeit der Monteure.
-- Zuordnung ausschließlich über die Mitarbeiter-ID; keine Gruppen, Rollen oder Einsatzplanung.
-- Aufträge und Mitarbeiter werden nie gelöscht (RESTRICT); entfernte Zuordnungen löschen nur die
-- Zuordnungszeile, nie Zeiten, Berichte oder andere Vorgänge.
CREATE TABLE order_employees (
    order_id TEXT NOT NULL,
    employee_id TEXT NOT NULL,
    assigned_by_user_id TEXT,
    assigned_at TEXT NOT NULL,
    PRIMARY KEY (order_id, employee_id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE INDEX idx_order_employees_employee ON order_employees(employee_id, order_id);

-- Sichtbarkeit für Monteure (Einstellungen → Auftragsabwicklung): ohne Eintrag gilt „nur
-- zugewiesene Aufträge“. Bestehende Installationen mit Aufträgen behalten die bisherige Regel
-- („alle freigegebenen Aufträge“), damit laufende Arbeitsabläufe nicht unbemerkt abbrechen; dabei
-- wird nichts zusätzlich offengelegt. Die Umstellung auf „nur zugewiesene“ erfolgt bewusst in den
-- Einstellungen, nachdem Mitarbeiter zugeordnet wurden.
INSERT INTO operational_settings (setting_key, setting_value, updated_by_user_id, updated_at)
SELECT 'orders.technician_visibility', 'released', NULL, strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
WHERE EXISTS (SELECT 1 FROM orders) AND NOT EXISTS (SELECT 1 FROM operational_settings WHERE setting_key = 'orders.technician_visibility');
