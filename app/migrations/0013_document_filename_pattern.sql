UPDATE document_settings
SET settings_json = json_set(
    settings_json,
    '$.fileNamePattern', '{JJJJ}-{MM}-{TT}_{DOKUMENTTYP}_{DOKUMENTNUMMER}'
)
WHERE id = 1;
