<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use PDO;
use PDOException;

/**
 * Write model for `payments` and `payment_webhook_events`.
 *
 * This service is the only writer of these tables. Locks on `orders` and
 * `raffle_numbers` are read-only serialization points: the order state changes
 * are performed by orders-service through the payment-result command.
 */
final class PaymentRepository
{
    /** @var list<string> */
    private const SAFE_KEYS = [
        'simulated',
        'mode',
        'timeout',
        'preference_id',
        'sandbox',
        'live_mode',
        'mp_payment_id',
        'mp_status',
        'mp_status_detail',
        'mp_payment_method_id',
        'mp_payment_type_id',
        'mp_external_reference',
        'mp_date_approved',
        'mp_date_created',
        'mp_date_last_updated',
        'mp_live_mode',
        'mp_approved_payment_count',
        'mp_approved_payment_ids',
        'mp_approved_payment_count_unconfirmed',
        'mp_payment_ids',
        'mediation_after_approval',
        'transaction_amount',
        'currency_id',
        'provider_approved',
        'multiple_approved_payments',
        'detected_by_reconciliation',
        'numbers_unavailable',
        'late_approval',
        'refund_incident',
        'provider_refund_status',
        'reason',
        'result_status',
        'failure',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<string, mixed>|null
     */
    public function lockOrder(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, status, total_amount, expires_at, order_number
             FROM orders WHERE id = :id LIMIT 1 FOR UPDATE',
        );
        $statement->execute([':id' => $orderId]);
        $order = $statement->fetch();

        return $order === false ? null : $order;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOrder(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, status, total_amount, expires_at, order_number
             FROM orders WHERE id = :id LIMIT 1',
        );
        $statement->execute([':id' => $orderId]);
        $order = $statement->fetch();

        return $order === false ? null : $order;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lockPaymentsForOrder(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_id, external_id, status, amount, currency, checkout_url, response_data
             FROM payments WHERE order_id = :order_id ORDER BY id FOR UPDATE',
        );
        $statement->execute([':order_id' => $orderId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findPaymentsForOrder(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_id, provider, external_id, status, amount, currency,
                    checkout_url, verified_at, response_data, created_at
             FROM payments WHERE order_id = :order_id ORDER BY id',
        );
        $statement->execute([':order_id' => $orderId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPaymentForOrder(int $orderId, int $paymentId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_id, provider, external_id, status, amount, currency,
                    checkout_url, verified_at, response_data, created_at
             FROM payments WHERE id = :payment_id AND order_id = :order_id LIMIT 1',
        );
        $statement->execute([
            ':payment_id' => $paymentId,
            ':order_id' => $orderId,
        ]);
        $payment = $statement->fetch();

        return $payment === false ? null : $payment;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalId(string $externalId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_id, provider, external_id, status, amount, currency,
                    checkout_url, verified_at, response_data, created_at
             FROM payments WHERE provider = :provider AND external_id = :external_id LIMIT 1',
        );
        $statement->execute([
            ':provider' => PaymentProvider::MERCADOPAGO->value,
            ':external_id' => $externalId,
        ]);
        $payment = $statement->fetch();

        return $payment === false ? null : $payment;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    public function createPayment(
        int $orderId,
        string $externalId,
        int $amount,
        string $currency,
        string $checkoutUrl,
        array $responseData,
    ): int {
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO payments
                    (order_id, provider, external_id, status, amount, currency,
                     checkout_url, response_data, created_at, updated_at)
                VALUES
                    (:order_id, :provider, :external_id, 'PENDING', :amount, :currency,
                     :checkout_url, :response_data, :created_at, :updated_at)
            SQL,
        );
        $statement->execute([
            ':order_id' => $orderId,
            ':provider' => PaymentProvider::MERCADOPAGO->value,
            ':external_id' => $externalId,
            ':amount' => $amount,
            ':currency' => $currency,
            ':checkout_url' => $checkoutUrl,
            ':response_data' => json_encode(
                $this->safeResponseData($responseData),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Persist the target status decided by orders-service.
     *
     * Idempotent: the same target is a no-op for the status and only refreshes
     * the audit evidence. A non-monotonic target is rejected without a write.
     *
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $raw
     */
    public function applyResult(
        int $paymentId,
        int $orderId,
        PaymentState $target,
        array $evidence,
        array $raw,
    ): void {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT id, status, response_data FROM payments
                 WHERE id = :payment_id AND order_id = :order_id LIMIT 1 FOR UPDATE',
            );
            $statement->execute([
                ':payment_id' => $paymentId,
                ':order_id' => $orderId,
            ]);
            $payment = $statement->fetch();

            if ($payment === false) {
                throw new NotFoundException();
            }

            $current = PaymentState::from((string) $payment['status']);

            if ($current !== $target && ! $current->canTransitionTo($target)) {
                throw PaymentConflictException::fromCode(
                    'INVALID_STATE_TRANSITION',
                    context: [
                        'entity' => 'payment',
                        'from' => $current->value,
                        'to' => $target->value,
                    ],
                );
            }

            $existing = $this->decode($payment['response_data']);
            $now = gmdate('Y-m-d H:i:s');

            $statement = $this->pdo->prepare(
                'UPDATE payments
                 SET status = :status, verified_at = :verified_at, response_data = :response_data,
                     updated_at = :updated_at
                 WHERE id = :payment_id',
            );
            $statement->execute([
                ':status' => $target->value,
                ':verified_at' => $now,
                ':response_data' => json_encode(
                    [
                        ...$existing,
                        ...$this->safeResponseData($raw),
                        'provider_status' => $target->value,
                        ...$evidence,
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
                ':updated_at' => $now,
                ':payment_id' => $paymentId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingOrUnknown(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, order_id, status FROM payments
             WHERE status IN ('PENDING', 'UNKNOWN')
             ORDER BY id LIMIT :limit",
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listApproved(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, order_id, status FROM payments
             WHERE status = 'APPROVED'
             ORDER BY id LIMIT :limit",
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Insert the event if absent. The unique key serializes duplicates.
     *
     * @param  array<string, mixed>  $payload
     */
    public function insertEventIgnore(string $providerEventId, string $eventType, array $payload): void
    {
        $now = gmdate('Y-m-d H:i:s');

        try {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                    INSERT IGNORE INTO payment_webhook_events
                        (provider, provider_event_id, event_type, payload, status, created_at, updated_at)
                    VALUES
                        (:provider, :provider_event_id, :event_type, :payload, 'RECEIVED', :created_at, :updated_at)
                SQL,
            );
            $statement->execute([
                ':provider' => PaymentProvider::MERCADOPAGO->value,
                ':provider_event_id' => $providerEventId,
                ':event_type' => $eventType,
                ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            // A duplicate key on a simultaneous delivery is expected; the
            // caller reads the existing row immediately afterwards.
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEvent(string $providerEventId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status, payload FROM payment_webhook_events
             WHERE provider = :provider AND provider_event_id = :provider_event_id LIMIT 1',
        );
        $statement->execute([
            ':provider' => PaymentProvider::MERCADOPAGO->value,
            ':provider_event_id' => $providerEventId,
        ]);
        $event = $statement->fetch();

        return $event === false ? null : $event;
    }

    public function resetFailedEvent(int $eventId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE payment_webhook_events
             SET status = 'RECEIVED', processed_at = NULL, updated_at = :updated_at
             WHERE id = :id AND status = 'FAILED'",
        );
        $statement->execute([
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $eventId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markEvent(
        int $eventId,
        WebhookStatus $status,
        array $extra = [],
        ?string $reason = null,
    ): void {
        $statement = $this->pdo->prepare(
            'SELECT payload FROM payment_webhook_events WHERE id = :id LIMIT 1',
        );
        $statement->execute([':id' => $eventId]);
        $payload = $this->decode($statement->fetchColumn());

        if ($reason !== null) {
            $extra['reason'] = $reason;
        }

        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'UPDATE payment_webhook_events
             SET status = :status, processed_at = :processed_at, payload = :payload, updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            ':status' => $status->value,
            ':processed_at' => $now,
            ':payload' => json_encode(
                [...$payload, ...$this->safeResponseData($extra)],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            ':updated_at' => $now,
            ':id' => $eventId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markEventPayloadOnly(int $eventId, array $extra): void
    {
        $statement = $this->pdo->prepare(
            'SELECT payload FROM payment_webhook_events WHERE id = :id LIMIT 1',
        );
        $statement->execute([':id' => $eventId]);
        $payload = $this->decode($statement->fetchColumn());

        $statement = $this->pdo->prepare(
            'UPDATE payment_webhook_events SET payload = :payload, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            ':payload' => json_encode(
                [...$payload, ...$this->safeResponseData($extra)],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $eventId,
        ]);
    }

    /**
     * Short named lock so two simultaneous deliveries do not both call the
     * provider and orders-service. It is an optimization guard, not the
     * idempotency mechanism (the unique key and the state machine are).
     */
    public function acquireWebhookLock(string $lockName, int $waitSeconds = 5): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, :wait)');
        $statement->bindValue(':name', $lockName);
        $statement->bindValue(':wait', max(0, $waitSeconds), PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn() === 1;
    }

    public function releaseWebhookLock(string $lockName): void
    {
        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $statement->execute([':name' => $lockName]);
        } catch (\Throwable) {
            // The connection may be gone; MySQL releases it automatically.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeResponseData(array $data): array
    {
        $safe = [];

        foreach (self::SAFE_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if ($value === null) {
                continue;
            }

            if (is_scalar($value)) {
                $safe[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $valid = true;

                foreach ($value as $item) {
                    if (! is_scalar($item)) {
                        $valid = false;
                        break;
                    }
                }

                if ($valid) {
                    $safe[$key] = $value;
                }
            }
        }

        return $safe;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
