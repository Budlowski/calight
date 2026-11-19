-- Zeiterfassung (Phase 3.4.3): tatsächliche Arbeits- und Reisezeiten je Mitarbeiter und Tag.
--
-- Keine Lohn-, Reisekosten- oder Personalverwaltung. Bestehende Bautagesberichte bleiben
-- unverändert. Eindeutige Ist-Stunden ohne Datenmigration: Für Mitarbeiter + Tag + Auftrag gilt
-- die Zeiterfassung, sobald dort Zeitblöcke bestehen, sonst die Bautagesbericht-Zeiten
-- (TimeTrackingService::actualHoursForOrder). Rapportstunden sind nur Referenz.

-- Zeitblöcke. Zeiten als HH:MM; endet ein Block am Folgetag, ist ends_next_day = 1.
-- net_minutes wird serverseitig aus Beginn, Ende und Pause berechnet (für Summen und Sortierung).
CREATE TABLE time_entries (
    id TEXT PRIMARY KEY,
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL,
    start_time TEXT NOT NULL CHECK (length(start_time) = 5),
    end_time TEXT NOT NULL CHECK (length(end_time) = 5),
    ends_next_day INTEGER NOT NULL DEFAULT 0 CHECK (ends_next_day IN (0, 1)),
    break_minutes INTEGER NOT NULL DEFAULT 0 CHECK (break_minutes >= 0),
    net_minutes INTEGER NOT NULL CHECK (net_minutes > 0),
    activity TEXT NOT NULL CHECK (activity IN ('work', 'travel', 'preparation', 'internal', 'other')),
    order_id TEXT,
    notes TEXT NOT NULL DEFAULT '',
    -- Kostensatz zum Erfassungszeitpunkt (wie bei Bautagesberichten); nur mit costs.read sichtbar.
    hourly_cost_rate_snapshot TEXT,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_by_user_id TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_time_entries_employee_date ON time_entries(employee_id, work_date);
CREATE INDEX idx_time_entries_order ON time_entries(order_id, work_date);

-- Einsatz und Reise je Mitarbeiter und Tag (alles optional, keine steuerliche Einstufung).
CREATE TABLE time_day_details (
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL,
    assignment_kind TEXT CHECK (assignment_kind IS NULL OR assignment_kind IN ('regular', 'external')),
    overnight INTEGER NOT NULL DEFAULT 0 CHECK (overnight IN (0, 1)),
    travel_day TEXT NOT NULL DEFAULT 'none' CHECK (travel_day IN ('none', 'arrival', 'departure')),
    location TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    private_km TEXT,
    private_km_purpose TEXT NOT NULL DEFAULT '',
    private_km_order_id TEXT,
    updated_by_user_id TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (employee_id, work_date),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (private_km_order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);

-- Dokumentierte Auslagen (keine Buchhaltung). Beleg optional unter APP_DOCUMENTS_PATH/expenses/.
CREATE TABLE time_expenses (
    id TEXT PRIMARY KEY,
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL,
    amount TEXT NOT NULL,
    purpose TEXT NOT NULL,
    order_id TEXT,
    receipt_path TEXT UNIQUE,
    receipt_name TEXT,
    receipt_mime TEXT,
    receipt_size INTEGER,
    receipt_sha256 TEXT,
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK ((receipt_path IS NULL) = (receipt_name IS NULL))
);
CREATE INDEX idx_time_expenses_employee_date ON time_expenses(employee_id, work_date);
