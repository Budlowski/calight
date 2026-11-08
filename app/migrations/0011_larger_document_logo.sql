UPDATE document_settings
SET settings_json = json_set(
    settings_json,
    '$.logoWidth', CASE WHEN CAST(json_extract(settings_json, '$.logoWidth') AS REAL) = 120 THEN 180 ELSE json_extract(settings_json, '$.logoWidth') END,
    '$.logoHeight', CASE WHEN CAST(json_extract(settings_json, '$.logoHeight') AS REAL) = 70 THEN 100 ELSE json_extract(settings_json, '$.logoHeight') END
)
WHERE id = 1;
