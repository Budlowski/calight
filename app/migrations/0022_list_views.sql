-- Phase 3.2.1.3: serverseitige Pagination, Suche und Sortierung der Übersichten.

-- Listen-Cache für Angebote. Titel und Nettosumme stehen im Angebots-JSON und
-- werden für Suche/Sortierung in SQLite gespiegelt. list_etag hält fest, aus
-- welchem Dokumentstand (Entwurfs- bzw. Snapshot-ETag) der Cache stammt; ein
-- abweichender ETag kennzeichnet veraltete Einträge, die OfferService beim
-- nächsten Listenabruf aus dem Dokument neu befüllt. Bestehende Angebote
-- bleiben dadurch zunächst leer und werden beim ersten Abruf übernommen.
ALTER TABLE offers ADD COLUMN list_title TEXT;
ALTER TABLE offers ADD COLUMN list_net_total TEXT;
ALTER TABLE offers ADD COLUMN list_etag TEXT;

-- Ursprüngliche Auftragssumme aus der unveränderlichen Auftragsgrundlage,
-- unverändert als Dezimalstring übernommen (keine Gleitkommaarithmetik).
ALTER TABLE orders ADD COLUMN original_net_total TEXT;
UPDATE orders
SET original_net_total = CAST(json_extract(basis_json, '$.calculation.netTotal') AS TEXT)
WHERE basis_json IS NOT NULL AND json_valid(basis_json);

-- Standardsortierungen der Übersichten.
CREATE INDEX idx_customers_updated ON customers(updated_at DESC);
CREATE INDEX idx_projects_updated ON projects(updated_at DESC);
CREATE INDEX idx_offers_updated ON offers(updated_at DESC);
CREATE INDEX idx_orders_updated ON orders(updated_at DESC);
CREATE INDEX idx_daily_reports_updated ON daily_reports(updated_at DESC);
