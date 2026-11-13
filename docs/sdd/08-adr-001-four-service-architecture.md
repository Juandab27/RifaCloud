# ADR-001 - Four Business Microservices Behind Nginx

**Status:** Accepted for controlled evolution  
**Date:** 2026-11-05  
**Supersedes:** the "modular monolith is the final architecture / microservices
out of scope" part of the v1.2 technical design  
**Preserves:** all other approved v1.2 decisions

## 1. Context

RifaCloud started as a Laravel modular monolith with React, Nginx, MySQL and a
scheduler. The v1.2 SDD explicitly excluded microservices and Kubernetes and
declared the monolith as the final architecture. That decision was appropriate
for the original three-week MVP.

The project now needs an architecture that:

- separates authentication, raffle catalog, order/reservation and payment
  responsibilities;
- allows each business capability to be started, stopped and verified
  independently;
- keeps the existing React contracts and the Mercado Pago flow working;
- protects the atomic reservation and payment idempotency invariants;
- can be executed incrementally without a risky big-bang rewrite.

## 2. Decision

Adopt **four business microservices** behind a single Nginx entry point:

1. `auth-service` - users, authentication, sessions, roles, authorization.
2. `raffles-service` - catalog and raffle administration; it does not own
   reservation transactions.
3. `orders-service` - numbers, reservations, orders, expiry and atomic
   availability control.
4. `payments-service` - Mercado Pago, checkout, verification, webhooks,
   reconciliation and idempotency.

The `scheduler` remains a scheduled-task process, not a fifth business service.

MySQL may remain a single instance initially. Data ownership is defined per
domain and enforced by code boundaries, read-only sessions and, in the target
state, least-privilege grants and per-domain databases/schemas.

## 3. Why this order

| Stage | Service | Rationale |
|---|---|---|
| S1 | `auth-service` | Smallest transactional surface; sessions already live in the shared MySQL database; orders/payments semantics do not need to change |
| S2 | `raffles-service` administration | Read model already extracted; admin writes only raffles and number generation, which orders can still read/lock in the shared schema |
| S3 | `orders-service` | Contains the atomic reservation and double-sell invariants; requires careful transaction and schedule-projection design |
| S4 | `payments-service` | Depends on order state commands and contains provider idempotency, webhook and refund semantics |
| S5 | Data separation | Per-service grants, then per-domain databases and outbox/saga; only after S1-S4 are stable |

## 4. Constraints

- Nginx is the only public entry point.
- MySQL is never published.
- Services do not trust client-supplied identity headers.
- Service-to-service calls are authenticated and bounded by timeouts/retries.
- No distributed transaction is introduced for reservations; the atomic
  allocation stays inside `orders-service`.
- Payment confirmation remains server-side and idempotent.
- Each extracted service must start, stop and be tested independently before it
  is declared complete.
- Rollback is routing the affected paths back to the monolith without a data
  migration.

## 5. Consequences

### Positive

- Independent lifecycle and failure isolation per business capability.
- Clear ownership boundaries and smaller change surface.
- Catalog can degrade without stopping purchases, and authentication can
  degrade without stopping the catalog.
- Migration can be verified incrementally and rolled back per route.

### Negative / risks

- Shared schema creates coupling and cross-domain foreign keys during the
  transition.
- Session compatibility requires sharing `APP_KEY` and the `sessions` table
  until a token-based redesign is approved.
- Orders/payments extraction needs idempotent commands and compensation.
- More containers and health checks increase operational overhead on a single
  EC2 instance.
- A single EC2 instance still has no high availability.

## 6. Alternatives considered

| Alternative | Decision |
|---|---|
| Keep the monolith permanently | Rejected for the approved evolution; remains the rollback baseline |
| Big-bang rewrite into four services | Rejected: high risk to payment/reservation invariants |
| Extract orders/payments first | Rejected: highest transactional risk and cross-service coupling |
| Kubernetes / multi-instance HA | Out of scope; single EC2 remains the deployment target |
| Separate databases immediately | Rejected for the first increments; would break atomic reservation without a redesign |

## 7. Compliance with existing decisions

This ADR changes only the architecture target. It does **not** change:

- Mercado Pago Checkout Pro as the primary and only provider attempted.
- Server-side payment verification.
- Webhook idempotency.
- The 10-minute reservation rule.
- No auto-release on uncertain payment.
- Manual result publication.
- Monetary values in COP (integers).
- MySQL internal-only.
- Fail-closed behavior when the database is unavailable.

## 8. Review triggers

Revisit this ADR if any of the following occurs:

- A token-based auth redesign is approved.
- Orders/payments require a database split.
- The project moves beyond a single EC2 instance.
- Kubernetes or managed orchestration enters scope.
- A service cannot preserve an existing contract or invariant.
