<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_admin_routes(): void
    {
        $this->getJson('/api/admin/raffles')->assertUnauthorized();
    }

    public function test_user_role_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/admin/raffles')
            ->assertForbidden();
    }

    public function test_admin_role_can_access_admin_routes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson('/api/admin/raffles')
            ->assertOk();
    }

    public function test_user_role_cannot_create_raffles(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/admin/raffles', [
                'title' => 'Unauthorized raffle',
                'price_per_number' => 1000,
                'min_number' => 1,
                'max_number' => 10,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('raffles', 0);
    }
}
