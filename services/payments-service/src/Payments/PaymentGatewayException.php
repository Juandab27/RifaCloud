<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RuntimeException;

/**
 * Non-retryable provider rejection or configuration error (HTTP 502).
 */
final class PaymentGatewayException extends RuntimeException {}
