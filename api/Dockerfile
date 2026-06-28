# Symfony API — PHP-FPM (fronted by nginx in compose; mirrors production).
# PHP 8.4: composer.json/composer.lock require php >=8.4 (Symfony 7.4 + PHP 8.4 target).
FROM php:8.4-fpm

# pdo_pgsql for PostgreSQL/PostGIS; git+unzip for composer.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev git unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first for layer caching, using the committed lock for
# reproducible builds. The symfony/runtime plugin generates
# vendor/autoload_runtime.php during the autoload dump.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts \
    && composer dump-autoload --optimize

# App source is bind-mounted at runtime (see compose). vendor/ is preserved via a
# named volume so the mount doesn't hide it.
EXPOSE 9000
CMD ["php-fpm"]
