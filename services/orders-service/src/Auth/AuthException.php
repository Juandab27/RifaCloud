<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Auth;

use RuntimeException;

final class AuthException extends RuntimeException
{
    public function __construct(
        string $message = 'Unauthenticated.',
        public readonly int $status = 401,
    ) {
        parent::__construct($message);
    }

    public static function unauthenticated(): self
    {
        return new self('Unauthenticated.', 401);
    }

    public static function forbidden(): self
    {
        return new self('Forbidden.', 403);
    }
}
