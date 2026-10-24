<?php

namespace App\Modules\Payments\DTOs;

final readonly class CheckoutSession
{
    public function __construct(
        public string $externalId,
        public string $checkoutUrl,
    ) {}
}
