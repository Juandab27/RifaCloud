<?php

namespace Tests\Feature\Raffles;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Raffles\Enums\RaffleStatus;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRaffleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_raffle_and_numbers_are_generated(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/raffles', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.status', RaffleStatus::DRAFT->value)
            ->assertJsonPath('data.total_numbers', 100)
            ->assertJsonPath('data.numbers_count', 100);

        $raffle = Raffle::query()->firstOrFail();

        $this->assertSame(100, $raffle->numbers()->count());
        $this->assertSame(100, $raffle->numbers()->distinct()->count('number'));
        $this->assertSame(1, (int) $raffle->numbers()->min('number'));
        $this->assertSame(100, (int) $raffle->numbers()->max('number'));
        $this->assertSame($admin->id, $raffle->created_by);
    }

    public function test_number_generation_handles_ranges_larger_than_one_chunk(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/admin/raffles', $this->payload([
                'min_number' => 1,
                'max_number' => 2500,
            ]))
            ->assertCreated();

        $this->assertSame(2500, RaffleNumber::query()->count());
    }

    public function test_raffle_range_is_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/raffles', $this->payload(['min_number' => 50, 'max_number' => 10]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_number');

        $this->actingAs($admin)
            ->postJson('/api/admin/raffles', $this->payload(['max_number' => 10001]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_number');
    }

    public function test_price_per_number_is_stored_in_cents(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/admin/raffles', $this->payload(['price_per_number' => 5000]))
            ->assertCreated()
            ->assertJsonPath('data.price_per_number', 5000);
    }

    public function test_admin_can_publish_and_close_raffle(): void
    {
        $admin = User::factory()->admin()->create();
        $raffle = $this->createRaffle($admin);

        $this->actingAs($admin)
            ->postJson("/api/admin/raffles/{$raffle->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', RaffleStatus::PUBLISHED->value);

        $this->actingAs($admin)
            ->postJson("/api/admin/raffles/{$raffle->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', RaffleStatus::CLOSED->value);
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $raffle = $this->createRaffle($admin);

        // Closing a DRAFT raffle is not an allowed transition.
        $this->actingAs($admin)
            ->postJson("/api/admin/raffles/{$raffle->id}/close")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_range_can_be_reconfigured_while_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $raffle = $this->createRaffle($admin);

        $this->actingAs($admin)
            ->putJson("/api/admin/raffles/{$raffle->id}", $this->payload([
                'min_number' => 5,
                'max_number' => 9,
            ]))
            ->assertOk()
            ->assertJsonPath('data.numbers_count', 5);

        $numbers = $raffle->numbers()->orderBy('number')->get()->pluck('number')->all();

        $this->assertSame([5, 6, 7, 8, 9], $numbers);
    }

    public function test_published_raffle_cannot_be_reconfigured(): void
    {
        $admin = User::factory()->admin()->create();
        $raffle = $this->createRaffle($admin);

        $raffle->status = RaffleStatus::PUBLISHED;
        $raffle->save();

        $this->actingAs($admin)
            ->putJson("/api/admin/raffles/{$raffle->id}", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Rifa de prueba',
            'description' => 'Descripcion de la rifa',
            'price_per_number' => 5000,
            'min_number' => 1,
            'max_number' => 100,
        ], $overrides);
    }

    private function createRaffle(User $admin): Raffle
    {
        $response = $this->actingAs($admin)
            ->postJson('/api/admin/raffles', $this->payload());

        $response->assertCreated();

        return Raffle::query()->findOrFail($response->json('data.id'));
    }
}
