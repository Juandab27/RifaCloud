# 06 - Open Questions

**Project:** RifaCloud  
**Status:** Approved (architecture evolution)  
**Version:** 1.7  
**Last Updated:** 2026-11-12  

All business decisions from the confirmed list remain unchanged. Remaining questions are classified by the phase they actually block. A question only blocks the phase it is assigned to, and never an earlier phase.

## 1. Blocker Phases

| Phase | Name | Gate / What it covers | External credentials |
|---|---|---|---|
| **P1** | Local development | `docker-compose up`, unit tests, local integration tests, concurrency tests | **None required** |
| **P2** | External integration testing | Real validation against Mercado Pago sandbox and Mailtrap | MP sandbox, Mailtrap |
| **P3** | AWS deployment | EC2 provisioning, SSH access, EBS persistence, domain, DNS, TLS | AWS account, key pair, domain/DNS |
| **P4** | Real public release | Legal requirements, legal notice validation, real payment processing, public domain | Legal review, production credentials |

### Rules

- P1 starts on day 1 with no external credentials. Payment and email integrations use a simulated gateway and a `log` mailer until P2 credentials exist.
- Missing P2/P3/P4 answers must not stop P1 work.
- A question is answered at its phase gate, not before.
- Legal issues block **real publication only**, not the university demo.
- Mercado Pago Checkout Pro (sandbox) remains the first and only payment provider attempted. No second provider is implemented until MP is confirmed non-viable.

## 2. Legal & Compliance

| ID | Question | Impact | Blocks | Recommendation | Status |
|---|---|---|---|---|---|
| **Q-01** | **Legal requirements for raffles in Colombia?** Permits, age minimum, lottery regulations. | High - Publication real | **P4** | Consult legal advisor. Keep any legal text configurable; optional placeholder pages for the demo. Do not invent requirements. Not required for university demo. | **OPEN** |
| **Q-02** | **Legal notice content?** Terms of service, privacy policy. | Medium - Compliance | **P4** | Optional placeholder pages for the demo, clearly marked "PLACEHOLDER - NOT LEGAL ADVICE". Legally validated content is mandatory before real publication. Do not invent legal content. | **OPEN** |

## 3. Payment Provider

| ID | Question | Impact | Blocks | Recommendation | Status |
|---|---|---|---|---|---|
| **Q-05** | **Mercado Pago Checkout Pro sandbox credentials?** (Public Key, Access Token) | High - Core functionality | **P2** only | Primary provider. Required to validate the real integration. Do **not** block initial build or unit tests: P1 uses a simulated gateway behind `PaymentGateway`. Needed around Day 9. | **OPEN** |
| **Q-06** | **Public URL for webhook/return during development?** Temporary tunnel or end-to-end validation after deployment? | High - Webhook E2E | **P2** only | Two alternatives remain open; choose **before Day 12**: (a) temporary tunnel during Week 2, or (b) E2E validation after the first deployment. Neither alternative is confirmed yet. | **OPEN** |
| **Q-07** | **Second payment provider?** (e.g. PayPal Sandbox) | Low - Contingency | **None currently** | **Do not implement.** Evaluate and select only after documenting that Mercado Pago is not viable. The `PaymentGateway` interface keeps the option open. | **DEFERRED** |

## 4. Email Configuration

| ID | Question | Impact | Blocks | Recommendation | Status |
|---|---|---|---|---|---|
| **Q-08** | **Mailtrap account & SMTP credentials?** | Medium - Email integration | **P2** only | Free account sufficient. P1 uses a `log` mailer and queue/retry tests without external SMTP. Needed around Day 12. | **OPEN** |
| **Q-09** | **Amazon SES access?** (for future) | Low - Future | **None currently (future)** | Document as future option, outside MVP. Does not block any phase. | **DEFERRED** |

## 5. Domain & TLS

| ID | Question | Impact | Blocks | Recommendation | Status |
|---|---|---|---|---|---|
| **Q-03** | **Domain available for demo?** (e.g., example.com) | Medium - HTTPS | **P3** (HTTPS portion only) | If domain available, use Let's Encrypt. If not, document HTTP-only for controlled internal demo. Does not block local development. | **OPEN** |
| **Q-04** | **DNS configuration access?** For Let's Encrypt validation | Medium - TLS | **P3** | Need DNS or HTTP-01 challenge access to port 80 of the deployed instance. Does not block local development. | **OPEN** |

## 6. Deployment Environment

| ID | Question | Impact | Blocks | Recommendation | Status |
|---|---|---|---|---|---|
| **Q-10** | **AWS account, region and budget?** | High - Deployment | **P3** | Confirm account access, region and budget ceiling before Week 3. Not needed for local development. | **OPEN** |
| **Q-11** | **EC2 resource profile?** (instance family/size, AMI) | High - Deployment | **P3** | **Do not fix an instance type yet.** Document required resources (section 7) and select after reviewing memory needs, measured local load and budget. | **OPEN** |
| **Q-12** | **SSH access to EC2?** | High - Deployment | **P3** | Create key pair. Security group: 22 restricted to admin IP, 80/443 public, outbound 443 for MP/SMTP. | **OPEN** |
| **Q-13** | **EBS volume for persistence?** | Medium - Data persistence | **P3** | Attach a persistent EBS volume for MySQL data. Container-level persistence is already validated locally in P1. | **OPEN** |

## 7. Required Deployment Resources (instance selection deferred)

These are resource requirements, not a commitment to a specific AWS instance type.

| Resource | Requirement | Notes |
|---|---|---|
| Runtime | Docker Engine + Docker Compose v2 | AMI pending selection |
| Compute/Memory | Host nginx + PHP-FPM + queue worker + scheduler + MySQL 8 | Preliminary estimate: at least ~2 GB RAM. Validate against measured local usage |
| Storage | Persistent EBS volume (e.g. gp3) for OS, images and MySQL growth | Size decided with load estimate |
| Network | Security group: 80/443 public, 22 restricted to admin IP; outbound 443 | SMTP/MP outbound access required |
| Public IP | Elastic IP recommended | Keeps webhook/return URLs stable |
| Region | Pending | Consider latency to Colombia and payment provider |

Final selection is conditioned on the memory review, expected load and budget (Q-11).

## 8. Dependencies and Gates

| Gate | Needed by | Questions | If unresolved |
|---|---|---|---|
| P1 start | Day 1 | None | — |
| P2 payments | Days 9-12 | Q-05, Q-06 | Keep simulated gateway; MP E2E validation slips |
| P2 email | Day 12 | Q-08 | Keep `log` mailer; email integration slips |
| P3 deployment | Day 19 | Q-03, Q-04, Q-10 to Q-13 | Demo on local Docker Compose |
| P4 - Real publication | Outside 3-week plan | Q-01, Q-02 | No real public release; demo runs with optional placeholder pages |

Dependency flow: `P1 -> P2` and `P1 -> P3`. Payment webhook E2E may require P3 if no tunnel is used (Q-06).

## 9. Notes

- Questions marked OPEN only block the phase listed.
- P1 has no external blockers: no payment credentials, no SMTP credentials, no AWS access.
- Legal questions block real/publication use, not the university demo.
- Recommendations are proposals, not assumptions.
- Decisions already confirmed (business rules, stack, 10-minute reservation, concurrency, manual results) are unchanged.

## 10. Microservices Evolution Decisions

These questions are created by ADR-001. They do not block S1 (auth-service)
unless explicitly stated. S2 resolved Q-19 and added Q-20.

| ID | Question | Impact | Blocks | Recommendation | Status |
|---|---|---|---|---|---|
| **Q-14** | **Internal service-to-service authentication?** HMAC-signed requests, short-lived internal JWT, or mTLS. | High - Security | **S3/S4** internal calls | Prefer HMAC over method/path/body hash + timestamp/nonce or short-lived JWT with audience; rotate secrets; never trust client headers. | **OPEN** |
| **Q-15** | **When to split the shared MySQL schema into per-domain databases?** | High - Data ownership | **S5 only** | Keep one MySQL instance/schema for S1-S4; split after orders/payments contracts are stable. | **DEFERRED** |
| **Q-16** | **Keep DB sessions shared or move to tokens?** | High - Auth compatibility | **S1 design choice** | Keep `sessions` shared with the monolith for S1 (same `APP_KEY`/cookie), evaluate tokens later. | **DECIDED for S1/S2 (verified 2026-11-05 and 2026-11-06); revisit after S4** |
| **Q-17** | **Deployment topology for multiple services?** | Medium - Operability | **S3/S4** | Same single EC2 + Docker Compose; no Kubernetes or multi-instance HA in the MVP. | **DEFERRED** |
| **Q-18** | **Observability and correlation?** | Medium - Debugging | Before S3 | Generate `X-Request-Id` at Nginx, propagate it, log it without secrets. | **OPEN** |
| **Q-19** | **How does `raffles-service` authorize an admin without trusting a header?** | High - Security | **S2 (resolved)** | Validate the shared `rifacloud-session` cookie at the boundary: `APP_KEY` decryption, MAC/prefix, `sessions` lookup, expiry, `ADMIN` role, `password_hash_web`, and CSRF. No Nginx auth subrequest and no identity headers. | **DECIDED and verified for S2 (2026-11-06)** |
| **Q-20** | **Should extracted services refresh `sessions.last_activity`?** | Medium - Session lifetime | Before S5 | `sessions` is auth-owned; admin requests currently validate but do not touch it, so idle admin sessions depend on auth/monolith activity. Define a session-touch or sliding-expiry contract in S5. | **OPEN (residual risk, documented)** |

## 12. S11 Privacy and Legal Review

These questions are created by the S11 privacy and legal documentation. They do
**not** block P1 development or the academic demo. They block any real public
operation (P4) and, where indicated, the authorization to apply the S11
migration to the main schema.

| ID | Question | Impact | Blocks | Recommendation | Status |
|---|---|---|---|---|---|
| **Q-21** | **Who is the Responsable del Tratamiento and the contact/DPO?** The project has no legal entity, NIT, address, email or phone defined. | High - Legal identity | **P4** | Do not invent a company or NIT. The institution or future operator must designate the Responsible and publish a real channel before receiving personal data from the public. `docs/legal/02` uses explicit placeholders. | **OPEN** |
| **Q-22** | **Does the RNBD registration apply, and who registers?** It depends on the Responsible's legal nature and total assets (Decreto 1074, art. 2.2.2.26.1.2). | Medium - Compliance | **P4** | Evaluate with the future Responsible. Do not claim registration is done. | **OPEN** |
| **Q-23** | **Contracts and international transfers with payment, email and hosting providers?** | High - Data protection | **P4** (and P2/P3 for provider validation) | Identify location, sign transmission contracts, evaluate transfer rules and security. No provider is presented as a current processor. | **OPEN** |
| **Q-24** | **Which legal route will real raffles use?** RifaCloud is not an authorized operator and has no agreement with one. | High - Commercial operation | **P4** | Contract an authorized operator or define a technology-provider role in writing; comply with Decreto 1486 requirements (registration, GNA/lottery, 14% rights, reports, prizes, guarantees, age control). | **OPEN** |
| **Q-25** | **Who approves the legal drafts and how are versions maintained?** | High - Consent evidence | **P4** | A human lawyer must review and approve the privacy policy, notice, terms and regulatory scope; bump `LEGAL_CONSENT_VERSION` together with the documents. | **OPEN** |
| **Q-26** | **Authorization to apply the S11 additive migration to the main schema and grant `user_consents` to `rifacloud_auth`?** | Medium - Consent evidence | S11 completion on the main dev schema | **Authorized and applied on 2026-11-12.** Migration `2026_11_11_000011` ran on `rifacloud`; the grant was applied to `rifacloud_auth`; a controlled API registration verified the three rows and was cleaned up. | **DECIDED / APPLIED** |

### S11 status note

S11 implements the mandatory privacy/terms consent, the separate optional
marketing decision and evidence storage in `rifacloud_test` and, since
2026-11-12, in `rifacloud`. The main migration and grant were applied with
explicit authorization, and a controlled registration/cleanup verified the three
rows. It does **not** close Q-01/Q-02: those questions remain open for P4 real
publication. The drafts in `docs/legal/` reduce the missing-content gap but are
not legal advice and are not approved for commercial use.

## 13. Change Log

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-10-22 | Initial draft |
| 1.1 | 2026-10-23 | Classified questions by blocking phase; MP/Mailtrap moved to P2; AWS/domain/TLS to P3; legal to P4; second payment provider deferred; EC2 instance selection deferred |
| 1.2 | 2026-10-23 | P3 gate includes domain/DNS (Q-03, Q-04); coherence review applied (reservation model, REJECTED state, local webhook/email testing, refund email, acceptance gates); final pass: AC-016 gate, AC-019/031/032 traceability, email-state wording, legal pages optional, Q-06 alternatives kept open |
| 1.3 | 2026-11-05 | ADR-001: added Q-14 to Q-18 (internal auth, database split, session sharing, topology, observability); S1 auth decided to reuse shared sessions, later questions deferred |
| 1.4 | 2026-11-05 | Q-16 marked verified for S1 after the session-compatibility tests; no new open questions |
| 1.5 | 2026-11-06 | S2 resolved Q-19 (boundary admin authorization without trusted headers); added Q-20 (session `last_activity` touch contract, residual risk before S5); Q-16 extended to S2 |
| 1.6 | 2026-11-11 | S11 added Q-21 to Q-26 (legal identity/DPO, RNBD, provider contracts/international transfers, real raffle route, legal review/versioning, main-schema migration authorization). Q-01/Q-02 remain open for P4; the new legal drafts are not approval or advice |
| 1.7 | 2026-11-12 | Q-26 closed: S11 migration and `user_consents` grant applied to `rifacloud` with explicit authorization; controlled registration verified and cleaned up. Q-21 to Q-25 remain open for P4 |
