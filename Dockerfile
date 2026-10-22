FROM php:8.3-apache

# Reject cached PHP images missing the October 2026 security fixes.
# Covers CVE-2026-109618 and CVE-2026-109619 on the PHP 8.3 branch.
RUN php -r 'fwrite(STDOUT, "Build PHP version: " . PHP_VERSION . PHP_EOL); if (PHP_VERSION_ID < 80336 || PHP_VERSION_ID >= 80400) { fwrite(STDERR, "PHP 8.3.36 or a later 8.3 patch is required. Pull a patched base image and rebuild.\n"); exit(1); }'

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PORT=10000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl gd intl mbstring mysqli pdo_pgsql pgsql zip \
    && a2enmod rewrite headers setenvif \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

COPY . .
COPY deploy/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/render-entrypoint.sh /usr/local/bin/render-entrypoint

RUN chmod +x /usr/local/bin/render-entrypoint \
    && mkdir -p writable/cache writable/logs writable/session writable/uploads public/uploads \
    && chown -R www-data:www-data writable public/uploads \
    && printf 'Listen ${PORT}\n' > /etc/apache2/ports.conf

EXPOSE 10000

ENTRYPOINT ["render-entrypoint"]
CMD ["apache2-foreground"]
