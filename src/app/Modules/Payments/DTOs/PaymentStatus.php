<?php

namespace App\Modules\Payments\DTOs;

use App\Modules\Payments\Enums\PaymentState;

final readonly class PaymentStatus
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $externalId,
        public PaymentState $status,
        public int $amount,
        public string $currency,
        public array $raw = [],
    ) {}
}
