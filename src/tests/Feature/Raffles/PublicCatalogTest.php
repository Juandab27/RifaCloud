<?php

namespace Tests\Feature\Raffles;

use App\Modules\Auth\Models\User;
use App\Modules\Raffles\Enums\RaffleStatus;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_is_publicly_accessible(): void
    {
        $this->getJson('/api/raffles')->assertOk();
    }

    public function test_catalog_lists_only_published_raffles(): void
    {
        $admin = User::factory()->admin()->create();

        $this->raffle($admin, ['title' => 'Publicada', 'status' => RaffleStatus::PUBLISHED]);
        $this->raffle($admin, ['title' => 'Borrador', 'status' => RaffleStatus::DRAFT]);
        $this->raffle($admin, ['title' => 'Cerrada', 'status' => RaffleStatus::CLOSED]);

        $response = $this->getJson('/api/raffles')->assertOk();

        $this->assertSame(
            ['Publicada'],
            collect($response->json('data'))->pluck('title')->all(),
        );
    }

    public function test_published_raffle_detail_is_publicly_visible(): void
    {
        $admin = User::factory()->admin()->create();
        $raffle = $this->raffle($admin, [
            'title' => 'Publicada',
            'status' => RaffleStatus::PUBLISHED,
        ]);

        $this->getJson("/api/raffles/{$raffle->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Publicada');
    }

    public function test_draft_and_closed_raffle_details_are_not_public(): void
    {
        $admin = User::factory()->admin()->create();

        $draft = $this->raffle($admin, ['status' => RaffleStatus::DRAFT]);
        $closed = $this->raffle($admin, ['status' => RaffleStatus::CLOSED]);

        $this->getJson("/api/raffles/{$draft->id}")->assertNotFound();
        $this->getJson("/api/raffles/{$closed->id}")->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function raffle(User $admin, array $overrides = []): Raffle
    {
        return Raffle::query()->create(array_merge([
            'title' => 'Rifa',
            'description' => null,
            'price_per_number' => 1000,
            'min_number' => 1,
            'max_number' => 10,
            'status' => RaffleStatus::DRAFT,
            'created_by' => $admin->id,
        ], $overrides));
    }
}
