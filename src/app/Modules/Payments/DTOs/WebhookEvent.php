<?php

namespace App\Modules\Payments\DTOs;

use App\Modules\Payments\Enums\PaymentState;

final readonly class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public ?string $externalId = null,
        public ?PaymentState $status = null,
        public array $payload = [],
    ) {}
}
