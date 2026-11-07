<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Creates the checkout for an order at most once.
 *
 * Only the `payments` row is written. The order is locked (read-only) so a
 * concurrent checkout for the same order serializes, and its status/expiry are
 * read from the authoritative row. The provider call happens inside the
 * transaction, exactly like the monolith: if the provider rejects or times out,
 * no payment row is created and no charge exists.
 */
final class CheckoutService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PaymentRepository $payments,
        private readonly OrderProjection $projection,
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * @return array{order_id: int, created: bool}
     *
     * @throws NotFoundException
     * @throws PaymentConflictException
     * @throws PaymentGatewayException
     * @throws PaymentGatewayTimeoutException
     */
    public function create(int $userId, int $orderId): array
    {
        $this->pdo->beginTransaction();

        try {
            $order = $this->payments->lockOrder($orderId);

            if ($order === null || (int) $order['user_id'] !== $userId) {
                throw new NotFoundException();
            }

            $payments = $this->payments->lockPaymentsForOrder($orderId);

            foreach ($payments as $payment) {
                $state = PaymentState::from((string) $payment['status']);

                if (! $state->blocksNewCheckout()) {
                    continue;
                }

                throw $state === PaymentState::UNKNOWN
                    ? PaymentConflictException::paymentUncertain()
                    : PaymentConflictException::paymentAlreadyFinal($state);
            }

            if ((string) $order['status'] !== 'PENDING_PAYMENT') {
                throw PaymentConflictException::orderNotPayable();
            }

            if ($order['expires_at'] !== null
                && $this->parseDate((string) $order['expires_at']) <= $this->utcNow()) {
                throw PaymentConflictException::orderExpired();
            }

            foreach ($payments as $payment) {
                if (PaymentState::from((string) $payment['status']) === PaymentState::PENDING) {
                    $this->pdo->commit();

                    return ['order_id' => $orderId, 'created' => false];
                }
            }

            $checkoutOrder = $this->projection->forGateway($orderId);

            if ($checkoutOrder === null) {
                throw new NotFoundException();
            }

            $session = $this->gateway->createCheckout($checkoutOrder);

            $this->payments->createPayment(
                $orderId,
                $session->externalId,
                (int) $order['total_amount'],
                'COP',
                $session->checkoutUrl,
                [
                    'simulated' => $this->gateway instanceof FakePaymentGateway,
                    ...$session->raw,
                ],
            );

            $this->pdo->commit();

            return ['order_id' => $orderId, 'created' => true];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
