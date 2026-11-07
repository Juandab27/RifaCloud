#!/usr/bin/env bash
# RifaCloud — reproducible production database bootstrap.
#
# Safety:
#   - never runs migrate:fresh, never drops the schema, never deletes volumes;
#   - requires CONFIRM_MIGRATE=yes;
#   - creates an encrypted backup before migrating an existing schema;
#   - creates/updates the four least-privilege service users from the root .env;
#   - applies deploy/aws/grants-production.sql.
#
# Usage on the EC2 host, from the repository root:
#   set -a; . ./.env; set +a
#   CONFIRM_MIGRATE=yes deploy/aws/bootstrap-db.sh
#
# Values in .env must be single-quoted if they contain spaces or shell
# metacharacters. Passwords are never printed.

set -Eeuo pipefail
umask 077

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)
ENV_FILE="$REPO_ROOT/.env"
GRANTS_FILE="$REPO_ROOT/deploy/aws/grants-production.sql"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "ERROR: $ENV_FILE is missing. Copy deploy/aws/env.production.example to .env and fill it." >&2
    exit 1
fi

# Load the production values for this shell. The file must be trusted and
# root-owned (chmod 600).
set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

require_value() {
    local name="$1"
    if [[ -z "${!name:-}" ]]; then
        echo "ERROR: $name is empty in $ENV_FILE" >&2
        exit 1
    fi
}

for name in APP_KEY APP_URL APP_DOMAIN DB_PASSWORD AUTH_DB_PASSWORD RAFFLES_DB_PASSWORD \
    ORDERS_DB_PASSWORD PAYMENTS_DB_PASSWORD; do
    require_value "$name"
done

if [[ "${CONFIRM_MIGRATE:-}" != "yes" ]]; then
    echo "Refusing to migrate without explicit confirmation." >&2
    echo "Review the pending migrations and run:" >&2
    echo "  CONFIRM_MIGRATE=yes deploy/aws/bootstrap-db.sh" >&2
    exit 1
fi

# payments-service retains the base env_file for local development. In
# production it only needs a non-secret placeholder; explicit production
# variables are injected by docker-compose.prod.yml.
if [[ ! -f "$REPO_ROOT/src/.env" ]]; then
    printf '# production: secrets are supplied by the root .env; do not store secrets here\n' > "$REPO_ROOT/src/.env"
    chmod 600 "$REPO_ROOT/src/.env"
fi

"${COMPOSE[@]}" config -q

echo "Starting MySQL..."
"${COMPOSE[@]}" up -d mysql

echo "Waiting for MySQL to become healthy..."
for _ in $(seq 1 90); do
    if "${COMPOSE[@]}" exec -T mysql sh -c 'mysqladmin ping -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" --silent' >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

if ! "${COMPOSE[@]}" exec -T mysql sh -c 'mysqladmin ping -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" --silent' >/dev/null 2>&1; then
    echo "ERROR: MySQL did not become healthy." >&2
    exit 1
fi

TABLE_COUNT="$("${COMPOSE[@]}" exec -T mysql sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \"$MYSQL_DATABASE\";"
' | tr -d '[:space:]')"

if [[ "${TABLE_COUNT:-0}" -gt 0 ]]; then
    echo "Existing schema detected ($TABLE_COUNT tables). A pre-migration backup is required."
    if [[ "${SKIP_PRE_MIGRATION_BACKUP:-}" == "yes" ]]; then
        echo "WARNING: SKIP_PRE_MIGRATION_BACKUP=yes; no backup will be taken." >&2
    else
        "$REPO_ROOT/deploy/aws/backup-db.sh"
    fi
fi

echo "Applying pending Laravel migrations (13 expected on a fresh schema)..."
"${COMPOSE[@]}" run --rm app php artisan migrate --force

sql_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/''/g"
}

SQL_FILE="$(mktemp)"
trap 'rm -f "$SQL_FILE"' EXIT
chmod 600 "$SQL_FILE"

{
    printf "CREATE USER IF NOT EXISTS 'rifacloud_auth'@'%%' IDENTIFIED BY '%s';\n" "$(sql_escape "$AUTH_DB_PASSWORD")"
    printf "ALTER USER 'rifacloud_auth'@'%%' IDENTIFIED BY '%s';\n" "$(sql_escape "$AUTH_DB_PASSWORD")"
    printf "CREATE USER IF NOT EXISTS 'rifacloud_raffles'@'%%' IDENTIFIED BY '%s';\n" "$(sql_escape "$RAFFLES_DB_PASSWORD")"
    printf "ALTER USER 'rifacloud_raffles'@'%%' IDENTIFIED BY '%s';\n" "$(sql_escape "$RAFFLES_DB_PASSWORD")"
    printf "CREATE USER IF NOT EXISTS 'rifacloud_orders'@'%%' IDENTIFIED BY '%s';\n" "$(sql_escape "$ORDERS_DB_PASSWORD")"
    printf "ALTER USER 'rifacloud_orders'@'%%' IDENTIFIED BY '%s';\n" "$(sql_escape "$ORDERS_DB_PASSWORD")"
    printf "CREATE USER IF NOT EXISTS 'rifacloud_payments'@'%%' IDENTIFIED BY '%s';\n" "$(sql_escape "$PAYMENTS_DB_PASSWORD")"
    printf "ALTER USER 'rifacloud_payments'@'%%' IDENTIFIED BY '%s';\n" "$(sql_escape "$PAYMENTS_DB_PASSWORD")"
} > "$SQL_FILE"

echo "Creating/updating least-privilege MySQL users..."
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot' < "$SQL_FILE"

if [[ ! -f "$GRANTS_FILE" ]]; then
    echo "ERROR: $GRANTS_FILE is missing." >&2
    exit 1
fi

echo "Applying main-schema grants..."
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot' < "$GRANTS_FILE"

if [[ "${SEED_ADMIN:-}" == "yes" ]]; then
    require_value ADMIN_EMAIL
    require_value ADMIN_PASSWORD
    echo "Seeding the initial administrator from the root .env..."
    "${COMPOSE[@]}" run --rm app php artisan db:seed --class=AdminUserSeeder --force
fi

echo "Verifying migrations and grants..."
"${COMPOSE[@]}" run --rm app php artisan migrate:status
"${COMPOSE[@]}" exec -T mysql sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -e \
    "SHOW GRANTS FOR '\''rifacloud_raffles'\''@'\''%'\'';" | grep -E "raffles|raffle_numbers|raffle_results|sessions|users"
'

echo "Database bootstrap completed. No schema was dropped and no volume was removed."
