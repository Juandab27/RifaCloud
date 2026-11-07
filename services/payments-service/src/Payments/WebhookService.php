<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use Throwable;

/**
 * Idempotent Mercado Pago notification processing.
 *
 * Order of guarantees:
 *  1. The signature and the authoritative provider payment are validated by
 *     the gateway before anything is written.
 *  2. The event is persisted under UNIQUE(provider, provider_event_id), so a
 *     duplicate delivery cannot create a second audit row.
 *  3. A short named lock serializes simultaneous deliveries of the same event;
 *     a processed/ignored duplicate returns the stored outcome without calling
 *     orders-service again.
 *  4. orders-service applies the state machine idempotently, and this service
 *     persists only the payment status it decided.
 *  5. A failure after the business attempt rolls nothing back partially: the
 *     event is marked FAILED in an isolated write and the provider is asked to
 *     retry (503).
 */
final class WebhookService
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentGateway $gateway,
        private readonly OrdersClient $orders,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     *
     * @throws InvalidWebhookSignatureException
     * @throws InvalidWebhookPayloadException
     * @throws PaymentGatewayException
     * @throws PaymentGatewayTimeoutException
     * @throws PaymentNotFoundException
     */
    public function handle(array $payload, array $headers): WebhookStatus
    {
        $event = $this->gateway->parseWebhook($payload, $headers);

        if ($event->externalId === null || $event->externalId === '' || $event->payment === null) {
            return $this->recordIgnored(
                $event,
                $event->eventType === 'payment' ? 'incomplete_payment_notification' : 'unsupported_topic',
            );
        }

        $payment = $this->payments->findByExternalId($event->externalId);

        if ($payment === null) {
            return $this->recordIgnored($event, 'unknown_external_reference');
        }

        if (
            ! hash_equals((string) $payment['external_id'], $event->payment->externalId)
            || (int) $payment['amount'] !== $event->payment->amount
            || strtoupper((string) $payment['currency']) !== strtoupper($event->payment->currency)
        ) {
            error_log(sprintf(
                "[payments-service] webhook data mismatch for payment %d\n",
                (int) $payment['id'],
            ));

            return $this->recordIgnored($event, 'verification_mismatch');
        }

        $this->payments->insertEventIgnore($event->providerEventId, $event->eventType, $event->payload);
        $row = $this->payments->findEvent($event->providerEventId);

        if ($row === null) {
            throw new \RuntimeException('The webhook event could not be recorded.');
        }

        $eventId = (int) $row['id'];
        $lockName = 'payments_webhook_'.substr(hash('sha256', $event->providerEventId), 0, 40);
        $locked = $this->payments->acquireWebhookLock($lockName, 5);

        try {
            $current = $this->payments->findEvent($event->providerEventId);

            if ($current === null) {
                throw new \RuntimeException('The webhook event disappeared while processing.');
            }

            $status = WebhookStatus::from((string) $current['status']);

            if (in_array($status, [WebhookStatus::PROCESSED, WebhookStatus::IGNORED], true)) {
                return $status;
            }

            if ($status === WebhookStatus::FAILED) {
                $this->payments->resetFailedEvent($eventId);
            }

            $decision = $this->orders->applyPaymentResult([
                'payment_id' => (int) $payment['id'],
                'order_id' => (int) $payment['order_id'],
                'external_id' => $event->payment->externalId,
                'amount' => $event->payment->amount,
                'currency' => $event->payment->currency,
                'status' => $event->payment->status->value,
                'raw' => $event->payment->raw,
                'source' => 'webhook',
                'resolve_review' => false,
                'actor_role' => 'system',
            ]);

            if (($decision['outcome'] ?? '') === 'ignored') {
                $this->payments->markEvent(
                    $eventId,
                    WebhookStatus::IGNORED,
                    [],
                    (string) ($decision['reason'] ?? 'stale_event'),
                );

                return WebhookStatus::IGNORED;
            }

            $target = PaymentState::tryFrom((string) ($decision['payment_status'] ?? ''));

            if ($target === null) {
                throw new \RuntimeException('orders-service returned an invalid payment status.');
            }

            $this->payments->applyResult(
                (int) $payment['id'],
                (int) $payment['order_id'],
                $target,
                is_array($decision['evidence'] ?? null) ? $decision['evidence'] : [],
                $event->payment->raw,
            );

            $this->payments->markEvent(
                $eventId,
                WebhookStatus::PROCESSED,
                [
                    ...(is_array($decision['evidence'] ?? null) ? $decision['evidence'] : []),
                    'result_status' => $target->value,
                ],
            );

            return WebhookStatus::PROCESSED;
        } catch (PaymentConflictException $exception) {
            error_log(sprintf(
                "[payments-service] webhook ignored for payment %d: %s\n",
                (int) $payment['id'],
                $exception->errorCode,
            ));

            $this->payments->markEvent($eventId, WebhookStatus::IGNORED, [], $exception->errorCode);

            return WebhookStatus::IGNORED;
        } catch (Throwable $exception) {
            $this->markFailed($eventId, $exception);

            throw $exception;
        } finally {
            if ($locked) {
                $this->payments->releaseWebhookLock($lockName);
            }
        }
    }

    private function recordIgnored(WebhookEvent $event, string $reason): WebhookStatus
    {
        $this->payments->insertEventIgnore($event->providerEventId, $event->eventType, $event->payload);
        $row = $this->payments->findEvent($event->providerEventId);

        if ($row === null) {
            throw new \RuntimeException('The webhook event could not be recorded.');
        }

        $status = WebhookStatus::from((string) $row['status']);

        if (in_array($status, [WebhookStatus::PROCESSED, WebhookStatus::IGNORED], true)) {
            return $status;
        }

        $this->payments->markEvent((int) $row['id'], WebhookStatus::IGNORED, [], $reason);

        return WebhookStatus::IGNORED;
    }

    private function markFailed(int $eventId, Throwable $exception): void
    {
        try {
            $this->payments->markEvent(
                $eventId,
                WebhookStatus::FAILED,
                ['failure' => (new \ReflectionClass($exception))->getShortName()],
                'processing_failed',
            );
        } catch (Throwable $recordingFailure) {
            error_log(sprintf(
                "[payments-service] webhook failure could not be recorded: %s\n",
                (new \ReflectionClass($recordingFailure))->getShortName(),
            ));
        }
    }
}
