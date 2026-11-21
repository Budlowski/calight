-- Reisekosten und Auslagen (Phase B): Auswärtstätigkeit, Verpflegung, Privat-Pkw-Fahrten.
--
-- 1. time_day_details wird neu aufgebaut (Zielschema, keine Kompatibilitätsspalten):
--    - travel_category: none | single_short (bis einschließlich Grenze) | single_long (über
--      Grenze) | arrival (Anreisetag mit Übernachtung) | full (voller Tag, 24 h) | departure
--      (Abreisetag nach Übernachtung). Die Verpflegungspauschale wird nie aus der Arbeitszeit
--      abgeleitet; sie ergibt sich rechnerisch aus Kategorie, Mahlzeiten und Einstellungen.
--    - abroad, absence_minutes (optional), overnight, meal_breakfast/lunch/dinner.
--    - Entfallen: Beginn/Ende der Abwesenheit (in eine Dauer übernommen), Einsatzart je Tag
--      (seit 0033 je Block), Kilometerfelder (jetzt einzelne Fahrten).
--    Übernahme der Testdaten: Anreise-/Abreisetag bleiben erhalten; Abwesenheitsbeginn/-ende
--    werden zur Dauer (Ende ≤ Beginn: über Mitternacht); andere Tage werden „keine
--    Auswärtstätigkeit“ und sind bei Bedarf neu einzustufen.
-- 2. time_private_trips: mehrere dienstliche Privat-Pkw-Fahrten je Mitarbeiter und Tag, jeweils
--    mit Kilometern, Strecke, Zweck und optional Auftrag ODER Kostenstelle. Bisherige
--    Tageskilometer werden als eine Fahrt übernommen.
-- 3. Die Tagessperre (0042) gilt auch für Fahrten; die Trigger der neu aufgebauten
--    Tagesangaben werden nach dem Kopieren neu angelegt.
PRAGMA defer_foreign_keys = ON;

ALTER TABLE time_day_details RENAME TO time_day_details_before_0043;

CREATE TABLE time_day_details (
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL,
    travel_category TEXT NOT NULL DEFAULT 'none' CHECK (travel_category IN ('none', 'single_short', 'single_long', 'arrival', 'full', 'departure')),
    abroad INTEGER NOT NULL DEFAULT 0 CHECK (abroad IN (0, 1)),
    absence_minutes INTEGER CHECK (absence_minutes IS NULL OR absence_minutes BETWEEN 0 AND 1440),
    overnight INTEGER NOT NULL DEFAULT 0 CHECK (overnight IN (0, 1)),
    meal_breakfast INTEGER NOT NULL DEFAULT 0 CHECK (meal_breakfast IN (0, 1)),
    meal_lunch INTEGER NOT NULL DEFAULT 0 CHECK (meal_lunch IN (0, 1)),
    meal_dinner INTEGER NOT NULL DEFAULT 0 CHECK (meal_dinner IN (0, 1)),
    location TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    updated_by_user_id TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (employee_id, work_date),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);

INSERT INTO time_day_details (employee_id, work_date, travel_category, abroad, absence_minutes, overnight, location, notes, updated_by_user_id, updated_at)
SELECT employee_id, work_date,
    CASE travel_day WHEN 'arrival' THEN 'arrival' WHEN 'departure' THEN 'departure' ELSE 'none' END,
    0,
    CASE WHEN absence_start IS NOT NULL AND absence_end IS NOT NULL THEN
        ((CAST(substr(absence_end, 1, 2) AS INTEGER) * 60 + CAST(substr(absence_end, 4, 2) AS INTEGER))
        - (CAST(substr(absence_start, 1, 2) AS INTEGER) * 60 + CAST(substr(absence_start, 4, 2) AS INTEGER)) + 1440) % 1440
    END,
    overnight, location, notes, updated_by_user_id, updated_at
FROM time_day_details_before_0043;

CREATE TABLE time_private_trips (
    id TEXT PRIMARY KEY,
    employee_id TEXT NOT NULL,
    work_date TEXT NOT NULL,
    kilometers TEXT NOT NULL,
    route TEXT NOT NULL DEFAULT '',
    purpose TEXT NOT NULL DEFAULT '',
    order_id TEXT,
    cost_center_id TEXT,
    notes TEXT NOT NULL DEFAULT '',
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_by_user_id TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (cost_center_id IS NULL OR order_id IS NULL),
    CHECK (CAST(kilometers AS REAL) > 0 AND CAST(kilometers AS REAL) <= 5000)
);
CREATE INDEX idx_time_private_trips_employee_date ON time_private_trips(employee_id, work_date);
CREATE INDEX idx_time_private_trips_order ON time_private_trips(order_id);
CREATE INDEX idx_time_private_trips_cost_center ON time_private_trips(cost_center_id);

INSERT INTO time_private_trips (id, employee_id, work_date, kilometers, route, purpose, order_id, notes, created_by_user_id, created_at, updated_by_user_id, updated_at)
SELECT lower(hex(randomblob(4)) || '-' || hex(randomblob(2)) || '-4' || substr(hex(randomblob(2)), 2) || '-a' || substr(hex(randomblob(2)), 2) || '-' || hex(randomblob(6))),
    employee_id, work_date, private_km, location, private_km_purpose, private_km_order_id, '', updated_by_user_id, updated_at, updated_by_user_id, updated_at
FROM time_day_details_before_0043
WHERE private_km IS NOT NULL AND CAST(private_km AS REAL) > 0 AND CAST(private_km AS REAL) <= 5000;

DROP TABLE time_day_details_before_0043;

CREATE TRIGGER time_day_details_lock_insert BEFORE INSERT ON time_day_details
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = NEW.employee_id AND l.work_date = NEW.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
CREATE TRIGGER time_day_details_lock_update BEFORE UPDATE ON time_day_details
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND ((l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date) OR (l.employee_id = NEW.employee_id AND l.work_date = NEW.work_date)))
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
CREATE TRIGGER time_day_details_lock_delete BEFORE DELETE ON time_day_details
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;

CREATE TRIGGER time_private_trips_lock_insert BEFORE INSERT ON time_private_trips
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = NEW.employee_id AND l.work_date = NEW.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
CREATE TRIGGER time_private_trips_lock_update BEFORE UPDATE ON time_private_trips
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND ((l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date) OR (l.employee_id = NEW.employee_id AND l.work_date = NEW.work_date)))
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
CREATE TRIGGER time_private_trips_lock_delete BEFORE DELETE ON time_private_trips
WHEN EXISTS (SELECT 1 FROM time_day_locks l WHERE l.status <> 'reopened' AND l.employee_id = OLD.employee_id AND l.work_date = OLD.work_date)
BEGIN SELECT RAISE(ABORT, 'calight_time_locked'); END;
