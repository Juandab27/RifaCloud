#!/usr/bin/env bash
# RifaCloud — verify an encrypted backup by restoring it into a temporary DB.
#
# This script NEVER restores over rifacloud. It creates a throwaway database,
# restores the dump, prints key counts and drops the throwaway database.
#
# Usage:
#   deploy/aws/restore-db.sh --file /var/backups/rifacloud/rifacloud-....sql.gz.gpg
#   deploy/aws/restore-db.sh --file ... --keep-temp
#
# For a real disaster recovery, follow the manual procedure in
# deploy/aws/README.md (stop app/scheduler, take a current backup, restore to
# rifacloud, run migrations, verify, restart).

set -Eeuo pipefail
umask 077

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)

FILE=""
KEEP_TEMP="no"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --file) FILE="${2:-}"; shift 2 ;;
        --keep-temp) KEEP_TEMP="yes"; shift ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

if [[ -z "$FILE" || ! -f "$FILE" ]]; then
    echo "Usage: $0 --file <encrypted-backup> [--keep-temp]" >&2
    exit 2
fi

TMP_DIR="$(mktemp -d)"
SQL_FILE="$TMP_DIR/restore.sql"
TMP_DB="rifacloud_restore_check_$(date -u +%Y%m%d%H%M%S)"
cleanup() {
    rm -rf "$TMP_DIR"
    if [[ "$KEEP_TEMP" != "yes" ]]; then
        "${COMPOSE[@]}" exec -T mysql sh -c \
            "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot -e 'DROP DATABASE IF EXISTS \`$TMP_DB\`;'" \
            >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

case "$FILE" in
    *.gpg)
        command -v gpg >/dev/null 2>&1 || { echo "gpg is required." >&2; exit 1; }
        gpg --batch --decrypt "$FILE" > "$SQL_FILE"
        ;;
    *.age)
        command -v age >/dev/null 2>&1 || { echo "age is required." >&2; exit 1; }
        age --decrypt "$FILE" > "$SQL_FILE"
        ;;
    *)
        echo "Refusing to verify an unencrypted file. Expected .gpg or .age." >&2
        exit 1
        ;;
esac

gzip -t "$SQL_FILE"

echo "Creating temporary database $TMP_DB..."
"${COMPOSE[@]}" exec -T mysql sh -c \
    "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot -e 'CREATE DATABASE \`$TMP_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'"

echo "Restoring encrypted dump into $TMP_DB..."
# The encrypted file contains the gzip stream produced by backup-db.sh. The
# decrypted SQL_FILE is still compressed, so decompress it before feeding it to
# mysql; piping the gzip bytes directly makes mysql reject the dump.
gzip -dc "$SQL_FILE" | "${COMPOSE[@]}" exec -T mysql sh -c \
    "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot \`$TMP_DB\`"

echo "Verification counts:"
"${COMPOSE[@]}" exec -T mysql sh -c "
    MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot -N -e \"
        SELECT 'tables', COUNT(*) FROM information_schema.tables WHERE table_schema='$TMP_DB'
        UNION ALL SELECT 'users', COUNT(*) FROM \`$TMP_DB\`.users
        UNION ALL SELECT 'raffles', COUNT(*) FROM \`$TMP_DB\`.raffles
        UNION ALL SELECT 'orders', COUNT(*) FROM \`$TMP_DB\`.orders
        UNION ALL SELECT 'payments', COUNT(*) FROM \`$TMP_DB\`.payments
        UNION ALL SELECT 'migrations', COUNT(*) FROM \`$TMP_DB\`.migrations;
    \"
"

echo "Restore verification completed."
if [[ "$KEEP_TEMP" == "yes" ]]; then
    echo "Temporary database kept: $TMP_DB"
else
    echo "Temporary database will be dropped."
fi
