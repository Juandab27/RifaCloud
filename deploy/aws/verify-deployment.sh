#!/usr/bin/env bash
# RifaCloud — post-deployment verification (no secrets printed).
#
# Run on the EC2 host after `docker compose ... up -d` and bootstrap-db.sh:
#   deploy/aws/verify-deployment.sh

set -Eeuo pipefail
umask 077

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)
# Direct TLS mode redirects HTTP API requests to HTTPS. Override this on the
# command line (for example BASE_URL=https://your.domain) when using
# docker-compose.prod-tls.yml.
BASE_URL="${BASE_URL:-http://127.0.0.1}"
FAIL=0

check() {
    local name="$1"
    shift
    if "$@" >/dev/null 2>&1; then
        echo "PASS  $name"
    else
        echo "FAIL  $name"
        FAIL=1
    fi
}

echo "== Compose and containers =="
check "docker compose config" "${COMPOSE[@]}" config -q
for service in mysql auth-service raffles-service orders-service payments-service scheduler nginx app; do
    state="$("${COMPOSE[@]}" ps --format '{{.Service}} {{.State}} {{.Health}}' 2>/dev/null | awk -v s="$service" '$1==s {print $2" "$3}')"
    case "$state" in
        "running healthy"|"running ") echo "PASS  $service running ($state)";;
        *) echo "FAIL  $service state: ${state:-missing}"; FAIL=1;;
    esac
done

echo "== Public health and route boundaries =="
if command -v curl >/dev/null 2>&1; then
    check "Nginx /healthz" curl -fsS "${BASE_URL}/healthz"
    catalog_status="$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}/api/raffles" || true)"
    check "catalog HTTP 200" test "$catalog_status" = "200"
    admin_status="$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}/api/admin/orders" || true)"
    check "admin orders requires auth" test "$admin_status" = "401"
    internal_status="$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}/internal/health" || true)"
    check "/internal is not public" test "$internal_status" = "404"
else
    echo "WARN  curl not installed; HTTP checks skipped" >&2
fi

echo "== MySQL exposure =="
if [[ -z "$("${COMPOSE[@]}" port mysql 3306 2>/dev/null)" ]]; then
    echo "PASS  MySQL has no published host port"
else
    echo "FAIL  MySQL appears published"
    FAIL=1
fi

echo "== Migrations =="
if "${COMPOSE[@]}" run --rm app php artisan migrate:status 2>/dev/null | grep -q 'Pending'; then
    echo "FAIL  pending migrations"
    FAIL=1
else
    echo "PASS  no pending migrations"
fi

echo "== Scheduler heartbeat =="
if "${COMPOSE[@]}" exec -T scheduler test -f /tmp/rifacloud-scheduler-heartbeat; then
    echo "PASS  scheduler heartbeat present"
else
    echo "FAIL  scheduler heartbeat missing"
    FAIL=1
fi

echo
if [[ "$FAIL" -eq 0 ]]; then
    echo "Deployment verification passed."
else
    echo "Deployment verification found failures." >&2
fi

exit "$FAIL"
