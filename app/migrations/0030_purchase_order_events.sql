-- Phase 3.4.1.1: Bestellverwaltung als Vorgang (rein additiv zu 0029).
--
-- 1. Lieferantenstamm: Anschrift und eigene Kundennummer beim Lieferanten (alle optional).
-- 2. Auftragsbestätigungen, Lieferungen und Zahlungen als einzelne, unveränderliche Ereignisse.
--    Liefer- und Zahlungsstatus der Bestellung ergeben sich aus dem zuletzt erfassten Ereignis
--    (resulting_status); purchase_orders.delivery_status/payment_status bleiben als abgeleitete
--    Werte für Liste, Filter und bestehende Auswertungen erhalten.
-- 3. Lieferantenbelege: Belegart (Rechnung, Teil-, Schlussrechnung, Gutschrift, Stornobeleg),
--    Erfassungsart der Schlussrechnung und Bezug auf einen Originalbeleg. Beträge werden immer
--    positiv erfasst; die Kostenwirkung berechnet Domain\Purchase\PurchaseCostCalculator.
--    purchase_orders.invoiced_net_total speichert das Ergebnis für Liste und Sortierung.
-- 4. Statusverlauf mit erweitertem Feldkatalog (Tabellenneubau, nur wegen des CHECK).
--
-- Bestandsdaten: bisher manuell gesetzte Liefer-/Zahlungsstatus werden als Korrekturereignis
-- übernommen, vorhandene Rechnungen gelten als normale Rechnungen.

-- 1. Lieferanten -------------------------------------------------------------------------------
ALTER TABLE suppliers ADD COLUMN street TEXT NOT NULL DEFAULT '';
ALTER TABLE suppliers ADD COLUMN house_number TEXT NOT NULL DEFAULT '';
ALTER TABLE suppliers ADD COLUMN postal_code TEXT NOT NULL DEFAULT '';
ALTER TABLE suppliers ADD COLUMN city TEXT NOT NULL DEFAULT '';
ALTER TABLE suppliers ADD COLUMN country TEXT NOT NULL DEFAULT '';
-- Unsere Kundennummer beim Lieferanten (erscheint auf dem Bestell-PDF).
ALTER TABLE suppliers ADD COLUMN customer_number TEXT NOT NULL DEFAULT '';

-- 2a. Auftragsbestätigungen ---------------------------------------------------------------------
-- Mehrere Fassungen möglich; höchstens eine ist ausdrücklich „aktuell“. Bestätigte Beträge
-- sind weder ursprünglicher Bestellwert noch Ist-Kosten.
CREATE TABLE purchase_order_confirmations (
    id TEXT PRIMARY KEY,
    purchase_order_id TEXT NOT NULL,
    confirmation_number TEXT NOT NULL DEFAULT '',
    confirmation_date TEXT NOT NULL,
    confirmed_net_total TEXT,
    confirmed_delivery_date TEXT,
    document_id TEXT,
    notes TEXT NOT NULL DEFAULT '',
    is_current INTEGER NOT NULL DEFAULT 0 CHECK (is_current IN (0, 1)),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (document_id) REFERENCES purchase_order_documents(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_purchase_order_confirmations_order ON purchase_order_confirmations(purchase_order_id, created_at);
CREATE UNIQUE INDEX ux_purchase_order_confirmations_current ON purchase_order_confirmations(purchase_order_id) WHERE is_current = 1;

-- 2b. Lieferungen, Rücksendungen und Lieferkorrekturen (ohne Mengenabgleich je Position) -----------
CREATE TABLE purchase_order_deliveries (
    id TEXT PRIMARY KEY,
    purchase_order_id TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('partial', 'complete', 'return', 'correction')),
    delivery_date TEXT NOT NULL,
    delivery_note_number TEXT NOT NULL DEFAULT '',
    document_id TEXT,
    notes TEXT NOT NULL DEFAULT '',
    resulting_status TEXT NOT NULL CHECK (resulting_status IN ('pending', 'partial', 'delivered')),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (document_id) REFERENCES purchase_order_documents(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (kind <> 'partial' OR resulting_status = 'partial'),
    CHECK (kind <> 'complete' OR resulting_status = 'delivered')
);
CREATE INDEX idx_purchase_order_deliveries_order ON purchase_order_deliveries(purchase_order_id, created_at);

-- 2c. Zahlungsvermerke (keine Zahlungsabwicklung) -------------------------------------------------
CREATE TABLE purchase_order_payments (
    id TEXT PRIMARY KEY,
    purchase_order_id TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('payment', 'refund', 'correction')),
    payment_date TEXT NOT NULL,
    amount TEXT,
    notes TEXT NOT NULL DEFAULT '',
    resulting_status TEXT NOT NULL CHECK (resulting_status IN ('open', 'partial', 'paid')),
    created_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    -- Zahlung und Rückerstattung haben einen Betrag, eine reine Statuskorrektur nicht.
    CHECK ((kind = 'correction') = (amount IS NULL))
);
CREATE INDEX idx_purchase_order_payments_order ON purchase_order_payments(purchase_order_id, created_at);

-- Bisher manuell gesetzte Status als nachvollziehbare Korrektur übernehmen.
INSERT INTO purchase_order_deliveries (id, purchase_order_id, kind, delivery_date, notes, resulting_status, created_by_user_id, created_at)
SELECT lower(hex(randomblob(4))) || '-' || lower(hex(randomblob(2))) || '-4' || substr(lower(hex(randomblob(2))), 2) || '-a'
        || substr(lower(hex(randomblob(2))), 2) || '-' || lower(hex(randomblob(6))),
    id, 'correction', substr(updated_at, 1, 10), 'Übernommen aus dem bisherigen Lieferstatus.', delivery_status, created_by_user_id, updated_at
FROM purchase_orders WHERE delivery_status <> 'pending';

INSERT INTO purchase_order_payments (id, purchase_order_id, kind, payment_date, amount, notes, resulting_status, created_by_user_id, created_at)
SELECT lower(hex(randomblob(4))) || '-' || lower(hex(randomblob(2))) || '-4' || substr(lower(hex(randomblob(2))), 2) || '-a'
        || substr(lower(hex(randomblob(2))), 2) || '-' || lower(hex(randomblob(6))),
    id, 'correction', substr(updated_at, 1, 10), NULL, 'Übernommen aus dem bisherigen Zahlungsstatus.', payment_status, created_by_user_id, updated_at
FROM purchase_orders WHERE payment_status <> 'open';

-- 3. Lieferantenbelege ----------------------------------------------------------------------------
ALTER TABLE purchase_order_invoices ADD COLUMN kind TEXT NOT NULL DEFAULT 'invoice'
    CHECK (kind IN ('invoice', 'partial', 'final', 'credit', 'reversal'));
-- Schlussrechnung: 'additional' = zusätzlicher Betrag, 'total' = Gesamtrechnung inkl. Teilrechnungen.
ALTER TABLE purchase_order_invoices ADD COLUMN final_mode TEXT
    CHECK ((kind = 'final') = (final_mode IS NOT NULL) AND (final_mode IS NULL OR final_mode IN ('additional', 'total')));
-- Originalbeleg (Pflicht beim Stornobeleg, optional bei Gutschriften).
ALTER TABLE purchase_order_invoices ADD COLUMN related_invoice_id TEXT
    REFERENCES purchase_order_invoices(id) ON DELETE RESTRICT
    CHECK (kind <> 'reversal' OR related_invoice_id IS NOT NULL);
CREATE INDEX idx_purchase_order_invoices_related ON purchase_order_invoices(related_invoice_id);

-- Abgeleitete Summe der Kostenwirkung aller Belege (NULL = keine Belege).
ALTER TABLE purchase_orders ADD COLUMN invoiced_net_total TEXT;
-- Bestandsrechnungen sind normale Rechnungen: exakte Summe über ganze Cent (keine Gleitkommasumme).
UPDATE purchase_orders SET invoiced_net_total = (
    SELECT CASE WHEN cents < 0 THEN '-' ELSE '' END || (abs(cents) / 100) || '.' || printf('%02d', abs(cents) % 100)
    FROM (SELECT SUM(CAST(round(CAST(i.net_amount AS REAL) * 100) AS INTEGER)) AS cents
          FROM purchase_order_invoices i WHERE i.purchase_order_id = purchase_orders.id)
)
WHERE EXISTS (SELECT 1 FROM purchase_order_invoices i WHERE i.purchase_order_id = purchase_orders.id);

-- 4. Statusverlauf mit erweitertem Feldkatalog ----------------------------------------------------
-- Keine Tabelle verweist auf purchase_order_history; der Neubau ist daher gefahrlos.
CREATE TABLE purchase_order_history_new (
    id TEXT PRIMARY KEY,
    purchase_order_id TEXT NOT NULL,
    field TEXT NOT NULL CHECK (field IN ('status', 'delivery', 'payment', 'revision', 'confirmation', 'invoice', 'cost', 'allocation', 'edit')),
    old_value TEXT,
    new_value TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);
INSERT INTO purchase_order_history_new (id, purchase_order_id, field, old_value, new_value, note, user_id, created_at)
    SELECT id, purchase_order_id, field, old_value, new_value, note, user_id, created_at FROM purchase_order_history ORDER BY rowid;
DROP TABLE purchase_order_history;
ALTER TABLE purchase_order_history_new RENAME TO purchase_order_history;
CREATE INDEX idx_purchase_order_history_order ON purchase_order_history(purchase_order_id, created_at);
