#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# Container entrypoint
# ---------------------------------------------------------------------------
# Shared by the web, queue and scheduler containers. Only the web container
# migrates, so two containers starting together cannot migrate concurrently.
# ---------------------------------------------------------------------------

if [ ! -f .env ]; then
    cp .env.example .env
fi

# ---------------------------------------------------------------------------
# Compose environment -> .env
# ---------------------------------------------------------------------------
# "php artisan serve" spawns the PHP built-in server through a child process
# whose environment is stripped down to ServeCommand::$passthroughVariables —
# APP_ENV, PATH and a handful of debugger hooks. Everything Compose sets here
# (DB_HOST above all) is blanked on the way in, so the served application falls
# back to .env and reads .env.example's DB_HOST=127.0.0.1 instead of the
# postgres service. Artisan commands run in this process and see the real
# values, which is why migrations succeed while every HTTP request 500s.
#
# Writing the values into .env is what closes that gap: both the parent process
# and the served child then read the same configuration.
# ---------------------------------------------------------------------------

SYNCED_ENV_KEYS=(
    APP_NAME APP_ENV APP_KEY APP_DEBUG APP_URL APP_LOCALE
    LOG_CHANNEL LOG_STACK LOG_LEVEL
    DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
    QUEUE_CONNECTION CACHE_STORE SESSION_DRIVER BROADCAST_CONNECTION FILESYSTEM_DISK
    PAYMENTS_GATEWAY
    CASHBACK_BADGE_REWARD_MINOR CASHBACK_CURRENCY CASHBACK_RECONCILE_AFTER_MINUTES
    PAYSTACK_SECRET_KEY PAYSTACK_BASE_URL
    FLUTTERWAVE_SECRET_KEY FLUTTERWAVE_BASE_URL
    MONNIFY_API_KEY MONNIFY_SECRET_KEY MONNIFY_BASE_URL MONNIFY_SOURCE_ACCOUNT_NUMBER
)

# Values that carry whitespace, a comment marker or a quote are wrapped; the
# rest are written bare so booleans and numbers stay unquoted for env().
escape_env_value() {
    case "$1" in
        *[[:space:]\#\"\'\\]*)
            printf '"%s"' "$(printf '%s' "$1" | sed 's/[\\"]/\\&/g')"
            ;;
        *)
            printf '%s' "$1"
            ;;
    esac
}

# Rewritten rather than appended, so restarting the container does not stack a
# second copy of every key on top of the first.
write_env_value() {
    local key="$1"
    local value="$2"

    grep -v "^${key}=" .env > .env.tmp || true
    printf '%s=%s\n' "$key" "$(escape_env_value "$value")" >> .env.tmp
    mv .env.tmp .env
}

for key in "${SYNCED_ENV_KEYS[@]}"; do
    if [ -n "${!key+set}" ]; then
        write_env_value "$key" "${!key}"
    fi
done

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
