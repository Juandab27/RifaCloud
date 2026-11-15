<?php

namespace App\Modules\Raffles\Contracts;

interface RaffleDrawClient
{
    /**
     * @return array{closed: int, drawn: int, blocked: int, skipped: int, errors: int}
     */
    public function processDue(int $limit): array;
}
