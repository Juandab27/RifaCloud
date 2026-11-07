<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

/**
 * Port of App\Modules\Payments\Enums\PaymentState.
 *
 * payments-service uses it for its own row and for the monotonic guard applied
 * to the status returned by orders-service. orders-service keeps the
 * authoritative decision table; both copies must stay in sync.
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

    public function blocksNewCheckout(): bool
    {
        return $this !== self::PENDING;
    }

    public function blocksReservationRelease(): bool
    {
        return match ($this) {
            self::UNKNOWN, self::APPROVED, self::REFUND_REQUIRED => true,
            self::PENDING, self::REJECTED => false,
        };
    }
}
