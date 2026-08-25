#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# Container entrypoint
# ---------------------------------------------------------------------------
# Shared by the web and queue containers. Only the web container migrates, so
# two containers starting together cannot run migrations concurrently.
# ---------------------------------------------------------------------------

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

# Deferred from the image build, where no .env or APP_KEY exists yet.
php artisan package:discover --ansi

echo "Waiting for ${DB_HOST:-postgres}:${DB_PORT:-5432} ..."
until pg_isready \
        --host="${DB_HOST:-postgres}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME:-bumpa}" \
        --dbname="${DB_DATABASE:-bumpa_db}" \
        --quiet; do
    sleep 2
done
echo "Database is up."

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    # The catalog seeders use updateOrCreate, so re-running them is harmless.
    php artisan migrate --force --seed --no-interaction
fi

exec "$@"
