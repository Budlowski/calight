<?php

declare(strict_types=1);

use App\Application\Config;

return new Config(
    databasePath: getenv('APP_DATABASE_PATH') ?: '/var/www/data/database/app.sqlite',
    offersPath: getenv('APP_OFFERS_PATH') ?: '/var/www/data/offers',
    documentsPath: getenv('APP_DOCUMENTS_PATH') ?: '/var/www/data/documents',
    templatesPath: getenv('APP_TEMPLATES_PATH') ?: '/var/www/data/templates',
    sessionName: getenv('APP_SESSION_NAME') ?: 'kalkulation_session',
    secureCookies: filter_var(getenv('APP_SECURE_COOKIES') ?: '0', FILTER_VALIDATE_BOOL),
    timezone: getenv('APP_TIMEZONE') ?: 'Europe/Berlin',
);
