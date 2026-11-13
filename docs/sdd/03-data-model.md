# 03 - Data Model

**Project:** RifaCloud  
**Status:** Approved (architecture evolution)  
**Version:** 1.8  
**Last Updated:** 2026-11-12  

## 1. Design Principles

- Use integers for all monetary values (cents)
- Enforce uniqueness at DB level to prevent double-selling
- Use transactions and row locks for concurrency
- Minimize PII (name, email, phone only)
- Track all payment/webhook events for reconciliation
- Separate order state from payment state
- Store audit-relevant data

## 1.1 Data Ownership by Service (target)

The entity definitions below describe the physical model. In the target
architecture each entity has exactly one owning service. Other services use an
API/command or an explicitly approved read-only grant.

| Entity / table | Owner | Write rule | Transitional access |
|---|---|---|---|
| `users`, `sessions`, `password_reset_tokens` | `auth-service` | Only auth-service writes | Other services read sessions for validation; users via API or read grant |
| `user_consents` | `auth-service` | Only auth-service writes | No other service needs access; evidence is exposed only through a future owner-scoped rights flow |
| `raffles` | `raffles-service` | Only raffles-service writes | Orders/payments read-only |
| `raffle_numbers` | `orders-service` (status) / `raffles-service` (creation) | `raffles-service` inserts the initial range for a raffle and removes it with a deleted DRAFT raffle; `orders-service` alone performs reservation, sale and release status transitions (S4) | Shared schema: reservations/sales stay in orders; raffles-service reads for counts; payments-service reads status |
| `orders`, `order_items` | `orders-service` | Only orders-service writes | payments-service reads order state |
| `payments`, `payment_webhook_events` | `payments-service` | Only payments-service writes | orders-service reads payment projection |
| `notifications` | scheduler/notifications process | Only the notification worker writes | none |

### Shared-schema transition rules

The current implementation still uses one MySQL instance and schema. Until the
per-domain split (stage S5):

1. Only the owning service issues `INSERT`/`UPDATE`/`DELETE` for its tables.
2. Read services use a read-only MySQL session where possible.
3. Cross-domain foreign keys remain for database integrity but are **not** a
   license to write across domains.
4. Cross-service state changes go through an idempotent API/command, not a
   direct write to another domain's table.
5. `raffle_numbers` is a deliberately split table during the transition:
   `raffles-service` only creates/removes the number rows that belong to a
   raffle, while `orders-service` owns reservation, sale and release
   transitions. payments-service requests a result through the signed
   payment-result command and never writes the table. S5 must define the
   creation handoff contract (command or event) before the table can be
   partitioned; until then both operations are limited to their documented
   columns and the orders transaction remains the only place that locks rows
   for reservation.
6. `SELECT ... FOR UPDATE` may lock a row owned by another service only inside
   the documented orders reservation transaction; the lock is not a write.
7. A dedicated least-privilege MySQL user per service is required before
   production (stage S5). Until then, the shared credentials are a documented
   risk.

When a domain moves to its own database, cross-domain foreign keys are replaced
by identifiers plus events/compensation. The reservation and payment invariants
in sections 4-5 remain mandatory.

## 2. Entity Relationship Overview

```
users (1)──< user_consents
users (1)──< orders (1)──< order_items >──(1) raffle_numbers
                │                            │
                │                            │
               (1)                          (1)
                │                            │
              payments                    raffles
                │
               (1)
                │
           payment_webhook_events
```

## 3. Core Entities

### users
User accounts with role-based access.

| Field | Type | Constraints | Description |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Primary key |
| name | VARCHAR(255) | NOT NULL | Full name |
| email | VARCHAR(255) | NOT NULL, UNIQUE | Email address |
| phone | VARCHAR(20) | NOT NULL | Phone number |
| password | VARCHAR(255) | NOT NULL | Hashed password |
| role | ENUM('USER','ADMIN') | NOT NULL, DEFAULT 'USER' | User role |
| email_verified_at | TIMESTAMP | NULL | Not required for purchase |
| remember_token | VARCHAR(100) | NULL | Remember token |
| created_at | TIMESTAMP | NULL | Created timestamp |
| updated_at | TIMESTAMP | NULL | Updated timestamp |

### user_consents

S11 proof of informed consent. One row per user, consent type and document
version. The unique key makes consent recording idempotent. `restrictOnDelete`
prevents deleting a user while evidence exists: account deletion must first
decide what evidence is retained under legal/contractual obligations.

| Field | Type | Constraints | Description |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Primary key |
| user_id | BIGINT UNSIGNED | FK(users.id), NOT NULL, RESTRICT | User who accepted |
| type | VARCHAR(32) | NOT NULL | `PRIVACY`, `TERMS` or `MARKETING` |
| document_version | VARCHAR(64) | NOT NULL | Exact public document version accepted |
| accepted | BOOLEAN | NOT NULL | Decision; required types are always true |
| accepted_at | TIMESTAMP | NOT NULL | Decision timestamp |
| source | VARCHAR(32) | NOT NULL, DEFAULT 'REGISTRATION' | Collection channel |
| created_at | TIMESTAMP | NULL | Created timestamp |
| updated_at | TIMESTAMP | NULL | Updated timestamp |

**Constraints:**
- `UNIQUE(user_id, type, document_version)`
- Index on `(type, document_version)`
- No IP address or user agent is stored, to minimize personal data.

**Status:** additive migration `2026_11_11_000011_create_user_consents_table.php`
is applied to `rifacloud_test` and to the main `rifacloud` schema (2026-11-12,
explicit authorization). The `rifacloud_auth` user has SELECT/INSERT/UPDATE/
DELETE on the table. A controlled API registration verified the three rows and
was cleaned up; no test data remains.

### raffles
Raffle campaigns.

| Field | Type | Constraints | Description |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Primary key |
| title | VARCHAR(255) | NOT NULL | Raffle title |
| description | TEXT | NULL | Description |
| price_per_number | INT UNSIGNED | NOT NULL | Price in cents |
| min_number | INT UNSIGNED | NOT NULL | Range start |
| max_number | INT UNSIGNED | NOT NULL | Range end |
| status | ENUM('DRAFT','PUBLISHED','CLOSED','FINISHED') | NOT NULL, DEFAULT 'DRAFT' | Raffle status |
| starts_at | TIMESTAMP | NULL | Optional start date |
| ends_at | TIMESTAMP | NULL | Optional end date |
| created_by | BIGINT UNSIGNED | FK(users.id) | Admin creator |
| created_at | TIMESTAMP | NULL | Created timestamp |
| updated_at | TIMESTAMP | NULL | Updated timestamp |

**Indexes:** `status`, `created_by`

### raffle_numbers
Individual numbers for each raffle. One row per number.

| Field | Type | Constraints | Description |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Primary key |
| raffle_id | BIGINT UNSIGNED | FK(raffles.id), NOT NULL | Raffle reference |
| number | INT UNSIGNED | NOT NULL | Number value in range |
| status | ENUM('AVAILABLE','RESERVED','SOLD') | NOT NULL, DEFAULT 'AVAILABLE' | Number status |
| reserved_at | TIMESTAMP | NULL | When reserved |
| reserved_until | TIMESTAMP | NULL | Reservation expiry (10 min) |
| sold_at | TIMESTAMP | NULL | When sold |
| order_id | BIGINT UNSIGNED | FK(orders.id), NULL | Order that owns this number |
| created_at | TIMESTAMP | NULL | Created timestamp |
| updated_at | TIMESTAMP | NULL | Updated timestamp |

**Constraints:**
- `UNIQUE(raffle_id, number)` - Prevent duplicate numbers per raffle
- Index on `(raffle_id, status)`

### orders
Customer orders.

| Field | Type | Constraints | Description |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Primary key |
| user_id | BIGINT UNSIGNED | FK(users.id), NOT NULL | Customer |
| raffle_id | BIGINT UNSIGNED | FK(raffles.id), NOT NULL | Raffle |
| order_number | VARCHAR(50) | NOT NULL, UNIQUE | Human-readable order number |
| total_amount | INT UNSIGNED | NOT NULL | Total in cents |
| status | ENUM('PENDING_PAYMENT','PAID','REJECTED','EXPIRED','PAYMENT_REVIEW','REQUIRES_REFUND') | NOT NULL, DEFAULT 'PENDING_PAYMENT' | Order status |
| expires_at | TIMESTAMP | NULL | Order/reservation expiry |
| paid_at | TIMESTAMP | NULL | When marked as paid |
| notes | TEXT | NULL | Admin notes |
| created_at | TIMESTAMP | NULL | Created timestamp |
| updated_at | TIMESTAMP | NULL | Updated timestamp |

**Indexes:** `user_id`, `raffle_id`, `status`, `order_number`

### order_items
Line items for orders.

| Field | Type | Constraints | Description |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Primary key |
| order_id | BIGINT UNSIGNED | FK(orders.id), NOT NULL | Order reference |
| raffle_number_id | BIGINT UNSIGNED | FK(raffle_numbers.id), NOT NULL | Number reference |
| price_at_purchase | INT UNSIGNED | NOT NULL | Price snapshot in cents |
| created_at | TIMESTAMP | NULL | Created timestamp |
| updated_at | TIMESTAMP | NULL | Updated timestamp |

**Constraints:**
- `UNIQUE(order_id, raffle_number_id)` - Prevent duplicate items
- Index on `order_id`

### payments
Payment records linked to orders.

| Field | Type | Constraints | Description |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Primary key |
| order_id | BIGINT UNSIGNED | FK(orders.id), NOT NULL | Order reference |
| provider | ENUM('MERCADOPAGO','PAYPAL') | NOT NULL | Payment provider (`PAYPAL` reserved, not used in MVP) |
| external_id | VARCHAR(255) | NOT NULL | Provider's payment ID |
| status | ENUM('PENDING','APPROVED','REJECTED','UNKNOWN','REFUND_REQUIRED') | NOT NULL, DEFAULT 'PENDING' | Payment status |
| amount | INT UNSIGNED | NOT NULL | Amount in cents |
| currency | VARCHAR(3) | NOT NULL, DEFAULT 'COP' | Currency |
| checkout_url | TEXT | NULL | Checkout URL |
| verified_at | TIMESTAMP | NULL | Last verification time |
| response_data | JSON | NULL | Provider response (sanitized) |
| created_at | TIMESTAMP | NULL | Created timestamp |
| updated_at | TIMESTAMP | NULL | Updated timestamp |

**Constraints:**
- `UNIQUE(provider, external_id)` - Prevent duplicate payments
- Index on `order_id`, `status`

### payment_webhook_events
Idempotency tracking for webhooks.

| Field | Type | Constraints | Description |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Primary key |
| provider | ENUM('MERCADOPAGO','PAYPAL') | NOT NULL | Provider (`PAYPAL` reserved, not used in MVP) |
| provider_event_id | VARCHAR(255) | NOT NULL | Provider's event ID |
| event_type | VARCHAR(100) | NOT NULL | Event type |
| payload | JSON | NULL | Webhook payload (sanitized) |
| processed_at | TIMESTAMP | NULL | When processed |
| status | ENUM('RECEIVED','PROCESSED','FAILED','IGNORED') | NOT NULL, DEFAULT 'RECEIVED' | Processing status |
| created_at | TIMESTAMP | NULL | Created timestamp |
| updated_at | TIMESTAMP | NULL | Updated timestamp |

**Constraints:**
- `UNIQUE(provider, provider_event_id)` - Critical for idempotency
- Index on `status`, `processed_at`

### notifications
Email notification queue/tracking.

| Field | Type | Constraints | Description |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Primary key |
| type | VARCHAR(50) | NOT NULL | Notification type |
| recipient_email | VARCHAR(255) | NOT NULL | Recipient |
| subject | VARCHAR(255) | NOT NULL | Email subject |
| body | TEXT | NOT NULL | Email body |
| order_id | BIGINT UNSIGNED | FK(orders.id), NULL | Related order |
| status | ENUM('PENDING','SENT','FAILED','RETRY') | NOT NULL, DEFAULT 'PENDING' | Status |
| attempts | TINYINT UNSIGNED | NOT NULL, DEFAULT 0 | Retry count |
| last_attempt_at | TIMESTAMP | NULL | Last attempt |
| sent_at | TIMESTAMP | NULL | When sent |
| error_message | TEXT | NULL | Error details (no PII) |
| created_at | TIMESTAMP | NULL | Created timestamp |
| updated_at | TIMESTAMP | NULL | Updated timestamp |

**Indexes:** `status`, `order_id`, `type`

### raffle_results
Published results by admin.

| Field | Type | Constraints | Description |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Primary key |
| raffle_id | BIGINT UNSIGNED | FK(raffles.id), NOT NULL, UNIQUE | Raffle (one result per raffle) |
| winning_number | INT UNSIGNED | NOT NULL | Winning number |
| winning_raffle_number_id | BIGINT UNSIGNED | FK(raffle_numbers.id), NULL | Reference to winning number |
| published_by | BIGINT UNSIGNED | FK(users.id), NOT NULL | Admin who published |
| published_at | TIMESTAMP | NOT NULL | Publication date/time |
| evidence_description | TEXT | NULL | Description of evidence |
| evidence_url | VARCHAR(500) | NULL | Optional evidence URL |
| created_at | TIMESTAMP | NULL | Created timestamp |
| updated_at | TIMESTAMP | NULL | Updated timestamp |

**Constraints:**
- Must validate winning number belongs to raffle
- Only published by ADMIN role

## 4. Concurrency & Double-Sell Prevention

### Strategy
1. **Database constraint:** `UNIQUE(raffle_id, number)` on `raffle_numbers`
2. **Atomic reservation:** Use `SELECT ... FOR UPDATE` in transaction
3. **State transition:** `UPDATE raffle_numbers SET status='RESERVED' WHERE id=? AND status='AVAILABLE' AND (reserved_until IS NULL OR reserved_until < NOW())`
4. **Order creation:** Link numbers to order atomically
5. **Payment confirmation:** Only assign as SOLD if still RESERVED and belongs to order

### Race Condition Handling
- Multiple users selecting same number simultaneously → first transaction wins, second gets constraint/error
- Application checks affected rows from UPDATE
- Retry logic at application level if needed
- Expired reservations can be reclaimed

## 5. Reservation Logic

There is **no separate `reservations` table**. Reservation state lives in `raffle_numbers` (`reserved_at`, `reserved_until`, `order_id`) and `orders.expires_at`.

```sql
-- Atomic reservation (order is created first so the FK order_id is valid)
BEGIN;
-- 1. Create order with expires_at
INSERT INTO orders (user_id, raffle_id, order_number, total_amount, status, expires_at)
VALUES (?, ?, ?, ?, 'PENDING_PAYMENT', DATE_ADD(NOW(), INTERVAL 10 MINUTE));

-- 2. Lock and validate the requested numbers
SELECT * FROM raffle_numbers 
WHERE raffle_id = ? AND number IN (...) AND status = 'AVAILABLE'
FOR UPDATE;

-- 3. Reserve numbers and link them to the order
UPDATE raffle_numbers SET status='RESERVED', reserved_at=NOW(), reserved_until=DATE_ADD(NOW(), INTERVAL 10 MINUTE), order_id=? 
WHERE id IN (...) AND status='AVAILABLE';

-- 4. Create line items
INSERT INTO order_items (order_id, raffle_number_id, price_at_purchase)
VALUES (?, ?, ?), ...;
COMMIT;
```

### State Transitions on Payment Result

- `APPROVED` → order `PAID`, numbers `SOLD`, `sold_at` set
- `REJECTED` → order `REJECTED`, numbers back to `AVAILABLE`, reservation fields cleared
- `UNKNOWN` → order `PAYMENT_REVIEW`, payment `UNKNOWN`, numbers stay `RESERVED`
- Payment confirmed but numbers no longer available → order `REQUIRES_REFUND`, payment `REFUND_REQUIRED`, numbers are **not** reassigned

### Expiration Rules
- Scheduler finds reservations where `reserved_until < NOW()` and `status='RESERVED'`
- **Only release if:** no associated order with status in (`PAID`, `PAYMENT_REVIEW`, `REQUIRES_REFUND`) AND no uncertain payment
- If order is `PAYMENT_REVIEW`, `REQUIRES_REFUND` or payment is `UNKNOWN` → **DO NOT RELEASE** automatically
- If order is `PENDING_PAYMENT` and expired → release numbers, mark order `EXPIRED`

## 6. Data Retention & Audit

- Keep all orders, payments, webhook events for reconciliation
- `response_data` in payments should be sanitized (no full card details)
- Logs must not contain secrets or unnecessary PII
- Soft deletes not required for MVP
- Preserve data for dispute resolution

## 7. Change Log

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-10-22 | Initial data model |
| 1.1 | 2026-10-23 | Reservation/payment state clarifications |
| 1.2 | 2026-10-23 | Coherence review |
| 1.3 | 2026-11-05 | ADR-001: per-domain ownership matrix; shared-schema transition rules; least-privilege and database-split path |
| 1.4 | 2026-11-06 | S2 clarification: `raffle_numbers` creation/removal by `raffles-service`, status transitions by `orders-service`; S5 creation-handoff contract required before partitioning |
| 1.5 | 2026-11-06 | S3 clarification: `orders-service` performs reservation and expiry updates and owns `orders`/`order_items` writes; the monolith payment flow still performs the sale/order-status write until S4 replaces it with an idempotent command |
| 1.6 | 2026-11-06 | S4: `payments-service` is the only writer of `payments`/`payment_webhook_events`; `orders-service` performs every sale/release/order-status transition through the signed payment-result command. Cross-service atomicity is a convergent saga (order/number first, payment row second); the payment row is locked but never written by orders-service |
| 1.7 | 2026-11-11 | S11: added `user_consents` (auth-owned consent evidence: type, document version, decision, timestamp, source, user). Additive migration implemented and verified in `rifacloud_test`; not applied to the main schema. Business entities and reservation/payment rules unchanged |
| 1.8 | 2026-11-12 | S11 closed: `user_consents` migration applied to `rifacloud` with explicit authorization; `rifacloud_auth` grant verified; controlled registration confirmed PRIVACY/TERMS/MARKETING rows and was cleaned up. Business entities and rules unchanged |
