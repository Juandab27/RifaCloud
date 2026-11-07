<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RuntimeException;

/**
 * The provider has no payment for this checkout yet. Expected while the buyer
 * has not completed checkout; it never means rejection or uncertainty.
 */
final class PaymentNotFoundException extends RuntimeException {}
