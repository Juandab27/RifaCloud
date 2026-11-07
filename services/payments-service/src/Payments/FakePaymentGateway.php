<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

/**
 * Deterministic local gateway used for P1 tests and for the S4 E2E run.
 *
 * Port of App\Modules\Payments\Gateways\FakePaymentGateway. Unlike the
 * monolith version, the fake driver also accepts simulated notifications so
 * the webhook idempotency path can be exercised end-to-end without sandbox
 * credentials or a public URL. It is only reachable when PAYMENT_GATEWAY=fake,
 * which is refused when APP_ENV=production.
 */
final class FakePaymentGateway implements PaymentGateway
{
    private const EXTERNAL_ID_PATTERN = '/^SIM-([A-Z]{3})-(\d+)-[A-Z0-9]+$/';

    public function __construct(
        private readonly string $outcome,
        private readonly string $appUrl,
    ) {}

    /**
     * @param  array{total_amount: int}  $order
     */
    public function createCheckout(array $order): CheckoutSession
    {
        $externalId = sprintf(
            'SIM-COP-%d-%s',
            $order['total_amount'],
            strtoupper(bin2hex(random_bytes(13))),
        );

        return new CheckoutSession(
            $externalId,
            rtrim($this->appUrl, '/').'/checkout/simulated/'.$externalId,
            [
                'simulated' => true,
                'mode' => $this->outcome,
            ],
        );
    }

    public function verifyPayment(string $externalId): PaymentStatus
    {
        $simulated = $this->simulatedStatus($externalId);

        if ($simulated->status === PaymentState::UNKNOWN && $this->outcome === 'TIMEOUT') {
            throw new PaymentGatewayTimeoutException('Simulated payment provider timeout.');
        }

        return $simulated;
    }

    /**
     * Simulated notification: {"type":"payment","data":{"id":"SIM-COP-..."},"status":"APPROVED"}.
     *
     * No signature is required because the driver itself is the test double;
     * `parseWebhook` validates the shape and derives the event from the
     * external id, never from a browser-supplied amount.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function parseWebhook(array $payload, array $headers = []): WebhookEvent
    {
        $type = strtolower(trim((string) ($payload['type'] ?? $payload['topic'] ?? '')));

        if ($type === '') {
            throw new InvalidWebhookPayloadException('Notification type is missing.');
        }

        if (! in_array($type, ['payment', 'topic_payment_wh'], true)) {
            $dataId = is_array($payload['data'] ?? null) ? (string) ($payload['data']['id'] ?? '') : '';

            return new WebhookEvent(
                'topic:'.hash('sha256', $type.'|'.$dataId),
                mb_substr($type, 0, 100),
                null,
                null,
                ['type' => $type, 'data_id' => $dataId],
            );
        }

        $data = $payload['data'] ?? null;
        $externalId = is_array($data) ? trim((string) ($data['id'] ?? '')) : '';

        if ($externalId === '') {
            throw new InvalidWebhookPayloadException('Notification data.id is missing.');
        }

        if (preg_match(self::EXTERNAL_ID_PATTERN, $externalId) !== 1) {
            throw new InvalidWebhookPayloadException('Notification data.id is not a simulated payment.');
        }

        $requested = strtoupper(trim((string) ($payload['status'] ?? '')));
        $status = $requested !== '' ? $requested : $this->outcome;

        if ($status === 'TIMEOUT') {
            throw new PaymentGatewayTimeoutException('Simulated notification timeout.');
        }

        $payment = $this->simulatedStatus($externalId, $status);

        return new WebhookEvent(
            hash('sha256', 'fake|'.$externalId.'|'.$payment->status->value),
            'payment',
            $externalId,
            $payment,
            [
                'type' => 'payment',
                'data_id' => $externalId,
                'simulated' => true,
                'provider_status' => $payment->status->value,
            ],
        );
    }

    private function simulatedStatus(string $externalId, ?string $override = null): PaymentStatus
    {
        if (preg_match(self::EXTERNAL_ID_PATTERN, $externalId, $matches) !== 1) {
            throw new PaymentNotFoundException('Invalid simulated payment external ID.');
        }

        $mode = strtoupper($override ?? $this->outcome);

        return new PaymentStatus(
            $externalId,
            match ($mode) {
                'APPROVED' => PaymentState::APPROVED,
                'REJECTED' => PaymentState::REJECTED,
                'REFUND_REQUIRED' => PaymentState::REFUND_REQUIRED,
                'PENDING' => PaymentState::PENDING,
                default => PaymentState::UNKNOWN,
            },
            (int) $matches[2],
            $matches[1],
            [
                'simulated' => true,
                'mode' => $mode,
                'mp_approved_payment_count' => $mode === 'APPROVED' ? 1 : null,
            ],
        );
    }
}
