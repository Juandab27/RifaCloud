<?php

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * A signed notification has a shape or topic that cannot be processed.
 */
class InvalidWebhookPayloadException extends RuntimeException {}
