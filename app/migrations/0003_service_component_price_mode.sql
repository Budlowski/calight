ALTER TABLE service_template_components
    ADD COLUMN price_mode TEXT NOT NULL DEFAULT 'manual'
    CHECK (price_mode IN ('master', 'locked', 'manual'));

UPDATE service_template_components
SET price_mode = CASE
    WHEN master_data_id IS NULL THEN 'manual'
    WHEN price_locked = 1 THEN 'locked'
    ELSE 'master'
END;
