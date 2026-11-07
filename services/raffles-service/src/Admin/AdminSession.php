<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

final class AdminSession
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $csrfToken,
    ) {}
}
