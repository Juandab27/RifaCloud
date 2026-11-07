# 02 - Technical Design Document

**Project:** RifaCloud  
**Status:** Approved (architecture evolution)  
**Version:** 2.2  
**Last Updated:** 2026-11-06  
**Supersedes:** v1.2 (modular monolith as final architecture)  
**Decision record:** `08-adr-001-four-service-architecture.md`

## 1. Architecture Status and Decision Record

RifaCloud is evolving from a Laravel modular monolith into **four business
microservices** behind a single Nginx entry point. The evolution is incremental,
reversible and explicitly approved. It does not delete the previous decision:
v1.2 defined a modular monolith and excluded microservices from the MVP. That
decision remains part of the project history and is superseded only for the
target architecture described here.

### 1.1 Three distinct states

| State | Description | Status (2026-11-06) |
|---|---|---|
| **Current deployed** | Laravel monolith (`app`, `scheduler`) + `auth-service` (S1) + `raffles-service` catalog and administration (S0/S2) + `orders-service` orders/reservations/expiry (S3) + `payments-service` payments/Mercado Pago/webhooks (S4) + S5 minimal grants; Nginx + MySQL | Implemented and verified; S5 minimal applied |
| **Transitional** | Extracted services have their own process/container, their own least-privilege MySQL user and share the MySQL instance/schema under explicit ownership rules; the monolith keeps the shared user and rollback code | In progress (S0-S4 + S5 minimal done; per-domain databases deferred) |
| **Target** | Four independently deployable business services with domain-owned data, authenticated service-to-service contracts and controlled degradation | Approved target |

Do not describe the transitional state as the final state, and do not describe
the target state as already implemented.

### 1.2 Target business services

| Service | Owns | Must not own |
|---|---|---|
| `auth-service` | Users, credentials, sessions, roles, authorization decisions | Raffles, numbers, orders, payments |
| `raffles-service` | Raffle catalog, raffle administration, raffle metadata and configuration | Reservations, number ownership, orders, payments |
| `orders-service` | Number availability, reservations, orders, order items, expiry, atomic allocation | Payment provider integration, credentials, raffle content |
| `payments-service` | Mercado Pago integration, checkout preferences, verification, webhooks, reconciliation, idempotency | Order creation, number allocation, raffle content |

The `scheduler` remains a **process for scheduled tasks** (reservation expiry,
notification retries, reconciliation triggers). It is not a fifth business
microservice and owns no domain data.

### 1.3 Target topology

```
Internet
  │
  ▼
Nginx :80/:443  (single public entry point, TLS, routing, controlled 503)
  │
  ├── /api/auth/*        ──► auth-service      (PHP-FPM or HTTP, internal)
  ├── /api/raffles*      ──► raffles-service   (public catalog + admin)
  ├── /api/orders*       ──► orders-service    (reservations, orders)
  ├── /api/payments/*    ──► payments-service  (checkout, verify, webhooks)
  ├── /sanctum/*         ──► auth-service      (CSRF/session bootstrap)
  └── /*                 ──► React static build
  │
  └── internal Docker network
        ├── MySQL 8 (internal only, no public port)
        └── scheduler (cron/queue process; no business API)
```

Nginx is the only public entry point. MySQL is never published. React +
TypeScript remains the frontend and talks only to Nginx, never directly to a
service container.

## 2. Technology Stack

| Component | Technology | Purpose |
|---|---|---|
| Frontend | React + TypeScript | SPA for users and admins |
| Web Server | Nginx | Static build, routing, controlled degradation |
| `auth-service` | PHP 8.4 + Laravel components | Users, sessions, roles |
| `raffles-service` | PHP 8.4 (standalone catalog + admin) | Public catalog and raffle administration; number generation |
| `orders-service` | PHP 8.4 + Laravel components | Reservations, orders, atomic allocation |
| `payments-service` | PHP 8.4 + Laravel components | Mercado Pago, webhooks, reconciliation |
| Database | MySQL 8 | Shared instance initially; domain ownership enforced |
| Queue | Database driver (MVP) | Async email and retries |
| Scheduler | Laravel scheduler process | Expiry, retries, reconciliation triggers |
| Containers | Docker + Docker Compose | Orchestration |
| Hosting | AWS EC2 (single instance) | Deployment target |

## 3. Current Implemented State (not the target)

```text
Internet -> Nginx
             |-- /api/(register|login|logout|me), /sanctum/csrf-cookie
             |     `-- auth-service :9000 (users, sessions, roles; shared schema)
             |-- GET /api/raffles*, /api/admin/raffles*
             |     `-- raffles-service :80 (catalog read + admin writes; shared schema)
             |-- POST /api/orders, GET /api/orders/{id}
             |     `-- orders-service :80 (orders, reservations, expiry; shared schema)
             |-- /api/orders/{id}/payments*, /api/webhooks/mercadopago, /api/admin/orders/*/payments/*/resolve
             |     `-- payments-service :80 (payments, provider, webhooks; shared schema)
             `-- /api/* (rest: notifications)
                   `-- app (Laravel modular monolith, rollback code retained)

scheduler (Laravel schedule:work)
  |-> signed expiry command -> orders-service
  `-> signed reconcile command -> payments-service
payments-service -> signed payment-result command -> orders-service
mysql (internal only, named volume)
```

Implemented and verified in the first increment (S0, `raffles-service`):

- `raffles-service` has its own container and lifecycle.
- Public catalog, detail and number availability are served by the new service
  with Laravel-compatible JSON contracts.
- Nginx routes only the public read-only catalog to it (S0); S2 adds the
  administration location.
- Stopping the service returns a controlled HTTP 503 JSON; auth, orders,
  payments and webhooks remain on the monolith.
- In S0 the service reads the shared schema inside a read-only MySQL session
  and never writes; S2 opens a writable session only for the admin application.

Implemented and verified in the second increment (S1, `auth-service`):

- `auth-service` has its own container and health check.
- It owns `POST /api/register`, `POST /api/login`, `POST /api/logout`,
  `GET /api/me` and `GET /sanctum/csrf-cookie`.
- It shares `APP_KEY`, the `sessions` table and the `rifacloud-session` cookie
  with the monolith, so a session it issues is accepted by existing protected
  routes.
- Stopping it returns a controlled JSON 503 for auth routes while the catalog
  and the monolith stay up.
- It never writes raffles, numbers, orders or payments.
- Verification: 12/12 integration checks (including monolith session
  acceptance), 14/14 failure-isolation checks and 59 Laravel regression tests.

Implemented and verified in the third increment (S2, `raffles-service` admin):

- `raffles-service` now owns raffle administration: `GET/POST /api/admin/raffles`,
  `GET/PUT/DELETE /api/admin/raffles/{id}`, `POST /api/admin/raffles/{id}/publish`
  and `POST /api/admin/raffles/{id}/close`, with the same JSON envelopes,
  pagination (`per_page=15`), validation messages and transitions as the monolith.
- Number generation for `raffle_numbers` moved with the raffle write, in a single
  transaction and in chunks, bounded by `RAFFLE_MAX_NUMBERS`.
- Admin identity is validated at the service boundary, never from a
  client-supplied header: the shared `rifacloud-session` cookie is decrypted with
  the shared `APP_KEY`, its MAC and cookie prefix are verified, the session id is
  looked up in `sessions`, expiry is enforced, the linked user must still be
  `ADMIN`, and `password_hash_web` must match (password changes revoke access).
  CSRF is enforced with the same cookie/header/`_token` sources and the same
  419 response.
- Reservations, orders and payments stay in the monolith: `POST /api/orders` is
  still owned by `OrderReservationService`. Admin number generation is the only
  new write in the service, and admin endpoints refuse to delete or reconfigure a
  raffle with reserved/sold numbers or in a non-`DRAFT` state.
- The monolith admin routes are intentionally retained as the rollback path; the
  Nginx admin location can be removed to restore them.
- Stopping the service returns a controlled JSON 503 for catalog and admin routes
  while auth, orders and webhooks keep working.
- Verification: 56/56 admin integration assertions, 64/64 E2E checks through
  Nginx (auth boundary, CRUD, validation, reservations, webhook and stop/start
  isolation), a green S0 catalog E2E run and PHP lint for every service file.

Implemented and verified in the fourth increment (S3, `orders-service`):

- `orders-service` owns `POST /api/orders`, `GET /api/orders/{id}`, the
  `orders`/`order_items` writes and the reservation columns of
  `raffle_numbers` (`status`, `reserved_at`, `reserved_until`, `order_id`).
- The reservation stays one MySQL transaction: `raffles` row locked
  `FOR UPDATE`, order inserted, requested numbers locked in ascending order,
  `UPDATE ... WHERE status='AVAILABLE'` with an affected-row check, then
  `order_items`. Deadlock/lock-timeout retries are bounded; the response is the
  unchanged `409 NUMBERS_UNAVAILABLE` envelope with `unavailable_numbers`.
- Identity comes from the shared `rifacloud-session` cookie and `APP_KEY`, never
  from a client header. Any authenticated user can create an order and read only
  their own; anonymous requests are `401`, another user's order is `404` (not
  `403`) to avoid disclosing existence. `password_hash_web` and session expiry
  are enforced. CSRF applies to stateful requests only (Origin/Referer/
  `Sec-Fetch-Site` plus `X-XSRF-TOKEN`/`X-CSRF-TOKEN`/`_token`, `419` on
  mismatch), matching Sanctum.
- The `OrderResource` JSON contract is byte-compatible: `data` wrapper,
  ISO-8601 timestamps, prices in cents, `simulated_payments`/`simulated` from
  `PAYMENT_GATEWAY`, numbers and the read-only `payments` projection.
- Reservation expiry moved into the service. `POST
  /internal/orders/expire-reservations` is signed with
  `HMAC(timestamp "\n" sha256(body))` using a key derived from `APP_KEY`, checks
  a clock window, and runs under `GET_LOCK('rifacloud_orders_expire_reservations')`
  so overlapping schedulers or manual runs cannot release the same reservation.
  The monolith command `orders:expire-reservations` only calls that endpoint and
  fails closed (exit 1, no local release) when the service is unavailable.
- Nginx routes only `POST /api/orders` and `GET /api/orders/{id}` (exact/regex
  locations); `^~ /internal/` returns 404 and payment sub-routes, checkout,
  verification and webhooks stay in the monolith. Stopping the service returns a
  controlled JSON 503 and leaves catalog, auth and payments working.
- Transitional exception until S4 (now resolved): `PaymentService`/webhooks
  used to write `orders`, `order_items` and `raffle_numbers`
  (PAID/REJECTED/REQUIRES_REFUND, number SOLD). S4 moved that decision to
  orders-service through the payment-result command.
- Verification: 97/97 orders-service integration checks (auth, CSRF, validation,
  conflict codes, atomic reservation, ownership, real two-process double-sale,
  expiry rules including `UNKNOWN`/`APPROVED`/`REFUND_REQUIRED`/`SOLD`, advisory
  lock, internal signature, payments projection), 33/33 E2E checks through
  Nginx (including stop/start isolation) and 186/186 monolith tests.

Implemented and verified in the fifth increment (S4, `payments-service`):

- `payments-service` owns `payments` and `payment_webhook_events` and serves
  `POST /api/orders/{id}/payments`, `POST
  /api/orders/{id}/payments/{payment}/verify`, `POST
  /api/admin/orders/{id}/payments/{payment}/resolve` and `POST
  /api/webhooks/mercadopago`, with the same JSON envelopes, status codes and
  `code` values React already consumes.
- The provider is always queried server-side; a browser redirect never marks a
  payment approved. `UNKNOWN`/timeout keeps the payment uncertain and the
  numbers reserved; a late approval or an unconfirmed/multiple approved charge
  becomes `REFUND_REQUIRED` + `REQUIRES_REFUND` without reassigning numbers.
- Order and number transitions are no longer written by the monolith. Every
  provider result is sent to `orders-service` as a signed, idempotent command
  (`POST /internal/orders/payment-result`); orders-service locks
  `orders → payments → raffle_numbers`, applies the state machine and returns
  the target `payment_status`, which payments-service persists in its own row.
  Idempotency is state-based: a replay converges (no second sale, no reopening,
  `paid_at` set once), and stale webhook events return `ignored`.
- Webhooks: official Mercado Pago HMAC signature, authoritative re-fetch,
  `UNIQUE(provider, provider_event_id)`, a short per-event lock, `FAILED`
  retry handling and a 503 (never a silent 200) when the provider or
  orders-service is unavailable.
- Checkout is created at most once per order (`UNIQUE(order_id)`, PENDING
  resumed, UNKNOWN/final blocks a new charge) and uses the deterministic
  `MP-ORDER-{id}` external reference/idempotency key.
- Reconciliation (`payments:reconcile`, `--approved`, `--dry-run`) delegates
  from the monolith scheduler to a signed internal endpoint and fails closed.
- Nginx routes only the four payment entry points; `^~ /internal/` returns 404
  and a stopped service returns a controlled JSON 503 that Mercado Pago retries.
- Verification: 72/72 payments-service integration checks against
  `rifacloud_test` with a real test orders-service (checkout, resume, approved
  sale, UNKNOWN + expiry, late approval, rejection/release, admin review,
  webhook duplicate/refund/unknown, reconciliation, fail-closed), 38/38 Mercado
  Pago adapter checks (checkout payload/idempotency key, status mapping,
  official signature manifest, authoritative re-fetch, no payer data, provider
  error mapping), 9/9 real two-process webhook concurrency checks, 39/39 E2E
  checks through Nginx (including stop/start and recovery) and 190/190 monolith
  tests.
- Deployment prerequisite, documented rather than skipped: the main `rifacloud`
  schema still has two pending additive migrations
  (`payments.order_id` unique index and `payment_webhook_events`). They were not
  applied because running migrations on the main schema is out of scope for
  this task; the full S4 verification runs on `rifacloud_test`, which is fully
  migrated. Until they are applied, webhook persistence on the main schema fails
  closed with 503 and the DB-level single-payment guarantee relies only on the
  order row lock.

### Stabilization increment (S6, session continuity, expiry and checkout return)

S6 is not a new service; it closes the browser-facing gaps left by the
incremental extraction.

- **Session continuity:** the SPA revalidates `/api/me` on every page load
  (including the Mercado Pago return). Only an explicit `401` means "anonymous";
  a `503`, timeout or network error is treated as a recoverable availability
  problem (`SessionUnavailable` with retry) and never clears the session. The
  auth-service forces JSON negotiation on `api/*`, so an unauthenticated request
  returns `401 JSON` even without `Accept: application/json` (previously a 500
  from the missing `login` route).
- **CSRF:** the axios client refreshes `/sanctum/csrf-cookie` and replays a
  request once on `419`; `409`/`503` are never retried blindly and never clear
  the session. Credentials remain only in the HttpOnly session cookie (no
  `localStorage`).
- **Route preservation:** `RequireAuth`/`AdminRoute` redirect to `/login` with
  the original location, and login/register return to it (safe internal paths
  only), so a reload or an expired session does not lose the order page.
- **Checkout return:** the `?pago=retorno` hint triggers one server-side
  `POST /payments/{payment}/verify`; the order is never approved from the URL.
  The UI shows approved/pending/rejected/review/expired/refund states from the
  backend. A `409` re-reads the current order (review) and keeps the session; a
  `503` keeps the order on screen with a retry action.
- **Reservation expiry:** `RESERVATION_MINUTES` in `src/.env` is the single
  source of truth. `raffles-service` and `orders-service` fall back to it (the
  compose-specific overrides were removed), so the advertised
  `reservation_minutes` and the actual `expires_at` cannot diverge. The SPA uses
  `expires_at` from the backend and, after the countdown reaches zero, polls the
  order (never assumes release) until the scheduler processes it.
- The scheduler already runs `orders:expire-reservations` every minute with
  `withoutOverlapping(5)`; S6 re-verified release, protected states (`UNKNOWN`,
  `PAYMENT_REVIEW`, `APPROVED`/`PAID`, `SOLD`) and idempotency end to end.
- Verification: **42/42** S6 E2E checks through Nginx on `rifacloud_test`
  (cookie attributes, register, two `/me` reloads, 1-minute shared duration,
  return verification, 409/419/401/503, expiry matrix, idempotency), **12/12**
  auth-service integration checks, **190/190** monolith tests and a successful
  `tsc --noEmit` + `vite build`.

### Feature increment (S7, purchase confirmation and "Mis compras")

- `GET /api/orders` is owned by `orders-service`: authenticated owner only,
  newest first, `page`/`per_page` (default 10, max 50), response
  `{data: [OrderResource], meta: {current_page, per_page, total, last_page}}`.
  Each entry reuses the order contract and includes the raffle title, numbers
  with their real status, order number/id, total, timestamps and the payments
  projection. The owner filter is part of the SQL query; the detail endpoint
  keeps returning 404 for another user's order.
- **"Mis compras"** (`/orders`): paginated list with loading/error/empty states,
  status pill, raffle, numbers, totals and dates, and a "Cargar más" action.
  It never derives approval from the URL and never presents a number as
  purchased unless its status is `SOLD`; pending/review numbers are labelled
  `RESERVADO`, released numbers `DISPONIBLE` and only paid numbers `VENDIDO`.
- The detail page shows the raffle, order number/internal id, real status,
  created/paid dates and the number statuses; the numbers heading depends on the
  order state (reserved, acquired, review, refund, released).
- Session continuity reuses S6 (`RequireAuth`, `/api/me`, 401 vs 503).
- Verification: **123/123** orders-service integration checks (including 26
  list/ownership/pagination checks), **44/44** S7 E2E through Nginx on
  `rifacloud_test` (paid/pending/expired/review, pagination, ownership, 401/503,
  stop/start), **190/190** monolith tests and `tsc --noEmit` + `vite build`.

### Feature increment (S9.1, critical audit corrections)

- **Automatic reconciliation:** `payments:reconcile --limit=50` is scheduled
  every five minutes with `withoutOverlapping(10)`; the existing signed
  internal command keeps it idempotent. Dry-run and a real recovery of a
  PENDING payment were verified on `rifacloud_test`.
- **Scheduler supervision:** the container runs a small wrapper that traps the
  PHP image's `SIGQUIT`/`SIGTERM`, stops the child cleanly and restarts it after
  an unexpected exit. A heartbeat is scheduled every minute and the container
  healthcheck fails when it is older than three minutes. The previously
  observed Exited (137/131) was caused by stopping `schedule:work` with the
  image's stop signal; a manual stop now exits 0.
- **FR-014 results:** `raffle_results` stores one result per raffle
  (`raffle_id` unique), the winning number, the SOLD `raffle_numbers` reference,
  publisher, publication date and evidence. `POST /api/admin/raffles/{id}/result`
  requires ADMIN + CSRF, a CLOSED raffle, an in-range SOLD number and evidence;
  it transitions the raffle to FINISHED. Public catalog/detail keep FINISHED
  raffles visible and expose the result. The table was created on the main schema
  on 2026-11-07 (migration `000009`, batch 6) after explicit authorization;
  `notifications` was created in batch 7. The `rifacloud_raffles` grant on
  `raffle_results` was applied and verified with a transactional insert/rollback.
- **Admin orders and incidents:** `GET /api/admin/orders` (pagination, status
  filter, buyer and latest payment state) is owned by orders-service;
  `GET /api/admin/payments/incidents` (pagination, UNKNOWN/REFUND_REQUIRED) is
  owned by payments-service. Both require the ADMIN role from auth-service and
  reuse the existing provider-resolution action without changing financial
  rules.
- **Notifications:** the `notifications` table (migration `000010`, pending on
  the main schema) is the retryable email queue. `notifications:queue` creates
  idempotent purchase-confirmation, payment-review and refund-incident rows;
  `notifications:dispatch` sends through Laravel Mail with five bounded
  attempts and never changes an order or payment. Local validation used the
  log/array mailer; no real email was sent.
- **Security:** registration is rate-limited (`throttle:6,1`) in auth-service
  and the monolith rollback route. auth/raffles/orders no longer receive the
  full `src/.env`; only payments receives the Mercado Pago token. Explicit
  per-service environment variables preserve least privilege.
- **Verification:** 31/31 targeted admin/result checks, 12/12 reconcile and
  notification checks, 42/42 S6, 44/44 S7, 39/39 payments, 123/123 orders,
  12/12 auth, 56/56 raffles admin integration, 193/193 monolith tests,
  `tsc --noEmit` + `vite build`, and a restorable pre-migration backup.

### Deployment preparation (S10.1, AWS EC2)

- `docker-compose.prod.yml` provides the production overlay: explicit
  production/session/mail/payment variables, healthchecks for `app` and
  `nginx`, and no abandoned root/panel credentials. `docker-compose.prod-tls.yml`
  is the optional direct-Nginx TLS overlay.
- `deploy/aws/env.production.example` is a secret-free template. `APP_ENV`,
  `APP_DEBUG=false`, HTTPS `APP_URL`, secure cookies, Sanctum domains and
  trusted proxies are explicit.
- `deploy/aws/bootstrap-db.sh` requires `CONFIRM_MIGRATE=yes`, takes an
  encrypted backup before migrating an existing schema, applies the 13
  migrations, creates the four service users from the root environment and
  applies `deploy/aws/grants-production.sql`. It never uses `migrate:fresh`.
- `deploy/aws/backup-db.sh` produces an encrypted dump (age or GPG), uploads
  it to S3 with the instance role and enforces local retention;
  `restore-db.sh` verifies a backup in a throwaway database.
- HTTPS is prepared as ALB + ACM (preferred) or direct Nginx TLS from the
  versioned template; no domain or certificate is assumed.
- Security groups, instance sizing, IAM, S3 lifecycle, costs and the exact
  deployment/recovery runbook are in `deploy/aws/README.md`.
- No AWS resource was created. The real Mercado Pago webhook, SMTP delivery
  and S3 upload remain unverified until the user provides those inputs.
- `.gitignore` no longer hides `docs/sdd/` or `AGENTS.md`; they are untracked
  until the user authorizes a commit/push.

## 4. Service Responsibilities and Data Ownership

### 4.1 Responsibility matrix

| Capability | auth-service | raffles-service | orders-service | payments-service | scheduler |
|---|---|---|---|---|---|
| Register / login / logout / me | **Owner** | — | — | — | — |
| Sessions and roles | **Owner** | consume | consume | consume | — |
| Raffle CRUD / publish / close | — | **Owner** | read-only | read-only | — |
| Public catalog and detail | — | **Owner** | read-only | — | — |
| Number generation | — | **Owner** | read-only | — | — |
| Number availability | — | read-only | **Owner** | read-only | — |
| Reservation / expiry | — | — | **Owner** | read-only | trigger |
| Order and order items | — | — | **Owner** | read-only | trigger |
| Checkout preference | — | — | read-only | **Owner** | — |
| Provider verification | — | — | read-only | **Owner** | trigger |
| Webhook reception / idempotency | — | — | — | **Owner** | — |
| Payment reconciliation | — | — | read-only | **Owner** | trigger |
| Email dispatch | — | — | emits event | emits event | **Worker/trigger** |

### 4.2 Data ownership (target)

| Table | Owner | Other services |
|---|---|---|
| `users` | auth-service | read-only via API or approved read grant |
| `sessions` | auth-service | read-only for session validation during transition |
| `password_reset_tokens` | auth-service | none |
| `raffles` | raffles-service | orders/payments read-only |
| `raffle_numbers` | orders-service | raffles read-only for counts; payments read-only |
| `orders`, `order_items` | orders-service | payments read-only; raffles read-only for stats |
| `payments`, `payment_webhook_events` | payments-service | orders read-only status projection |
| `notifications` | scheduler/notifications process | none (event-driven) |

In the transitional shared schema, ownership is enforced by:

1. **Code boundaries:** only the owning service issues writes.
2. **MySQL grants:** a dedicated user per service, least privilege (target).
3. **Read-only sessions:** read services connect with
   `SET SESSION TRANSACTION READ ONLY`.
4. **Contract tests:** cross-service writes require an approved API/command.

The final state replaces the shared schema with a database (or schema) per
service and removes cross-domain foreign keys. Until then, the shared schema is
a documented transitional risk, not an accepted permanent design.

## 5. Contracts and Routing

### 5.1 Public routes by service

| Route | Service | Notes |
|---|---|---|
| `POST /api/register` | auth-service | 201 + user envelope |
| `POST /api/login` | auth-service | 200 + user envelope; 422 invalid |
| `POST /api/logout` | auth-service | 200 + message |
| `GET /api/me` | auth-service | 200 + user envelope; 401 anonymous |
| `GET /sanctum/csrf-cookie` | auth-service | CSRF bootstrap |
| `GET /api/raffles` | raffles-service | paginated published catalog |
| `GET /api/raffles/{id}` | raffles-service | published detail |
| `GET /api/raffles/{id}/numbers` | raffles-service (read) / orders-service (source of truth) | availability and counts |
| `GET/POST/PUT/DELETE /api/admin/raffles*` | raffles-service | authenticated ADMIN; monolith route kept as rollback |
| `POST /api/admin/raffles/{id}/result` | raffles-service | ADMIN; publishes the single FR-014 result and finishes the raffle |
| `GET /api/admin/orders` | orders-service | ADMIN; paginated orders with buyer and latest payment state |
| `GET /api/admin/payments/incidents` | payments-service | ADMIN; paginated UNKNOWN/REFUND_REQUIRED incidents with buyer, order status and payment context |
| `GET /api/orders` | orders-service | authenticated owner; paginated purchase history (`page`, `per_page`) |
| `POST /api/orders` | orders-service | authenticated; atomic reservation |
| `GET /api/orders/{id}` | orders-service | owner only (404 for anyone else, including ADMIN, matching the monolith); admin views live under `/api/admin/*` |
| `POST /api/orders/{id}/payments` | payments-service | authenticated owner; 201 new / 200 resumed |
| `POST /api/orders/{id}/payments/{payment}/verify` | payments-service | authenticated owner; provider verified server-side |
| `POST /api/webhooks/mercadopago` | payments-service | provider signature, no session |
| `POST /api/admin/orders/{id}/payments/{payment}/resolve` | payments-service | ADMIN; resolves a `PAYMENT_REVIEW` incident |

### 5.2 JSON compatibility

Every service preserves the existing envelopes:

- Single resource: `{"data": {...}}`
- Collection/pagination: `{"data": [...], "links": {...}, "meta": {...}}`
- Validation: HTTP 422 with `{"message": "...", "errors": {...}}`
- Unauthenticated: HTTP 401 JSON (never an HTML redirect)
- Forbidden: HTTP 403 JSON
- Conflict/business rule: HTTP 409 or 422 as today
- Controlled outage: HTTP 503 JSON `{"message": "..."}`

### 5.3 Service-to-service authentication

The target does not trust client-supplied identity headers.

| Call | Mechanism |
|---|---|
| Client → Nginx → service | Existing Sanctum session cookie (transition); auth-service-issued short-lived token (target option) |
| Nginx → service | Internal network only; no published service ports |
| Service → service | Signed request (HMAC over method, path, body hash, timestamp, nonce) **or** short-lived internal JWT with audience and `kid` rotation |
| Admin/role propagation | auth-service introspection endpoint or signed claims, validated by the receiving service |
| Provider webhook → payments | Mercado Pago signature verified by payments-service; never trust the body |

Rules:

- Internal endpoints are never exposed through Nginx.
- Replays are rejected by timestamp + nonce (HMAC) or short expiry (JWT).
- Shared secrets come from environment/secret storage, never source code.
- Authorization is re-checked by the owning service; Nginx routing is not
  authorization.

### 5.4 Errors, timeouts and retries

| Rule | Value |
|---|---|
| Connect timeout (internal) | 2 s |
| Read timeout (internal) | 5–10 s (provider calls up to 30 s) |
| Retries | Only idempotent GET/commands with idempotency key; max 2 |
| Circuit breaking | Gateway returns controlled 503 after repeated failures |
| Correlation | `X-Request-Id` propagated end-to-end |
| Fail closed | No fake order, no fake payment, no auto-release on uncertainty |

### 5.5 Internal commands (implemented in S3/S4)

All internal endpoints live on the Docker network only; Nginx returns 404 for
`/internal/`. Every command is POST + JSON with:

- Headers: `X-RifaCloud-Timestamp` (Unix seconds) and `X-RifaCloud-Signature`.
- Signature: `HMAC-SHA256(timestamp + "\n" + SHA256(raw_body))` with a secret
  derived from `APP_KEY` (no second shared secret is stored).
- Replay protection: requests outside a 60 s clock window are rejected with 401.

| Command | Direction | Derived secret context | Response |
|---|---|---|---|
| `POST /internal/orders/expire-reservations` | scheduler → orders | `rifacloud-orders-internal` | `{"expired": int, "skipped": bool}` |
| `POST /internal/orders/payment-result` | payments → orders | `rifacloud-payments-internal` | `{outcome, payment_status, order_status, evidence, ...}` |
| `POST /internal/payments/reconcile` | scheduler → payments | `rifacloud-payments-reconcile` | `{mode, count}` or `{mode, inspected, incidents, errors}` |

- Expiry uses `GET_LOCK`; `skipped=true` means another run holds it.
- The payment-result command is idempotent by state convergence (see section 7);
  a stale webhook event returns `{"outcome":"ignored","reason":"stale_event"}`
  without a second side effect.
- Both monolith clients use `file_get_contents` with a short timeout and treat
  any non-200 as a failure, so the command exits non-zero and never writes the
  other service's state locally.

## 6. Atomicity of Reservations (orders-service)

The reservation transaction must remain atomic. The target is **not** a
distributed transaction across services.

### Transitional design (shared MySQL)

`orders-service` owns `orders`, `order_items` and `raffle_numbers` writes and
runs one database transaction:

```sql
BEGIN;
SELECT ... FROM raffles WHERE id = ? FOR UPDATE;          -- read-only check
INSERT INTO orders (...) VALUES (...);
SELECT ... FROM raffle_numbers
  WHERE raffle_id = ? AND number IN (...) FOR UPDATE;
UPDATE raffle_numbers
  SET status='RESERVED', reserved_at=NOW(), reserved_until=?, order_id=?
  WHERE id IN (...) AND status='AVAILABLE';
INSERT INTO order_items (...) VALUES (...);
COMMIT;
```

`SELECT ... FOR UPDATE` on `raffles` serializes administrative schedule changes
with reservations. `UNIQUE(raffle_id, number)` and the affected-row check remain
the final defense against double selling.

### Target design (separate databases)

If `raffles` moves to another database, the raffle schedule must be projected
into `orders-service` (or read through a versioned snapshot). The reservation
transaction then locks only orders-owned rows. Administrative changes are
propagated as immutable events with a version; a reservation against a stale
version is rejected and retried. This redesign is **not** part of the current
increment and is required before splitting the databases.

### Non-negotiable invariants

- One number row can belong to at most one active reservation/order.
- A failed transaction leaves no partial order.
- Expiry never releases numbers when payment is `UNKNOWN`/`PAYMENT_REVIEW`.
- A confirmed payment never assigns a number already sold; it becomes
  `REQUIRES_REFUND`.

## 7. Idempotency of Payments (payments-service)

### Ownership

`payments-service` owns `payments` and `payment_webhook_events`. It is the only
writer of payment state. Order state changes are requested through an
idempotent internal command to `orders-service` (implemented in S4).

### Implemented flow (S4)

1. payments-service validates the identity/ownership (user routes) or the
   Mercado Pago signature (webhook) and queries the provider server-side.
2. It sends the authoritative result to `orders-service`
   (`POST /internal/orders/payment-result`) with `payment_id`, `order_id`,
   `external_id`, `amount`, `currency`, `status`, allowlisted `raw`, `source`
   and `resolve_review`.
3. orders-service locks `orders → payments → raffle_numbers`, re-checks the
   amount/currency/external reference, applies the stale-event guards and the
   state machine, performs the number sale/release and the order status change,
   and returns the target `payment_status` plus evidence.
4. payments-service persists that status in its own row (monotonic guard,
   `verified_at`, allowlisted evidence) and renders the `OrderResource`
   contract from a read-only projection.

Cross-service atomicity is a saga, not a distributed transaction. The write
order is deliberate: the order/sale commits first and the payment row second.
If the second step fails, the payment row lags behind (PENDING/UNKNOWN) while the
order is already PAID/REQUIRES_REFUND; expiry still cannot release a non-
PENDING_PAYMENT order, and the next webhook/verify/reconcile replay converges.

### Required controls

1. `UNIQUE(order_id)` on `payments` prevents a second charge for one order.
2. `UNIQUE(provider, external_id)` prevents duplicate provider payments.
3. `UNIQUE(provider, provider_event_id)` on `payment_webhook_events` makes
   webhook handling idempotent.
4. The internal command is idempotent by state convergence: equal statuses are
   no-ops, numbers move `RESERVED → SOLD/AVAILABLE` once, `paid_at` is set once,
   and the payment transition table is monotonic. The provider event id and the
   payment row are the audit/idempotency keys.
5. Provider verification is always server-side; the browser redirect is never
   trusted (NFR-001).
6. Payment state transitions are monotonic: a final state is never downgraded.
7. `UNKNOWN` keeps numbers reserved and requires human review.
8. Approved-but-unavailable becomes `REFUND_REQUIRED` + `REQUIRES_REFUND`;
   numbers are never reassigned.
9. Duplicate/simultaneous webhooks are serialized by the unique event key plus a
   short per-event lock; the business command is safe to replay concurrently.
10. A failure after the order commit is recorded as a failed webhook attempt and
    the provider is asked to retry (503), never silently acknowledged.

### Reconciliation

`payments-service` exposes `POST /internal/payments/reconcile` (signed). The
monolith command `payments:reconcile`, `--approved` and `--dry-run` only
delegates to it (`InternalPaymentsClient`) and fails closed when it is
unavailable. Reconciliation never creates a checkout or a charge; it re-queries
the provider and reuses the same idempotent command.

## 8. Transition Stages

| Stage | Scope | Prerequisite | Exit criterion | Rollback |
|---|---|---|---|---|
| **S0 (done)** | Read-only catalog in `raffles-service`; shared schema; 503 gateway | None | Catalog, detail, numbers work; auth/orders/payments unaffected | Route catalog back to monolith |
| **S1 (done)** | `auth-service` owns register/login/logout/me/sessions; shared `users`/`sessions`; Nginx routes auth paths | Session-compatible design verified against monolith | Login/logout/me work through Nginx; monolith protected routes accept the session; auth down returns controlled 503 | Route auth paths back to monolith; sessions remain valid |
| **S2 (done)** | `raffles-service` owns admin CRUD, publish/close and number generation; admin identity validated in the service | S1 stable; shared session/app-key contract | Admin CRUD/publish/close work through Nginx; orders still reserve correctly; 56/56 integration and 64/64 E2E checks pass | Route admin paths back to monolith; monolith routes and data remain in place |
| **S3 (done)** | `orders-service` owns orders, reservations, `raffle_numbers` reservation writes and expiry; shared schema; signed internal expiry command | S1 stable; atomic allocation and concurrency tests; Nginx exact/regex order locations | 97/97 integration, 33/33 E2E and 186/186 monolith tests pass; two-process double-sale test yields one 201 + one 409; payments still write orders until S4 | Route order paths back to the monolith; data stays in place; delete the two Nginx order locations |
| **S4 (done)** | `payments-service` owns payments, checkout, verify, webhooks, reconciliation; order/number writes only through the signed payment-result command | S3 stable; internal command contract and idempotency tests | 72/72 integration, 38/38 adapter, 9/9 real webhook concurrency, 39/39 E2E and 190/190 monolith tests; UNKNOWN/late approval/refund preserved; catalog/auth/orders unaffected | Delete the four Nginx payment locations; the monolith routes and data remain |
| **S5 (closed for the minimal scope, 2026-11-07)** | Per-service MySQL users and the two additive migrations; per-domain databases, outbox/sagas and rollback removal deferred | S1-S4 stable; explicit authorization; preflight green | Migrations applied separately; 4 least-privilege users validated (positive/negative/locking); services recreated one at a time; auth/catalog/reservations/payments/scheduler/isolation verified; 190/190 monolith + 33/33 orders E2E | Revert Compose to the shared user, `DROP USER`, `migrate:rollback` by path (webhook events would be lost); rollback routes retained |
| **S6 (done)** | Browser continuity: session revalidation, CSRF retry, route preservation, checkout return verification, expiry polling, single reservation-duration source | S1-S4 stable; S5 preparation | 42/42 S6 E2E, 12/12 auth integration, 190/190 monolith, frontend typecheck/build; 401/409/419/503 keep the session state; expiry matrix and idempotency verified | Frontend-only revert; backend changes are backward compatible |
| **S7 (done)** | Purchase confirmation and "Mis compras": owner-scoped order history with numbers/status, detail dates, status-specific number labels | S3 orders service + S6 session | 123/123 orders integration (26 list checks), 44/44 S7 E2E on `rifacloud_test`, 190/190 monolith, frontend typecheck/build; ownership 404/empty and 401/503 verified | Remove the frontend page/route and the `GET /api/orders` handler; existing detail/create behavior unchanged |

**Why auth first:** it has the smallest transactional surface (users and
sessions), and the monolith already stores sessions in the shared MySQL
database. A compatible auth-service can be introduced without changing order,
reservation or payment semantics. Orders and payments are extracted later
because they contain the atomic allocation and provider idempotency invariants.

## 9. Docker & Deployment (target)

```yaml
services:
  nginx:            # public entry point, TLS, routing, 503
  auth-service:     # PHP-FPM or HTTP, internal only
  raffles-service:  # catalog read + admin writes (S0/S2)
  orders-service:   # reservations/orders/expiry, internal only (S3 done)
  payments-service: # payments/provider/webhooks, internal only (S4 done)
  scheduler:        # php artisan schedule:work; no business API
  mysql:            # internal only, named volume
```

Deployment rules:

- Only Nginx publishes ports (80/443).
- Every business service has its own container, health check and restart policy.
- `docker compose stop <service>` must not stop MySQL or Nginx.
- Gateway maps upstream failure to a controlled JSON 503.
- The scheduler is not a business service and must not be used as a backdoor to
  write another service's data.

### Security

- MySQL on the internal Docker network only, no public port (NFR-011).
- Secrets via environment/secret storage, never committed (NFR-010).
- Security groups: 80/443 public, 22 restricted to the administrator IP.
- Outbound 443 required for Mercado Pago and SMTP.
- No service-to-service call trusts a client-supplied identity header.

#### Applied per-service MySQL users (S5 minimal, 2026-11-07)

Applied on the main `rifacloud` schema after explicit authorization, verifying
each step: the two additive migrations were applied separately, the four users
were created from `scripts/mysql-s5-grants.sql`, and each service was recreated
one at a time. Passwords live only in the gitignored root `.env`; they are never
printed or committed.

| Service | Own tables (SELECT/INSERT/UPDATE/DELETE) | Read-only | Locking-read allowance |
|---|---|---|---|
| `auth-service` | `users`, `sessions`, `cache`, `cache_locks` | — | — |
| `raffles-service` | `raffles`, `raffle_numbers` | `users`, `sessions` | — |
| `orders-service` | `orders`, `order_items`, `raffle_numbers` | `raffles`, `payments`, `users`, `sessions` | `UPDATE (updated_at)` on `raffles`, `payments` |
| `payments-service` | `payments`, `payment_webhook_events` | `orders`, `order_items`, `raffle_numbers`, `raffles`, `users`, `sessions` | `UPDATE (updated_at)` on `orders` |

Why the column-level allowance: **MySQL 8.4 requires the UPDATE privilege for
`SELECT ... FOR UPDATE`** (verified: without it the error is
`SELECT with locking clause command denied`). `LOCK TABLES` cannot be granted at
table level (`Illegal GRANT/REVOKE command`). Granting `UPDATE` only on the
inert `updated_at` column enables the documented locking reads while the
negative tests still reject any change to business columns (for example
`payments.status` from orders-service and `orders.status` from payments-service).

`app`, `scheduler` and `mysql` keep the shared `rifacloud` user until the
rollback routes/classes are removed (full S5/S7 work). Applied checks:
connectivity, reads, locking reads, own-table writes (transaction + rollback)
and denied cross-domain writes all pass. The same matrix was granted on
`rifacloud_test` so the existing E2E suites exercise the production credentials
against the test schema.

### Persistence

- MySQL data in the named volume `mysql_data`.
- On EC2, the volume persists on EBS.
- Service containers are stateless; sessions live in MySQL during the
  transition.

## 10. Failure Handling Summary

| Failure | Expected behavior | Data preserved |
|---|---|---|
| `auth-service` down | Login/register/logout/me return controlled 503; existing sessions already in MySQL can still be validated by services that read the session table; catalog stays up | Users/sessions unchanged |
| `raffles-service` down | Catalog/detail/numbers and admin raffle routes return controlled 503; auth/orders/payments remain available; admin writes fail closed (no partial raffle/number rows) | Raffles unchanged |
| `orders-service` down | Order/reservation endpoints return a controlled 503; catalog, auth and payment records remain readable; `orders:expire-reservations` exits non-zero and releases nothing (next scheduled run retries) | No partial orders; reservations stay as they were until expiry resumes |
| `payments-service` down | Checkout/verify/resolve and webhook reception return a controlled 503; the webhook 503 makes Mercado Pago retry, so no notification is lost. No order is marked paid and no number sold; `payments:reconcile` exits non-zero. Catalog, auth and orders keep working. | `payments` and order/number state unchanged |
| MySQL down | All writes fail closed; no fake order/payment; health checks report unhealthy | Volume intact |
| Nginx down | Complete public outage (single entry point) | Data intact |
| Scheduler down | No automatic expiry/retries; no corruption; manual trigger available | Data intact |

Detailed scenarios and acceptance criteria are in
`07-resilience-and-failure-handling.md` and `05-acceptance-criteria.md`.

## 11. Health Checks & Observability

- Each service exposes an internal health endpoint (process + DB connectivity).
- Nginx health depends on upstream availability and returns 503 when an
  upstream is down.
- `X-Request-Id` is generated at the gateway and propagated.
- Logs go to stdout/stderr, never include secrets, tokens or full provider
  payloads.
- No sensitive data in health responses.

## 12. Limitations (current)

- **Single EC2 instance:** no high availability; instance failure is a complete
  outage.
- **Shared MySQL/schema during transition:** documented risk; not the final
  state.
- **Session compatibility:** auth extraction and S2 admin authorization depend on
  sharing the `sessions` table, cookie name, `APP_KEY` and `password_hash_web`
  contract until a token-based redesign is approved.
- **Shared-schema admin writes:** `raffles-service` now writes `raffles` and
  `raffle_numbers` inside the shared schema. Per-domain databases and grants are
  deferred to S5.
- **Cross-owner payment writes (resolved in S4):** the monolith payment flow no
  longer writes `orders`, `order_items` or `raffle_numbers`; the decision moved
  to orders-service. The monolith classes remain only as the documented rollback
  path.
- **Main schema S5 applied (2026-11-07):** both additive migrations were applied
  separately after a fresh backup and duplicate/NULL checks. `payments` has the
  unique `order_id` index and `payment_webhook_events` exists. The four
  per-service MySQL users are in place; the monolith keeps the shared
  `rifacloud` user until the rollback routes are removed. Remaining work for
  the full S5/S7 is per-domain databases, outbox/compensation and cleanup.
- **Webhook configuration on the main stack:** `MERCADOPAGO_WEBHOOK_SECRET` and
  `MERCADOPAGO_WEBHOOK_URL` are still empty. An unsigned notification gets a
  retryable 503 (fail closed), and `APP_URL=http://localhost` is not a public
  HTTPS callback. Set both values in `src/.env`, recreate `payments-service`, and
  use a tunnel or the Mercado Pago simulator for local delivery (P3 for public
  deployment).
- **Saga window:** order/number changes commit before the payment row update. A
  crash in between leaves the payment row lagging (PENDING/UNKNOWN) until the
  next webhook/verify/reconcile replay; it can never produce a sale without an
  order transition or an unreleased paid order.
- **Session last-activity:** admin requests are validated but do not refresh
  `sessions.last_activity` (an auth-owned column); a session-touch contract is
  pending. Long-lived admin sessions therefore rely on the auth-service/monolith
  activity that already updates the row. The same applies to orders-service: a
  user who only browses the catalog and places orders never touches
  auth-service, so the session still expires after `SESSION_LIFETIME` (120 min).
  This is a consequence of not writing an auth-owned table, not a bug.
- **No distributed transactions:** orders/payments extraction requires
  idempotent commands and compensation, not two-phase commit.
- **Sandbox only:** no real money.
- **Manual result publication:** no automatic winner selection.
- **Phase-gated external dependencies:** P2 payment/email, P3 deployment, P4
  legal.

## 13. Historical Architecture (v1.2, preserved)

The previously approved architecture was a Laravel modular monolith deployed
with Nginx, PHP-FPM, a queue worker, a scheduler and MySQL on a single EC2
instance. Microservices and Kubernetes were explicitly out of scope, and the
monolith was the final architecture. That decision was valid for the original
MVP and remains in the project history. ADR-001 supersedes it only for the
approved four-service evolution; all other v1.2 decisions (Mercado Pago as the
only provider attempted, 10-minute reservations, cents, manual results, MySQL
internal-only, fail-closed payments) remain unchanged.

## 14. Change Log

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-10-22 | Initial technical design (modular monolith) |
| 1.1 | 2026-10-23 | Reservation model, payment states, resilience details |
| 1.2 | 2026-10-23 | Coherence review; final approved monolith design |
| 2.0 | 2026-11-05 | ADR-001: approved four-service target; current/transitional/target states; responsibility and data-ownership matrix; contracts; service auth; reservation atomicity; payment idempotency; shared-schema risks; transition stages S0-S5 |
| 2.1 | 2026-11-05 | S1 implemented and verified: `auth-service` container/health, Nginx routing, shared session contract, 12/12 integration (including monolith session acceptance), 14/14 isolation, 59 Laravel regression tests |
| 2.2 | 2026-11-06 | S2 implemented and verified: `raffles-service` admin CRUD/publish/close + transactional number generation; cookie/app-key/role/`password_hash_web`/CSRF admin boundary; 56/56 integration and 64/64 E2E; Apache 419 and Nginx `proxy_intercept_errors` findings recorded in `07-resilience-and-failure-handling.md` |
| 2.3 | 2026-11-06 | S3 implemented and verified: `orders-service` owns order creation, `GET /api/orders/{id}`, atomic reservation and expiry; session/CSRF boundary; signed internal expiry endpoint with advisory lock; Nginx exact/regex order routing and `/internal/` denial; 97/97 integration, 33/33 E2E and 186/186 monolith tests; payment writes documented as the only remaining cross-owner write until S4 |
| 2.4 | 2026-11-06 | S4 implemented and verified: `payments-service` owns payments/Mercado Pago/webhooks/reconciliation; order and number transitions moved to a signed idempotent payment-result command in `orders-service`; duplicate webhook, UNKNOWN, late approval, refund and fail-closed paths covered; 72/72 integration, 38/38 adapter, 9/9 real webhook concurrency, 39/39 E2E and 190/190 monolith tests; two additive main-schema migrations remain pending and are documented as the deployment prerequisite |
| 2.5 | 2026-11-06 | S5 preparation (not closed): preflight confirmed no duplicate/NULL `payments.order_id`, `migrate --pretend` showed only additive DDL, and a backup was restored into a temporary schema; per-service grant matrix designed and validated in `rifacloud_test` (including the MySQL 8.4 locking-read requirement and negative cross-domain write tests); webhook secret/URL gap documented. Migrations and grants were NOT applied to the main schema. |
| 2.6 | 2026-11-07 | S6 stabilization implemented and verified: session revalidation distinguishes 401 from 503, one-shot CSRF 419 replay, login redirect preserves the intended route, checkout return performs a server-side verification and shows the real state, countdown polls until the scheduler releases, and `RESERVATION_MINUTES` has a single source across catalog/orders/monolith. Fixed the auth-service 500 on anonymous non-JSON `/api/*` requests. Evidence: 42/42 S6 E2E, 12/12 auth integration, 190/190 monolith, frontend typecheck/build. |
| 2.7 | 2026-11-07 | S5 minimal applied with explicit authorization: fresh backup; unique `payments.order_id` index and `payment_webhook_events` applied and verified separately; four least-privilege MySQL users created (connectivity, locking reads, own writes and denied cross-domain writes verified); services switched one at a time; auth (register/logout/login/me), catalog, reservations/expiry (33/33 E2E), payments routing, scheduler and failure isolation verified; 190/190 monolith tests. Monolith shared user and rollback routes retained. Webhook secret/URL still pending. |
| 2.8 | 2026-11-07 | S7 implemented and verified: `GET /api/orders` owner-scoped paginated history in `orders-service`; "Mis compras" page and enriched detail with raffle, order id/number, real status, created/paid dates and per-number status labels; only `SOLD` numbers are shown as purchased. 123/123 orders integration, 44/44 S7 E2E, 190/190 monolith, frontend typecheck/build. |
| 2.9 | 2026-11-07 | S9.1 critical corrections: scheduled idempotent `payments:reconcile`; scheduler heartbeat/healthcheck and clean SIGQUIT shutdown; FR-014 `raffle_results` + admin publish + public result; admin orders/payment-incidents lists; retryable notifications queue/worker; registration throttle; per-service environment isolation. Test schema verified. |
| 2.10 | 2026-11-07 | S9.1 activated on the main schema with explicit authorization: migrations `000009`/`000010` applied separately and verified (unique/FKs/indexes); `raffle_results` granted to `rifacloud_raffles` (transactional insert/rollback passed). Scheduler healthy; `payments:reconcile --dry-run` reported 3 pending; notification queue/dispatch produced 2 incident notices marked SENT with the log mailer (no real email). Main data stayed at 8 users, 2 raffles, 15 orders, 8 payments. |
| 2.11 | 2026-11-07 | S10.1 deployment preparation: production Compose overlay, secret-free env template, ALB/ACM and direct-Nginx TLS strategies, DB bootstrap with encrypted pre-migration backup and least-privilege grants, encrypted S3 backup/restore procedures, healthchecks, Security Group/cost/runbook documentation. Local validations passed; no AWS resource created and no main migration altered. |
| 2.12 | 2026-11-07 | S10.2 functional/UX polish: public raffle state derived from backend status plus `starts_at`/`ends_at` (upcoming, available, closed, finished) reusing the S8 StatusPill; reservation controls disabled outside the sale window while all server checks remain; rejected/expired payment recovery card with a link back to the raffle; result card with winner, publication date and evidence. 16 unit checks and 12/12 browser tests (intercepted API, simulated gateway), all critical regressions green. No backend contract, schema, payment rule or main data changed. |
| 2.13 | 2026-11-07 | S10.3 admin dashboard: read-only aggregates `GET /api/admin/raffles/metrics` (PUBLISHED raffles), `GET /api/admin/orders/metrics` (PAID orders and SOLD numbers) and `GET /api/admin/payments/incidents/metrics` (UNKNOWN + REFUND_REQUIRED payments), each authenticated as ADMIN in its owning service. Nginx exposes exact routes with a controlled JSON 503; `/admin/dashboard` shows partial availability without inventing zeros. 28/28 E2E on `rifacloud_test`, 20/20 Playwright, 129/129 orders integration, 77/77 payments integration, 42/42 S6, 44/44 S7, 39/39 payments E2E, 12/12 auth and 193/193 monolith. No migration or main-data change. |
