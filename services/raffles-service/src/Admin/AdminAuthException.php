<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

use RuntimeException;

final class AdminAuthException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function unauthenticated(): self
    {
        return new self(401, 'Unauthenticated.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'Administrator role required.');
    }
}
