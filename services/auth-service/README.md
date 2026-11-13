# auth-service

Independent authentication service for RifaCloud (stage S1 of ADR-001).

## Ownership

| Owns | Must not own |
|---|---|
| Users, credentials, sessions, roles, authorization decisions, consent evidence (`user_consents`) | Raffles, numbers, orders, payments |

It shares the MySQL instance and schema with the monolith **during the
transition**, but it is the only writer of `users` and `user_consents`. It never
writes raffles, numbers, orders or payments.

## Public contract (through Nginx)

| Method | Path | Success | Errors |
|---|---|---|---|
| `POST` | `/api/register` | `201 {"data": {...}}` | `422` validation |
| `POST` | `/api/login` | `200 {"data": {...}}` | `422` invalid credentials, `429` throttled |
| `POST` | `/api/logout` | `200 {"message": "Logged out."}` | `401` anonymous |
| `GET` | `/api/me` | `200 {"data": {...}}` | `401` anonymous |
| `GET` | `/api/auth/health` | `200 {"status": "ok"}` | `503` database unavailable |
| `GET` | `/sanctum/csrf-cookie` | `204` | — |

Responses preserve the monolith envelopes exactly, so the React client and
existing protected routes do not change.

### S11 registration consent

`POST /api/register` also requires:

| Field | Rule |
|---|---|
| `privacy_consent` | Required, accepted |
| `terms_consent` | Required, accepted |
| `consent_version` | Required, must equal `config('rifacloud.legal.consent_version')` |
| `marketing_consent` | Optional boolean; separate from the required boxes |

The service writes one `user_consents` row per type (`PRIVACY`, `TERMS`,
`MARKETING`) with the document version, decision, timestamp and user. It does
not store IP address or user agent in the evidence table. The additive
`user_consents` migration and the grant from `scripts/mysql-s11-grants.sql` are
applied to `rifacloud` and `rifacloud_test` (2026-11-12). If the table were
missing, registration would still succeed but the evidence would not persist and
a warning would be logged.

## Session compatibility (transition constraint)

The service must use the same session contract as the monolith:

- same `APP_KEY` (provided by `env_file: ./src/.env`)
- `SESSION_DRIVER=database`
- same `sessions` table in the shared MySQL database
- same cookie name (`rifacloud-session`) and domain (`localhost`)
- `statefulApi()` (Sanctum session cookies, no API tokens in this increment)

A session issued by this service is accepted by the monolith because both
validate the same database row with the same key. This was verified with
`scripts/verify-auth-service-integration.ps1` and
`scripts/verify-auth-session-compat.php`.

Do not change the password hashing, role values, cookie name or session driver
without updating the compatibility test.

## Environment

The service reads the shared Laravel environment through Compose
(`env_file: ./src/.env`). The relevant keys are:

```
APP_NAME=RifaCloud
APP_KEY=...
DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=rifacloud
DB_USERNAME=...
DB_PASSWORD=...
SESSION_DRIVER=database
SESSION_DOMAIN=localhost
SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1
CACHE_STORE=database
```

`CACHE_STORE=database` is required for `throttle:6,1` to work across PHP-FPM
workers. `services/auth-service/.env.example` documents the required keys.

## Independent lifecycle

```bash
# Build and start only this service (MySQL must already be healthy)
docker compose build auth-service
docker compose up -d --no-deps auth-service

# Health
docker compose ps auth-service
curl -s http://localhost/api/auth/health

# Stop it without touching MySQL, Nginx, the catalog or the monolith
docker compose stop auth-service

# Restore it
docker compose start auth-service
```

While it is stopped, Nginx returns a controlled JSON `503` for auth routes and
`/sanctum/csrf-cookie`; the catalog and the monolith (orders/admin) keep
working. See `scripts/verify-auth-isolation.ps1`.

## Tests

```powershell
# End-to-end contracts against rifacloud_test, with cleanup
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/verify-auth-service-integration.ps1

# Failure isolation against the live Compose stack
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/verify-auth-isolation.ps1
```

Both scripts are non-destructive: they use `rifacloud_test`, delete the test
user they create, and never remove volumes or the real `rifacloud` data.

## Rollback

Route `/api/register`, `/api/login`, `/api/logout`, `/api/me` and
`/sanctum/csrf-cookie` back to `app:9000` in `docker/nginx/default.conf`.
Sessions already stored in MySQL remain valid because the session contract is
shared. No data migration is required.
