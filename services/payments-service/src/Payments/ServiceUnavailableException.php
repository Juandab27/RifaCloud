<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RuntimeException;

final class ServiceUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Payments temporarily unavailable.')
    {
        parent::__construct($message);
    }
}
