<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Draw;

use RuntimeException;

final class InternalAuthException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 401)
    {
        parent::__construct($message);
    }
}
