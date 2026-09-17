FROM php:8.4-apache-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git curl \
    && docker-php-ext-install pdo_pgsql pcntl bcmath zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/stack.ini
WORKDIR /var/www/html

FROM base AS development

FROM base AS production
COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY app/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/stack-entrypoint
ENTRYPOINT ["stack-entrypoint"]
CMD ["apache2-foreground"]
