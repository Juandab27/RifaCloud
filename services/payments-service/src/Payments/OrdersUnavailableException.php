<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RuntimeException;

/**
 * orders-service could not confirm the result (unreachable, timeout, invalid
 * signature or unexpected response). The caller must fail closed: no payment
 * is marked final and a webhook asks the provider to retry.
 */
final class OrdersUnavailableException extends RuntimeException {}
