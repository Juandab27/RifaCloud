# 04 - Implementation Plan (3 Weeks)

**Project:** RifaCloud  
**Status:** Approved (architecture evolution)  
**Version:** 1.5  
**Last Updated:** 2026-11-06  

## 1. Timeline Overview

| Week | Focus | Goal |
|---|---|---|
| **Week 1** | Foundation & Core Domain | Auth, roles, raffles CRUD, number generation, catalog |
| **Week 2** | Purchase Flow & Payments | Reservations, checkout, Mercado Pago integration, webhooks, emails |
| **Week 3** | Admin, Resilience, Deployment & Testing | Results, incidents, failure testing, Docker deployment to EC2 |

### 1.1 Phase Gates (blocking dependencies)

Work is organized so that local development never waits for external credentials or cloud access.

| Gate | Needed by | Open questions | If unresolved |
|---|---|---|---|
| **P1 - Local development** | Day 1 | None | — |
| **P2 - Payment integration testing** | Days 9-12 | Q-05, Q-06 | Keep simulated gateway; MP E2E validation slips |
| **P2 - Email integration testing** | Day 12 | Q-08 | Keep `log` mailer; email integration slips |
| **P3 - AWS deployment** | Day 19 | Q-03, Q-04, Q-10 to Q-13 | Demo on local Docker Compose |
| **P4 - Real publication** | Outside 3-week plan | Q-01, Q-02 | No public release |

Rules:
- No external credentials are required on Day 1.
- Payment and email integrations are developed against interfaces plus local simulators (`PaymentGateway` fake driver, `log` mailer).
- AWS, SSH, domain, DNS and TLS only block the P3 deployment tasks listed below.
- Legal questions only block real publication, not the university demo.
- Mercado Pago Checkout Pro sandbox remains the first and only provider attempted; no second provider is implemented until MP is confirmed non-viable.

## 2. Week 1: Foundation & Core Domain (Days 1-7)

### Phase 1.1: Project Setup (Days 1-2) - **CRITICAL**
| Task | Priority | Est. Hours | Dependencies | Deliverable |
|---|---|---|---|---|
| Initialize Laravel project (modular structure) | Must | 2-3 | None | Project scaffold |
| Setup Docker Compose (nginx, php, mysql) | Must | 3-4 | None | Working dev environment |
| Configure environment variables & secrets (placeholders only, no external credentials) | Must | 1-2 | Docker setup | `.env.example` with empty placeholders |
| Payment gateway interface definition (no driver yet) | Must | 1-2 | Laravel init | `PaymentGateway` contract |
| Local mailer (`log` driver) configuration (no SMTP) | Must | 1 | Laravel init | Emails verifiable locally |
| Setup database migrations structure | Must | 1-2 | Laravel init | Migration framework ready |

### Phase 1.2: Auth & Users (Days 2-3) - **CRITICAL**
| Task | Priority | Est. Hours | Dependencies | Deliverable |
|---|---|---|---|---|
| User model with roles (USER/ADMIN) | Must | 2-3 | Migrations | User entity |
| Registration & login (API) | Must | 3-4 | User model | Auth endpoints |
| Role-based middleware & policies | Must | 2-3 | Auth | Authorization system |
| Frontend auth (React) | Must | 3-4 | Auth API | Login/register forms |

### Phase 1.3: Raffles & Numbers (Days 4-7) - **CRITICAL**
| Task | Priority | Est. Hours | Dependencies | Deliverable |
|---|---|---|---|---|
| Raffle model & migrations | Must | 2-3 | DB setup | Raffle entity |
| Number generation from range | Must | 3-4 | Raffle model | Auto-generate numbers |
| Admin CRUD for raffles | Must | 4-5 | Auth/Roles | Admin raffle management |
| Public catalog & number listing | Must | 4-5 | Raffles/Numbers | User catalog view |
| Number availability display | Must | 2-3 | Catalog | Real-time availability |

**Week 1 Deliverables:** Working auth, role system, raffle management, public catalog

## 3. Week 2: Purchase Flow & Payments (Days 8-14)

### Phase 2.1: Reservations (Days 8-9) - **CRITICAL**
| Task | Priority | Est. Hours | Dependencies | Deliverable |
|---|---|---|---|---|
| Reservation logic on `raffle_numbers` + `orders` (10 min) | Must | 3-4 | Numbers/Raffles | Reservation system |
| Atomic reservation with locks | Must | 4-5 | Reservations | Concurrency-safe |
| Expiration scheduler job | Must | 2-3 | Scheduler setup | Auto-expiry |
| Frontend number selection | Must | 3-4 | API reservations | Selection UI |

### Phase 2.2: Orders & Payments (Days 10-11) - **CRITICAL**
| Task | Priority | Est. Hours | Dependencies | Deliverable |
|---|---|---|---|---|
| Order & OrderItem models | Must | 2-3 | Reservations | Order structure |
| Payment model & states | Must | 2-3 | Orders | Payment tracking |
| Simulated gateway driver (APPROVED/REJECTED/TIMEOUT/UNKNOWN) | Must | 2-3 | Payment model | Local purchase and error-path tests |
| Mercado Pago integration (sandbox) | Must | 5-6 | Payments, Q-05 | Checkout creation |
| Server-side payment verification | Must | 3-4 | MP integration | Payment validation |
| Provider contingency evaluation (only if MP non-viable) | Conditional | 0 in base plan | Documented MP failure | Decision record, no second provider implemented yet |

### Phase 2.3: Webhooks & Emails (Days 12-14) - **CRITICAL**
| Task | Priority | Est. Hours | Dependencies | Deliverable |
|---|---|---|---|---|
| Webhook endpoint & idempotency | Must | 4-5 | Payments | Webhook handler |
| Local webhook fixtures & duplicate-delivery tests | Must | 2-3 | Webhook endpoint, simulated gateway | Local webhook verification |
| Webhook processing in transactions | Must | 3-4 | Webhooks | Safe processing |
| Queue setup (database driver) | Must | 2-3 | Laravel queues | Queue configured |
| Email notifications (queue) | Must | 3-4 | Orders/Payments | Async emails |
| Test mail transport for failure/retry simulation (no SMTP) | Must | 1 | Queue | Local email failure injection without Mailtrap |
| Mailtrap integration (when Q-08 resolved) | Must | 1-2 | Email, Q-08 | Real SMTP validation (P2) |
| Order history for users | Must | 2-3 | Orders | User orders page |

**Week 2 Deliverables:** Complete purchase flow, payments, webhooks, emails

> P2 note: Mercado Pago, Mailtrap and webhook E2E validation depend on Q-05, Q-08 and Q-06. If they are not resolved by Day 12, the flow continues to be validated with the simulated gateway and the `log` mailer; only the real integration validation slips.

## 4. Week 3: Admin, Resilience, Deployment & Testing (Days 15-21)

### Phase 3.1: Admin Features & Results (Days 15-16) - **CRITICAL**
| Task | Priority | Est. Hours | Dependencies | Deliverable |
|---|---|---|---|---|
| Admin dashboard | Must | 3-4 | Admin routes | Admin panel |
| Order management & incidents | Must | 3-4 | Orders/Payments | Incident handling |
| Manual result publication | Must | 3-4 | Raffles | Result entry |
| Payment review workflow | Must | 2-3 | Payments | Review system |

### Phase 3.2: Resilience & Error Handling (Days 17-18) - **CRITICAL**
| Task | Priority | Est. Hours | Dependencies | Deliverable |
|---|---|---|---|---|
| Payment timeout & retry logic | Must | 2-3 | Payments | Limited retries |
| Uncertain payment handling | Must | 3-4 | Webhooks | PAYMENT_REVIEW flow |
| REQUIRES_REFUND flow + refund-incident email | Must | 3-4 | Payment review, queue | Refund case handling and notification |
| Email retry logic | Must | 2-3 | Queue | Retry mechanism |
| Health check endpoint | Must | 1-2 | App | `/api/health` |

### Phase 3.3: Deployment & Testing (Days 19-21) - **CRITICAL**
| Task | Priority | Est. Hours | Dependencies | Deliverable |
|---|---|---|---|---|
| Docker Compose finalization | Must | 2-3 | All services | Production-like compose |
| Instance sizing review (memory, measured load, budget) | Must | 1-2 | Q-11 | Documented instance profile |
| EC2 setup & deployment (P3: Q-03, Q-04, Q-10 to Q-13) | Must | 4-5 | Compose, AWS access | Deployed app |
| MySQL persistence verification (local container + EBS) | Must | 2-3 | DB | Persistence tested |
| Concurrency testing (double purchase) | Must | 2-3 | Reservations | Race condition tested |
| Failure scenario testing (local; EC2 failure only after P3) | Must | 3-4 | Resilience | Failure tests |
| Demo preparation & docs review | Must | 2-3 | All | Demo ready |

**Week 3 Deliverables:** Deployed app, tested resilience, demo ready

> P3 note: if AWS access, SSH, domain, DNS or TLS (Q-03, Q-04, Q-10 to Q-13) are not resolved by Day 19, the demo runs on local Docker Compose and the EC2 deployment task is rescheduled. Development and testing are not blocked by these questions.

## 5. Priority Classification

| Priority | Definition | Action |
|---|---|---|
| **Must** | Essential for MVP demo | Cannot be dropped |
| **Important** | Valuable but can be simplified | Drop only if critical delay |
| **Nice to have** | Not needed for MVP | Exclude from 3-week scope |
| **Conditional** | Only if a prior approach fails | Not scheduled; requires explicit decision |

### 5.1 MVP Cut List

Task estimates total ≈140 h across 21 days (≈6.7 h/day). If progress falls behind, degrade in this order:

| Order | Cut / simplify | Keep |
|---|---|---|
| 1 | Provider contingency evaluation (already Conditional) | Mercado Pago as only provider |
| 2 | Advanced filters/sorting beyond the basic status filter (order history and admin dashboard) | Basic lists and the status filter required by AC-026 |
| 3 | `evidence_url` in results | `evidence_description` and publication date |
| 4 | Extra container health-check tuning | `/api/health` endpoint and basic health checks |
| 5 | Visual polish, animations, responsive refinements | Functional, usable screens |

**Never cut:** server-side payment verification, webhook idempotency, locks and uniqueness constraints, `REJECTED`/`PAYMENT_REVIEW`/`REQUIRES_REFUND` handling, refund-incident email queuing, 10-minute reservation rule, no auto-release on uncertain payment, MySQL internal-only.

## 6. Critical Path

1. **DB Schema** → Blocks all entities
2. **Auth/Roles** → Blocks protected routes
3. **Reservations with locks** → Blocks purchase flow
4. **Payment integration** → Blocks checkout (real MP validation gated by P2)
5. **Webhooks** → Blocks confirmation (E2E gated by P2)
6. **Deployment** → Blocks public demo (local Compose demo is the fallback)

## 7. Risk Assessment

| Risk | Impact | Probability | Mitigation |
|---|---|---|---|
| Mercado Pago credentials delayed | High | Medium | Simulated gateway + unit/contract tests; real E2E validation slips, development does not |
| Second provider distraction | Medium | Low | Not implemented until MP is documented as non-viable |
| Concurrency bugs (double sell) | High | Medium | DB constraints + locks + thorough testing |
| Webhook idempotency issues | High | Medium | Track events, test duplicates |
| Webhook URL not publicly reachable (Q-06) | High | Medium | Decide tunnel vs post-deployment validation by Day 12 |
| AWS access / domain / TLS delayed | High | Medium | Local Docker Compose demo fallback; deployment rescheduled |
| EC2 deployment issues | High | Medium | Test locally with compose first |
| Time pressure on week 3 | High | High | Prioritize Must items, cut Important if needed |
| Email delivery issues in demo | Medium | Medium | Test with `log` mailer locally, Mailtrap at P2, never block purchase |

## 8. Testing Strategy

| Test Type | Focus | When | Phase |
|---|---|---|---|
| **Unit** | Business logic, validations | Throughout | P1 |
| **Integration** | API endpoints, DB operations | Weekly | P1 |
| **Payment/email integration** | Real provider and SMTP | When Q-05/Q-08 resolved | P2 |
| **Concurrency** | Simultaneous purchases | Week 3 | P1 |
| **Resilience** | Payment/email/DB failures | Week 3 | P1 |
| **E2E** | Complete purchase flow | Week 3 | P1 (simulated) / P2 (real) |
| **Deployment** | EC2 setup, persistence | Week 3 | P3 |

## 9. Definition of Done (MVP)

- [ ] User can register, login, browse raffles
- [ ] Admin can create raffle with number range
- [ ] User can select multiple numbers (reserved 10 min)
- [ ] Complete purchase flow validated locally with simulated gateway (P1)
- [ ] Payment via Mercado Pago sandbox works (**P2 gate: Q-05, Q-06**)
- [ ] Server validates payment, numbers assigned on success
- [ ] Webhooks processed idempotently
- [ ] Emails queued and sent or retried (local `log` mailer; Mailtrap **P2 gate: Q-08**)
- [ ] Refund-incident email queued automatically on `REQUIRES_REFUND` (delivery at P2)
- [ ] Double purchase prevented
- [ ] Admin can publish results
- [ ] App deployed on EC2 with Docker Compose (**P3 gate: Q-03, Q-04, Q-10 to Q-13**)
- [ ] MySQL persists across restarts
- [ ] Resilience scenarios tested (EC2 failure only after P3)
- [ ] SDD approved and documented

## 10. Microservices Migration Plan (post-MVP evolution)

This plan is additive to the original three-week MVP. It does not reopen the
completed purchase flow. Each stage must be independently deployable,
verifiable and reversible before the next one starts.

| Stage | Scope | Safe boundary | Required tests | Rollback |
|---|---|---|---|---|
| **S0 (done)** | `raffles-service` read-only catalog | `GET /api/raffles*` | Catalog contracts; stop/start; 503; auth/orders/payments unaffected | Route catalog back to monolith |
| **S1 (done)** | `auth-service` register/login/logout/me/sessions | Auth routes; shared `users`/`sessions` | Session recognized by monolith protected routes; 422/401 parity; stop/start 503; logout invalidates | Route auth routes back; existing sessions remain valid |
| **S2 (done)** | `raffles-service` admin CRUD + number generation | `/api/admin/raffles*` | ADMIN authorization; create/publish/close; number generation; reservation still works. 56/56 integration, 64/64 E2E | Route admin paths back; monolith routes/data retained |
| **S3 (done)** | `orders-service` numbers/reservations/orders/expiry | `POST /api/orders`, `GET /api/orders/{id}`, internal expiry command | Double-sell concurrency (two processes); expiry; no release on UNKNOWN/SOLD; catalog unaffected. 97/97 integration, 33/33 E2E | Route order paths back; delete the two Nginx order locations |
| **S4 (done)** | `payments-service` checkout/verify/webhooks/reconciliation | `/api/orders/{id}/payments*`, `/api/webhooks/mercadopago`, admin resolve | Duplicate webhook (two processes); UNKNOWN; late approval; refund; no fake paid order; fail closed. 72/72 integration, 38/38 adapter, 9/9 concurrency, 39/39 E2E | Route payment paths back; keep data in place |
| **S5 (closed minimal, 2026-11-07)** | Per-service MySQL users + two additive migrations; per-domain DBs/outbox deferred | Explicit authorization; preflight/backup | Migrations applied separately, 4 users validated, services recreated one at a time, auth/catalog/reservations/payments/scheduler/isolation verified. 190/190 monolith, 33/33 orders E2E | Revert Compose to shared user, `DROP USER`, `migrate:rollback` by path; rollback routes retained |
| **S6 (done)** | Session continuity, CSRF retry, checkout return and expiry polling | No schema change; frontend + auth-service | Login/reload, 401/409/419/503, expiry matrix, idempotency, frontend build. 42/42 E2E, 12/12 auth, 190/190 monolith | Revert the frontend changes; backend is backward compatible |
| **S7 (done)** | Purchase confirmation and "Mis compras": owner-scoped order history, numbers/status and detail dates | S3 orders service + S6 session | 123/123 orders integration (26 list checks), 44/44 E2E, ownership 401/404/empty, 190/190 monolith, frontend build | Remove the frontend page/route and the list handler |
| **S9.1 (done)** | Critical audit fixes: scheduled reconcile, scheduler health/clean shutdown, FR-014 results, admin orders/incidents, retryable notifications, registration throttle and per-service env isolation | S1-S7; migrations 000009/000010 applied to main with authorization | 31/31 admin/result E2E, 12/12 reconcile/notify, 6→429 registration limit, 193/193 monolith, all prior regressions green; main migration + grant verified; backup restored | Keep the monolith rollback route; AWS TLS/webhook/backups remain |
| **S10.1 (prepared)** | AWS EC2 deployment preparation: production Compose, env template, HTTPS strategy, DB bootstrap/grants, encrypted backups/restore, healthchecks and runbook | S9.1 complete; no AWS resources created | Compose/scripts/TLS syntax validated; 42/42 S6, 44/44 S7, 39/39 payments, 123/123 orders, 12/12 auth, 193/193 monolith | Provide domain/certs/Mercado Pago/SMTP/S3 and authorize AWS phase; commit docs |
| **S10.2 (done)** | Functional/UX polish: derived raffle states, reservation UI gated by the sale window, rejected/expired payment recovery, public result presentation, mobile checks and browser tests | S6/S8 components; no backend, schema or financial rule change | 16/16 state unit checks, 12/12 Playwright tests with intercepted API, 42/42 S6, 44/44 S7, 39/39 payments, 123/123 orders, 12/12 auth, 193/193 monolith, frontend build | Freeze features and continue with the authorized AWS deployment |
| **S10.3 (done)** | Minimal admin dashboard at `/admin/dashboard`: PUBLISHED raffles, PAID orders, SOLD numbers and pending payment incidents | Owner-service read aggregates; no schema or financial-rule change | 20/20 E2E on `rifacloud_test` (401/403/200, exact values, controlled 503 per service), 19/19 Playwright, 129/129 orders integration, 77/77 payments integration, 42/42 S6, 44/44 S7, 39/39 payments E2E, 12/12 auth, 193/193 monolith | Freeze code and start the authorized AWS deployment |

### S1 sequencing (auth-service)
1. Build a standalone service with the same `APP_NAME`, `APP_KEY`,
   `SESSION_DRIVER=database`, `SESSION_DOMAIN` and cookie name as the monolith.
2. Keep `users` and `sessions` in the shared MySQL database during the
   transition. Do not change password hashing or role values.
3. Route `/api/register`, `/api/login`, `/api/logout`, `/api/me` and
   `/sanctum/csrf-cookie` to the service through Nginx.
4. Verify: invalid login 422; valid session recognized by a monolith protected
   route; duplicate email 422; logout invalidates; auth service stopped returns
   controlled 503; existing sessions still work on the monolith.
5. If session compatibility fails, roll back the Nginx routes immediately. Do
   not modify order/payment code to force the split.

**Status (2026-11-05): implemented and verified.** `services/auth-service/`
runs independently; Nginx routes the auth paths and `/sanctum/csrf-cookie` to
it. The test suite proves 12/12 integration checks (including the monolith
accepting an auth-service session), 14/14 failure-isolation checks and 59
Laravel regression tests.

### S2 sequencing (raffles administration)

1. Port the existing admin raffle controller rules into `raffles-service`
   without changing envelopes, pagination, validation messages or transitions.
2. Validate admin identity at the service boundary: decrypt the shared
   `rifacloud-session` cookie with `APP_KEY`, verify MAC/prefix, look up the
   session, enforce expiry, require `ADMIN`, verify `password_hash_web`, and
   enforce CSRF (cookie/header/`_token`, 419 on mismatch). Never trust an
   identity header.
3. Generate `raffle_numbers` inside the same transaction as the raffle insert,
   chunked and bounded by `RAFFLE_MAX_NUMBERS`.
4. Keep reservations, orders and payments in the monolith. Do not move
   `POST /api/orders` or any payment logic into this service.
5. Route `/api/admin/raffles*` to the service through Nginx and retain the
   monolith admin routes as the rollback path.
6. Verify: anonymous 401; USER 403; CSRF 419; CRUD/publish/close; validation;
   number integrity; reservations still reserve and reject double-selling;
   webhooks/auth/orders work while the service is stopped; catalog recovers.

**Status (2026-11-06): implemented and verified.** `services/raffles-service/`
now owns admin CRUD/publish/close and number generation. Evidence: 56/56 admin
integration assertions, 64/64 E2E checks through Nginx (including stop/start
isolation and reservation regression), a green S0 catalog E2E run and PHP lint
for every service file.

### S3 sequencing (orders-service)

1. Port `OrderReservationService` into the standalone service without changing
   the reservation semantics: lock the `raffles` row, insert the order, lock the
   requested `raffle_numbers` in ascending order, update with
   `WHERE status='AVAILABLE'` and check affected rows, insert `order_items`, then
   commit. Bounded retries only for deadlock/lock-timeout/duplicate keys.
2. Validate the shared `rifacloud-session` cookie with `APP_KEY`, session expiry
   and `password_hash_web`; never trust an identity header. Enforce CSRF only for
   stateful requests and keep the 401/404/419/422/409 envelopes identical.
3. Keep `OrderResource` byte-compatible, including `simulated_payments`,
   `simulated` and the read-only `payments` projection.
4. Move reservation expiry into the service. Expose only
   `POST /internal/orders/expire-reservations`, signed with a key derived from
   `APP_KEY` and serialized with `GET_LOCK`; keep the monolith command as a
   delegating client that fails closed. Never run expiry in two owners.
5. Route only `POST /api/orders` and `GET /api/orders/{id}` through Nginx. Leave
   payment sub-routes, checkout, verification and webhooks in the monolith, and
   return 404 for `/internal/` at the gateway.
6. Verify: anonymous 401; another user's order 404; CSRF 419; validation 422;
   conflict 409 with `unavailable_numbers`; two parallel processes yield one
   201 + one 409 and a single order; expiry releases only `PENDING_PAYMENT`
   without `UNKNOWN`/`APPROVED`/`REFUND_REQUIRED` payments or `SOLD` numbers;
   catalog/auth/payments keep working while the service is stopped.

**Status (2026-11-06): implemented and verified.** `services/orders-service/`
owns order creation, order reads, atomic reservation and expiry. Evidence: 97/97
integration checks (including a real two-process double-sale test and the
advisory-lock check), 33/33 E2E checks through Nginx (including stop/start
isolation and payment regression) and 186/186 monolith tests.

### S4 sequencing (payments-service)

1. Port the provider adapter and the checkout/verify/review/webhook endpoints
   without changing the React JSON contract, status codes or `code` values.
2. Keep `payments` and `payment_webhook_events` writes in the service. Remove
   every direct write to `orders`, `order_items` and `raffle_numbers` from the
   payment path; send the authoritative provider result to orders-service as a
   signed, idempotent command.
3. Make the command state-based idempotent: equal statuses are no-ops, numbers
   move once, `paid_at` is set once, stale webhook events are ignored, and the
   payment transition table is monotonic.
4. Serialize duplicate notifications with `UNIQUE(provider, provider_event_id)`
   plus a short per-event lock; mark failures `FAILED` and return 503 so the
   provider retries.
5. Route the four payment entry points through Nginx, keep `/internal/`
   unreachable, and return a controlled 503 while the service is down.
6. Delegate `payments:reconcile`/`--approved`/`--dry-run` to the signed internal
   endpoint and fail closed when it is unavailable.
7. Verify: checkout/resume contract; approved sale exactly once; UNKNOWN + expiry
   keeps numbers; late approval/refund/manual review; duplicate and simultaneous
   webhooks; reconciliation; stop/start with the rest of the stack operating.

**Status (2026-11-06): implemented and verified.** `services/payments-service/`
owns payments, Mercado Pago checkout/verification, webhooks and reconciliation.
`orders-service` remains the only writer of order/number state. Evidence: 72/72
integration checks against `rifacloud_test` with a real test orders-service,
38/38 Mercado Pago adapter checks, 9/9 real two-process webhook concurrency
checks, 39/39 E2E checks through Nginx (including stop/start and recovery) and
190/190 monolith tests. The main `rifacloud` schema still has two pending
additive migrations; applying them is the documented S4 deployment prerequisite
(out of scope for the implementation task) and S5 implementation must not close
until they are applied and verified.

### S5 sequencing (minimal, safe)

1. **Preflight (read-only):** `scripts/s5-preflight.ps1` checks pending
   migrations, prints the exact DDL with `migrate --pretend`, verifies there are
   no duplicate/NULL `payments.order_id`, checks the webhook table and reports
   the webhook secret/URL presence without printing values.
2. **Backup:** dump `rifacloud` with `--single-transaction` and prove it is
   restorable in a temporary schema before touching the main schema.
3. **Migrations (with explicit authorization only):** run the unique index
   migration first (fails safely if duplicates exist), verify, then run the
   additive `payment_webhook_events` migration. Verify `migrate:status` and the
   resulting `SHOW CREATE TABLE`.
4. **Per-service users:** apply `scripts/mysql-s5-grants.sql` with real secrets,
   then switch Compose env vars (`AUTH_DB_*`, `RAFFLES_DB_*`, `ORDERS_DB_*`,
   `PAYMENTS_DB_*`) and recreate only the affected services.
5. **Ownership enforcement:** run the negative checks (orders cannot write
   `payments`, payments cannot write `orders`, auth cannot read `payments`,
   raffles cannot read `orders`) and the positive locking-read checks.
6. **Monolith cleanup:** after the demo and rollback window, remove the
   duplicated routes/controllers/services listed in section 10 status notes and
   revoke the shared `rifacloud` grants. Never before the rollback path is
   accepted.

**Status (2026-11-07): S5 minimal closed.** With explicit authorization: a fresh
backup was taken; the unique `payments.order_id` index and the additive
`payment_webhook_events` table were applied **separately** and verified; four
least-privilege MySQL users were created and validated (connectivity, locking
reads, own writes and denied cross-domain writes); the services were recreated
one at a time with their own credentials; auth (register/logout/login/`/me`),
catalog, reservations/expiry (33/33 orders E2E), payments routing, the scheduler
and failure isolation were verified; 190/190 monolith tests pass. The monolith
keeps the shared `rifacloud` user and the rollback routes. Remaining for the
full data separation (deferred, not S7 yet): per-domain databases, outbox/
compensation and removal of the rollback path. Webhook secret/URL still pending.

### S5 prerequisites (do not skip)

- S4 stable in the deployed environment: apply and verify the two pending
  additive migrations (`payments.order_id` unique index and
  `payment_webhook_events`) on the target schema.
- Remove the monolith rollback write paths (or gate them behind an explicit
  feature flag) so no code path can double-write another domain.
- Define per-service MySQL users/grants and the `raffles` projection/version
  contract before splitting databases.
- Outbox/compensation plan for the payment-result saga.

### Residual monolith dependencies (S5 cleanup candidates)

Reviewed 2026-11-06. Nothing here is removed yet: every item is the documented
rollback path and the demo depends on it.

| Component | Still referenced by | Safe to remove when |
|---|---|---|
| `src/routes/api/{auth,raffles,orders,webhooks}.php` and the admin raffle/payment routes | `src/routes/api.php` | Nginx rollback window closed and demo accepted |
| Auth/Raffle/Order/Payment controllers, `OrderResource` | Rollback routes | same |
| `PaymentService`, `OrderReservationService`, `ReservationExpiryService` | Rollback controllers + regression tests | rollback routes removed |
| Payment gateway contract, MercadoPago/Fake adapters, DTOs and exceptions | `PaymentService` + tests | rollback routes removed |
| `AppServiceProvider` payment binding and fake-in-production guard | `PaymentService` | rollback routes removed |
| Payment/order feature tests | CI regression | replaced by the service suites (already green) |
| `config/mercadopago.php`, `rifacloud.payment_gateway`/`payment_fake_outcome` | Rollback payment code | rollback routes removed |

Models, migrations and the shared session contract stay: they are schema history
and the S5 data-separation input, not residual runtime paths. The scheduler has
no residual write path: `orders:expire-reservations` and `payments:reconcile`
only call the owning service.

### S6 sequencing (stabilization)

1. Revalidate the session with `/api/me` on every load. Treat `401` as
   anonymous; treat `503`/timeout/network as unavailable and offer a retry
   instead of redirecting to login. Never store credentials in `localStorage`.
2. On `419`, refresh `/sanctum/csrf-cookie` and replay the request once. Never
   retry `409`/`503` blindly and never clear the session for them.
3. Preserve the intended route through `/login` (safe internal paths only) so a
   reload or an expired session returns to the order page.
4. On `?pago=retorno`, perform one server-side `POST /payments/{id}/verify`; the
   URL never approves the order. Show the real state and keep the order visible
   on `409`/`503`.
5. Use the backend `expires_at`; after the countdown reaches zero, poll the
   order until the scheduler releases or keeps the numbers.
6. Make `RESERVATION_MINUTES` in `src/.env` the single source: fallbacks in
   `raffles-service`/`orders-service`, no Compose-specific override.
7. Force JSON negotiation on the auth-service API group so an anonymous
   non-JSON `/api/*` request returns `401`, not a 500 from a missing login route.

**Status (2026-11-07): implemented and verified.** `scripts/verify-s6-session-and-return.ps1`
passes 42/42 checks through Nginx on `rifacloud_test` (cookie attributes,
register, two reloads, shared 1-minute duration, return verification, repeated
409, 419, 401, 503 and the full expiry matrix), the auth-service integration
passes 12/12, the monolith suite 190/190 and the SPA builds with
`tsc --noEmit` + `vite build`. No schema or main-data change.

### S7 sequencing (purchase confirmation and "Mis compras")

1. Add `GET /api/orders` to `orders-service` only: owner-scoped query, newest
   first, `page`/`per_page` (default 10, max 50), `{data, meta}`, reusing the
   order presenter and including raffle/numbers/payments.
2. Keep `GET /api/orders/{id}` backward compatible; enrich the frontend detail
   with created/paid dates, internal id and per-number status labels.
3. Add the "Mis compras" page (`/orders`) with loading/error/empty states,
   status pills, numbers with status labels and a "Cargar más" action; link it
   from the navigation and the detail page.
4. Never approve from the URL and never label a number as purchased unless its
   status is `SOLD`: reserved/released numbers show `RESERVADO`/`DISPONIBLE`,
   paid numbers show `VENDIDO`.
5. Reuse S6 session handling (`RequireAuth`, `/api/me`, 401 vs 503).
6. Verify the list contract, pagination, owner isolation (empty list/404),
   paid/pending/expired/review statuses, 401/503 and the detail.

**Status (2026-11-07): implemented and verified.** `GET /api/orders` is live in
`orders-service`; "Mis compras" and the enriched detail are served by the
rebuilt Nginx image. Evidence: 123/123 orders-service integration checks
(including 26 list/ownership/pagination checks), 44/44 S7 E2E through Nginx on
`rifacloud_test` (paid/pending/expired/review, pagination, ownership, 401/503,
stop/start), 190/190 monolith tests and `tsc --noEmit` + `vite build`. No new
service, no schema change and no main-data change.

## 11. Change Log

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-10-22 | Initial 3-week plan |
| 1.1 | 2026-10-23 | Phase gates and external dependencies |
| 1.2 | 2026-10-23 | Coherence review |
| 1.3 | 2026-11-05 | ADR-001: added S0-S5 microservices migration plan, sequencing, prerequisites and rollback |
| 1.4 | 2026-11-05 | S1 marked done: auth-service implemented, routed and verified; S2 is next |
| 1.5 | 2026-11-06 | S2 marked done: raffle admin CRUD/publish/close and number generation moved to `raffles-service`; admin session/CSRF boundary; 56/56 integration and 64/64 E2E; S3 is next |
| 1.6 | 2026-11-06 | S3 marked done: orders, reservations, atomic allocation and expiry moved to `orders-service`; session/CSRF boundary; signed internal expiry endpoint with advisory lock; 97/97 integration, 33/33 E2E and 186/186 monolith tests; S4 is next |
| 1.7 | 2026-11-06 | S4 marked done: payments, Mercado Pago, webhooks and reconciliation moved to `payments-service`; order/number transitions only through the signed payment-result command; 72/72 integration, 38/38 adapter, 9/9 real webhook concurrency, 39/39 E2E and 190/190 monolith tests; S5 blocked on the two pending additive migrations in the main dev schema |
| 1.8 | 2026-11-06 | S5 preparation recorded: read-only preflight (`scripts/s5-preflight.ps1`), backup restored with identical counts, `migrate --pretend` DDL only additive, per-service grant matrix validated in `rifacloud_test` (including MySQL 8.4 locking-read requirement and negative cross-domain tests), residual monolith dependency list. Migrations/grants NOT applied to the main schema; webhook secret/URL still missing. |
| 1.9 | 2026-11-07 | S6 marked done: session revalidation (401 vs 503), one-shot CSRF 419 replay, login route preservation, server-side checkout-return verification, expiry polling and a single `RESERVATION_MINUTES` source. Auth-service forced JSON on `api/*` (401 instead of 500). 42/42 S6 E2E, 12/12 auth, 190/190 monolith, frontend build. |
| 1.10 | 2026-11-07 | S5 minimal closed with authorization: fresh backup, two additive migrations applied separately and verified, four least-privilege MySQL users validated, services switched one at a time, auth/catalog/reservations/payments/scheduler/isolation verified, 190/190 monolith and 33/33 orders E2E. Monolith shared user and rollback retained; per-domain DBs/outbox deferred; webhook secret/URL still pending. |
| 1.11 | 2026-11-07 | S7 marked done: owner-scoped `GET /api/orders` history in `orders-service`, "Mis compras" page, detail enrichment (raffle, id/number, real status, created/paid dates, number status labels) and strict non-purchased labels. 123/123 orders integration, 44/44 E2E, 190/190 monolith, frontend build. |
| 1.12 | 2026-11-07 | S9.1 critical corrections implemented: reconcile scheduled with lock, scheduler heartbeat/healthcheck/clean stop, FR-014 `raffle_results` and public result, admin orders/incidents endpoints and page, retryable notification queue/worker, registration throttling, per-service env isolation. Test schema verified; main migrations 000009/000010 pending explicit authorization. |
| 1.13 | 2026-11-07 | S9.1 activated on main: migrations 000009/000010 applied separately, `raffle_results` granted to `rifacloud_raffles`, scheduler healthy, reconcile dry-run 3 pending, notifications queue/dispatch 2 incident rows SENT via log mailer. Main business data unchanged. |
| 1.14 | 2026-11-07 | S10.1 deployment preparation: production Compose overlay, secret-free env template, ALB/ACM and Nginx TLS strategies, DB bootstrap with encrypted pre-migration backup and production grants, encrypted S3 backup/restore scripts, healthchecks and AWS runbook. Local syntax/Compose validations and critical suites passed; no AWS resource created. Docs unignored but still need a commit. |
| 1.15 | 2026-11-07 | S10.2 marked done: derived raffle states (upcoming/available/closed/finished) from backend status and sale window, reservation UI disabled outside the window without removing server checks, rejected/expired payment recovery back to the raffle, and result card with winner/date/evidence. Added 16 pure state checks and 12 Playwright browser tests with intercepted API; 42/42 S6, 44/44 S7, 39/39 payments, 123/123 orders, 12/12 auth and 193/193 monolith remain green. No schema or main data change. |
| 1.16 | 2026-11-07 | S10.3 marked done: minimal admin dashboard with four read-only aggregates sourced from their owner services, ADMIN authorization at every endpoint, controlled JSON 503 and partial-availability UI. 20/20 dashboard E2E on `rifacloud_test`, 19/19 Playwright, 129/129 orders integration, 77/77 payments integration and all critical regressions green. No migration, AWS resource, secret or main-data change. |
