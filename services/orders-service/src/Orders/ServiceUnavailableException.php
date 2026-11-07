<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use RuntimeException;

final class ServiceUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Orders temporarily unavailable.')
    {
        parent::__construct($message);
    }
}
