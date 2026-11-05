<?php

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * The provider has no payment for this checkout yet.
 *
 * This is an expected outcome while the buyer has not completed the checkout.
 * It must not be treated as a rejection or as an uncertain result: the payment
 * service leaves the order and its numbers untouched.
 */
class PaymentNotFoundException extends RuntimeException {}
