<?php

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * The notification did not carry a valid Mercado Pago signature.
 *
 * It must be rejected without touching any order, payment or number.
 */
class InvalidWebhookSignatureException extends RuntimeException {}
