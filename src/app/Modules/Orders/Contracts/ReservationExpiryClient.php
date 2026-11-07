<?php

namespace App\Modules\Orders\Contracts;

/**
 * Delegates reservation expiry to orders-service (S3).
 *
 * The monolith scheduler no longer releases numbers locally: doing so would
 * duplicate ownership and allow two processes to expire the same reservation.
 * Implementations must call the internal orders-service endpoint and fail
 * closed when it is unavailable.
 */
interface ReservationExpiryClient
{
    public function expire(): int;
}
