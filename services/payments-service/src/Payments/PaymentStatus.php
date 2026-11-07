<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

final class PaymentStatus
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $externalId,
        public readonly PaymentState $status,
        public readonly int $amount,
        public readonly string $currency,
        public readonly array $raw = [],
    ) {}
}
