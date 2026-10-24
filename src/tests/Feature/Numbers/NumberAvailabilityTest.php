<?php

namespace Tests\Feature\Numbers;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Services\NumberGenerator;
use App\Modules\Raffles\Enums\RaffleStatus;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_raffle_exposes_number_availability(): void
    {
        $raffle = $this->raffleWithNumbers(RaffleStatus::PUBLISHED, 10);

        $this->getJson("/api/raffles/{$raffle->id}/numbers")
            ->assertOk()
            ->assertJsonPath('meta.total', 10)
            ->assertJsonPath('meta.counts.available', 10)
            ->assertJsonPath('meta.counts.reserved', 0)
            ->assertJsonPath('meta.counts.sold', 0)
            ->assertJsonPath('data.0.number', 1)
            ->assertJsonPath('data.0.status', 'AVAILABLE');
    }

    public function test_numbers_of_unpublished_raffle_are_not_public(): void
    {
        $raffle = $this->raffleWithNumbers(RaffleStatus::DRAFT, 10);

        $this->getJson("/api/raffles/{$raffle->id}/numbers")->assertNotFound();
    }

    public function test_availability_is_paginated(): void
    {
        $raffle = $this->raffleWithNumbers(RaffleStatus::PUBLISHED, 10);

        $response = $this->getJson("/api/raffles/{$raffle->id}/numbers?per_page=2")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 5)
            ->assertJsonPath('meta.total', 10);

        $this->assertCount(2, $response->json('data'));
    }

    private function raffleWithNumbers(RaffleStatus $status, int $maxNumber): Raffle
    {
        $admin = User::factory()->admin()->create();

        $raffle = Raffle::query()->create([
            'title' => 'Rifa',
            'description' => null,
            'price_per_number' => 1000,
            'min_number' => 1,
            'max_number' => $maxNumber,
            'status' => $status,
            'created_by' => $admin->id,
        ]);

        app(NumberGenerator::class)->generate($raffle);

        return $raffle;
    }
}
