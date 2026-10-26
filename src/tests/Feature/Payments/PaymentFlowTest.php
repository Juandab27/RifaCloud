<?php

namespace Tests\Feature\Payments;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReservationService;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Exceptions\PaymentGatewayTimeoutException;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use CreatesRaffles;
    use RefreshDatabase;

    public function test_guest_cannot_create_payment(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);

        $this->postJson("/api/orders/{$order->id}/payments")->assertUnauthorized();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_another_user_cannot_create_or_verify_payment(): void
    {
        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = $this->reservedOrder($owner);

        $this->actingAs($intruder)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertNotFound()
            ->assertJsonPath('message', 'No encontrado.');

        $payment = $this->createCheckout($owner, $order);

        $this->actingAs($intruder)
            ->postJson("/api/orders/{$order->id}/payments/{$payment->id}/verify")
            ->assertNotFound()
            ->assertJsonPath('message', 'No encontrado.');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);
    }

    public function test_checkout_creates_pending_payment_without_marking_order_paid(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);

        $response = $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_PAYMENT')
            ->assertJsonPath('data.payments.0.status', 'PENDING')
            ->assertJsonPath('data.payments.0.provider', 'MERCADOPAGO')
            ->assertJsonPath('data.payments.0.amount', 2000)
            ->assertJsonPath('data.payments.0.currency', 'COP')
            ->assertJsonPath('data.payments.0.simulated', true)
            ->assertJsonPath('data.simulated_payments', true);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(PaymentState::PENDING, $payment->status);
        $this->assertStringStartsWith('SIM-COP-2000-', $payment->external_id);
        $this->assertStringContainsString('/checkout/simulated/', (string) $response->json('data.payments.0.checkout_url'));
        $this->assertNull($payment->verified_at);

        $order->refresh();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $this->assertNull($order->paid_at);

        foreach (RaffleNumber::query()->get() as $number) {
            $this->assertSame(NumberStatus::RESERVED, $number->status);
        }
    }

    public function test_pending_checkout_is_idempotent_and_does_not_create_a_second_charge(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);

        $first = $this->createCheckout($user, $order);
        $second = $this->createCheckout($user, $order, false);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->external_id, $second->external_id);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_checkout_provider_timeout_returns_error_without_payment_or_paid_order(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);

        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('createCheckout')
            ->once()
            ->andThrow(new PaymentGatewayTimeoutException('Simulated provider timeout.'));

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertStatus(503)
            ->assertJsonPath('code', 'PAYMENT_PROVIDER_TIMEOUT');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertNull($order->paid_at);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_approved_payment_marks_order_paid_and_numbers_sold(): void
    {
        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payments.0.status', 'APPROVED');

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(PaymentState::APPROVED, $payment->status);
        $this->assertNotNull($payment->verified_at);

        foreach (RaffleNumber::query()->get() as $number) {
            $this->assertSame(NumberStatus::SOLD, $number->status);
            $this->assertSame($order->id, $number->order_id);
            $this->assertNotNull($number->sold_at);
            $this->assertNull($number->reserved_until);
        }

        // A repeated verification cannot duplicate the sale.
        $this->verify($user, $order, $payment)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_NOT_VERIFIABLE');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_rejected_payment_rejects_order_and_releases_numbers(): void
    {
        config(['rifacloud.payment_fake_outcome' => 'REJECTED']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.payments.0.status', 'REJECTED');

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REJECTED, $order->status);
        $this->assertSame(PaymentState::REJECTED, $payment->status);
        $this->assertNull($order->paid_at);

        foreach (RaffleNumber::query()->get() as $number) {
            $this->assertSame(NumberStatus::AVAILABLE, $number->status);
            $this->assertNull($number->order_id);
            $this->assertNull($number->reserved_at);
            $this->assertNull($number->reserved_until);
        }
    }

    public function test_late_approval_after_rejection_is_recorded_as_refund_without_a_sale(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        config(['rifacloud.payment_fake_outcome' => 'REJECTED']);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.payments.0.status', 'REJECTED');

        $this->assertSame(OrderStatus::REJECTED, $order->refresh()->status);
        $this->assertSame(PaymentState::REJECTED, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::AVAILABLE->value)->count());

        // The provider later confirms the charge even though the attempt was
        // already rejected. It must be reconciled as a refund incident, never
        // as a sale, and the released numbers must not be touched.
        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->status);
        $this->assertNotNull($payment->verified_at);
        $this->assertTrue((bool) ($payment->response_data['late_approval'] ?? false));
        $this->assertSame('APPROVED', $payment->response_data['provider_status'] ?? null);
        $this->assertNull($order->paid_at);

        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::AVAILABLE->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
        $this->assertSame(0, RaffleNumber::query()->whereNotNull('order_id')->count());

        $this->travelBack();
    }

    public function test_late_approval_after_rejection_does_not_reassign_numbers_reserved_by_another_order(): void
    {
        $this->travelTo(now()->startOfSecond());

        $buyer = User::factory()->create();
        $order = $this->reservedOrder($buyer, [1, 2]);
        $payment = $this->createCheckout($buyer, $order);

        config(['rifacloud.payment_fake_outcome' => 'REJECTED']);

        $this->verify($buyer, $order, $payment)->assertOk();

        $this->assertSame(OrderStatus::REJECTED, $order->refresh()->status);

        $otherBuyer = User::factory()->create();
        $otherOrder = app(OrderReservationService::class)->create($otherBuyer, $order->raffle_id, [1, 2]);

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->verify($buyer, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->refresh()->status);
        $this->assertTrue((bool) ($payment->response_data['late_approval'] ?? false));

        // The numbers now belong to the other order and must stay that way.
        foreach (RaffleNumber::query()->whereIn('number', [1, 2])->get() as $number) {
            $this->assertSame(NumberStatus::RESERVED, $number->status);
            $this->assertSame($otherOrder->id, $number->order_id);
        }

        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    public function test_rejected_order_cannot_retry_a_checkout_in_the_same_order(): void
    {
        config(['rifacloud.payment_fake_outcome' => 'REJECTED']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->createCheckout($user, $order);

        $this->verify($user, $order, $payment)->assertOk();

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_ALREADY_FINAL');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(OrderStatus::REJECTED, $order->refresh()->status);
    }

    public function test_unknown_payment_goes_to_review_and_expiry_does_not_release_numbers(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['rifacloud.payment_fake_outcome' => 'UNKNOWN']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'PAYMENT_REVIEW')
            ->assertJsonPath('data.payments.0.status', 'UNKNOWN');

        $this->assertSame(OrderStatus::PAYMENT_REVIEW, $order->refresh()->status);
        $this->assertSame(PaymentState::UNKNOWN, $payment->refresh()->status);

        $this->travel(11)->minutes();

        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 0')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PAYMENT_REVIEW, $order->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());

        $this->travelBack();
    }

    public function test_timeout_is_treated_as_unknown_and_requires_review(): void
    {
        config(['rifacloud.payment_fake_outcome' => 'TIMEOUT']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'PAYMENT_REVIEW')
            ->assertJsonPath('data.payments.0.status', 'UNKNOWN');

        $payment->refresh();

        $this->assertSame(PaymentState::UNKNOWN, $payment->status);
        $this->assertTrue((bool) ($payment->response_data['timeout'] ?? false));
        $this->assertTrue((bool) ($payment->response_data['simulated'] ?? false));
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_unknown_payment_blocks_a_new_checkout(): void
    {
        config(['rifacloud.payment_fake_outcome' => 'UNKNOWN']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->createCheckout($user, $order);

        $this->verify($user, $order, $payment)->assertOk();

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_UNCERTAIN');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_approved_payment_blocks_a_new_checkout(): void
    {
        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->createCheckout($user, $order);

        $this->verify($user, $order, $payment)->assertOk();

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_ALREADY_FINAL');
    }

    public function test_expired_order_late_approval_is_recorded_as_refund_required(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        $this->travel(11)->minutes();

        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 1')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::EXPIRED, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->status);
        $this->assertNotNull($payment->verified_at);
        $this->assertNull($order->paid_at);
        $this->assertTrue((bool) ($payment->response_data['late_approval'] ?? false));
        $this->assertSame('APPROVED', $payment->response_data['provider_status'] ?? null);

        // The scheduler already released the numbers. They must stay AVAILABLE
        // and never be reassigned or partially sold.
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::AVAILABLE->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    public function test_late_approval_before_scheduler_uses_the_same_refund_policy(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        // The reservation has elapsed, but the scheduler has not run yet. The
        // decision must be the same as when the scheduler already expired it.
        $this->travel(11)->minutes();

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->status);
        $this->assertTrue((bool) ($payment->response_data['late_approval'] ?? false));
        $this->assertNull($order->paid_at);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        // The order is no longer PENDING_PAYMENT, so the scheduler must not
        // release the still-reserved numbers behind the manual refund review.
        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 0')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());

        $this->travelBack();
    }

    public function test_approval_before_expiry_is_paid_and_expiry_does_not_release(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID');

        $this->travel(11)->minutes();

        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 0')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    public function test_late_approval_after_numbers_are_resold_does_not_reassign_them(): void
    {
        $this->travelTo(now()->startOfSecond());

        $buyer = User::factory()->create();
        $order = $this->reservedOrder($buyer, [1, 2]);
        $payment = $this->createCheckout($buyer, $order);

        $this->travel(11)->minutes();

        $this->artisan('orders:expire-reservations')->assertExitCode(0);

        $otherBuyer = User::factory()->create();
        $otherOrder = app(OrderReservationService::class)->create($otherBuyer, $order->raffle_id, [1, 2]);

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->verify($buyer, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->refresh()->status);
        $this->assertTrue((bool) ($payment->response_data['late_approval'] ?? false));

        foreach (RaffleNumber::query()->whereIn('number', [1, 2])->get() as $number) {
            $this->assertSame(NumberStatus::RESERVED, $number->status);
            $this->assertSame($otherOrder->id, $number->order_id);
        }

        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    public function test_late_rejection_on_expired_order_is_recorded_without_reopening_a_sale(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        $this->travel(11)->minutes();
        $this->artisan('orders:expire-reservations')->assertExitCode(0);

        config(['rifacloud.payment_fake_outcome' => 'REJECTED']);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'EXPIRED')
            ->assertJsonPath('data.payments.0.status', 'REJECTED');

        $this->assertSame(OrderStatus::EXPIRED, $order->refresh()->status);
        $this->assertSame(PaymentState::REJECTED, $payment->refresh()->status);
        $this->assertSame('REJECTED', $payment->response_data['provider_status'] ?? null);
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    public function test_late_approval_after_a_late_rejection_preserves_the_approval_as_refund(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        $this->travel(11)->minutes();
        $this->artisan('orders:expire-reservations')->assertExitCode(0);

        config(['rifacloud.payment_fake_outcome' => 'REJECTED']);
        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.payments.0.status', 'REJECTED');

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->refresh()->status);
        $this->assertTrue((bool) ($payment->response_data['late_approval'] ?? false));
        $this->assertSame('APPROVED', $payment->response_data['provider_status'] ?? null);
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    public function test_late_unknown_on_expired_order_is_recorded_without_reopening_a_sale(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        $this->travel(11)->minutes();
        $this->artisan('orders:expire-reservations')->assertExitCode(0);

        config(['rifacloud.payment_fake_outcome' => 'UNKNOWN']);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'EXPIRED')
            ->assertJsonPath('data.payments.0.status', 'UNKNOWN');

        $this->assertSame(OrderStatus::EXPIRED, $order->refresh()->status);
        $this->assertSame(PaymentState::UNKNOWN, $payment->refresh()->status);
        $this->assertSame('UNKNOWN', $payment->response_data['provider_status'] ?? null);
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    public function test_unknown_after_expiry_before_scheduler_cannot_become_a_sale_later(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        // Expiry time has passed, but the scheduler has not run. An UNKNOWN
        // result must not create a PAYMENT_REVIEW hold that later becomes a sale.
        $this->travel(11)->minutes();
        config(['rifacloud.payment_fake_outcome' => 'UNKNOWN']);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'EXPIRED')
            ->assertJsonPath('data.payments.0.status', 'UNKNOWN');

        $this->assertSame(OrderStatus::EXPIRED, $order->refresh()->status);
        $this->assertSame(PaymentState::UNKNOWN, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->refresh()->status);
        $this->assertTrue((bool) ($payment->response_data['late_approval'] ?? false));
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 0')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());

        $this->travelBack();
    }

    public function test_unknown_payment_protects_numbers_even_if_order_status_is_inconsistent(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);

        Payment::query()->create([
            'order_id' => $order->id,
            'provider' => PaymentProvider::MERCADOPAGO,
            'external_id' => 'SIM-COP-2000-UNKNOWN-PROTECTION',
            'status' => PaymentState::UNKNOWN,
            'amount' => $order->total_amount,
            'currency' => 'COP',
        ]);

        $this->travel(11)->minutes();

        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 0')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());

        $this->travelBack();
    }

    public function test_approved_payment_with_unassignable_numbers_requires_refund_without_partial_sale(): void
    {
        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        // Simulate one number losing its link to the order before the payment
        // is confirmed. The other number must not be sold alone.
        RaffleNumber::query()
            ->where('number', 1)
            ->update([
                'status' => NumberStatus::AVAILABLE->value,
                'order_id' => null,
                'reserved_at' => null,
                'reserved_until' => null,
                'sold_at' => null,
            ]);

        $this->verify($user, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->refresh()->status);
        $this->assertNull($order->paid_at);
        $this->assertSame(NumberStatus::AVAILABLE, RaffleNumber::query()->where('number', 1)->firstOrFail()->status);
        $this->assertSame(NumberStatus::RESERVED, RaffleNumber::query()->where('number', 2)->firstOrFail()->status);
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
        $this->assertTrue((bool) ($payment->response_data['numbers_unavailable'] ?? false));
        $this->assertSame('APPROVED', $payment->response_data['provider_status'] ?? null);
    }

    public function test_invalid_payment_transition_does_not_change_state_or_release_numbers(): void
    {
        config(['rifacloud.payment_fake_outcome' => 'REJECTED']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->createCheckout($user, $order);

        // Force an inconsistent pre-existing terminal payment status. A later
        // REJECTED result must not downgrade it and must not touch the numbers.
        $payment->forceFill(['status' => PaymentState::APPROVED])->save();

        $this->verify($user, $order, $payment)
            ->assertStatus(409)
            ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_verification_amount_mismatch_is_rejected_without_state_change(): void
    {
        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->createCheckout($user, $order);

        $payment->forceFill(['amount' => 1])->save();

        $this->verify($user, $order, $payment)
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_VERIFICATION_MISMATCH');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_database_rejects_a_second_payment_for_the_same_order(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);

        $this->createCheckout($user, $order);

        $this->expectException(QueryException::class);

        Payment::query()->create([
            'order_id' => $order->id,
            'provider' => PaymentProvider::MERCADOPAGO,
            'external_id' => 'SIM-COP-2000-SECOND-PAYMENT',
            'status' => PaymentState::PENDING,
            'amount' => $order->total_amount,
            'currency' => 'COP',
        ]);
    }

    private function reservedOrder(User $user, array $numbers = [1, 2]): Order
    {
        $raffle = $this->createRaffle(maxNumber: 2);

        return app(OrderReservationService::class)->create($user, $raffle->id, $numbers);
    }

    private function createCheckout(User $user, Order $order, bool $expectCreated = true): Payment
    {
        $response = $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments");

        if ($expectCreated) {
            $response->assertCreated();
        } else {
            $response->assertOk();
        }

        return Payment::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
    }

    private function verify(User $user, Order $order, Payment $payment): TestResponse
    {
        return $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments/{$payment->id}/verify");
    }
}
