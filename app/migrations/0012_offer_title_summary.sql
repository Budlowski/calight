UPDATE document_settings
SET settings_json = json_set(settings_json, '$.showTitleSummary', json('true'))
WHERE id = 1;
