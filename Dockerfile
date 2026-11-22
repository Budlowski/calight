FROM composer:2.11 AS composer
FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libsqlite3-dev \
    && docker-php-ext-install mbstring pdo_sqlite \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

# Anhänge zu Bautagesberichten (Handyfotos): bis 15 MB je Datei, eine Datei pro Anfrage.
RUN printf 'upload_max_filesize = 16M\npost_max_size = 20M\n' > /usr/local/etc/php/conf.d/calight-uploads.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN printf '<Directory /var/www/app/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/calight.conf \
    && a2enconf calight

WORKDIR /var/www/app
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative
COPY app/ ./
RUN mkdir -p /var/www/data/database /var/www/data/offers /var/www/data/documents /var/www/data/templates /var/www/data/backups \
    && chown -R www-data:www-data /var/www/data /var/www/app/var \
    && chmod 700 /var/www/data/backups

EXPOSE 80
