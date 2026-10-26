<?php

namespace Tests\Concerns;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Services\NumberGenerator;
use App\Modules\Raffles\Enums\RaffleStatus;
use App\Modules\Raffles\Models\Raffle;

trait CreatesRaffles
{
    protected function createRaffle(
        RaffleStatus $status = RaffleStatus::PUBLISHED,
        int $minNumber = 1,
        int $maxNumber = 10,
        int $pricePerNumber = 1000,
    ): Raffle {
        $admin = User::factory()->admin()->create();

        $raffle = Raffle::query()->create([
            'title' => 'Rifa de prueba',
            'description' => null,
            'price_per_number' => $pricePerNumber,
            'min_number' => $minNumber,
            'max_number' => $maxNumber,
            'status' => $status,
            'created_by' => $admin->id,
        ]);

        app(NumberGenerator::class)->generate($raffle);

        return $raffle;
    }
}
