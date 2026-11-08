INSERT INTO number_ranges
    (id, code, label, prefix, format, minimum_length, next_counter, reset_mode, manual_numbers_allowed, created_at, updated_at)
VALUES
    ('00000000-0000-4000-8000-000000000009', 'service_template', 'Leistungspositionen', 'LP', '{K}{NR}', 5, 1, 'none', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
