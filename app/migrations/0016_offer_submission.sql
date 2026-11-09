ALTER TABLE offers ADD COLUMN snapshot_relative_path TEXT;
ALTER TABLE offers ADD COLUMN snapshot_etag TEXT;
ALTER TABLE offers ADD COLUMN submitted_pdf_relative_path TEXT;
ALTER TABLE offers ADD COLUMN submitted_pdf_sha256 TEXT;
ALTER TABLE offers ADD COLUMN submitted_at TEXT;
ALTER TABLE offers ADD COLUMN document_date TEXT;
