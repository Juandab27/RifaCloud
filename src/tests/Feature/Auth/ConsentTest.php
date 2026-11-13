<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S11: the privacy policy and prototype terms are mandatory, the optional
 * promotional consent is recorded separately, and the accepted document
 * version is stored as evidence together with the user and timestamp.
 */
class ConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_privacy_and_terms_consent(): void
    {
        $this->postJson('/api/register', $this->payload([
            'privacy_consent' => false,
            'terms_consent' => false,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['privacy_consent', 'terms_consent']);

        $this->assertDatabaseMissing('users', ['email' => 'consent@example.com']);
    }

    public function test_registration_rejects_a_stale_document_version(): void
    {
        $this->postJson('/api/register', $this->payload([
            'consent_version' => '2020-01-01',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent_version');

        $this->assertDatabaseMissing('users', ['email' => 'consent@example.com']);
    }

    public function test_registration_records_privacy_terms_and_optional_marketing_separately(): void
    {
        $response = $this->stateful()->postJson('/api/register', $this->payload([
            'marketing_consent' => false,
        ]));

        $response->assertCreated();

        $user = User::query()->where('email', 'consent@example.com')->firstOrFail();
        $version = (string) config('rifacloud.legal.consent_version');

        foreach (['PRIVACY', 'TERMS'] as $type) {
            $this->assertDatabaseHas('user_consents', [
                'user_id' => $user->id,
                'type' => $type,
                'document_version' => $version,
                'accepted' => 1,
                'source' => 'REGISTRATION',
            ]);
        }

        $marketing = DB::table('user_consents')
            ->where('user_id', $user->id)
            ->where('type', 'MARKETING')
            ->first();

        $this->assertNotNull($marketing);
        $this->assertSame(0, (int) $marketing->accepted);
        $this->assertSame($version, $marketing->document_version);

        $privacy = DB::table('user_consents')
            ->where('user_id', $user->id)
            ->where('type', 'PRIVACY')
            ->first();

        $this->assertNotNull($privacy);
        $this->assertSame($user->id, (int) $privacy->user_id);
        $this->assertNotNull($privacy->accepted_at);
    }

    public function test_marketing_consent_can_be_accepted_without_mixing_it_with_required_consent(): void
    {
        $this->stateful()->postJson('/api/register', $this->payload([
            'marketing_consent' => true,
        ]))->assertCreated();

        $user = User::query()->where('email', 'consent@example.com')->firstOrFail();

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'type' => 'MARKETING',
            'accepted' => 1,
        ]);

        // The required consents are still independent rows, not implied by
        // the promotional decision.
        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'type' => 'PRIVACY',
            'accepted' => 1,
        ]);
        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'type' => 'TERMS',
            'accepted' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Consent Test',
            'email' => 'consent@example.com',
            'phone' => '3001234567',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'privacy_consent' => true,
            'terms_consent' => true,
            'marketing_consent' => false,
            'consent_version' => (string) config('rifacloud.legal.consent_version'),
        ], $overrides);
    }

    private function stateful(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost']);
    }
}
