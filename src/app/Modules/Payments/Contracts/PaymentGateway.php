<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\DTOs\CheckoutSession;
use App\Modules\Payments\DTOs\PaymentStatus;
use App\Modules\Payments\DTOs\WebhookEvent;

/**
 * Provider-agnostic payment contract (SDD 02-technical-design.md, section 7).
 *
 * Phase 1 defines the contract only. The simulated driver is implemented in
 * Phase 2; Mercado Pago is the first and only real provider attempted (Q-05).
 */
interface PaymentGateway
{
    public function createCheckout(Order $order): CheckoutSession;

    public function verifyPayment(string $externalId): PaymentStatus;

    /**
     * Validate the provider signature and return an authoritative event.
     *
     * The payload and headers are never trusted by themselves: drivers must
     * obtain the payment state from the provider API before returning a
     * PaymentStatus.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers  Lower-cased header names
     */
    public function parseWebhook(array $payload, array $headers = []): WebhookEvent;
}
