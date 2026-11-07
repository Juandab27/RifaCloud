<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use RuntimeException;

/**
 * 403 for an internal command whose actor is not allowed to perform it.
 */
final class ForbiddenException extends RuntimeException
{
    public function __construct(string $message = 'Forbidden.')
    {
        parent::__construct($message);
    }
}
