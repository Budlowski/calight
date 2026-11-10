CREATE TABLE employees (
    id TEXT PRIMARY KEY,
    user_id TEXT UNIQUE,
    personnel_number TEXT COLLATE NOCASE UNIQUE,
    display_name TEXT NOT NULL,
    hourly_cost_rate TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE INDEX idx_employees_active_name ON employees(active, display_name COLLATE NOCASE);

CREATE TABLE daily_reports (
    id TEXT PRIMARY KEY,
    order_id TEXT NOT NULL,
    report_date TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    activities TEXT NOT NULL,
    notes TEXT NOT NULL DEFAULT '',
    created_by_user_id TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE INDEX idx_daily_reports_order_date ON daily_reports(order_id, report_date DESC, created_at DESC);
CREATE INDEX idx_daily_reports_date ON daily_reports(report_date DESC);

CREATE TABLE daily_report_time_entries (
    id TEXT PRIMARY KEY,
    report_id TEXT NOT NULL,
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL,
    duration_hours TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    target_kind TEXT NOT NULL DEFAULT 'order' CHECK (target_kind IN ('order','title','position')),
    target_basis_type TEXT CHECK (target_basis_type IN ('order','addendum')),
    target_basis_id TEXT,
    target_node_id TEXT,
    hourly_cost_rate_snapshot TEXT NOT NULL,
    labor_cost TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    CHECK ((target_kind='order' AND target_basis_type IS NULL AND target_basis_id IS NULL AND target_node_id IS NULL)
        OR (target_kind IN ('title','position') AND target_basis_type IS NOT NULL AND target_basis_id IS NOT NULL AND target_node_id IS NOT NULL))
);

CREATE INDEX idx_daily_report_entries_report ON daily_report_time_entries(report_id, work_date, created_at);
CREATE INDEX idx_daily_report_entries_employee_date ON daily_report_time_entries(employee_id, work_date);
