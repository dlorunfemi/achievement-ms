#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# A database for the suite to destroy
# ---------------------------------------------------------------------------
# RefreshDatabase drops and rebuilds every table it touches. Pointed at the
# database the running stack serves from, "docker compose exec app php artisan
# test" would take the demo store with it, which is the one thing the stack is
# up to show. So the suite gets its own database:
#
#   docker compose exec -e DB_DATABASE=bumpa_db_test app php artisan test
#
# Postgres only runs this on first initialisation of the data volume. An
# existing stack needs it once by hand:
#
#   docker compose exec postgres psql -U bumpa -d bumpa_db \
#     -c 'CREATE DATABASE bumpa_db_test OWNER bumpa;'
# ---------------------------------------------------------------------------

psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE ${POSTGRES_DB}_test OWNER $POSTGRES_USER;
SQL
