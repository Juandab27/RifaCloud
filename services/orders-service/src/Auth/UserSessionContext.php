<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Auth;

final class UserSessionContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly string $csrfToken,
        public readonly bool $passwordMatches,
    ) {}
}
