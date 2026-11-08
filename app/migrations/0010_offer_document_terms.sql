UPDATE document_settings
SET settings_json = json_set(
    settings_json,
    '$.logoHeight', 70,
    '$.validityMode', 'duration',
    '$.validityDays', 30,
    '$.validityDate', '',
    '$.additionalTerms', ''
)
WHERE id = 1;
