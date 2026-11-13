# RifaCloud SDD Documentation

**Project:** RifaCloud  
**Purpose:** Specification-Driven Development documentation for university Cloud Computing project  
**Status:** Approved (architecture evolution)  
**Version:** 1.10
**Last Updated:** 2026-11-12

## Purpose

This documentation follows Specification-Driven Development (SDD) principles. All design decisions, requirements, and architecture are documented here before implementation. The goal is to create a functional MVP demonstrable and deployable on AWS in 3 weeks.

## Document Index

| Document | Purpose | When to Read |
|---|---|---|
| **[01-product-requirements.md](01-product-requirements.md)** | Objectives, actors, scope, functional/non-functional requirements | Start here - understand what we're building |
| **[02-technical-design.md](02-technical-design.md)** | Architecture, components, flows, tech stack, deployment | Read after PRD - understand how we'll build it |
| **[03-data-model.md](03-data-model.md)** | Entities, relationships, constraints, concurrency prevention | Read with technical design - data structure |
| **[04-implementation-plan.md](04-implementation-plan.md)** | 3-week timeline, tasks, priorities, risks | Read for planning - execution roadmap |
| **[05-acceptance-criteria.md](05-acceptance-criteria.md)** | Verifiable test criteria for all features | Use for testing and validation |
| **[06-open-questions.md](06-open-questions.md)** | Pending decisions requiring user input | Check before deployment/implementation blockers |
| **[07-resilience-and-failure-handling.md](07-resilience-and-failure-handling.md)** | Failure scenarios, recovery, resilience strategy | Critical for robustness understanding |
| **[08-adr-001-four-service-architecture.md](08-adr-001-four-service-architecture.md)** | Architecture decision: four business microservices, supersedes the monolith-only target | Read before any extraction work |
| **[../legal/README.md](../legal/README.md)** | S11 legal/privacy drafts, data inventory, rights limitations, launch requirements and official sources | Read before publishing legal text or receiving real personal data |
| **[AGENTS.md](../../AGENTS.md)** | Instructions for future OpenCode sessions | Read by AI agents working on this project |

## Reading Order

1. **01-product-requirements.md** - Understand scope and requirements
2. **02-technical-design.md** - Understand architecture and approach
3. **03-data-model.md** - Understand data structure and constraints
4. **06-open-questions.md** - Identify what needs clarification
5. **04-implementation-plan.md** - Understand timeline and priorities
6. **05-acceptance-criteria.md** - Understand how to validate
7. **07-resilience-and-failure-handling.md** - Understand failure modes
8. **08-adr-001-four-service-architecture.md** - Understand the approved architecture evolution
9. **AGENTS.md** - Understand working rules

## Key Decisions (Confirmed)

- **Architecture target:** four business microservices (`auth`, `raffles`, `orders`, `payments`) behind Nginx; incremental extraction from the Laravel modular monolith; React + TypeScript; Docker Compose (ADR-001)
- **Current state:** monolith + `auth-service` (S1) + `raffles-service` catalog and administration (S0/S2) + `orders-service` orders/reservations/expiry (S3) + `payments-service` payments/Mercado Pago/webhooks (S4); S6 browser continuity, S7 purchase confirmation/history, S9.1 audit corrections, S10.1 AWS deployment preparation, S10.2 functional/UX polish, S10.3 admin dashboard and S11 privacy/transparency are done. S11 adds public privacy/terms/regulatory pages, footer legal links, a contextual checkout clarification and versioned informed consent stored in `user_consents`; the additive S11 migration and the auth-service grant are applied to `rifacloud` (2026-11-12), with a controlled registration/cleanup verifying the three consent rows. S5 minimal is closed (migrations and per-service MySQL users applied). The full data separation (per-domain DBs, outbox, rollback removal) is deferred to S8, while the AWS resources, domain/certificates, real Mercado Pago webhook and legal identity remain pending user input
- **Data:** single MySQL 8 instance and shared schema during the transition, with per-domain ownership; per-service grants/databases in stage S5
- **Deployment:** Single AWS EC2 instance (no HA); instance type pending memory/load/budget review
- **Database:** MySQL, not publicly exposed
- **Payments:** Mercado Pago Checkout Pro (sandbox) primary and only provider attempted; no second provider until MP is confirmed non-viable
- **Reservations:** 10 minutes, configurable
- **Purchase:** Requires registration, multiple numbers per order
- **Results:** Manual publication by admin only
- **Email:** Queue-based, decoupled from purchase (log mailer locally, Mailtrap for P2 validation)
- **Refund incidents:** Automatic queued email to buyer on `REQUIRES_REFUND`; email failure never alters order state
- **Concurrency:** DB constraints + row locks to prevent double-selling

## Blocker Phases

`06-open-questions.md` classifies pending decisions by the phase they actually block:

| Phase | Meaning | External dependency |
|---|---|---|
| **P1** | Local development (`docker-compose up`, unit/integration tests) | No |
| **P2** | External integration validation (Mercado Pago sandbox, Mailtrap) | Yes |
| **P3** | AWS deployment (EC2, SSH, EBS, domain, DNS, TLS) | Yes |
| **P4** | Real public release (legal requirements, legal notice) | Yes |

Local development starts with no external credentials. Unresolved P2/P3/P4 questions never block P1.

## Workflow

### Before Implementation
1. Review all SDD documents
2. Resolve only **P1 blockers** in `06-open-questions.md` (there are none by design)
3. Get explicit approval to start implementation
4. Only then proceed with coding

### Phase Gates
1. Start P1 with the simulated payment gateway and `log` mailer
2. Resolve Q-05, Q-06 and Q-08 before real P2 integration validation (around Days 9-12)
3. Resolve Q-03, Q-04 and Q-10 to Q-13 before P3 deployment (around Day 19)
4. Resolve Q-01 and Q-02 only for P4 real publication

### During Implementation
1. Always consult SDD documents before making changes
2. Follow approved architecture and requirements
3. Don't invent new requirements or change decisions without authorization
4. Maintain traceability to requirements and acceptance criteria
5. Update tests when implementing features

### Rules
- **Implement only approved scope** - Follow the 3-week plan and phase gates
- **No unapproved architecture changes** - Changes require authorization
- **No dependency installation outside the approved stack**
- **Respect approved decisions** - Changes require authorization
- **Document blockers** - Add to open questions if needed

## Status

All documents are **Approved** as of 2026-11-06. The architecture target was
updated by ADR-001 to four business microservices; the previously approved
monolith decision is preserved in the change history. Open questions remain open
and are resolved at their phase gates (P1-P4). The S11 documents in `docs/legal/`
are **drafts**, not approved legal advice or operating authorization.

The five migration increments are implemented and verified: the read-only
catalog `raffles-service` (S0), `auth-service` (S1), raffle administration in
`raffles-service` (S2), orders/reservations/expiry in `orders-service` (S3) and
payments/Mercado Pago/webhooks in `payments-service` (S4). S6 adds browser
continuity (session, return, expiry polling) and S7 adds purchase confirmation
and "Mis compras" (owner-scoped history). S5 minimal is closed: the two additive
main-schema migrations and four per-service MySQL users were applied and
verified with explicit authorization. The full data separation (per-domain
databases, outbox/compensation and removal of the monolith rollback path) is
deferred to S8. See `02-technical-design.md` section 8,
`04-implementation-plan.md` section 10 and `05-acceptance-criteria.md` section 9.

S11 is documented separately in `docs/legal/`: it adds academic legal drafts,
public privacy/terms/regulatory pages, a contextual checkout clarification and
versioned informed consent. The documents are not legal advice and do not
authorize real raffles.
The `user_consents` migration and the `auth-service` grant were applied to the
main schema on 2026-11-12 with explicit authorization; a controlled
registration/cleanup verified the three consent rows and left no test data.
