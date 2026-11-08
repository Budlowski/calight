UPDATE service_template_components
SET price_mode = 'manual'
WHERE price_mode = 'locked';
