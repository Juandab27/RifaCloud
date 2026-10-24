<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_name_email_and_phone(): void
    {
        $response = $this->stateful()->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '3001234567',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.role', UserRole::USER->value);

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'phone' => '3001234567',
            'role' => UserRole::USER->value,
        ]);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertNotSame('secret-password', $user->password);
        $this->assertAuthenticated();
    }

    public function test_registration_requires_name_email_and_phone(): void
    {
        $this->postJson('/api/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'password']);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '3001234567',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->stateful()->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticated();
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_read_their_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', UserRole::USER->value);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->stateful()->actingAs($user)
            ->postJson('/api/logout')
            ->assertOk();
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    /**
     * Simulate a same-origin SPA request so Sanctum applies the stateful
     * (session + cookie) middleware stack.
     */
    private function stateful(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost']);
    }
}
