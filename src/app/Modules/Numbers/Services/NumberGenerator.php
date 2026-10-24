<?php

namespace App\Modules\Numbers\Services;

use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Raffles\Models\Raffle;

class NumberGenerator
{
    /**
     * Rows inserted per statement. Keeps memory bounded for large ranges.
     */
    public const CHUNK_SIZE = 1000;

    /**
     * Generate one AVAILABLE row per number in the raffle range (FR-011, AC-005).
     *
     * Uniqueness is enforced by the (raffle_id, number) constraint; this method
     * must run inside the transaction that creates the raffle.
     */
    public function generate(Raffle $raffle): int
    {
        $total = $raffle->max_number - $raffle->min_number + 1;
        $now = now();

        for ($offset = 0; $offset < $total; $offset += self::CHUNK_SIZE) {
            $chunkSize = min(self::CHUNK_SIZE, $total - $offset);
            $rows = [];

            for ($i = 0; $i < $chunkSize; $i++) {
                $rows[] = [
                    'raffle_id' => $raffle->id,
                    'number' => $raffle->min_number + $offset + $i,
                    'status' => NumberStatus::AVAILABLE->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            RaffleNumber::query()->insert($rows);
        }

        return $total;
    }
}
