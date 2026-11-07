<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use RuntimeException;

final class NotFoundException extends RuntimeException
{
    public function __construct(string $message = 'No encontrado.')
    {
        parent::__construct($message);
    }
}
