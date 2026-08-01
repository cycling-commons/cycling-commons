# Symfony API — PHP-FPM (fronted by nginx in compose; mirrors production).
# PHP 8.4: composer.json/composer.lock require php >=8.4 (Symfony 7.4 + PHP 8.4 target).
FROM php:8.4-fpm

# pdo_pgsql for PostgreSQL/PostGIS; gettext for sokil/php-isocodes translations
# (app:world:import); intl (needs libicu-dev) for EasyAdmin date/time formatting
# and locale-aware i18n; git+unzip for composer.
#
# imagick decodes and re-encodes rider photos (docs/specs/photo-uploads.md §3).
# libwebp-dev is mandatory — WebP is the ONLY stored format. libheif-dev is what
# makes HEIC (the iPhone default) readable; PhotoProcessor feature-detects the
# delegate at runtime, so a build without it degrades to a clear photo_format
# error rather than a crash.
#
# zip builds the GDPR data export (docs/specs/account-and-auth.md §11). NOT
# optional and not feature-detected: without it the export is a 500, and that
# endpoint answers a legal obligation. composer.json requires ext-zip so a host
# missing it fails at install rather than at a rider's request.
#
# Acquire::Retries is not decoration: deb.debian.org's index for trixie is a
# 13 MB file and a flaky link drops the connection part-way, which fails the
# whole build with a bare "Some index files failed to download".
RUN apt-get -o Acquire::Retries=15 update \
    && apt-get install -y --no-install-recommends \
        libpq-dev libicu-dev git unzip \
        libmagickwand-dev libwebp-dev libheif-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql gettext intl zip \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*

# Route GPX proposals can be up to 15 MB (spec §5.3/§15) — the php defaults
# (upload_max_filesize=2M, post_max_size=8M) would reject them before Symfony's
# File constraint. post_max_size stays comfortably above upload + form fields.
RUN { \
      echo 'upload_max_filesize = 16M'; \
      echo 'post_max_size = 20M'; \
    } > "$PHP_INI_DIR/conf.d/cc-uploads.ini"

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
#
# Deliberately no USER directive: the php-fpm master must start as root so it can
# drop each pool worker to www-data (pool config) — app code never runs as root.
# A USER line would disable that setuid drop and silence the pool user directives.
EXPOSE 9000
CMD ["php-fpm"]
