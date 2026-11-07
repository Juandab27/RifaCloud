# 05 - Acceptance Criteria

**Project:** RifaCloud  
**Status:** Approved (architecture evolution)  
**Version:** 1.5  
**Last Updated:** 2026-11-06  

All criteria are verifiable with observable results. Each criterion links to functional requirements.

## Phase Gates for Acceptance

Most criteria are verifiable locally in P1. The following require external access (see `06-open-questions.md`):

| Criteria | Phase | Requirement |
|---|---|---|
| AC-015 | P1 (simulated) + P2 | Successful `APPROVED` path verified with the simulated gateway; real E2E with Mercado Pago sandbox (Q-05) |
| AC-016 | P1 (simulated) + P2 | `REJECTED` path verified with the simulated gateway; real validation with Mercado Pago sandbox (Q-05). Order must end in `REJECTED` |
| AC-017 | P1 (fixtures) + P2 | Duplicate delivery tested locally with signed fixtures; real delivery requires public URL (Q-06) |
| AC-035 | P1 (log) + P2 | `log` mailer locally; Mailtrap (Q-08) |
| AC-037 | P1 (queue) + P2 | Refund email queued locally; real delivery at P2 |
| AC-031, AC-032 | P1 local + P3 AWS | `docker-compose up` and named-volume persistence locally; EC2 and EBS persistence at deployment |

## 1. Authentication & Authorization

| ID | Requirement | Acceptance Criteria | Test Method |
|---|---|---|---|
| AC-001 | FR-001, FR-002 | User can register with name, email, phone. Password hashed. Can login with valid credentials. | Manual/E2E |
| AC-002 | FR-003 | Unauthenticated user cannot access purchase endpoints. Returns 401/403. | API test |
| AC-003 | NFR-012 | Admin routes require ADMIN role checked on backend. Direct API calls without role return 403. | API test |
| AC-004 | NFR-012 | USER role cannot access admin endpoints. | API test |

S2 note: admin authorization is enforced inside `raffles-service` from the
shared session, never from a client-supplied identity header. Anonymous is 401,
a USER session is 403, a tampered/expired/password-changed session is 401, and a
missing/invalid CSRF token on an unsafe method is 419.

## 2. Raffle Management

| ID | Requirement | Acceptance Criteria | Test Method |
|---|---|---|---|
| AC-005 | FR-011, FR-012 | Admin can create raffle with title, price per number, min/max range. Numbers auto-generated. | Manual |
| AC-006 | FR-011 | Only PUBLISHED raffles visible in public catalog. DRAFT/CLOSED not shown. | Manual/API |
| AC-007 | FR-004 | Public catalog shows raffle details without authentication. | Manual |

## 3. Number Selection & Reservation

| ID | Requirement | Acceptance Criteria | Test Method |
|---|---|---|---|
| AC-008 | FR-005, FR-006 | User can view available numbers. Can select multiple numbers in one order. | Manual |
| AC-009 | FR-007 | Selecting numbers creates reservation for 10 minutes. `reserved_until` set correctly. | DB check |
| AC-010 | FR-007, NFR-004 | Reserved numbers show as unavailable to other users during reservation period. | Concurrency test |
| AC-011 | NFR-002, NFR-004 | Two users clicking same number simultaneously - only first succeeds, second gets error. No double reservation. | Concurrency test |
| AC-012 | FR-007 | After 10 minutes, expired reservations release numbers back to AVAILABLE (if no uncertain payment). | Scheduler test |

## 4. Payment Flow

| ID | Requirement | Acceptance Criteria | Test Method |
|---|---|---|---|
| AC-013 | FR-008 | Checkout creates payment record with provider checkout URL. Order status PENDING_PAYMENT. | API/Manual |
| AC-014 | NFR-001 | Payment confirmation requires server-side verification via provider API. Browser redirect alone doesn't mark paid. | Manual |
| AC-015 | FR-008 | Successful payment (verified) marks order PAID, numbers SOLD, assigned to order. | Simulated gateway (P1); E2E with sandbox (P2) |
| AC-016 | FR-008 | Rejected payment marks order `REJECTED`, numbers released back to AVAILABLE. | Simulated gateway (P1); E2E with sandbox (P2) |
| AC-017 | NFR-003 | Duplicate webhook with same provider_event_id processed only once. Order/payment not duplicated. | Webhook test |
| AC-018 | NFR-009 | Payment result UNKNOWN goes to PAYMENT_REVIEW. Numbers remain reserved, not released. | Manual test |

## 5. Edge Cases & Incidents

| ID | Requirement | Acceptance Criteria | Test Method |
|---|---|---|---|
| AC-019 | FR-008, FR-015, NFR-002 | If payment confirmed but numbers already sold to another order → order marked `REQUIRES_REFUND`, payment marked `REFUND_REQUIRED`, admin incident created, numbers NOT assigned twice. Refund remains manual. | Simulated test: assert both states; no automatic refund |
| AC-020 | FR-015 | Admin can view payment review cases and REQUIRES_REFUND orders in dashboard. | Manual |
| AC-021 | NFR-005, NFR-006 | Email failure doesn't prevent purchase confirmation. Order state is preserved: `PAID` for a paid purchase, `REQUIRES_REFUND` for a refund incident; email is queued for retry. | Simulate email failure |
| AC-022 | NFR-005 | Failed emails retry with limited attempts. After max attempts marked FAILED. | Queue test |

## 6. Results & Admin

| ID | Requirement | Acceptance Criteria | Test Method |
|---|---|---|---|
| AC-023 | FR-014 | Admin can publish result for finished raffle with winning number, date, evidence. Only valid numbers allowed. | Manual |
| AC-024 | FR-014 | Cannot publish result for invalid number not in raffle range/sold set. Validation prevents it. | API test |
| AC-025 | FR-014 | Only one result per raffle. Cannot publish twice. | API test |
| AC-026 | FR-013 | Admin can view all orders, filter by status, see payment incidents. | Manual |

## 7. Resilience & Infrastructure

| ID | Requirement | Acceptance Criteria | Test Method |
|---|---|---|---|
| AC-027 | NFR-008 | Catalog loads even if payment provider API unreachable. No errors blocking browse. | Network simulation |
| AC-028 | NFR-007 | If MySQL unavailable during purchase attempt, system returns error. Does NOT create order or mark as paid. | DB stop test |
| AC-029 | NFR-011 | MySQL port not accessible from outside Docker network. Only internal services connect. | Network check |
| AC-030 | NFR-010 | No secrets in source code. All credentials from environment variables. | Code review |
| AC-031 | NFR-010, NFR-011 | App runs via docker-compose up. All services healthy. | Local compose (P1); EC2 (P3) |
| AC-032 | NFR-014 | After docker-compose down/up **and** after EC2 stop/start, MySQL data persists (orders, users, raffles remain). | Local named volume (P1); EBS (P3) |
| AC-033 | NFR-009 | External API calls have timeouts. Failures don't hang requests indefinitely. | Timeout test |

## 8. User Experience

| ID | Requirement | Acceptance Criteria | Test Method |
|---|---|---|---|
| AC-034 | FR-009 | User can view order history with status, numbers, amount, dates. | Manual |
| AC-035 | FR-010 | User receives email notification for order confirmation/payment status. | `log` mailer (P1); Mailtrap (P2) |
| AC-036 | FR-006 | Single order can contain multiple numbers from same raffle. Total calculated correctly. | Manual |
| AC-037 | FR-010, NFR-005 | When an order enters `REQUIRES_REFUND`, a refund-incident email is queued for the buyer. Email failure does not alter order/payment state. | Queue test (P1): order stays `REQUIRES_REFUND`, payment stays `REFUND_REQUIRED`; Mailtrap (P2) |

## 9. Microservices Evolution

These criteria apply to each extracted service and are verified with the shared
MySQL transition unless a later stage states otherwise.

| ID | Requirement | Acceptance Criteria | Test Method |
|---|---|---|---|
| AC-038 | NFR-016 | A service can be started and stopped independently of MySQL, Nginx and the other services. Its health check reports healthy when ready. | `docker compose stop/start <service>`, health status |
| AC-039 | NFR-019 | When `auth-service` is down, login/register/logout/me return a controlled JSON 503 (not HTML, not a fake success). Existing valid sessions continue to be accepted by services that read the shared session table. | Stop/start test with `Accept: application/json` |
| AC-040 | NFR-008, NFR-019 | When `raffles-service` is down, the catalog **and admin raffle routes** return a controlled JSON 503 while auth, orders and payments remain operational. | Stop/start test |
| AC-041 | NFR-019 | When `orders-service` is down, order/reservation endpoints return a controlled JSON 503 while catalog and auth remain operational. No partial order is created, and the expiry command fails closed without releasing numbers. | Stop/start + DB check + expiry command exit code |
| AC-042 | NFR-001, NFR-019 | When `payments-service` is down, checkout/verify/resolve/webhook return a controlled JSON 503 (the webhook 503 asks the provider to retry). No order is marked paid and no number is sold; reconciliation fails closed. | Stop/start + DB check |
| AC-043 | NFR-017 | Service-to-service calls are authenticated. A forged client header (`X-User-Id`, `X-Role`) cannot authorize an admin/internal operation. | Negative API test |
| AC-044 | NFR-018 | No service writes another domain's tables except through an approved command. `payments-service` never writes `orders`, `order_items` or `raffle_numbers`; it requests the result through the signed payment-result command. The prepared S5 grants allow only the owning tables plus read-only/locking-read access, so a cross-domain business-column write is denied by MySQL. | Code review + DB grant test + command contract test |
| AC-045 | NFR-016 | Rollback of an extracted route requires only routing it back to the owner; no data migration and no data loss. | Route rollback test |
| AC-046 | NFR-019 | After login, a page reload and a Mercado Pago return, a still-valid session is recovered with `/api/me`; a 503/timeout is shown as unavailable with retry and never as a logout. No credentials are stored in `localStorage`. | Reload/return E2E + 503 test |
| AC-047 | NFR-001, NFR-019 | The checkout return URL never approves an order. The backend verifies the payment with the provider and the UI shows approved/pending/rejected/review/expired/refund from the API; 409/419/503 keep the order and session on screen. | Return E2E + 409/419/503 tests |
| AC-048 | FR-009, NFR-017, NFR-018 | An authenticated user can list only their own orders (paginated) with raffle, numbers and their real status, order id/number, state and dates. Only `SOLD` numbers are presented as purchased; reserved/released numbers are labelled accordingly. Another user's orders never appear and their detail returns 404. | Integration + E2E (ownership, pagination, status matrix) |
| AC-049 | FR-014, NFR-012, NFR-018 | An ADMIN, with CSRF, can publish exactly one result for a CLOSED raffle using an in-range number marked SOLD, plus publication date and evidence. The raffle transitions to FINISHED and the result is visible publicly; unsold/out-of-range numbers, missing evidence and duplicate results are rejected. | Targeted admin/result E2E |
| AC-050 | FR-013, NFR-012 | An ADMIN can page through all orders and payment incidents, filter by status and see buyer/order/payment context. Non-admin and anonymous callers receive 403/401; the existing provider-resolution action is reused without new financial rules. | Targeted admin panel E2E |
| AC-051 | FR-010, NFR-005, NFR-006 | Paid orders queue one purchase confirmation; PAYMENT_REVIEW/REQUIRES_REFUND queue an incident/refund notice. Delivery is asynchronous with bounded retries; a mail failure only changes the notification row and never the order/payment. Tests use the log/array mailer and send no real email. | Feature tests + runtime command checks |
| AC-052 | NFR-010, NFR-012 | Registration is rate-limited in auth-service and the monolith rollback route. Services receive only the environment variables they need; Mercado Pago credentials are not injected into auth/raffles/orders. | E2E 429 check + container environment inspection |

### S1 verification evidence (auth-service, 2026-11-05)

Verified against `rifacloud_test` and the live Compose stack:

- **12/12 integration checks:** CSRF 204; invalid login 422; register 201;
  `/api/me` 200 with the registered email and no password; the monolith
  accepts the auth-service session (200); logout 200; `/api/me` after logout
  401.
- **14/14 failure-isolation checks:** when `auth-service` is stopped,
  `/api/auth/health`, `/api/login`, `/api/me` and `/sanctum/csrf-cookie` return
  a controlled JSON 503; the catalog stays 200; the monolith admin route
  returns 401; the service becomes healthy again after restart.
- **59 Laravel regression tests pass** (catalog, auth, orders and payments).

### S2 verification evidence (raffles administration, 2026-11-06)

Verified against `rifacloud_test` and the live Compose stack through Nginx:

- **56/56 admin integration assertions** (`tests/admin_integration.php`):
  CSRF 419; anonymous 401; tampered/expired/password-changed session 401; USER
  403; malformed JSON 400; unknown path 404; unsupported method 405; paginated
  list with `data/links/meta` and `per_page=15`; create 201 DRAFT with inclusive
  `total_numbers`; exact, unique `raffle_numbers` (including a 2500-row chunked
  range); update; publish/close transitions; reserved/sold guards; delete 204;
  FK cascade; catalog projection still works.
- **64/64 E2E checks through Nginx** (`scripts/verify-raffles-service-admin.ps1`):
  ADMIN login via auth-service; admin boundary (401/403/419, tampered cookie);
  CRUD/publish/close and validation messages; `POST /api/orders` still reserves
  atomically and rejects double-selling with 409 `NUMBERS_UNAVAILABLE`; webhook
  still rejects an empty payload; while `raffles-service` is stopped the catalog
  and admin routes return a controlled 503 while auth, orders and webhooks keep
  working; everything recovers after restart.
- **S0 catalog E2E** remains green (`scripts/verify-raffles-service.ps1`) and the
  S0 repository integration test still passes, proving no catalog regression.
- **No application data mutated:** the E2E fixture created disposable
  `raffles-e2e-*@example.invalid` users and removed them and their rows; the main
  `rifacloud` counts were unchanged after cleanup.
- **Rollback proven:** the monolith admin routes remain registered and the Nginx
  admin location is the only switch needed to restore the previous path.

### S3 verification evidence (orders, reservations and expiry, 2026-11-06)

Verified against `rifacloud_test` and the live Compose stack through Nginx:

- **97/97 orders-service integration checks**
  (`services/orders-service/tests/orders_integration.php`): anonymous 401;
  tampered/expired session 401; stateful POST without token 419; same-origin POST
  accepted; validation 422 (`numbers`, duplicates `numbers.1`, range, raffle
  existence, foreign numbers); business conflicts 409
  (`RAFFLE_NOT_AVAILABLE`, `RAFFLE_NOT_STARTED`, `RAFFLE_ENDED`,
  `NUMBERS_UNAVAILABLE` with `unavailable_numbers`); 201 contract
  (`data`, `PENDING_PAYMENT`, cents, raffle, numbers, `simulated_payments`,
  empty payments); owner 200 and another user 404 without disclosing the order
  number; **real two-process concurrency yields exactly one 201 + one 409 and a
  single order**; expiry releases numbers, keeps `order_items`, is idempotent,
  ignores active/`PAID`/`PAYMENT_REVIEW`/`REQUIRES_REFUND` orders and never
  releases with `UNKNOWN`/`APPROVED`/`REFUND_REQUIRED` payments or `SOLD`
  numbers; `GET_LOCK` makes an overlapping run return `skipped=true`; internal
  endpoint rejects unsigned/invalid/stale requests and accepts a valid one;
  payments projection and routing surface.
- **33/33 E2E checks through Nginx** (`scripts/verify-orders-service.ps1`):
  auth/CSRF/ownership; order creation contract; parallel POST double-sale
  prevention; scheduler command expiry; `UNKNOWN` payment blocking; payment
  sub-route and webhook still handled by the monolith; while `orders-service` is
  stopped the order routes return a controlled 503 while `/api/me` and the
  catalog stay 200; everything recovers after restart.
- **Fail-closed expiry verified live:** with the service stopped,
  `orders:expire-reservations` printed `Reservation expiry failed: orders-service
  is unreachable.` and exited 1 without releasing numbers.
- **186/186 monolith tests pass** (1032 assertions), including the payment
  regression paths that keep exercising the expiry rules through
  `ReservationExpiryService`.
- **No application data mutated:** the E2E fixture created a disposable
  `orders-e2e-*@example.invalid` user/admin and removed them, the raffle, numbers,
  orders, items and payments; the main `rifacloud` counts were unchanged after
  cleanup.
- **Rollback proven:** deleting the two Nginx order locations returns the paths
  to the monolith, which still owns equivalent routes and the same data.

### S4 verification evidence (payments, Mercado Pago and webhooks, 2026-11-06)

Verified against the fully migrated `rifacloud_test` schema and, for the E2E
run, the real Compose stack through Nginx with the simulated gateway:

- **72/72 payments-service integration checks**
  (`services/payments-service/tests/payments_integration.php`) against a real
  orders-service test container: 401/419/404; checkout 201 and 200 resume with a
  single payment row; order stays `PENDING_PAYMENT`; APPROVED marks the order
  PAID and the numbers SOLD once; a repeated verification returns 409 without a
  second sale; UNKNOWN moves to `PAYMENT_REVIEW` and expiry keeps the numbers
  reserved; a late approval becomes `REQUIRES_REFUND`/`REFUND_REQUIRED` without
  selling; a rejection releases the numbers; admin review resolution; webhook
  approved/duplicate/refund/unknown + one event row; signed reconciliation and
  dry-run; `ORDER_SERVICE_UNAVAILABLE` fail-closed with the payment still
  PENDING; routing surface and health.
- **38/38 Mercado Pago adapter checks**
  (`tests/gateway_integration.php`, offline transport): checkout payload,
  deterministic external reference/idempotency key, cents→unit conversion,
  sandbox URL selection, status mapping, official HMAC manifest, authoritative
  re-fetch, no payer data persisted, 4xx/5xx/transport error mapping and the
  unconfirmed duplicate-approval search.
- **9/9 real two-process webhook concurrency checks**
  (`tests/webhook_concurrency.php`): two PHP processes POST the same notification
  simultaneously; both return 200, one event row exists, the order is PAID, the
  numbers are SOLD exactly once and no second order is created.
- **39/39 E2E checks through Nginx**
  (`scripts/verify-payments-service.ps1`) on the test schema: auth/CSRF/routing,
  checkout contract and resume, parallel duplicate webhooks, approved sale,
  ignored/unknown/malformed notifications, admin authorization, and stop/start
  isolation (only `payments-service` stops; auth/catalog/orders keep working,
  checkout and webhook return a controlled 503, and the order + payment survive
  the restart).
- **190/190 monolith tests pass** (1038 assertions), including the reconciliation
  delegation and fail-closed command tests and the retained payment state-machine
  regression tests.
- **No development data mutated:** the main `rifacloud` counts were unchanged
  after the runs; the E2E fixture and the integration/concurrency tests removed
  their users, sessions, raffles, numbers, orders, items, payments and webhook
  events.
- **Rollback proven:** deleting the four Nginx payment locations returns the
  paths to the monolith, which keeps equivalent routes and the same data.
- **Deployment prerequisite:** the main `rifacloud` schema still lacks two
  additive migrations (`payments.order_id` unique and
  `payment_webhook_events`). They were not applied because migrating the main
  schema is out of scope; webhook persistence on that schema fails closed (503)
  until they are applied with authorization.

### S5 preparation evidence (environment and least privilege, 2026-11-06)

Read-only checks and isolated validation; **the main schema was not modified**:

- **Real state confirmed:** `migrate:status` on `rifacloud` shows exactly two
  pending migrations (unique `payments.order_id`, `payment_webhook_events`);
  `payments` matches `rifacloud_test` except for the unique index.
- **Unique-index preconditions:** 0 duplicate `order_id` groups and 0 NULL
  `order_id` rows. `migrate --pretend` printed only additive DDL and left both
  migrations pending.
- **Backup verified:** `mysqldump --single-transaction` (33 KB) restored into a
  temporary schema with identical counts (8 users, 14 orders, 8 payments, 65
  numbers); the temporary schema was dropped afterwards.
- **Grant matrix validated in `rifacloud_test`** with four temporary users:
  auth (users/sessions/cache R+W), raffles (raffles/raffle_numbers R+W, identity
  read), orders (orders/items/numbers R+W, locking reads on raffles/payments)
  and payments (payments/events R+W, locking read on orders). Negative checks
  passed: orders cannot write `payments`, payments cannot write `orders`,
  raffles cannot read `orders`, auth cannot read `payments`. Temporary users were
  dropped and the test schema returned to 0 fixture rows.
- **MySQL 8.4 finding:** `SELECT ... FOR UPDATE` requires the UPDATE privilege;
  table-level `LOCK TABLES` cannot be granted. The design uses column-level
  `UPDATE (updated_at)` on the read-only locked tables, so locking reads pass
  while business-column writes stay denied.
- **Webhook configuration:** `MERCADOPAGO_WEBHOOK_SECRET` and
  `MERCADOPAGO_WEBHOOK_URL` are missing; an unsigned notification returns the
  retryable 503 (`{"status":"retry"}`), and `APP_URL=http://localhost` is not a
  public callback. The adapter suite (38/38) already proves the HMAC manifest.
- **Residual dependencies reviewed:** no runtime path outside the documented
  rollback routes writes another domain; the cleanup list and the conditions to
  delete it are recorded in `04-implementation-plan.md`.

### S5 application evidence (minimal scope, 2026-11-07)

Applied with explicit authorization, verifying each operation before the next:

- **Fresh backup:** 34.564 bytes, SHA-256
  `4388F1A0DD14BB4B04ADEB1EB87C7BB8C3FE766AB3794240898971689A01F84A`; the
  earlier preflight dump remains available and was restore-tested.
- **Migrations, separately:** unique `payments_order_id_unique(order_id)` applied
  (138.71 ms) and verified with `SHOW CREATE TABLE` / `migrate:status`; then
  `payment_webhook_events` applied (226.01 ms) with its unique `(provider,
  provider_event_id)` and indexes. Both show `Ran`; the table is empty and
  `payments` still has 8 rows. MySQL removed the now-redundant FK index.
- **Grants:** `rifacloud_auth`, `rifacloud_raffles`, `rifacloud_orders` and
  `rifacloud_payments` created from the template, with passwords only in the
  gitignored root `.env`. Verification: connectivity, allowed reads, locking
  reads (`FOR UPDATE` on the cross-domain tables), own-table writes with
  `START TRANSACTION … ROLLBACK`, and **denied** cross-domain writes
  (orders→`payments.status`, payments→`orders.status`, raffles→`orders`,
  auth→`payments`). No business data changed. The same matrix was granted on
  `rifacloud_test`, so the S6 E2E (42/42) ran with the production per-service
  credentials against the test schema.
- **Compose/credentials:** `auth-service` uses `AUTH_DB_*`; `raffles`, `orders`
  and `payments` use their own `*_DB_*` variables; `app`, `scheduler` and
  `mysql` keep the shared `rifacloud` user as the rollback path. Each service was
  recreated and health-checked before the next.
- **Functional verification on the main environment:** auth register 201,
  logout 200, login 200, `/me` 200 (browser-like Referer); catalog/detail/numbers
  200; orders/reservations/expiry E2E 33/33 (double sale, UNKNOWN expiry block,
  scheduler, stop/start); payments routes 401/405 and webhook 503/400; scheduler
  `orders:expire-reservations` exit 0; stopping payments/auth returned the
  controlled 503 while orders/catalog/`/me` kept working; 190/190 monolith tests.
- **Data intact:** users 8, raffles 2, raffle_numbers 65, orders 14,
  order_items 18, payments 8, webhook_events 0. Rollback retained (shared user
  and routes); per-domain databases, outbox and route removal remain deferred.

### S6 verification evidence (session, expiry and checkout return, 2026-11-07)

Verified through Nginx on the fully migrated `rifacloud_test` with the simulated
gateway and a 1-minute reservation (`scripts/verify-s6-session-and-return.ps1`);
the normal stack was restored afterwards.

- **42/42 S6 checks:** session cookie `HttpOnly`, `SameSite=Lax`,
  `domain=localhost` and no forced `Secure` on HTTP; XSRF cookie readable by the
  SPA; CSRF bootstrap 204; registration 201; `/api/me` 200 twice (page reload);
  the catalog and the order `expires_at` both use the same 1-minute value; the
  return URL alone leaves the order `PENDING_PAYMENT`; the server-side verify
  reaches `PAID` and sells the numbers; a repeated verify returns 409 without
  losing the session; missing CSRF returns 419 and the same request with the
  token succeeds; a stopped auth-service returns 503 and the session survives
  the restart; an invalidated session returns 401 for `/api/me` and the order.
- **Expiry matrix:** a plain expired reservation becomes `EXPIRED` and the
  numbers return to `AVAILABLE`; a second run is idempotent (`Expired orders: 0`);
  `UNKNOWN`, `PAYMENT_REVIEW`, `APPROVED`/`PAID` and `SOLD` are never released
  (numbers stay `RESERVED`/`SOLD` as appropriate).
- **12/12 auth-service integration checks** remain green (CSRF, login, `/me`,
  logout, monolith session acceptance).
- **190/190 monolith tests** (1038 assertions) remain green.
- **Frontend:** `tsc --noEmit` and `vite build` pass; the Nginx image was
  rebuilt, so the demo serves the corrected SPA.
- **Fixed during S6:** anonymous non-JSON `/api/*` requests to auth-service
  returned 500 (missing `login` route); the frontend could clear the session on
  503; `419` aborted the action without retrying; login did not return to the
  intended order; `RESERVATION_MINUTES` could diverge between the catalog and
  orders-service; and the checkout return required a manual verification.

### S7 verification evidence (purchase confirmation and history, 2026-11-07)

Verified through Nginx on `rifacloud_test` with the simulated gateway; the
normal stack was restored afterwards and the main data was not touched.

- **123/123 orders-service integration checks** (26 new list checks):
  anonymous list 401; owner list 200; `meta` default 10 and `per_page` clamp 50;
  `per_page=1` pagination with disjoint pages and newest-first order; the list
  contract includes raffle title, order number/id, status, numbers, `paid_at`
  and `created_at`; the owner's list includes their orders and excludes another
  user's order; the other user's list contains only their own order.
- **44/44 S7 E2E checks through Nginx**
  (`scripts/verify-s7-purchases.ps1`): buyer A registers, reloads and keeps the
  session; a verified approved payment is listed as `PAID` with both numbers
  `SOLD` and a non-null `paid_at`; a pending order is `PENDING_PAYMENT` with the
  number `RESERVED`; an expired order is `EXPIRED` with the number `AVAILABLE`;
  a review order is `PAYMENT_REVIEW` with the number `RESERVED`; only the paid
  purchase reports `SOLD` numbers; the detail exposes raffle, id, order number,
  `PAID` and `paid_at`; pagination is disjoint; buyer B gets an empty list and a
  404 for buyer A's detail; anonymous list 401; stopping orders-service returns
  a controlled 503 while auth/catalog remain 200, and the list recovers after
  restart.
- **190/190 monolith tests** (1038 assertions) remain green; `tsc --noEmit` and
  `vite build` pass, and the Nginx image was rebuilt to serve the new page.
- **No schema change, no new service and no main-data change.**

### S10.1 deployment preparation evidence (AWS EC2, 2026-11-07)

- `docker compose -f docker-compose.yml -f docker-compose.prod.yml config -q`
  and the TLS overlay config validate with a temporary, non-secret env file.
- `deploy/aws/*.sh` pass `bash -n`; the Nginx HTTPS template passes `nginx -t`
  in the Compose network with a temporary self-signed certificate.
- Production overlay inspection: `app`/`scheduler` receive no
  `DB_ROOT_PASSWORD`; the Mercado Pago token is present only in `app`
  (rollback) and `payments-service`; auth/raffles/orders do not receive it.
- Critical regressions after the Nginx/bootstrap changes: 42/42 S6, 44/44 S7,
  39/39 payments, 123/123 orders integration, 12/12 auth integration,
  193/193 monolith, frontend typecheck/build.
- No AWS resource, DNS record, certificate, bucket or credential was created.
  The real Mercado Pago webhook, SMTP delivery and S3 upload were not tested.
- `docs/sdd/` and `AGENTS.md` are no longer ignored, but remain untracked and
  require a user-authorized commit/push before they can be recovered from
  GitHub.

## 10. Change Log

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-10-22 | Initial acceptance criteria |
| 1.1 | 2026-10-23 | Payment/reservation/refund clarifications |
| 1.2 | 2026-10-23 | Coherence review |
| 1.3 | 2026-11-05 | ADR-001: added AC-038 to AC-045 for independent lifecycle, degradation, service auth, data ownership and rollback |
| 1.4 | 2026-11-05 | S1 verification evidence recorded: 12/12 integration, 14/14 isolation, 59 regression tests |
| 1.5 | 2026-11-06 | S2 verification evidence recorded: admin boundary, CRUD/validation, number integrity, reservation regression, stop/start isolation; 56/56 integration and 64/64 E2E |
| 1.6 | 2026-11-06 | S3 verification evidence recorded: order contract, CSRF/ownership, real two-process double-sale prevention, expiry rules and advisory lock, internal signature, payment regression, fail-closed command and stop/start isolation; 97/97 integration, 33/33 E2E and 186/186 monolith tests |
| 1.7 | 2026-11-06 | S4 verification evidence recorded: checkout/resume, approved sale exactly once, UNKNOWN + expiry, late approval/refund/review, duplicate and simultaneous webhooks, reconciliation, fail-closed and stop/start recovery; 72/72 integration, 38/38 adapter, 9/9 real concurrency, 39/39 E2E and 190/190 monolith tests; AC-042/AC-044 updated; main-schema migration prerequisite documented |
| 1.8 | 2026-11-06 | S5 preparation evidence recorded: pending migrations/preconditions confirmed, backup restored with identical counts, additive DDL preview, per-service grant matrix validated in `rifacloud_test` (positive + negative, MySQL 8.4 locking-read finding), webhook config gap, residual dependency list. Main schema unchanged; migrations/grants pending authorization. |
| 1.9 | 2026-11-07 | S6 verification evidence recorded and AC-046/AC-047 added: session continuity across reload/return, 401 vs 503, 419 retry, route preservation, server-side return verification, expiry matrix and idempotency. 42/42 S6 E2E, 12/12 auth, 190/190 monolith, frontend typecheck/build. |
| 1.10 | 2026-11-07 | S5 application evidence recorded: fresh backup, both additive migrations applied separately, four least-privilege MySQL users and credentials applied, services recreated one at a time, auth/catalog/reservations/payments/scheduler/isolation verified, 190/190 monolith and 33/33 orders E2E; business data intact. Rollback retained; per-domain DBs/outbox deferred. |
| 1.11 | 2026-11-07 | S7 evidence recorded and AC-048 added: owner-scoped paginated order history, detail enrichment, status/number labeling (only SOLD is purchased), ownership 401/404/empty and 503 recovery. 123/123 orders integration, 44/44 E2E, 190/190 monolith, frontend build. |
| 1.12 | 2026-11-07 | S9.1 evidence recorded and AC-049 to AC-052 added: scheduled reconcile, scheduler health/clean shutdown, FR-014 results, admin orders/incidents, retryable notifications, registration throttle and per-service env isolation. 31/31 targeted admin/result, 12/12 reconcile/notify, 193/193 monolith (including 3 notification tests), all prior regressions green. Main migrations remain pending authorization. |
| 1.13 | 2026-11-07 | S9.1 activated on main with explicit authorization: migrations 000009/000010 applied separately and verified; `raffle_results` granted to `rifacloud_raffles`; scheduler healthy; reconcile dry-run 3 pending; notification queue/dispatch produced 2 incident rows SENT via log mailer (no real email); main business counts unchanged. |
| 1.14 | 2026-11-07 | S10.1 evidence recorded: production Compose and TLS template validated, scripts syntax-checked, secret separation inspected, critical regressions green. No AWS resource created; docs unignored but need commit/push. |
