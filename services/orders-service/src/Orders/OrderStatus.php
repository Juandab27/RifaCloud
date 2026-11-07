<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

/**
 * Port of App\Modules\Orders\Enums\OrderStatus (state machine only).
 */
enum OrderStatus: string
{
    case PENDING_PAYMENT = 'PENDING_PAYMENT';
    case PAID = 'PAID';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';
    case PAYMENT_REVIEW = 'PAYMENT_REVIEW';
    case REQUIRES_REFUND = 'REQUIRES_REFUND';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING_PAYMENT => [
                self::PAID,
                self::REJECTED,
                self::EXPIRED,
                self::PAYMENT_REVIEW,
                self::REQUIRES_REFUND,
            ],
            self::PAYMENT_REVIEW => [
                self::PAID,
                self::REJECTED,
                self::REQUIRES_REFUND,
            ],
            self::EXPIRED, self::REJECTED => [self::REQUIRES_REFUND],
            self::PAID => [self::REQUIRES_REFUND],
            self::REQUIRES_REFUND => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
