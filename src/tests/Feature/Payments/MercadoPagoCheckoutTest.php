<?php

namespace Tests\Feature\Payments;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReservationService;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

class MercadoPagoCheckoutTest extends TestCase
{
    use CreatesRaffles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'app.url' => 'https://rifacloud.test',
            'rifacloud.payment_gateway' => 'mercadopago',
            'mercadopago.access_token' => 'test-access-token',
            'mercadopago.sandbox' => true,
            'mercadopago.base_url' => 'https://api.mercadopago.test',
            'mercadopago.timeout' => 5,
            'mercadopago.back_url' => null,
        ]);
    }

    public function test_checkout_creates_a_pending_payment_with_the_sandbox_url(): void
    {
        $this->fakePreference();

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_PAYMENT')
            ->assertJsonPath('data.payments.0.status', 'PENDING')
            ->assertJsonPath('data.payments.0.provider', 'MERCADOPAGO')
            ->assertJsonPath('data.payments.0.checkout_url', 'https://mercadopago.test/sandbox')
            ->assertJsonPath('data.payments.0.simulated', false)
            ->assertJsonPath('data.simulated_payments', false);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame('MP-ORDER-'.$order->id, $payment->external_id);
        $this->assertSame(PaymentState::PENDING, $payment->status);
        $this->assertSame('pref-123', $payment->response_data['preference_id']);
        $this->assertTrue($payment->response_data['sandbox']);
        $this->assertNull($payment->verified_at);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.mercadopago.test/checkout/preferences'
            && $request->hasHeader('X-Idempotency-Key', 'MP-ORDER-'.$order->id));
    }

    public function test_repeating_checkout_reuses_the_pending_payment_without_calling_the_provider_again(): void
    {
        $this->fakePreference();

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertCreated();

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertOk();

        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
    }

    public function test_approved_provider_payment_marks_the_order_paid_and_numbers_sold(): void
    {
        $this->fakePreference();

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$this->paymentResult($order, 'approved')],
            ]),
        ]);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payments.0.status', 'APPROVED');

        $payment->refresh();
        $order->refresh();

        $this->assertSame(PaymentState::APPROVED, $payment->status);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame('123456', $payment->response_data['mp_payment_id']);
        $this->assertSame('APPROVED', $payment->response_data['provider_status']);
        $this->assertSame('accredited', $payment->response_data['mp_status_detail']);
        $this->assertSame('pref-123', $payment->response_data['preference_id']);
        $this->assertArrayNotHasKey('payer', $payment->response_data);

        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_no_provider_payment_yet_leaves_the_order_and_numbers_untouched(): void
    {
        $this->fakePreference();

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [],
            ]),
        ]);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING_PAYMENT')
            ->assertJsonPath('data.payments.0.status', 'PENDING');

        $payment->refresh();
        $order->refresh();

        $this->assertSame(PaymentState::PENDING, $payment->status);
        $this->assertNull($payment->verified_at);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_provider_timeout_during_verification_keeps_the_existing_review_protection(): void
    {
        $this->fakePreference();

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([], 503),
        ]);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'PAYMENT_REVIEW')
            ->assertJsonPath('data.payments.0.status', 'UNKNOWN');

        $this->assertSame(OrderStatus::PAYMENT_REVIEW, $order->refresh()->status);
        $this->assertSame(PaymentState::UNKNOWN, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_provider_4xx_on_preference_creation_returns_502_without_a_payment(): void
    {
        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response([
                'error' => 'invalid_token',
            ], 401),
        ]);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertStatus(502)
            ->assertJsonPath('code', 'PAYMENT_PROVIDER_ERROR');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_provider_timeout_on_preference_creation_returns_503_without_a_payment(): void
    {
        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response([], 500),
        ]);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertStatus(503)
            ->assertJsonPath('code', 'PAYMENT_PROVIDER_TIMEOUT');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_refunded_provider_payment_requires_refund_and_does_not_release_numbers(): void
    {
        $this->fakePreference();

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$this->paymentResult($order, 'refunded')],
            ]),
        ]);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->refresh()->status);
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::AVAILABLE->value)->count());
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_late_approval_on_an_expired_order_requires_refund_through_the_existing_rule(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->fakePreference();

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        $this->travel(11)->minutes();
        app(\App\Modules\Orders\Services\ReservationExpiryService::class)->expire();

        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$this->paymentResult($order, 'approved')],
            ]),
        ]);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $payment->refresh();

        $this->assertTrue((bool) ($payment->response_data['late_approval'] ?? false));
        $this->assertSame('APPROVED', $payment->response_data['provider_status']);
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    private function reservedOrder(User $user, array $numbers = [1, 2]): Order
    {
        $raffle = $this->createRaffle(maxNumber: 2);

        return app(OrderReservationService::class)->create($user, $raffle->id, $numbers);
    }

    private function createCheckout(User $user, Order $order): Payment
    {
        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertCreated();

        return Payment::query()->where('order_id', $order->id)->firstOrFail();
    }

    private function verify(User $user, Order $order, Payment $payment): TestResponse
    {
        return $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments/{$payment->id}/verify");
    }

    private function fakePreference(): void
    {
        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response([
                'id' => 'pref-123',
                'init_point' => 'https://mercadopago.test/init',
                'sandbox_init_point' => 'https://mercadopago.test/sandbox',
                'live_mode' => false,
            ], 201),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentResult(Order $order, string $status): array
    {
        return [
            'id' => 123456,
            'status' => $status,
            'status_detail' => 'accredited',
            'transaction_amount' => $order->total_amount / 100,
            'currency_id' => 'COP',
            'external_reference' => 'MP-ORDER-'.$order->id,
            'payment_method_id' => 'visa',
            'payment_type_id' => 'credit_card',
            'live_mode' => false,
            'date_created' => '2026-10-25T00:00:00.000-04:00',
            'date_approved' => '2026-10-25T00:00:01.000-04:00',
            'payer' => ['email' => 'buyer@example.test'],
        ];
    }
}
