FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip opcache \
    && a2enmod rewrite headers expires deflate remoteip \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/dayflow-servername.conf \
    && a2enconf dayflow-servername \
    && rm -rf /var/lib/apt/lists/*

COPY docker/remoteip.conf /etc/apache2/conf-available/dayflow-remoteip.conf
RUN a2enconf dayflow-remoteip

# Only public/ is web-reachable; the code, config, uploads and logs sit beside
# it under /var/www/html. The second sed moves the image's
# <Directory /var/www/> block (AllowOverride All) along with it.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/dayflow.ini
COPY . /var/www/html/

RUN mkdir -p /var/www/html/storage/logs /var/www/html/storage/ratelimit \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/uploads

WORKDIR /var/www/html

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health | grep -q '"ok":true'
