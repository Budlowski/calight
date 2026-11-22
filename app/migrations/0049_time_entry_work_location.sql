-- Einsatzort je Zeitbuchung (Freitext, optional). Die Einsatzart (Erste Tätigkeitsstätte bzw.
-- Auswärtstätigkeit) bleibt in assignment_kind; bestehende Buchungen erhalten einen leeren Ort.
ALTER TABLE time_entries ADD COLUMN work_location TEXT NOT NULL DEFAULT '';
