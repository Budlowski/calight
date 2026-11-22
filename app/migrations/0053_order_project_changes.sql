-- Bearbeitbare Projektzuordnung von Aufträgen mit historischem Rapportkontext.
ALTER TABLE work_reports ADD COLUMN project_id TEXT REFERENCES projects(id) ON DELETE RESTRICT;
UPDATE work_reports
SET project_id = (SELECT o.project_id FROM orders o WHERE o.id = work_reports.order_id)
WHERE order_id IS NOT NULL;
CREATE INDEX idx_work_reports_project ON work_reports(project_id, work_date DESC);

-- Der bestehende order_status_history-Vertrag enthält ausschließlich Statuswerte. Deshalb wird
-- die fachlich eigenständige Projektänderung in einem eigenen Auftragsverlauf protokolliert.
CREATE TABLE order_project_history (
    id TEXT PRIMARY KEY,
    order_id TEXT NOT NULL,
    previous_project_id TEXT,
    new_project_id TEXT,
    changed_by_user_id TEXT NOT NULL,
    changed_at TEXT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (previous_project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    FOREIGN KEY (new_project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (previous_project_id IS NOT new_project_id)
);
CREATE INDEX idx_order_project_history_order ON order_project_history(order_id, changed_at DESC);
