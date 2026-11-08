UPDATE document_settings
SET settings_json = json_set(
    settings_json,
    '$.showEmptyTitles', json('false'),
    '$.logoWidth', 120,
    '$.logoPosition', 'right',
    '$.addressMode', 'standard',
    '$.baseFontSize', 8,
    '$.marginLeft', 45,
    '$.marginRight', 45,
    '$.marginTop', 42,
    '$.marginBottom', 82,
    '$.showHeader', json('true'),
    '$.showFooter', json('true'),
    '$.showProjectInfo', json('true'),
    '$.taxMode', 'standard',
    '$.taxNote', ''
)
WHERE id = 1;
