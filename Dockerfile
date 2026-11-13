FROM composer:2 AS composer
FROM php:8.4-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev libxml2-dev unzip \
    && docker-php-ext-install pdo_pgsql mbstring dom xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p .phpunit.cache .phpstan.cache \
    && chown -R www-data:www-data .phpunit.cache .phpstan.cache
USER www-data
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
