<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload  Sanitized notification metadata
     */
    public function __construct(
        public readonly string $providerEventId,
        public readonly string $eventType,
        public readonly ?string $externalId = null,
        public readonly ?PaymentStatus $payment = null,
        public readonly array $payload = [],
    ) {}
}
