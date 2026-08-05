# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: Dockerfile
# Propósito: Construye la imagen principal de SIVI, instala las extensiones requeridas y ejecuta validaciones antes del despliegue.
FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libzip-dev libonig-dev libxml2-dev libcurl4-openssl-dev unzip \
        libjpeg62-turbo-dev libpng-dev libwebp-dev qrencode zbar-tools tesseract-ocr \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip mbstring simplexml xmlreader gd opcache curl \
    && a2enmod rewrite headers expires deflate \
    && printf '%s\n' 'ServerTokens Prod' 'ServerSignature Off' > /etc/apache2/conf-available/sivi-security.conf \
    && a2enconf sivi-security \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html
COPY . /var/www/html
COPY php.ini /usr/local/etc/php/conf.d/sivi.ini
RUN cp docker/apache-sivi-security-strong.conf /etc/apache2/conf-available/sivi-security-strong.conf \
    && a2enconf sivi-security-strong

# Controles funcionales de construcción. Las validaciones documentales se ejecutan manualmente
# y no deben impedir que una versión operativa se despliegue en Dokploy.
RUN sh scripts/run_production_build_checks.sh

RUN mkdir -p \
        storage/uploads \
        storage/logs \
        storage/import-previews \
        storage/reports \
        storage/backups \
    && chown -R www-data:www-data storage \
    && chmod +x docker/entrypoint.sh

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=6s --start-period=40s --retries=5 CMD ["php", "/var/www/html/scripts/healthcheck.php"]
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
