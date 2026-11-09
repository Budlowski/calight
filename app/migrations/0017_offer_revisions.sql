ALTER TABLE offers ADD COLUMN revision_root_id TEXT REFERENCES offers(id) ON DELETE RESTRICT;
ALTER TABLE offers ADD COLUMN revision_number INTEGER NOT NULL DEFAULT 0 CHECK (revision_number >= 0);
ALTER TABLE offers ADD COLUMN previous_revision_id TEXT REFERENCES offers(id) ON DELETE RESTRICT;

UPDATE offers SET revision_root_id = id WHERE revision_root_id IS NULL;

CREATE UNIQUE INDEX idx_offers_revision_number
    ON offers(revision_root_id, revision_number);

CREATE UNIQUE INDEX idx_offers_single_open_revision
    ON offers(revision_root_id)
    WHERE status = 'draft';

CREATE UNIQUE INDEX idx_offers_single_commissioned_revision
    ON offers(revision_root_id)
    WHERE status = 'commissioned';

CREATE INDEX idx_offers_revision_root
    ON offers(revision_root_id, revision_number DESC);
