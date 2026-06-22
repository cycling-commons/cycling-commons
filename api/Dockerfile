# Symfony API — PHP-FPM (fronted by nginx in compose; mirrors production).
FROM php:8.3-fpm

# pdo_pgsql for PostgreSQL/PostGIS; git+unzip for composer.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev git unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first for layer caching. No lock file is committed for the
# scaffold, so `install` falls back to resolving + writing one. The symfony/runtime
# plugin generates vendor/autoload_runtime.php during the autoload dump.
COPY composer.json ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts \
    && composer dump-autoload --optimize

# App source is bind-mounted at runtime (see compose). vendor/ is preserved via a
# named volume so the mount doesn't hide it.
EXPOSE 9000
CMD ["php-fpm"]
