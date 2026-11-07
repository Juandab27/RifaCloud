#!/usr/bin/env bash
# Render deploy/aws/nginx/https.conf from the versioned example.
#
# Usage:
#   deploy/aws/render-nginx-tls.sh --domain raffle.example.com \
#       --cert /etc/nginx/certs/fullchain.pem --key /etc/nginx/certs/privkey.pem
#
# The generated file is ignored by Git and must never contain a private key.

set -Eeuo pipefail
umask 077

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOMAIN=""
CERT="/etc/nginx/certs/fullchain.pem"
KEY="/etc/nginx/certs/privkey.pem"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 ;;
        --cert) CERT="${2:-}"; shift 2 ;;
        --key) KEY="${2:-}"; shift 2 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

if [[ -z "$DOMAIN" ]]; then
    echo "Usage: $0 --domain <fqdn> [--cert path] [--key path]" >&2
    exit 2
fi

if [[ ! "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]]; then
    echo "Invalid domain: $DOMAIN" >&2
    exit 2
fi

SOURCE="$REPO_ROOT/deploy/aws/nginx/https.conf.example"
TARGET="$REPO_ROOT/deploy/aws/nginx/https.conf"

sed \
    -e "s|__APP_DOMAIN__|$DOMAIN|g" \
    -e "s|__TLS_CERT__|$CERT|g" \
    -e "s|__TLS_KEY__|$KEY|g" \
    "$SOURCE" > "$TARGET"
chmod 600 "$TARGET"

echo "Rendered $TARGET for $DOMAIN."
echo "Ensure the certificate and key exist at the paths above on the EC2 host."
