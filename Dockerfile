FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip \
    && a2enmod rewrite headers expires \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/dayflow-servername.conf \
    && a2enconf dayflow-servername \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/dayflow.ini
COPY . /var/www/html/

RUN mkdir -p /var/www/html/storage/logs /var/www/html/storage/ratelimit \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/uploads

WORKDIR /var/www/html

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health | grep -q '"ok":true'
