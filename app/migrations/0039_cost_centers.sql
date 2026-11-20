-- Allgemeine Kostenstellen (Stammdaten) und gemeinsame Kostenzuordnung.
--
-- Eine Kostenstelle ist ein internes Zuordnungsziel neben dem Kundenauftrag (z. B. K100
-- Verwaltung & Büro). Keine Buchhaltung: keine Konten, keine Steuern, keine Umlagen.
--
-- Grundregel: Jede Arbeitszeitbuchung und jede kostenrelevante Zuordnung hat höchstens ein
-- Kostenziel – order_id ODER cost_center_id (oder noch keines). Bestehende Auftragsbezüge
-- bleiben unverändert; alle neuen Spalten sind optional und zunächst leer. Es werden keine
-- Kostenstellen automatisch angelegt (Vorlage nur auf ausdrücklichen Wunsch, siehe
-- CostCenterService::importTemplate).
PRAGMA defer_foreign_keys = ON;

CREATE TABLE cost_centers (
    id TEXT PRIMARY KEY,
    number TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(trim(number)) BETWEEN 1 AND 20),
    name TEXT NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 120),
    description TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_cost_centers_active ON cost_centers(active, number);

-- Zeitbuchungen: nur manuelle Buchungen können einer Kostenstelle zugeordnet werden;
-- Rapport- und Bautagesberichtstunden folgen weiterhin dem Auftrag ihres Berichts.
ALTER TABLE time_entries ADD COLUMN cost_center_id TEXT REFERENCES cost_centers(id) ON DELETE RESTRICT
    CHECK (cost_center_id IS NULL OR (order_id IS NULL AND source_kind = 'manual'));
CREATE INDEX idx_time_entries_cost_center ON time_entries(cost_center_id, work_date);

-- Auslagen der Zeiterfassung.
ALTER TABLE time_expenses ADD COLUMN cost_center_id TEXT REFERENCES cost_centers(id) ON DELETE RESTRICT
    CHECK (cost_center_id IS NULL OR order_id IS NULL);
CREATE INDEX idx_time_expenses_cost_center ON time_expenses(cost_center_id, work_date);

-- Bestellungen (Hauptzuordnung).
ALTER TABLE purchase_orders ADD COLUMN cost_center_id TEXT REFERENCES cost_centers(id) ON DELETE RESTRICT
    CHECK (cost_center_id IS NULL OR order_id IS NULL);
CREATE INDEX idx_purchase_orders_cost_center ON purchase_orders(cost_center_id);

-- Kostenverteilung einer Bestellung: je Zeile genau ein Ziel (Auftrag oder Kostenstelle).
-- order_id war NOT NULL; Neuaufbau (keine Tabelle verweist auf purchase_order_allocations,
-- die Elterntabelle purchase_orders bleibt unverändert).
ALTER TABLE purchase_order_allocations RENAME TO purchase_order_allocations_before_cost_centers;

CREATE TABLE purchase_order_allocations (
    id TEXT PRIMARY KEY,
    purchase_order_id TEXT NOT NULL,
    order_id TEXT,
    cost_center_id TEXT,
    amount TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE RESTRICT,
    CHECK ((order_id IS NULL) <> (cost_center_id IS NULL)),
    UNIQUE (purchase_order_id, order_id),
    UNIQUE (purchase_order_id, cost_center_id)
);
INSERT INTO purchase_order_allocations (id, purchase_order_id, order_id, cost_center_id, amount, created_at)
SELECT id, purchase_order_id, order_id, NULL, amount, created_at FROM purchase_order_allocations_before_cost_centers;
DROP TABLE purchase_order_allocations_before_cost_centers;
CREATE INDEX idx_purchase_order_allocations_order ON purchase_order_allocations(order_id);
CREATE INDEX idx_purchase_order_allocations_cost_center ON purchase_order_allocations(cost_center_id);
