ALTER TABLE service_templates ADD COLUMN article_number TEXT COLLATE NOCASE;

CREATE UNIQUE INDEX uq_service_templates_article_number
    ON service_templates(article_number)
    WHERE article_number IS NOT NULL;

ALTER TABLE service_template_components ADD COLUMN source_article_number TEXT;

UPDATE service_template_components
SET source_article_number = (
    SELECT article_number FROM cost_items WHERE cost_items.id = service_template_components.master_data_id
)
WHERE master_data_id IS NOT NULL;
