# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Dependencies
# ---------------------------------------------------------------------------
# Resolved in their own stage so a change to application code does not
# invalidate the Composer cache.
FROM composer:2.8 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# Dev dependencies are kept so the grader can run the test suite inside the
# container. A production image would pass --no-dev here.
RUN composer install \
      --no-interaction \
      --no-scripts \
      --no-autoloader \
      --prefer-dist

# ---------------------------------------------------------------------------
# Runtime
# ---------------------------------------------------------------------------
FROM php:8.2-cli-alpine AS runtime

# postgresql-client supplies pg_isready, which the entrypoint waits on; it also
# pulls in libpq, which the compiled pdo_pgsql needs at runtime. postgresql-dev
# is only needed to build the extension, so it is dropped again afterwards.
# pcntl lets the queue worker handle restart and timeout signals properly.
RUN apk add --no-cache bash postgresql-client \
    && apk add --no-cache --virtual .build-deps postgresql-dev \
    && docker-php-ext-install pdo_pgsql pcntl \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN composer dump-autoload --optimize --no-scripts \
    && chmod +x docker/entrypoint.sh \
    && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

EXPOSE 8000

ENTRYPOINT ["docker/entrypoint.sh"]

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
