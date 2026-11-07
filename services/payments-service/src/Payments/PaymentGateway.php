<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

/**
 * Provider-agnostic payment contract (SDD 02-technical-design.md section 7).
 *
 * The notification body is never trusted: implementations must obtain the
 * payment state from the provider API before returning a PaymentStatus.
 */
interface PaymentGateway
{
    /**
     * @param  array{id: int, order_number: string, total_amount: int, raffle_title: string|null}  $order
     */
    public function createCheckout(array $order): CheckoutSession;

    public function verifyPayment(string $externalId): PaymentStatus;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers  Lower-cased header names
     */
    public function parseWebhook(array $payload, array $headers = []): WebhookEvent;
}
