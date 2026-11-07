<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RuntimeException;

final class InvalidWebhookSignatureException extends RuntimeException {}
