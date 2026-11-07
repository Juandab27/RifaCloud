<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

/**
 * Port of App\Modules\Payments\Enums\PaymentState.
 *
 * The enum lives in orders-service because the payment result state machine
 * (and the order/number transitions it triggers) is owned by orders-service in
 * S4. payments-service keeps its own copy for its row and its own guards; the
 * two must stay in sync (see docs/sdd/02-technical-design.md section 7).
 */
enum PaymentState: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case UNKNOWN = 'UNKNOWN';
    case REFUND_REQUIRED = 'REFUND_REQUIRED';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::PENDING, self::APPROVED, self::REJECTED, self::UNKNOWN, self::REFUND_REQUIRED],
            self::UNKNOWN => [self::APPROVED, self::REJECTED, self::REFUND_REQUIRED],
            self::REJECTED => [self::REFUND_REQUIRED],
            self::APPROVED => [self::REFUND_REQUIRED],
            self::REFUND_REQUIRED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
