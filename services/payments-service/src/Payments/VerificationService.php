<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

/**
 * Server-side verification, manual resolution and reconciliation.
 *
 * The provider is always queried here; the browser redirect or a request body
 * never decides that a payment is approved. The resulting state machine runs in
 * orders-service through the signed internal command, then the target payment
 * status is persisted in the `payments` row owned by this service.
 */
final class VerificationService
{
    /** @var list<string> */
    private const VERIFIABLE_ORDER_STATES = ['PENDING_PAYMENT', 'PAYMENT_REVIEW', 'EXPIRED', 'REJECTED'];

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentGateway $gateway,
        private readonly OrdersClient $orders,
        private readonly bool $simulated,
    ) {}

    /**
     * @throws NotFoundException
     * @throws PaymentConflictException
     * @throws PaymentGatewayException
     * @throws PaymentGatewayTimeoutException
     * @throws OrdersUnavailableException
     */
    public function verify(int $userId, int $orderId, int $paymentId): void
    {
        $order = $this->loadOrder($orderId);

        if ((int) $order['user_id'] !== $userId) {
            throw new NotFoundException();
        }

        $payment = $this->loadPayment($orderId, $paymentId);

        if (! in_array((string) $order['status'], self::VERIFIABLE_ORDER_STATES, true)) {
            throw PaymentConflictException::orderNotVerifiable();
        }

        if ((string) $order['status'] === 'PAYMENT_REVIEW') {
            throw PaymentConflictException::reviewRequiresAdmin();
        }

        $this->verifyWithProvider($order, $payment, source: 'verify', resolveReview: false, actorRole: 'user');
    }

    /**
     * @throws NotFoundException
     * @throws PaymentConflictException
     * @throws PaymentGatewayException
     * @throws PaymentGatewayTimeoutException
     * @throws OrdersUnavailableException
     */
    public function resolve(int $orderId, int $paymentId): void
    {
        $order = $this->loadOrder($orderId);
        $payment = $this->loadPayment($orderId, $paymentId);

        if ((string) $order['status'] !== 'PAYMENT_REVIEW') {
            throw PaymentConflictException::fromCode(
                'ORDER_NOT_UNDER_REVIEW',
                'La orden no está en revisión de pago.',
            );
        }

        $this->verifyWithProvider($order, $payment, source: 'resolve', resolveReview: true, actorRole: 'admin');
    }

    /**
     * Re-query PENDING/UNKNOWN payments. Never creates a checkout or a charge.
     *
     * @throws OrdersUnavailableException
     */
    public function reconcile(int $limit, bool $dryRun): int
    {
        $rows = $this->payments->listPendingOrUnknown($limit);

        if ($dryRun) {
            return count($rows);
        }

        $reconciled = 0;

        foreach ($rows as $row) {
            $order = $this->payments->findOrder((int) $row['order_id']);

            if ($order === null) {
                continue;
            }

            $payment = $this->payments->findPaymentForOrder(
                (int) $row['order_id'],
                (int) $row['id'],
            );

            if ($payment === null) {
                continue;
            }

            $resolveReview = (string) $order['status'] === 'PAYMENT_REVIEW';

            try {
                $this->verifyWithProvider(
                    $order,
                    $payment,
                    source: 'reconcile',
                    resolveReview: $resolveReview,
                    actorRole: 'system',
                );
                $reconciled++;
            } catch (PaymentConflictException $exception) {
                error_log(sprintf(
                    "[payments-service] reconcile skipped payment %d: %s\n",
                    (int) $payment['id'],
                    $exception->errorCode,
                ));
            } catch (PaymentGatewayException) {
                error_log(sprintf(
                    "[payments-service] reconcile provider error for payment %d\n",
                    (int) $payment['id'],
                ));
            }
        }

        return $reconciled;
    }

    /**
     * Re-audit APPROVED payments for additional approved charges.
     *
     * @return array{inspected: int, incidents: int, errors: int}
     */
    public function reconcileApproved(int $limit, bool $dryRun): array
    {
        $rows = $this->payments->listApproved($limit);

        $result = [
            'inspected' => count($rows),
            'incidents' => 0,
            'errors' => 0,
        ];

        if ($dryRun) {
            return $result;
        }

        foreach ($rows as $row) {
            $order = $this->payments->findOrder((int) $row['order_id']);
            $payment = $this->payments->findPaymentForOrder(
                (int) $row['order_id'],
                (int) $row['id'],
            );

            if ($order === null || $payment === null) {
                $result['errors']++;

                continue;
            }

            try {
                $status = $this->gateway->verifyPayment((string) $payment['external_id']);
            } catch (PaymentGatewayException|PaymentGatewayTimeoutException|PaymentNotFoundException) {
                $result['errors']++;
                error_log(sprintf(
                    "[payments-service] approved audit could not verify payment %d\n",
                    (int) $payment['id'],
                ));

                continue;
            }

            $approvedCount = $status->raw['mp_approved_payment_count'] ?? null;

            if (! is_int($approvedCount) || $approvedCount < 1) {
                $result['errors']++;
                error_log(sprintf(
                    "[payments-service] approved audit returned an unconfirmed count for payment %d\n",
                    (int) $payment['id'],
                ));

                continue;
            }

            if ($approvedCount <= 1) {
                continue;
            }

            try {
                $decision = $this->sendCommand($payment, $status, 'reconcile', false, 'system');

                if (($decision['outcome'] ?? '') === 'applied') {
                    $result['incidents']++;
                }

                $this->persistDecision($payment, $status, $decision);
            } catch (PaymentConflictException|OrdersUnavailableException $exception) {
                $result['errors']++;
                error_log(sprintf(
                    "[payments-service] approved audit could not escalate payment %d\n",
                    (int) $payment['id'],
                ));
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payment
     *
     * @throws PaymentConflictException
     * @throws PaymentGatewayException
     * @throws PaymentGatewayTimeoutException
     * @throws OrdersUnavailableException
     */
    private function verifyWithProvider(
        array $order,
        array $payment,
        string $source,
        bool $resolveReview,
        string $actorRole,
    ): void {
        try {
            $status = $this->gateway->verifyPayment((string) $payment['external_id']);
        } catch (PaymentNotFoundException) {
            // The buyer has not completed checkout yet. Not a rejection and
            // not uncertain: leave order and numbers untouched.
            error_log(sprintf(
                "[payments-service] no provider payment yet for payment %d\n",
                (int) $payment['id'],
            ));

            return;
        } catch (PaymentGatewayTimeoutException) {
            $status = new PaymentStatus(
                (string) $payment['external_id'],
                PaymentState::UNKNOWN,
                (int) $payment['amount'],
                (string) $payment['currency'],
                [
                    'simulated' => $this->simulated,
                    'timeout' => true,
                ],
            );
        }

        $this->assertMatches($payment, $status);

        $decision = $this->sendCommand($payment, $status, $source, $resolveReview, $actorRole);

        $this->persistDecision($payment, $status, $decision);
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $decision
     */
    private function persistDecision(array $payment, PaymentStatus $status, array $decision): void
    {
        if (($decision['outcome'] ?? '') === 'ignored') {
            return;
        }

        $target = PaymentState::tryFrom((string) ($decision['payment_status'] ?? ''));

        if ($target === null) {
            throw new OrdersUnavailableException('orders-service returned an invalid payment status.');
        }

        $this->payments->applyResult(
            (int) $payment['id'],
            (int) $payment['order_id'],
            $target,
            is_array($decision['evidence'] ?? null) ? $decision['evidence'] : [],
            $status->raw,
        );
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private function sendCommand(
        array $payment,
        PaymentStatus $status,
        string $source,
        bool $resolveReview,
        string $actorRole,
    ): array {
        return $this->orders->applyPaymentResult([
            'payment_id' => (int) $payment['id'],
            'order_id' => (int) $payment['order_id'],
            'external_id' => $status->externalId,
            'amount' => $status->amount,
            'currency' => $status->currency,
            'status' => $status->status->value,
            'raw' => $status->raw,
            'source' => $source,
            'resolve_review' => $resolveReview,
            'actor_role' => $actorRole,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function assertMatches(array $payment, PaymentStatus $status): void
    {
        if (
            ! hash_equals((string) $payment['external_id'], $status->externalId)
            || (int) $payment['amount'] !== $status->amount
            || strtoupper((string) $payment['currency']) !== strtoupper($status->currency)
        ) {
            throw PaymentConflictException::verificationMismatch();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOrder(int $orderId): array
    {
        $order = $this->payments->findOrder($orderId);

        if ($order === null) {
            throw new NotFoundException();
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPayment(int $orderId, int $paymentId): array
    {
        $payment = $this->payments->findPaymentForOrder($orderId, $paymentId);

        if ($payment === null) {
            throw new NotFoundException();
        }

        return $payment;
    }
}
