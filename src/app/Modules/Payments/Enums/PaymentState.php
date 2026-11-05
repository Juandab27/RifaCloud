<?php

namespace App\Modules\Payments\Enums;

enum PaymentState: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case UNKNOWN = 'UNKNOWN';
    case REFUND_REQUIRED = 'REFUND_REQUIRED';

    /**
     * Allowed transitions for payment results (03-data-model.md §5).
     *
     * A payment already resolved cannot be resurrected or downgraded. UNKNOWN
     * may only move to a final result after a later server-side verification.
     * PENDING may refresh itself when the provider still reports PENDING. A
     * REJECTED payment may still move to REFUND_REQUIRED if a late provider
     * approval is discovered; it can never move back to a sale. An APPROVED
     * payment may move to REFUND_REQUIRED when the provider later reports a
     * refund, chargeback or mediation: the sale is preserved as evidence and
     * the incident requires manual resolution.
     *
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

    /**
     * An uncertain or already-confirmed payment must not be released by the
     * reservation expiry job (03-data-model.md §5, 07-resilience §2.8).
     */
    public function blocksReservationRelease(): bool
    {
        return match ($this) {
            self::UNKNOWN, self::APPROVED, self::REFUND_REQUIRED => true,
            self::PENDING, self::REJECTED => false,
        };
    }

    /**
     * A new checkout must never be created when a previous payment is unknown
     * or already resolved. A PENDING payment can be resumed idempotently.
     */
    public function blocksNewCheckout(): bool
    {
        return $this !== self::PENDING;
    }
}
