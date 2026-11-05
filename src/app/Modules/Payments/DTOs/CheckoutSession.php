<?php

namespace App\Modules\Payments\DTOs;

final readonly class CheckoutSession
{
    /**
     * @param  array<string, mixed>  $raw  Safe, non-sensitive metadata to persist
     */
    public function __construct(
        public string $externalId,
        public string $checkoutUrl,
        public array $raw = [],
    ) {}
}
