-- Testdaten für SystemPurgeTest (nur isolierte Testdatenbanken).
-- Admin u-admin, Büro u-office. Kunde c1 mit Projekt p1, Regieauftrag o1, Tagesberichten,
-- festgeschriebener Zeiterfassungswoche, Fahrt, Material, Dokumenten; Kunde c2 ohne Vorgänge.
INSERT INTO users (id, username, password_hash, display_name, role, active, created_at, updated_at) VALUES
    ('u-admin', 'admin-test', 'hash', 'Admin', 'admin', 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'),
    ('u-office', 'office-test', 'hash', 'Büro', 'office', 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

INSERT INTO customers (id, customer_number, customer_type, company_name, created_at, updated_at) VALUES
    ('c1', 'K-1', 'company', 'Testkunde', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'),
    ('c2', 'K-2', 'company', 'Zweitkunde', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');
INSERT INTO assigned_numbers (id, number_range_id, business_number, entity_type, entity_id, counter_value, assigned_manually, assigned_at)
    SELECT 'n1', id, 'K-1', 'customer', 'c1', 10001, 0, '2026-01-01T00:00:00Z' FROM number_ranges WHERE code = 'customer';
INSERT INTO assigned_numbers (id, number_range_id, business_number, entity_type, entity_id, counter_value, assigned_manually, assigned_at)
    SELECT 'n2', id, 'K-2', 'customer', 'c2', 10002, 0, '2026-01-01T00:00:00Z' FROM number_ranges WHERE code = 'customer';

INSERT INTO projects (id, project_number, customer_id, name, created_at, updated_at) VALUES
    ('p1', 'P-1', 'c1', 'Testprojekt', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');
INSERT INTO orders (id, order_number, customer_id, order_type, title, order_date, status, created_at, updated_at) VALUES
    ('o1', 'A-1', 'c1', 'regie', 'Regie', '2026-03-01', 'in_progress', '2026-03-01T00:00:00Z', '2026-03-01T00:00:00Z');

INSERT INTO employees (id, display_name, hourly_cost_rate, created_at, updated_at) VALUES
    ('e1', 'Max', '40.0000', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');
INSERT INTO cost_centers (id, number, name, created_at, updated_at) VALUES
    ('k1', 'K100', 'Werkstatt', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

-- Tagesbericht d1 (März, später festgeschrieben) und Entwurf d2 (April, ohne Auftrag).
INSERT INTO daily_reports (id, customer_id, order_id, report_date, activities, created_by_user_id, created_at, updated_at) VALUES
    ('d1', 'c1', 'o1', '2026-03-10', 'Montage', 'u-admin', '2026-03-10T08:00:00Z', '2026-03-10T08:00:00Z'),
    ('d2', NULL, NULL, '2026-04-15', 'Inventur', 'u-admin', '2026-04-15T08:00:00Z', '2026-04-15T08:00:00Z');
INSERT INTO daily_report_time_entries (id, report_id, employee_id, work_date, duration_hours, hourly_cost_rate_snapshot, labor_cost, created_at, updated_at) VALUES
    ('de1', 'd1', 'e1', '2026-03-10', '2.000', '40.0000', '80.00', '2026-03-10T08:00:00Z', '2026-03-10T08:00:00Z'),
    ('de2', 'd2', 'e1', '2026-04-15', '1.000', '40.0000', '40.00', '2026-04-15T08:00:00Z', '2026-04-15T08:00:00Z');
INSERT INTO time_entries (id, employee_id, work_date, source_kind, daily_report_entry_id, net_minutes, activity, order_id, created_by_user_id, created_at, updated_by_user_id, updated_at) VALUES
    ('t-d1', 'e1', '2026-03-10', 'daily_report', 'de1', 120, 'work', 'o1', 'u-admin', '2026-03-10T08:00:00Z', 'u-admin', '2026-03-10T08:00:00Z'),
    ('t-d2', 'e1', '2026-04-15', 'daily_report', 'de2', 60, 'work', NULL, 'u-admin', '2026-04-15T08:00:00Z', 'u-admin', '2026-04-15T08:00:00Z'),
    ('t-m1', 'e1', '2026-03-11', 'manual', NULL, 60, 'work', NULL, 'u-admin', '2026-03-11T08:00:00Z', 'u-admin', '2026-03-11T08:00:00Z');
UPDATE daily_reports SET status = 'approved', submitted_once = 1 WHERE id = 'd1';
INSERT INTO daily_report_statements (id, report_id, revision, status, snapshot_json, file_name, storage_path, sha256, size_bytes, created_by_user_id, created_at) VALUES
    ('ds1', 'd1', 1, 'current', '{}', 'Tagesbericht.pdf', 'daily-report-statements/d1/ds1.pdf', 'x', 10, 'u-admin', '2026-03-12T08:00:00Z');
INSERT INTO time_private_trips (id, employee_id, work_date, kilometers, created_by_user_id, created_at, updated_by_user_id, updated_at) VALUES
    ('trip1', 'e1', '2026-03-10', '12.0', 'u-admin', '2026-03-10T08:00:00Z', 'u-admin', '2026-03-10T08:00:00Z');

-- Festgeschriebene Woche 2026-W11 (09.–15.03.).
INSERT INTO time_submissions (id, employee_id, period_type, period_key, from_date, to_date, status, summary_json, submitted_by_user_id, submitted_at, decided_by_user_id, decided_at) VALUES
    ('s1', 'e1', 'week', '2026-W11', '2026-03-09', '2026-03-15', 'approved', '{}', 'u-admin', '2026-03-16T08:00:00Z', 'u-admin', '2026-03-16T09:00:00Z');
INSERT INTO time_day_locks (employee_id, work_date, status, submission_id, updated_by_user_id, updated_at)
    WITH RECURSIVE days(d) AS (SELECT '2026-03-09' UNION ALL SELECT date(d, '+1 day') FROM days WHERE d < '2026-03-15')
    SELECT 'e1', d, 'approved', 's1', 'u-admin', '2026-03-16T09:00:00Z' FROM days;
INSERT INTO time_statements (id, employee_id, period_type, period_key, from_date, to_date, revision, status, submission_id, snapshot_json, file_name, storage_path, sha256, size_bytes, created_by_user_id, created_at) VALUES
    ('ts1', 'e1', 'week', '2026-W11', '2026-03-09', '2026-03-15', 1, 'current', 's1', '{}', 'Nachweis.pdf', 'time-statements/e1/ts1.pdf', 'x', 10, 'u-admin', '2026-03-16T09:00:00Z');

INSERT INTO material_requests (id, customer_id, order_id, daily_report_id, description, created_by_user_id, created_at, updated_at) VALUES
    ('mr1', 'c1', 'o1', 'd1', 'Kabel', 'u-admin', '2026-03-10T08:00:00Z', '2026-03-10T08:00:00Z');

-- Dokumente: a1 nur am Kunden c1, a2 am Projekt p1 und am Kunden c2 (gemeinsam verwendet).
INSERT INTO attachments (id, original_name, mime_type, size_bytes, sha256, storage_path, uploaded_by_user_id, created_at) VALUES
    ('a1', 'plan.pdf', 'application/pdf', 10, 'x', 'attachments/a1.pdf', 'u-admin', '2026-01-02T00:00:00Z'),
    ('a2', 'foto.pdf', 'application/pdf', 10, 'x', 'attachments/a2.pdf', 'u-admin', '2026-01-02T00:00:00Z');
INSERT INTO attachment_links (attachment_id, target_type, target_id, created_by_user_id, created_at) VALUES
    ('a1', 'customer', 'c1', 'u-admin', '2026-01-02T00:00:00Z'),
    ('a2', 'project', 'p1', 'u-admin', '2026-01-02T00:00:00Z'),
    ('a2', 'customer', 'c2', 'u-admin', '2026-01-02T00:00:00Z');
