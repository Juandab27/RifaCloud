<?php

namespace App\Modules\Payments\DTOs;

final readonly class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload  Sanitized notification metadata
     */
    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public ?string $externalId = null,
        public ?PaymentStatus $payment = null,
        public array $payload = [],
    ) {}
}
