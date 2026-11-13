# 01 - Product Requirements Document (PRD)

**Project:** RifaCloud  
**Status:** Approved (architecture evolution)  
**Version:** 1.7  
**Last Updated:** 2026-11-12  

## 1. Objective

RifaCloud is a web platform for managing raffles. It must allow users to consult available raffles, select numbers and make purchases. It must also have administrative functionalities to create and manage raffles and publish results.

The project is developed as a university assignment for Cloud Computing and must have a functional MVP that is demonstrable and deployable on AWS in approximately three weeks.

## 2. Actors

| Actor | Description | Responsibilities |
|---|---|---|
| **User (USER)** | Registered customer | Register, log in, browse raffles, select numbers, make payments, view their orders, receive notifications |
| **Administrator (ADMIN)** | Platform administrator | Create/configure raffles, define number ranges and pricing, publish/close raffles, manage payment incidents, manually publish results with evidence |

## 3. Scope

### In Scope (MVP)
- User registration and authentication with role-based access
- Raffle catalog and number availability
- Multi-number selection in a single order
- Payment via real provider in sandbox mode (Mercado Pago Checkout Pro, primary and only provider attempted in the MVP; local development uses a simulated gateway in P1 and the real sandbox integration is validated in P2)
- Server-side payment validation
- Idempotent webhook processing
- 10-minute number reservation during checkout
- Order and purchase history for users
- Administrative panel for raffle management
- Manual result publication by admin
- Email notifications via queue (decoupled from purchase flow)
- Resilient error handling and failure management
- Docker Compose deployment on single EC2 instance
- Incremental evolution from the modular monolith to four business
  microservices (`auth`, `raffles`, `orders`, `payments`), as approved in
  ADR-001

### Out of Scope (MVP)
- Automatic winner generation / auditable randomness
- Automated refunds/cancellations from platform
- Email verification required for purchase
- Identity document (ID) collection
- Purchase limits per buyer
- Kubernetes and multi-instance high availability
- Distributed transactions / two-phase commit
- Automatic database sharding
- Legally reviewed compliance texts (S11 adds clearly marked academic drafts and public pages; no legal review is claimed)
- Real money transactions

## 4. Functional Requirements

| ID | Requirement | Actor | Priority | Notes |
|---|---|---|---|---|
| FR-001 | Users must be able to register with name, email, and phone | USER | Must | No ID document required |
| FR-002 | Users must be able to log in securely | USER | Must | Session/token-based auth |
| FR-003 | Users must be authenticated to purchase | USER | Must | Guest checkout not allowed |
| FR-004 | Users can browse published raffles and view details | USER | Must | Public catalog access |
| FR-005 | Users can view available numbers for a raffle | USER | Must | Real-time availability |
| FR-006 | Users can select multiple numbers in one order | USER | Must | Single payment for multiple numbers |
| FR-007 | System reserves selected numbers for 10 minutes during checkout | USER | Must | Configurable, concurrent-safe |
| FR-008 | Users can pay via Mercado Pago Checkout Pro (sandbox) | USER | Must | Server validates payment status |
| FR-009 | Users can view their orders and purchase status | USER | Must | Order history |
| FR-010 | Users receive email notifications about purchases | USER | Must | Asynchronous via queue; includes refund-incident notification |
| FR-011 | Admins can create and configure raffles | ADMIN | Must | Define range, price per number |
| FR-012 | Admins can publish/close raffles | ADMIN | Must | Status management |
| FR-013 | Admins can view all orders and payment incidents | ADMIN | Must | Incident management |
| FR-014 | Admins can manually publish results with winner number, date, and evidence | ADMIN | Must | Must correspond to valid number in raffle |
| FR-015 | Admins can manage payment review cases | ADMIN | Must | For uncertain payment results |
| FR-016 | Registration must require informed acceptance of the privacy policy and prototype terms, recorded with document version, date and user; promotional consent must be optional and separate | USER | Must | S11; no real raffle operation authorized |

## 5. Non-Functional Requirements

| ID | Requirement | Category | Priority | Notes |
|---|---|---|---|---|
| NFR-001 | Payment validation must be server-side only | Security | Must | Never trust browser redirect |
| NFR-002 | Prevent double-selling of numbers | Concurrency | Must | DB constraints + atomic operations |
| NFR-003 | Webhooks must be idempotent | Integration | Must | Track processed webhook events |
| NFR-004 | Reservations must be concurrency-safe | Concurrency | Must | Handle race conditions |
| NFR-005 | Email sending must be decoupled from purchase flow | Resilience | Must | Queue-based, retryable |
| NFR-006 | Failed email must not cancel confirmed purchase | Resilience | Must | Purchase must persist |
| NFR-007 | If DB unavailable, system must not fake successful purchase | Resilience | Must | Fail closed |
| NFR-008 | Catalog must work if payment provider fails | Resilience | Must | Graceful degradation |
| NFR-009 | Use timeouts and limited retries for external calls | Resilience | Must | Prevent cascading failures |
| NFR-010 | Secrets must be outside source code | Security | Must | Environment variables |
| NFR-011 | DB must not be publicly exposed | Security | Must | Internal network only |
| NFR-012 | Admin actions must be authorized on backend | Security | Must | Not just UI checks |
| NFR-013 | All monetary values in COP (integer pesos) | Data Integrity | Must | Avoid floating point and cents |
| NFR-014 | Persist data for audit and reconciliation | Audit | Must | Payments, webhooks, orders |
| NFR-015 | Minimize personal data collection | Privacy | Must | Only name, email, phone |
| NFR-016 | Each extracted business service must start, stop and report health independently | Operability | Must | Own container and health check; no shared lifecycle |
| NFR-017 | Service-to-service calls must be authenticated and least-privilege | Security | Must | No trust in client-supplied identity headers |
| NFR-018 | Each domain owns its data; other services access it through an approved contract or read-only grant | Data Integrity | Must | Enforced by code, grants and contract tests |
| NFR-019 | Failure of one service must not silently corrupt or fake another domain's state | Resilience | Must | Controlled 503, fail closed, no fake orders/payments |
| NFR-020 | Consent evidence must be versioned, dated and linked to the authenticated user; public privacy/terms/regulatory pages must remain accessible | Privacy / Transparency | Must | S11; migration and grant applied to `rifacloud` on 2026-11-12 |

## 6. Assumptions

- Local development (P1) requires no external credentials.
- Mercado Pago Checkout Pro sandbox credentials are required to validate the real payment integration (P2), not to build or unit-test the application.
- Mailtrap SMTP credentials are required to validate real email delivery (P2); local development uses a `log` mailer.
- Single AWS EC2 instance with persistent volume for MySQL; instance profile selection is deferred pending memory, load and budget review.
- AWS access, SSH, domain, DNS and TLS are required only for deployment (P3) and never block local development.
- Domain may or may not be available for TLS (HTTP acceptable for controlled demo).
- Legal requirements and legal notice content are not validated: they block real public release (P4), not the university demo.
- S11 provides academic drafts for privacy, terms and regulatory scope plus a versioned consent control. They are not legal advice, are not legally validated and do not authorize real raffles.
- Placeholder legal pages are optional for the demo; legally validated content is mandatory before any real publication.
- Amazon SES is a future option and does not block any phase.

## 7. Decisions Confirmed (Approved)

The business decisions below remain approved and are not open for modification without explicit authorization.

| Decision | Value |
|---|---|
| Architecture | Target: four business microservices (`auth`, `raffles`, `orders`, `payments`) behind Nginx; incremental extraction from the Laravel modular monolith; React + TypeScript; Docker Compose (ADR-001) |
| Transitional data | Single MySQL 8 instance with domain ownership and read-only access; per-service grants/databases in a later stage |
| Deployment target | Single AWS EC2 instance (no HA); instance type deferred to memory/load/budget review |
| Database | MySQL 8, internal network only, not publicly exposed |
| Payments | Mercado Pago Checkout Pro (sandbox) as primary and only provider attempted; no second provider until MP is confirmed non-viable |
| Reservations | 10 minutes, configurable |
| Purchase | Requires authentication; multiple numbers per order |
| Results | Manual publication by admin only |
| Refund incidents | Order `REQUIRES_REFUND` triggers an automatic email to the buyer; email failure never alters order/payment state |
| Email | Queue-based, decoupled from purchase flow |
| Concurrency | DB constraints + row locks to prevent double-selling |
| Monetary values | Stored in integer COP (no cents) |
| Legal | Blocks real public release only; placeholders acceptable for the university demo; S11 drafts are not legal advice |

The architecture decision does not change any business rule above. The current
deployed state is a modular monolith plus `auth-service` (S1) and
`raffles-service` (catalog S0 and administration S2); the four-service topology
is the approved target and is reached in stages (S3-S5 in
`02-technical-design.md`).

## 8. Change Log

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-10-22 | Initial PRD |
| 1.1 | 2026-10-23 | Payment/reservation/refund clarifications |
| 1.2 | 2026-10-23 | Coherence review; approved business decisions |
| 1.3 | 2026-11-05 | ADR-001: four-service target approved; microservices moved from out-of-scope to in-scope evolution; added NFR-016 to NFR-019; data ownership and failure isolation recorded |
| 1.4 | 2026-11-05 | S1 auth-service implemented and verified; current-state note updated (no requirement change) |
| 1.5 | 2026-11-06 | S2 raffle administration implemented and verified; current-state note updated (no requirement change) |
| 1.6 | 2026-11-11 | S11: added FR-016 (informed privacy/terms consent with version evidence and separate optional marketing consent) and NFR-020 (consent evidence and public legal pages); legal drafts remain unvalidated and do not authorize real raffles |
| 1.7 | 2026-11-12 | S11 closed on the main schema: `user_consents` migration and auth-service grant applied with explicit authorization; controlled registration verified and cleaned up. No requirement change |
