-- Rapporte: optionaler Einsatzort / Objekt als Freitext (z. B. „Werk 2, Produktionshalle 4“).
-- Unabhängig von Kunde, Projekt und Auftrag; bestehende Rapporte behalten einen leeren Wert.
-- Rein additiv: Spalte wird angefügt, vorhandene Daten bleiben unverändert.
ALTER TABLE work_reports ADD COLUMN work_location TEXT NOT NULL DEFAULT '';
