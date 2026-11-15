<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_register_with_name_email_and_phone(): void
    {
        $response = $this->stateful()->postJson('/api/register', $this->validRegistration());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.role', UserRole::USER->value);

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'phone' => '3001234567',
            'role' => UserRole::USER->value,
        ]);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('Secret123', $user->password));
        $this->assertNotSame('Secret123', $user->password);
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

        $this->postJson('/api/register', $this->validRegistration())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_normalizes_accepted_colombian_mobile_formats(): void
    {
        $formats = [
            '300 123 4567',
            '+57 300 123 4567',
            '+573001234567',
            '573001234567',
            '(300) 123-4567',
        ];

        foreach ($formats as $index => $phone) {
            $email = "phone-format-{$index}@example.com";

            $this->stateful()->postJson('/api/register', $this->validRegistration([
                'email' => $email,
                'phone' => $phone,
            ]))->assertCreated();

            $this->assertDatabaseHas('users', [
                'email' => $email,
                'phone' => '3001234567',
            ]);
        }
    }

    public function test_registration_rejects_invalid_colombian_mobiles(): void
    {
        foreach ([
            '300123456',
            '30012345678',
            '4001234567',
            '0000000000',
            '30012A4567',
            '+1 3001234567',
        ] as $phone) {
            $this->postJson('/api/register', $this->validRegistration([
                'email' => 'invalid-phone-'.md5($phone).'@example.com',
                'phone' => $phone,
            ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('phone');
        }

    }

    public function test_registration_rejects_weak_passwords(): void
    {
        foreach (['Abc1', 'abcdefg1', 'Abcdefgh', 'sinmayuscula1'] as $password) {
            $this->postJson('/api/register', $this->validRegistration([
                'email' => 'weak-'.md5($password).'@example.com',
                'password' => $password,
                'password_confirmation' => $password,
            ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('password');
        }

    }

    public function test_registration_rejects_password_mismatch(): void
    {
        $this->postJson('/api/register', $this->validRegistration([
            'password_confirmation' => 'Different123',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_rejects_malformed_emails(): void
    {
        foreach ([
            'ana',
            'ana@',
            'ana@example',
            'ana @example.com',
        ] as $email) {
            $this->postJson('/api/register', $this->validRegistration([
                'email' => $email,
            ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('email');
        }
    }

    public function test_registration_rejects_another_set_of_malformed_emails(): void
    {
        foreach ([
            'ana@@example.com',
            '.ana@example.com',
            'ana..x@example.com',
        ] as $email) {
            $this->postJson('/api/register', $this->validRegistration([
                'email' => $email,
            ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('email');
        }
    }

    public function test_registration_accepts_legitimate_email_variants(): void
    {
        $this->stateful()->postJson('/api/register', $this->validRegistration([
            'email' => 'ana+rifas@sub.example.com',
        ]))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'ana+rifas@sub.example.com']);
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

    public function test_existing_accounts_keep_their_historical_phone_and_password(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy@example.com',
            'phone' => '0000000000',
            'password' => 'secret-password',
        ]);

        $this->stateful()->postJson('/api/login', [
            'email' => 'legacy@example.com',
            'password' => 'secret-password',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'legacy@example.com',
            'phone' => '0000000000',
        ]);
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validRegistration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '3001234567',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'privacy_consent' => true,
            'terms_consent' => true,
            'marketing_consent' => false,
            'consent_version' => config('rifacloud.legal.consent_version'),
        ], $overrides);
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
