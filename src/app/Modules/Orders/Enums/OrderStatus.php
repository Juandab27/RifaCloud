<?php

namespace App\Modules\Orders\Enums;

enum OrderStatus: string
{
    case PENDING_PAYMENT = 'PENDING_PAYMENT';
    case PAID = 'PAID';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';
    case PAYMENT_REVIEW = 'PAYMENT_REVIEW';
    case REQUIRES_REFUND = 'REQUIRES_REFUND';

    /**
     * Explicit order state machine for payment results (03-data-model.md §5).
     *
     * PAID was terminal in Block B. A later provider refund, chargeback or
     * mediation must still be recorded without faking a sale reversal, so PAID
     * may move to REQUIRES_REFUND; the numbers remain SOLD and an administrator
     * resolves the incident. EXPIRED and REJECTED are terminal for sales, but a
     * late confirmed payment may move either of them to REQUIRES_REFUND so the
     * approval is reconciled manually without faking a sale. Uncertain payments
     * can only move to a verified final state.
     *
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

    /**
     * Statuses whose reserved numbers must never be released by the expiry job
     * (03-data-model.md §5, 07-resilience-and-failure-handling.md §2.8).
     */
    public function blocksReservationRelease(): bool
    {
        return match ($this) {
            self::PAID, self::PAYMENT_REVIEW, self::REQUIRES_REFUND => true,
            self::PENDING_PAYMENT, self::REJECTED, self::EXPIRED => false,
        };
    }
}
