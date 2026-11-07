# 07 - Resilience and Failure Handling

**Project:** RifaCloud  
**Status:** Approved (architecture evolution)  
**Version:** 1.5  
**Last Updated:** 2026-11-06  

## 1. Core Principles

- **Fail closed:** If DB unavailable, never simulate successful purchase
- **Graceful degradation:** Catalog must work if payment provider fails
- **Isolation:** Email failure must not cancel confirmed purchase
- **No auto-release on uncertainty:** Never release numbers if payment status uncertain
- **Idempotency:** All critical operations must be idempotent
- **Limited retries:** Timeouts and bounded retries for external services
- **Human intervention:** Uncertain cases go to admin review

## 2. Failure Scenarios

### 2.1 Payment Provider Down or Slow

| Aspect | Expected Behavior | Data Preserved | Verification |
|---|---|---|---|
| **Provider API unreachable** | Cannot create checkout. Show user-friendly error. Catalog remains accessible. | Existing reservation stays until expiry; no order marked PAID. | AC-027 |
| **Timeout on API call** | Use configured timeout (e.g., 10-30s). Don't hang. Return error to user. | Order stays PENDING_PAYMENT; reservation expires normally. | AC-033 |
| **Slow response** | Timeout enforced. User sees error, can retry later. | No payment confirmation; no partial SOLD state. | AC-033 |

### 2.2 Payment Result Unknown

| Aspect | Expected Behavior | Data Preserved | Verification |
|---|---|---|---|
| **Cannot determine status** | Order → `PAYMENT_REVIEW`, Payment → `UNKNOWN`. | Numbers remain RESERVED. Not sold. | AC-018 |
| **Webhook arrives later with result** | Process idempotently. Update if becomes APPROVED/REJECTED. | History tracked in webhook events. | AC-017 |
| **Admin must resolve** | Visible in admin dashboard. Manual resolution required. | Full audit trail. | AC-020 |

### 2.3 Payment Confirmed After Expiry / Already Sold

| Aspect | Expected Behavior | Data Preserved | Verification |
|---|---|---|---|
| **Numbers sold to another order** | Order → `REQUIRES_REFUND`, Payment → `REFUND_REQUIRED`. Admin incident created. Refund-incident email queued automatically to the buyer. | No double assignment. Email failure does not alter order/payment state. | AC-019, AC-037 |
| **Concurrency race** | DB constraints prevent duplicate. Transaction fails safely. | Data integrity maintained. | AC-011 |
| **Manual resolution** | Admin documents and processes refund outside platform. | Incident logged. | AC-020 |

### 2.4 Webhooks

| Aspect | Expected Behavior | Data Preserved | Verification |
|---|---|---|---|
| **Duplicate webhook** | Check `payment_webhook_events` by `provider_event_id`. Skip if processed. | No duplicate state changes. | AC-017 |
| **Out of order webhooks** | Process based on current state. Idempotent updates. | State machine enforced. | AC-017 |
| **Malformed webhook** | Validate signature. Reject if invalid. Log without secrets. | No state change. | Security check |
| **Webhook delayed** | System can still verify via API. Both paths update same records safely. | Reconciliation possible. | AC-014 |

### 2.5 Email/Notifications

| Aspect | Expected Behavior | Data Preserved | Verification |
|---|---|---|---|
| **SMTP unavailable** | Job fails, queued for retry. Purchase NOT cancelled. | Order state preserved: `PAID`/`SOLD` for a purchase, `REQUIRES_REFUND` for a refund incident. | AC-021 |
| **Max retries exceeded** | Mark notification FAILED. Log error (no PII). Admin can see failed notifications. | Purchase data intact. | AC-022 |
| **Queue worker down** | Emails accumulate in queue. Restart worker processes them. | No data loss. | Manual test |

### 2.6 Backend Unavailable

| Aspect | Expected Behavior | Data Preserved | Verification |
|---|---|---|---|
| **API down** | Users see error. No new purchases. Existing data safe. | DB unchanged. | Manual |
| **Container restart** | Services restart via docker-compose. Health checks. | Data in volumes intact. | AC-032 |

### 2.7 Database Unavailable

| Aspect | Expected Behavior | Data Preserved | Verification |
|---|---|---|---|
| **DB connection fails** | All DB operations fail. Return 500/error. **Never create fake order or mark paid.** | No writes attempted/simulated. | AC-028 |
| **Transaction fails mid-way** | Rollback. User sees error. Can retry. | Consistency maintained. | AC-028 |
| **DB restart** | App reconnects. Health check reports status. | Data persists in volume. | AC-032 |

### 2.8 Reservations Expiry

| Aspect | Expected Behavior | Data Preserved | Verification |
|---|---|---|---|
| **Normal expiry** | Scheduler command delegates to orders-service, which finds expired reservations and releases only if the order is PENDING_PAYMENT and no uncertain payment exists. | Numbers return to AVAILABLE. | AC-012 |
| **Uncertain payment exists** | **DO NOT RELEASE.** Keep reserved. Require admin review. | Prevents double-sell after confirmation. | Critical rule |
| **Order PAID but not yet processed** | Do not release. Numbers should be SOLD. | Integrity maintained. | Critical rule |
| **Two expiry runners overlap** | `GET_LOCK('rifacloud_orders_expire_reservations')` admits one run; the other returns `skipped=true` and changes nothing. | No double release, no partial state. | AC-012 |
| **orders-service down during expiry** | `orders:expire-reservations` exits non-zero and releases nothing locally; the next scheduled minute retries. | Reservations stay as they were. | AC-041 |

### 2.9 EC2 Instance Failure

| Aspect | Expected Behavior | Data Preserved | Verification |
|---|---|---|---|
| **Instance crash/reboot** | All containers restart (restart policy). MySQL data on volume persists. | Data intact if EBS/volume persists. | AC-032 |
| **Complete instance failure** | **No high availability.** Complete outage until instance restored. | Data depends on volume persistence. | Documented limitation |
| **Recovery** | Restore instance, start containers, verify health. | No automatic failover. | Documented |

> Phase note: container restart and named-volume persistence are validated locally (P1). EC2/EBS failure behavior is validated only at deployment (P3).

### 2.10 Microservices Partial Failures

Each business service has its own container and lifecycle. Nginx maps an
unavailable upstream to a controlled JSON 503. The owning service is the only
writer of its data; a failed call never triggers a direct write to another
domain.

| Failure | Expected behavior | Data preserved | Verification |
|---|---|---|---|
| **auth-service down** | Login/register/logout/me and `/sanctum/csrf-cookie` return controlled 503 JSON. Existing sessions already stored in MySQL remain valid for services that read the session table. Catalog remains available. | `users`, `sessions` unchanged | AC-039 |
| **raffles-service down** | Catalog, detail, number availability and `/api/admin/raffles*` return controlled 503 JSON. Auth, orders and payments continue. Admin writes fail closed with no partial raffle/number rows. | `raffles`, `raffle_numbers` unchanged | AC-040 |
| **orders-service down** | `POST /api/orders` and `GET /api/orders/{id}` return controlled 503 JSON. No partial order or partial reservation is created. `orders:expire-reservations` exits non-zero without releasing anything; catalog, auth and payments continue. | No partial order; numbers unchanged until expiry resumes | AC-041 |
| **payments-service down** | Checkout/verify/resolve return controlled 503 JSON; the webhook returns 503 so Mercado Pago retries. No order is marked paid, no number sold; `payments:reconcile` exits non-zero. Existing reservations expire normally unless the stored payment is uncertain. | `payments` unchanged; order/number state unchanged | AC-042 |
| **Internal auth/signature failure** | The receiving service rejects the call with 401/403 and makes no state change. Nginx never treats routing as authorization. | No state change | AC-043 |
| **Cross-domain write attempt** | Rejected by code boundary or DB grant; owning service must expose a command. | No cross-domain mutation | AC-044 |
| **Scheduler down** | Reservations do not expire automatically and retries pause; no data corruption. A manual command can trigger expiry/reconciliation. | Data intact | Manual |
| **Nginx down** | Complete public outage because it is the only entry point. Containers keep running; data intact. | Data intact | Documented limitation |

S1 verification (2026-11-05): stopping `auth-service` returned a controlled
JSON 503 for `/api/auth/health`, `/api/login`, `/api/me` and
`/sanctum/csrf-cookie`; the catalog stayed 200 and the monolith admin route
returned 401. The service became healthy again after restart (14/14 checks).

S2 verification (2026-11-06): stopping `raffles-service` returned a controlled
JSON 503 for the catalog **and** `/api/admin/raffles*`, while login, `/api/me`,
order detail and the webhook endpoint kept working; a reservation created before
the stop survived, and catalog + admin recovered after restart (64/64 E2E
checks).

S3 verification (2026-11-06): stopping only `orders-service` returned a
controlled JSON 503 for `/api/orders` and `/api/orders/{id}`, while `/api/me`,
the catalog and payment sub-routes/webhooks kept working; the service became
healthy and served orders again after restart (33/33 E2E checks). With the
service down, `orders:expire-reservations` exited non-zero and released no
numbers (fail closed). Two concurrent expiry runners are serialized by
`GET_LOCK`; the second returns `skipped=true`.

S4 verification (2026-11-06): stopping only `payments-service` returned a
controlled JSON 503 for checkout/verify (and for the webhook, so Mercado Pago
retries), while `/api/me`, the catalog and `/api/orders/{id}` kept working; the
service became healthy again and the order + payment survived the restart
(39/39 E2E checks). Two real PHP processes POSTing the same webhook
simultaneously produced one event row, one PAID order and one SOLD transition
(9/9 concurrency checks). The offline adapter suite (38 checks) covers the
official signature manifest, the authoritative re-fetch, the no-payer-data rule
and the provider error mapping. `payments:reconcile` fails closed (exit 1) when
the service is unavailable. Simulating orders-service being unreachable makes
verification return 503 `ORDER_SERVICE_UNAVAILABLE` and leaves both the payment
and the order unchanged (no partial sale).

S6 verification (2026-11-07): the React client distinguishes a real logout
(`401`) from an availability failure (`503`/timeout): the latter shows a
recoverable "session could not be checked" screen and never clears the user.
A `419` refreshes `/sanctum/csrf-cookie` and replays the request once; `409` and
`503` keep the order on screen. Returning from Mercado Pago triggers one
server-side verification, and a stopped auth-service returns 503 while the
DB-backed session survives its restart. The expired-reservation countdown polls
the order until the scheduler releases or keeps the numbers; releases are
idempotent and never touch `SOLD`/`UNKNOWN`/`PAYMENT_REVIEW`/`APPROVED`
reservations (42/42 S6 checks).

#### Recovery

| Scenario | Recovery |
|---|---|
| Service container stopped | `docker compose start <service>`; health check turns healthy; Nginx resumes routing |
| Service crash-looping | Inspect `docker compose logs <service>`, fix config/dependency, restart only that service |
| Upstream 503 | Confirm the owning service health and MySQL connectivity; do not bypass the service with a direct DB write |
| Session rejected after auth restart | Verify shared `APP_KEY`, `SESSION_DRIVER=database`, cookie name/domain and MySQL connectivity |
| Orders/payments degraded | Keep the catalog and auth online; do not mark orders paid or release uncertain numbers manually without evidence |

### 2.11 HTTP Status Fidelity Through the Gateway (S2 findings)

The extracted admin boundary must preserve Laravel/Sanctum status codes exactly;
two infrastructure behaviors had to be handled:

| Layer | Problem | Resolution |
|---|---|---|
| **Apache (raffles-service)** | Apache maps the non-standard 419 to `500` when PHP calls `http_response_code(419)` and Apache builds the status line. | Send an explicit status line `header('HTTP/1.1 419 Authentication Timeout', true, 419)` for 419 only; other statuses use `http_response_code()`. Verified with probes (removed afterwards). |
| **Nginx (gateway)** | With `proxy_intercept_errors on`, Nginx does not recognize 419 and rewrites an intercepted upstream 419 to `500`; `error_page 419` cannot match after that rewrite. | Use `proxy_intercept_errors off` on the admin location. Locally generated connect failures are still mapped by `error_page 502 503 504 =503 /api/_raffles_unavailable`, so a stopped service still yields the controlled JSON 503. |
| **Response bodies** | 204/304 responses must not carry a body. | `JsonResponse` suppresses the body for 204/304 (used by `DELETE /api/admin/raffles/{id}`). |

Regression guard: the E2E suite asserts `419` on a POST without a CSRF token and
`204` on DELETE, both through Nginx.

## 3. Monitoring & Logging

| Item | Requirement | Notes |
|---|---|---|
| **Health Check** | `/api/health` checks DB connectivity | Returns status, no secrets |
| **Logs** | Laravel logs to stdout/stderr | Container logs accessible |
| **Sensitive Data** | No passwords, tokens, card data in logs | Sanitize all logs |
| **PII** | Minimize PII in logs | Avoid logging full payment responses |
| **Errors** | Log errors with context but not secrets | For debugging |
| **Audit** | Keep webhook events, payments for audit | Reconciliation trail |

## 4. Recovery Procedures

| Scenario | Recovery Steps |
|---|---|
| **Failed emails** | Queue worker running, jobs retry automatically up to limit. Check failed jobs table. |
| **Stuck PAYMENT_REVIEW** | Admin reviews payment via provider dashboard, updates order/payment manually. (Requires P2 provider access.) |
| **REQUIRES_REFUND case** | Admin processes refund outside platform per provider procedure. Documents incident. |
| **Expired reservations stuck** | Scheduler runs every minute. Manual job if scheduler down. |
| **Webhook missed** | Can verify payment via provider API using external_id. Update order manually if needed. |

## 5. Testing Checklist

- [ ] Payment provider timeout simulation (simulated gateway `TIMEOUT` mode)
- [ ] Payment rejection path (`REJECTED` order, numbers released)
- [ ] Duplicate webhook delivery (local signed fixtures)
- [ ] Email failure via test transport (retry and `FAILED` state)
- [ ] Refund-incident email queued on `REQUIRES_REFUND`
- [ ] DB connection loss during checkout
- [ ] Concurrent purchase same number
- [ ] Reservation expiry
- [ ] Expiry with uncertain payment (must not release)
- [ ] Payment confirmed after numbers sold
- [ ] Container restart persistence
- [ ] Health check endpoint

## 6. Limitations Acknowledged

- **Single EC2:** No automatic failover, no load balancing, single point of failure
- **No Redis:** Using database queue - less performant but acceptable for MVP
- **Manual recovery:** Some cases require admin intervention
- **No automated refunds:** Must be done outside platform
- **Sandbox only:** Not tested with real money

## 7. Change Log

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-10-22 | Initial resilience design |
| 1.1 | 2026-10-23 | Reservation/payment failure paths |
| 1.2 | 2026-10-23 | Coherence review |
| 1.3 | 2026-11-05 | ADR-001: per-service partial-failure matrix, controlled 503, internal auth failure, cross-domain write rejection and recovery |
| 1.4 | 2026-11-05 | S1 auth-service failure isolation verified: JSON 503 for auth routes, catalog and monolith unaffected |
| 1.5 | 2026-11-06 | S2 raffles admin failure isolation verified; documented Apache 419→500 and Nginx `proxy_intercept_errors` findings and their resolutions; fixed the S1 note placement in the failure matrix |
| 1.6 | 2026-11-06 | S3 orders-service failure isolation verified: controlled 503 for order routes, catalog/auth/payments unaffected, expiry command fails closed with exit 1 and releases nothing, advisory lock serializes concurrent expiry runs |
| 1.7 | 2026-11-06 | S4 payments-service failure isolation verified: controlled 503 for checkout/verify and a retryable 503 for webhooks, catalog/auth/orders unaffected, order + payment survive restart, duplicate simultaneous webhooks are idempotent (one event, one sale), reconciliation fails closed, and an unreachable orders-service leaves payment/order unchanged |
| 1.8 | 2026-11-07 | S6 browser resilience verified: 401 vs 503 distinction with retry, one-shot 419 CSRF replay, session survives an auth-service restart, checkout return verifies server-side, 409/503 keep the order, expiry polling is idempotent and never releases protected numbers |
