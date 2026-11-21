-- Bevorzugte Oberfläche je Benutzerkonto (Monteurs-Frontend, Phase 1).
-- NULL bedeutet: Standard der Rolle (Monteure mobil, Büro und Administratoren Büro).
-- Die Einstellung steuert nur den Einstieg nach der Anmeldung und verändert keine Rechte.
ALTER TABLE users ADD COLUMN preferred_interface TEXT
    CHECK (preferred_interface IS NULL OR preferred_interface IN ('office', 'mobile'));
