<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RuntimeException;

/**
 * The provider did not answer or is temporarily unavailable (HTTP 503).
 */
final class PaymentGatewayTimeoutException extends RuntimeException {}
