<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Applies a provider payment result to an order, its items and its numbers.
 *
 * S4 moved this decision here because orders-service owns `orders`,
 * `order_items` and the sale/release transitions of `raffle_numbers`.
 * payments-service performs the provider call, owns the `payments` row and
 * sends this command with the authoritative result. The payment row is locked
 * (never written) for the duration of the transaction, so payments-service
 * cannot update it concurrently and the decision is based on a consistent
 * state.
 *
 * Idempotency: the command is state-based. Replaying the same result converges
 * because equal payment/order statuses are no-ops, numbers only move
 * RESERVED -> SOLD/AVAILABLE once, `paid_at` is only set on the transition to
 * PAID, and the payment transition table is monotonic. A conflicting or stale
 * replay returns `ignored`/`noop` without a second side effect.
 *
 * Lock order: orders -> payments -> raffle_numbers, the same order used by
 * reservation expiry and checkout creation, which avoids deadlocks.
 */
final class PaymentResultApplier
{
    /** @var list<string> */
    public const SOURCES = ['verify', 'webhook', 'resolve', 'reconcile'];

    /** @var list<string> */
    private const VERIFIABLE_ORDER_STATES = ['PENDING_PAYMENT', 'PAYMENT_REVIEW', 'EXPIRED', 'REJECTED'];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param  array<string, mixed>  $command
     * @return array{
     *     outcome: string,
     *     reason: string|null,
     *     payment_status: string,
     *     order_status: string,
     *     evidence: array<string, mixed>,
     *     payment_changed: bool,
     *     order_changed: bool
     * }
     *
     * @throws ValidationException
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws PaymentConflictException
     */
    public function apply(array $command): array
    {
        $result = $this->validate($command);

        $this->pdo->beginTransaction();

        try {
            $order = $this->lockOrder($result['order_id']);
            $payment = $this->lockPayment($result['payment_id'], $result['order_id']);

            $this->assertMatches($payment, $result);
            $this->assertEligible($order, $result);

            $forced = $this->staleOutcome($result, $payment);

            if ($forced === 'ignored') {
                $this->pdo->commit();

                return [
                    'outcome' => 'ignored',
                    'reason' => 'stale_event',
                    'payment_status' => (string) $payment['status'],
                    'order_status' => (string) $order['status'],
                    'evidence' => [],
                    'payment_changed' => false,
                    'order_changed' => false,
                ];
            }

            $decision = $this->decide($order, $payment, $result);

            $paymentChanged = $decision['payment_status'] !== (string) $payment['status'];
            $orderChanged = $decision['order_status'] !== (string) $order['status'];

            $this->applyDecision($order, $payment, $decision);

            $this->pdo->commit();

            return [
                'outcome' => $paymentChanged || $orderChanged ? 'applied' : 'noop',
                'reason' => null,
                'payment_status' => $decision['payment_status'],
                'order_status' => $decision['order_status'],
                'evidence' => $decision['evidence'],
                'payment_changed' => $paymentChanged,
                'order_changed' => $orderChanged,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array{
     *     order_id: int,
     *     payment_id: int,
     *     external_id: string,
     *     amount: int,
     *     currency: string,
     *     status: PaymentState,
     *     raw: array<string, mixed>,
     *     source: string,
     *     resolve_review: bool,
     *     actor_role: string
     * }
     */
    private function validate(array $command): array
    {
        $errors = [];

        $orderId = $command['order_id'] ?? null;
        $paymentId = $command['payment_id'] ?? null;
        $externalId = $command['external_id'] ?? null;
        $amount = $command['amount'] ?? null;
        $currency = $command['currency'] ?? null;
        $status = $command['status'] ?? null;
        $source = $command['source'] ?? null;
        $actorRole = $command['actor_role'] ?? 'system';
        $raw = $command['raw'] ?? [];

        if (! is_int($orderId) && ! (is_string($orderId) && ctype_digit($orderId))) {
            $errors['order_id'][] = 'The order id field is required.';
        } elseif ((int) $orderId <= 0) {
            $errors['order_id'][] = 'The order id field must be a positive integer.';
        }

        if (! is_int($paymentId) && ! (is_string($paymentId) && ctype_digit($paymentId))) {
            $errors['payment_id'][] = 'The payment id field is required.';
        } elseif ((int) $paymentId <= 0) {
            $errors['payment_id'][] = 'The payment id field must be a positive integer.';
        }

        if (! is_string($externalId) || trim($externalId) === '' || strlen($externalId) > 255) {
            $errors['external_id'][] = 'The external id field is required.';
        }

        if (! is_int($amount) && ! (is_string($amount) && preg_match('/^[0-9]+$/', $amount) === 1)) {
            $errors['amount'][] = 'The amount field must be an integer.';
        } elseif ((int) $amount < 0) {
            $errors['amount'][] = 'The amount field must be at least 0.';
        }

        if (! is_string($currency) || preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
            $errors['currency'][] = 'The currency field must be a three-letter code.';
        }

        if (! is_string($status) || PaymentState::tryFrom($status) === null) {
            $errors['status'][] = 'The status field is invalid.';
        }

        if (! is_string($source) || ! in_array($source, self::SOURCES, true)) {
            $errors['source'][] = 'The source field is invalid.';
        }

        if (! is_string($actorRole) || ! in_array($actorRole, ['user', 'admin', 'system'], true)) {
            $errors['actor_role'][] = 'The actor role field is invalid.';
        }

        if (! is_array($raw)) {
            $errors['raw'][] = 'The raw field must be an object.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'order_id' => (int) $orderId,
            'payment_id' => (int) $paymentId,
            'external_id' => (string) $externalId,
            'amount' => (int) $amount,
            'currency' => strtoupper((string) $currency),
            'status' => PaymentState::from((string) $status),
            'raw' => $raw,
            'source' => (string) $source,
            'resolve_review' => (bool) ($command['resolve_review'] ?? false),
            'actor_role' => (string) $actorRole,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lockOrder(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, status, total_amount, expires_at, paid_at
             FROM orders
             WHERE id = :id
             LIMIT 1
             FOR UPDATE',
        );
        $statement->execute([':id' => $orderId]);
        $order = $statement->fetch();

        if ($order === false) {
            throw new NotFoundException();
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function lockPayment(int $paymentId, int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_id, external_id, status, amount, currency
             FROM payments
             WHERE id = :payment_id AND order_id = :order_id
             LIMIT 1
             FOR UPDATE',
        );
        $statement->execute([
            ':payment_id' => $paymentId,
            ':order_id' => $orderId,
        ]);
        $payment = $statement->fetch();

        if ($payment === false) {
            throw new NotFoundException();
        }

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $result
     */
    private function assertMatches(array $payment, array $result): void
    {
        if (
            ! hash_equals((string) $payment['external_id'], $result['external_id'])
            || (int) $payment['amount'] !== $result['amount']
            || strtoupper((string) $payment['currency']) !== $result['currency']
        ) {
            throw PaymentConflictException::verificationMismatch();
        }
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $result
     */
    private function assertEligible(array $order, array $result): void
    {
        $status = (string) $order['status'];

        // Webhooks do not choose an order state: the state machine and the
        // stale guards decide, exactly like the monolith. A refund after
        // approval must reach PAID -> REQUIRES_REFUND even though PAID is not
        // in the "verifiable" list used by the browser-initiated verify.
        if ($result['source'] === 'webhook') {
            return;
        }

        if ($result['resolve_review']) {
            if (! in_array($result['actor_role'], ['admin', 'system'], true)) {
                throw new ForbiddenException('Administrator role required.');
            }

            if ($status !== 'PAYMENT_REVIEW') {
                throw PaymentConflictException::orderNotUnderReview();
            }

            return;
        }

        // Approved-payment audit (S4): an order that is already PAID can still
        // escalate to REQUIRES_REFUND when the provider confirms more than one
        // approved charge. Numbers are never released or reassigned.
        if (
            $result['source'] === 'reconcile'
            && $result['status'] === PaymentState::APPROVED
            && (int) ($result['raw']['mp_approved_payment_count'] ?? 0) > 1
        ) {
            $canEscalate = OrderStatus::from($status)->canTransitionTo(OrderStatus::REQUIRES_REFUND)
                || $status === OrderStatus::REQUIRES_REFUND->value;

            if (! $canEscalate) {
                throw PaymentConflictException::orderNotVerifiable();
            }

            return;
        }

        if (! in_array($status, self::VERIFIABLE_ORDER_STATES, true)) {
            throw PaymentConflictException::orderNotVerifiable();
        }

        // The owner can never resolve an incident even if the order moved to
        // review while the provider call was in flight.
        if ($status === 'PAYMENT_REVIEW') {
            throw PaymentConflictException::reviewRequiresAdmin();
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $payment
     */
    private function staleOutcome(array $result, array $payment): ?string
    {
        if ($result['source'] !== 'webhook') {
            return null;
        }

        $current = PaymentState::from((string) $payment['status']);

        if ($result['status'] === PaymentState::UNKNOWN && $current === PaymentState::APPROVED) {
            // A mediation after approval is a refund incident, not uncertainty.
            if (($result['raw']['mp_status'] ?? null) === 'in_mediation') {
                return null;
            }

            return 'ignored';
        }

        if ($result['status'] === PaymentState::PENDING && $current !== PaymentState::PENDING) {
            return 'ignored';
        }

        if (
            $result['status'] === PaymentState::REJECTED
            && in_array($current, [PaymentState::APPROVED, PaymentState::REFUND_REQUIRED], true)
        ) {
            return 'ignored';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $result
     * @return array{payment_status: string, order_status: string, evidence: array<string, mixed>, numbers: list<array<string, mixed>>|null}
     */
    private function decide(array $order, array $payment, array $result): array
    {
        $currentPayment = PaymentState::from((string) $payment['status']);
        $currentOrder = OrderStatus::from((string) $order['status']);
        $targetPayment = $result['status'];
        $evidence = [];

        $reservationExpired = $currentOrder === OrderStatus::EXPIRED
            || ($currentOrder === OrderStatus::PENDING_PAYMENT
                && $order['expires_at'] !== null
                && $this->parseDate((string) $order['expires_at']) <= $this->utcNow());

        $lateApproval = $reservationExpired || $currentOrder === OrderStatus::REJECTED;

        if ($targetPayment === PaymentState::PENDING) {
            $this->assertPaymentTransition($currentPayment, PaymentState::PENDING);

            return [
                'payment_status' => PaymentState::PENDING->value,
                'order_status' => $currentOrder->value,
                'evidence' => [],
                'numbers' => null,
            ];
        }

        $numbers = null;

        if ($targetPayment === PaymentState::UNKNOWN && $currentPayment === PaymentState::APPROVED) {
            $targetPayment = PaymentState::REFUND_REQUIRED;
            $evidence = ['mediation_after_approval' => true];
        }

        if ($targetPayment === PaymentState::APPROVED) {
            $numbers = $this->lockOrderNumbers((int) $order['id']);

            $approvedCountUnconfirmed = ($result['raw']['mp_approved_payment_count_unconfirmed'] ?? false) === true;
            $approvedCount = (int) ($result['raw']['mp_approved_payment_count'] ?? 1);

            if ($approvedCountUnconfirmed) {
                $targetPayment = PaymentState::REFUND_REQUIRED;
                $targetOrder = OrderStatus::REQUIRES_REFUND;
                $evidence = [
                    'provider_approved' => true,
                    'approved_payment_count_unconfirmed' => true,
                ];

                if ($lateApproval) {
                    $evidence['late_approval'] = true;
                }
            } elseif ($approvedCount > 1) {
                $targetPayment = PaymentState::REFUND_REQUIRED;
                $targetOrder = OrderStatus::REQUIRES_REFUND;
                $evidence = [
                    'provider_approved' => true,
                    'multiple_approved_payments' => true,
                    'approved_payment_count' => $approvedCount,
                ];

                if ($lateApproval) {
                    $evidence['late_approval'] = true;
                }
            } elseif ($lateApproval) {
                $targetPayment = PaymentState::REFUND_REQUIRED;
                $targetOrder = OrderStatus::REQUIRES_REFUND;
                $evidence = [
                    'provider_approved' => true,
                    'late_approval' => true,
                ];
            } elseif ($this->numbersCanBeAssigned((int) $order['id'], $numbers)) {
                $targetOrder = OrderStatus::PAID;
            } else {
                $targetPayment = PaymentState::REFUND_REQUIRED;
                $targetOrder = OrderStatus::REQUIRES_REFUND;
                $evidence = [
                    'provider_approved' => true,
                    'numbers_unavailable' => true,
                ];
            }
        } elseif ($targetPayment === PaymentState::REJECTED) {
            $numbers = $this->lockOrderNumbers((int) $order['id']);

            foreach ($numbers as $number) {
                if ((string) $number['status'] === 'SOLD') {
                    throw PaymentConflictException::numbersNotReleasable();
                }
            }

            $targetOrder = $reservationExpired ? OrderStatus::EXPIRED : OrderStatus::REJECTED;
        } elseif ($targetPayment === PaymentState::UNKNOWN) {
            $targetOrder = $reservationExpired ? OrderStatus::EXPIRED : OrderStatus::PAYMENT_REVIEW;
        } else {
            $targetOrder = OrderStatus::REQUIRES_REFUND;
            $providerRefundStatus = (string) ($result['raw']['mp_status'] ?? '');

            if (in_array($providerRefundStatus, ['refunded', 'charged_back', 'in_mediation'], true)) {
                $evidence = [
                    ...$evidence,
                    'refund_incident' => true,
                    'provider_refund_status' => $providerRefundStatus,
                ];
            }
        }

        $this->assertPaymentTransition($currentPayment, $targetPayment);
        $this->assertOrderTransition($currentOrder, $targetOrder);

        return [
            'payment_status' => $targetPayment->value,
            'order_status' => $targetOrder->value,
            'evidence' => $evidence,
            'numbers' => $numbers,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payment
     * @param  array{payment_status: string, order_status: string, evidence: array<string, mixed>, numbers: list<array<string, mixed>>|null}  $decision
     */
    private function applyDecision(array $order, array $payment, array $decision): void
    {
        $now = $this->databaseNow();
        $currentOrder = (string) $order['status'];
        $targetOrder = $decision['order_status'];

        if ($targetOrder === OrderStatus::PAID->value && $currentOrder !== OrderStatus::PAID->value) {
            $this->sellNumbers((int) $order['id'], $decision['numbers'], $now);
        }

        if (
            $decision['payment_status'] === PaymentState::REJECTED->value
            && $decision['numbers'] !== null
        ) {
            $this->releaseNumbers((int) $order['id'], $decision['numbers'], $now);
        }

        if ($currentOrder === $targetOrder) {
            return;
        }

        $attributes = ['status = :status', 'updated_at = :updated_at'];
        $parameters = [
            ':status' => $targetOrder,
            ':updated_at' => $now,
            ':id' => (int) $order['id'],
        ];

        if ($targetOrder === OrderStatus::PAID->value && $order['paid_at'] === null) {
            $attributes[] = 'paid_at = :paid_at';
            $parameters[':paid_at'] = $now;
        }

        $statement = $this->pdo->prepare(
            'UPDATE orders SET '.implode(', ', $attributes).' WHERE id = :id',
        );
        $statement->execute($parameters);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lockOrderNumbers(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, number, status FROM raffle_numbers WHERE order_id = :order_id ORDER BY number FOR UPDATE',
        );
        $statement->execute([':order_id' => $orderId]);

        return $statement->fetchAll();
    }

    /**
     * @param  list<array<string, mixed>>  $numbers
     */
    private function numbersCanBeAssigned(int $orderId, array $numbers): bool
    {
        if ($numbers === []) {
            return false;
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id');
        $count->execute([':order_id' => $orderId]);

        if (count($numbers) !== (int) $count->fetchColumn()) {
            return false;
        }

        $allReserved = true;
        $allSold = true;

        foreach ($numbers as $number) {
            $status = (string) $number['status'];

            if (! in_array($status, ['RESERVED', 'SOLD'], true)) {
                return false;
            }

            $allReserved = $allReserved && $status === 'RESERVED';
            $allSold = $allSold && $status === 'SOLD';
        }

        return $allReserved || $allSold;
    }

    /**
     * @param  list<array<string, mixed>>|null  $numbers
     */
    private function sellNumbers(int $orderId, ?array $numbers, string $now): void
    {
        if ($numbers === null) {
            throw PaymentConflictException::numbersUpdateFailed();
        }

        $ids = [];

        foreach ($numbers as $number) {
            if ((string) $number['status'] === 'RESERVED') {
                $ids[] = (int) $number['id'];
            }
        }

        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $statement = $this->pdo->prepare(
            "UPDATE raffle_numbers
             SET status = 'SOLD', sold_at = ?, reserved_until = NULL, updated_at = ?
             WHERE id IN ({$placeholders}) AND order_id = ? AND status = 'RESERVED'",
        );
        $statement->execute([$now, $now, ...$ids, $orderId]);

        if ($statement->rowCount() !== count($ids)) {
            throw PaymentConflictException::numbersUpdateFailed();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $numbers
     */
    private function releaseNumbers(int $orderId, array $numbers, string $now): void
    {
        $ids = [];

        foreach ($numbers as $number) {
            if ((string) $number['status'] === 'RESERVED') {
                $ids[] = (int) $number['id'];
            }
        }

        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $statement = $this->pdo->prepare(
            "UPDATE raffle_numbers
             SET status = 'AVAILABLE', reserved_at = NULL, reserved_until = NULL,
                 sold_at = NULL, order_id = NULL, updated_at = ?
             WHERE id IN ({$placeholders}) AND order_id = ? AND status = 'RESERVED'",
        );
        $statement->execute([$now, ...$ids, $orderId]);

        if ($statement->rowCount() !== count($ids)) {
            throw PaymentConflictException::numbersUpdateFailed();
        }
    }

    private function assertPaymentTransition(PaymentState $from, PaymentState $to): void
    {
        if ($from === $to) {
            return;
        }

        if (! $from->canTransitionTo($to)) {
            throw PaymentConflictException::invalidTransition('payment', $from->value, $to->value);
        }
    }

    private function assertOrderTransition(OrderStatus $from, OrderStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        if (! $from->canTransitionTo($to)) {
            throw PaymentConflictException::invalidTransition('order', $from->value, $to->value);
        }
    }

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function databaseNow(): string
    {
        return $this->utcNow()->format('Y-m-d H:i:s');
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
