# AGENTS.md - RifaCloud Project Instructions

**Project:** RifaCloud  
**Purpose:** Instructions for OpenCode agents working on this project  
**Last Updated:** 2026-11-12  

## Core Principles

1. **Specification-Driven Development**: Always consult `docs/sdd/` before starting any task
2. **No Unauthorized Changes**: Do not change approved requirements, architecture, or decisions without explicit authorization
3. **No Invention**: Do not invent business rules, legal requirements, or features
4. **Scope Discipline**: Only execute tasks that have been explicitly approved
5. **Documentation First**: Update documentation when requirements change with approval

## Mandatory Workflow

### Before Starting Any Task
- [ ] Read relevant SDD documents in `docs/sdd/`
- [ ] Check `docs/sdd/06-open-questions.md` for blockers **of the current phase only** (P1 local, P2 external integration, P3 deployment, P4 publication)
- [ ] Confirm external credentials are not required for P1 tasks
- [ ] Verify task is approved and within scope
- [ ] Understand requirements and acceptance criteria
- [ ] Identify files that will be modified

### During Implementation
- [ ] Follow approved architecture from `02-technical-design.md`
- [ ] Respect data model from `03-data-model.md`
- [ ] Implement according to acceptance criteria in `05-acceptance-criteria.md`
- [ ] Handle failures per `07-resilience-and-failure-handling.md`
- [ ] Maintain traceability to FR/NFR/AC IDs
- [ ] Keep changes minimal and focused on assigned task

### After Completing Any Task
- [ ] Report files modified
- [ ] List verifications executed
- [ ] Note any pending decisions or blockers
- [ ] Update tests if functionality implemented
- [ ] Ensure no secrets exposed
- [ ] Verify no scope creep

## Constraints

### Never Do
- ❌ Implement features not in approved SDD
- ❌ Change architecture without authorization
- ❌ Invent business rules or legal requirements
- ❌ Expose secrets, credentials, or API keys
- ❌ Create/modify files outside task scope
- ❌ Run `migrate` on the main `rifacloud` schema without explicit authorization
- ❌ Create MySQL users/grants on the main server without authorization
- ❌ Write directly to another service's owned tables (use its contract/command)
- ❌ Deploy Kubernetes or multi-instance HA (outside the approved scope)
- ❌ Declare a service complete without independent start/stop/health tests
- ❌ Trust client-supplied identity headers for service-to-service authorization
- ❌ Skip server-side validation for payments
- ❌ Trust browser redirects for payment confirmation
- ❌ Auto-release numbers when payment is uncertain
- ❌ Allow purchases without authentication
- ❌ Expose MySQL publicly
- ❌ Implement a second payment provider before Mercado Pago is confirmed non-viable

### Always Do
- ✅ Consult SDD before changes
- ✅ Use server-side payment verification
- ✅ Keep each service on its own least-privilege MySQL user; never reuse the shared `rifacloud` user for new service code
- ✅ Scope order/purchase queries to the authenticated owner; never accept a user id from the client for history or detail
- ✅ Recover the session with `/api/me` on load and treat 503 as retryable, not as logout
- ✅ Keep the intended route through login and never store credentials in `localStorage`
- ✅ Implement idempotency for webhooks
- ✅ Use transactions and locks for concurrency
- ✅ Queue emails asynchronously
- ✅ Validate admin actions on backend
- ✅ Store monetary values in COP (integer pesos)
- ✅ Follow 10-minute reservation rule
- ✅ Mark uncertain payments for review, don't auto-release
- ✅ Keep catalog working if payment provider fails
- ✅ Respect per-domain data ownership from ADR-001
- ✅ Give every extracted service its own container, health check and contract tests
- ✅ Return controlled JSON 503 when an upstream service is unavailable
- ✅ Make every service-to-service command idempotent and authenticated

## Project Context

- **Type:** University Cloud Computing project
- **Timeline:** 3 weeks
- **Deployment:** Single AWS EC2 with Docker Compose
- **Stack:** React + TypeScript, Nginx, PHP/Laravel components, MySQL
- **Architecture target:** four business microservices (`auth`, `raffles`, `orders`, `payments`) behind Nginx, extracted incrementally from the modular monolith (ADR-001)
- **Current state:** monolith + `auth-service` (S1) + `raffles-service` catalog (S0) and administration (S2) + `orders-service` orders, reservations and expiry (S3) + `payments-service` payments, Mercado Pago and webhooks (S4) + S6 browser continuity + S7 purchase history ("Mis compras") + S9.1 critical audit fixes (scheduled reconcile, scheduler supervision, FR-014 results, admin orders/incidents, retryable notifications, registration throttle) + S10.1 AWS deployment preparation + S10.2 functional/UX polish (derived raffle states, payment recovery, result presentation, browser tests) + S10.3 admin dashboard (published raffles, paid orders, sold numbers, pending incidents) + S11 privacy/transparency (public privacy/terms/regulatory pages, contextual checkout clarification, footer legal links, versioned informed consent in `user_consents`); S5 minimal is closed (two additive migrations and four per-service MySQL users applied and verified). The S11 additive migration and the `user_consents` grant for `auth-service` were applied to `rifacloud` on 2026-11-12 with explicit authorization, and a controlled registration/cleanup verified the three consent rows. The legal drafts in `docs/legal/` are not legal advice and do not authorize real raffles
- **Payments:** Mercado Pago Checkout Pro (sandbox), primary and only provider attempted; owned by `payments-service` since S4
- **S5 minimal applied:** both additive migrations are applied; each service uses its own MySQL user, and the monolith keeps the shared user plus the rollback routes. Pending for the full data separation: webhook secret/public URL, per-domain databases, outbox/compensation and removal of the rollback path. S9.1 added `raffle_results` and `notifications`: both migrations and the `raffle_results` grant are applied and verified on `rifacloud_test` and `rifacloud`; notification delivery currently uses the log mailer. S10.1 added the AWS EC2 deployment overlay, environment template, database bootstrap/backup/restore scripts and runbook under `deploy/aws/`; no AWS resource was created
- **No HA:** Single instance only

## Microservices Evolution Rules (ADR-001)

The approved target is four business services behind Nginx:

| Service | Owns | Must not own |
|---|---|---|
| `auth-service` | Users, sessions, roles, authorization | Raffles, numbers, orders, payments |
| `raffles-service` | Catalog, raffle administration, number generation | Reservation transactions, payments |
| `orders-service` | Numbers, reservations, orders, expiry, atomic allocation | Provider integration |
| `payments-service` | Mercado Pago, checkout, verify, webhooks, reconciliation | Order creation, number allocation |

The `scheduler` is a scheduled-task process, not a fifth business service.

Implementation order: S0 read-only catalog (done) → S1 auth (done) → S2 raffles
administration (done) → S3 orders/reservations/expiry (done) → S4
payments/Mercado Pago/webhooks (done) → S5 data separation (minimal closed;
per-domain DBs/outbox deferred) → S6 browser continuity (done) → S7 purchase
confirmation and history (done). Read the full plan in
`docs/sdd/02-technical-design.md` section 8 and
`docs/sdd/04-implementation-plan.md` section 10. Do not start S8 or remove the
rollback path until the demo is accepted, never introduce a distributed
transaction, and keep each service on its own MySQL user.

## Decision Authority

- **Approved decisions** in `01-product-requirements.md` section 7 are final
- **Open questions** in `06-open-questions.md` require user input
- **Architecture changes** require explicit authorization
- **New features** require adding to SDD first with approval

## Traceability Requirements

Every implementation must trace to:
- Functional Requirements (FR-XXX)
- Non-Functional Requirements (NFR-XXX)
- Acceptance Criteria (AC-XXX)

## Security Rules

- Secrets in environment variables only
- No secrets in code, logs, or commits
- MySQL internal network only
- Backend authorization for all admin actions
- Server-side validation for all critical operations
- Webhook signature validation
- Sanitize data before logging

## Resilience Rules

- Fail closed on DB unavailability
- Graceful degradation for external services
- Limited retries with timeouts
- Email failure isolated from purchase success
- Uncertain payments require human review
- Never duplicate sales (DB constraints + locks)

## Communication

When reporting task completion, include:
1. **Files modified** - List with paths
2. **Verifications executed** - Tests/checks performed
3. **Decisions pending** - Items needing clarification/approval
4. **Traceability** - FR/NFR/AC references
5. **Risks/issues** - Any concerns found

## Getting Started

If you're a new agent session:
1. Read `docs/sdd/README.md` for overview
2. Read `docs/sdd/01-product-requirements.md` for requirements
3. Read `docs/sdd/02-technical-design.md` for architecture
4. Check `docs/sdd/06-open-questions.md` for blockers
5. Only proceed if explicitly told to implement an approved task
