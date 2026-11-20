-- Rapporte: Leistungsdatum je Stundenposition – rein additiv.
--
-- work_report_labor.work_date: Datum, an dem der Mitarbeiter die Leistung erbracht hat.
-- Entwürfe übernehmen das bisherige Leistungsdatum des Rapports als Ausgangswert.
-- Bereits eingereichte Rapporte bleiben unverändert (work_date bleibt NULL): Ihr Nachweis,
-- ihre Prüfsumme, Bestätigungen und archivierten PDFs beziehen sich auf das Leistungsdatum
-- bzw. den Leistungszeitraum des Rapports (work_reports.work_date/work_date_to).
-- Das Einreichungsdatum ist work_reports.submitted_at (Serverzeit beim Einreichen).

ALTER TABLE work_report_labor ADD COLUMN work_date TEXT CHECK (work_date IS NULL OR (length(work_date) = 10 AND work_date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'));

UPDATE work_report_labor SET work_date = (SELECT w.work_date FROM work_reports w WHERE w.id = work_report_labor.report_id)
WHERE report_id IN (SELECT id FROM work_reports WHERE status = 'draft');

CREATE INDEX idx_work_report_labor_employee_date ON work_report_labor(employee_id, work_date);
