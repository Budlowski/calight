CREATE TABLE document_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    settings_json TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO document_settings (id, settings_json) VALUES (1, '{"companyName":"","legalForm":"","owner":"","street":"","houseNumber":"","postalCode":"","city":"","country":"Deutschland","phone":"","email":"","website":"","taxNumber":"","vatId":"","iban":"","bic":"","bankName":"","commercialRegister":"","logoData":null,"activeTemplate":"standard","accentColor":"#155a8a","vatRate":"19.00","introText":"Sehr geehrte Damen und Herren,\n\nvielen Dank für Ihre Anfrage. Gerne bieten wir Ihnen nachfolgend an:","closingText":"Wir freuen uns auf Ihre Beauftragung.","paymentTerms":"Zahlbar innerhalb von 14 Tagen ohne Abzug.","footerText":""}');
