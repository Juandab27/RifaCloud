#!/usr/bin/env bash
# RifaCloud — encrypted MySQL backup with external upload and retention.
#
# Configuration (root-owned, NOT in Git):
#   /etc/rifacloud/backup.env
#
# Required:
#   BACKUP_GPG_RECIPIENT=ops@example.com
#   or BACKUP_AGE_RECIPIENT=age1...
#
# Optional:
#   BACKUP_DIR=/var/backups/rifacloud
#   BACKUP_RETENTION_DAYS=14
#   BACKUP_S3_URI=s3://YOUR_BUCKET/rifacloud/mysql
#
# The backup is encrypted before it leaves the host. S3 upload uses the EC2
# instance role (never static AWS keys). Enable SSE-KMS, versioning and a
# lifecycle policy on the bucket.
#
# Usage:
#   deploy/aws/backup-db.sh
#   BACKUP_S3_URI=s3://... deploy/aws/backup-db.sh

set -Eeuo pipefail
umask 077

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)

BACKUP_ENV_FILE="${BACKUP_ENV_FILE:-/etc/rifacloud/backup.env}"
if [[ -f "$BACKUP_ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    . "$BACKUP_ENV_FILE"
    set +a
elif [[ -f "$REPO_ROOT/deploy/aws/backup.env" ]]; then
    set -a
    # shellcheck disable=SC1090
    . "$REPO_ROOT/deploy/aws/backup.env"
    set +a
fi

BACKUP_DIR="${BACKUP_DIR:-/var/backups/rifacloud}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_S3_URI="${BACKUP_S3_URI:-}"

if [[ -z "${BACKUP_GPG_RECIPIENT:-}" && -z "${BACKUP_AGE_RECIPIENT:-}" ]]; then
    echo "ERROR: set BACKUP_GPG_RECIPIENT or BACKUP_AGE_RECIPIENT in $BACKUP_ENV_FILE." >&2
    echo "Refusing to create an unencrypted backup." >&2
    exit 1
fi

if ! [[ "$BACKUP_RETENTION_DAYS" =~ ^[0-9]+$ ]] || [[ "$BACKUP_RETENTION_DAYS" -lt 1 ]]; then
    echo "ERROR: BACKUP_RETENTION_DAYS must be a positive integer." >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

STAMP="$(date -u +%Y%m%d-%H%M%S)"
RAW="$BACKUP_DIR/rifacloud-$STAMP.sql.gz"
ENC="$RAW.gpg"

cleanup_raw() {
    if [[ -n "${RAW:-}" && -f "$RAW" ]]; then
        rm -f "$RAW"
    fi
}
trap cleanup_raw EXIT

echo "Creating logical dump..."
"${COMPOSE[@]}" exec -T mysql sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot \
        --single-transaction --quick --routines --triggers \
        --set-gtid-purged=OFF "$MYSQL_DATABASE"
' | gzip -9 > "$RAW"

gzip -t "$RAW"

if [[ -n "${BACKUP_AGE_RECIPIENT:-}" ]] && command -v age >/dev/null 2>&1; then
    ENC="$RAW.age"
    age -r "$BACKUP_AGE_RECIPIENT" -o "$ENC" "$RAW"
elif [[ -n "${BACKUP_GPG_RECIPIENT:-}" ]] && command -v gpg >/dev/null 2>&1; then
    ENC="$RAW.gpg"
    gpg --batch --yes --trust-model always \
        --recipient "$BACKUP_GPG_RECIPIENT" \
        --output "$ENC" --encrypt "$RAW"
else
    echo "ERROR: the configured encryption tool (age/gpg) is not installed." >&2
    rm -f "$RAW"
    exit 1
fi

rm -f "$RAW"
RAW=""
chmod 600 "$ENC"

if [[ -n "$BACKUP_S3_URI" ]]; then
    if ! command -v aws >/dev/null 2>&1; then
        echo "ERROR: BACKUP_S3_URI is set but the AWS CLI is not installed." >&2
        exit 1
    fi

    echo "Uploading encrypted backup to S3..."
    aws s3 cp "$ENC" "${BACKUP_S3_URI%/}/$(basename "$ENC")" --only-show-errors
else
    echo "WARNING: BACKUP_S3_URI is empty; the encrypted backup stays only on this host." >&2
fi

# Local retention. Remote retention is enforced by the S3 lifecycle policy.
find "$BACKUP_DIR" -maxdepth 1 -type f \
    \( -name 'rifacloud-*.sql.gz.gpg' -o -name 'rifacloud-*.sql.gz.age' \) \
    -mtime "+$BACKUP_RETENTION_DAYS" -delete

SIZE="$(du -h "$ENC" | cut -f1)"
echo "BACKUP_OK $ENC ($SIZE)"
