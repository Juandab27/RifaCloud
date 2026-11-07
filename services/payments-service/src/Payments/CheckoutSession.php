<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

final class CheckoutSession
{
    /**
     * @param  array<string, mixed>  $raw  Safe, non-sensitive metadata to persist
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $checkoutUrl,
        public readonly array $raw = [],
    ) {}
}
