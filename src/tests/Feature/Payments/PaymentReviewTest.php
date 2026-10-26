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
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

class PaymentReviewTest extends TestCase
{
    use CreatesRaffles;
    use RefreshDatabase;

    public function test_buyer_cannot_resolve_an_order_under_payment_review(): void
    {
        [$buyer, $order, $payment] = $this->orderUnderReview();

        $this->verify($buyer, $order, $payment)
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_REVIEW_REQUIRES_ADMIN');

        $this->assertSame(OrderStatus::PAYMENT_REVIEW, $order->refresh()->status);
        $this->assertSame(PaymentState::UNKNOWN, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_buyer_can_still_read_their_order_under_payment_review(): void
    {
        [$buyer, $order] = $this->orderUnderReview();

        $this->actingAs($buyer)
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'PAYMENT_REVIEW')
            ->assertJsonPath('data.payments.0.status', 'UNKNOWN');
    }

    public function test_admin_can_resolve_a_review_as_paid_using_the_provider_result(): void
    {
        [, $order, $payment] = $this->orderUnderReview();

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $admin = User::factory()->admin()->create();

        $this->resolve($admin, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payments.0.status', 'APPROVED');

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(PaymentState::APPROVED, $payment->status);
        $this->assertSame('APPROVED', $payment->response_data['provider_status'] ?? null);

        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
        $this->assertSame($order->id, RaffleNumber::query()->where('number', 1)->firstOrFail()->order_id);
    }

    public function test_admin_resolution_ignores_a_status_sent_by_the_browser(): void
    {
        [, $order, $payment] = $this->orderUnderReview();

        // The provider rejected the charge even though the request body claims
        // it was approved. The provider result must win.
        config(['rifacloud.payment_fake_outcome' => 'REJECTED']);

        $admin = User::factory()->admin()->create();

        $this->resolve($admin, $order, $payment, ['status' => 'APPROVED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.payments.0.status', 'REJECTED');

        $this->assertSame(OrderStatus::REJECTED, $order->refresh()->status);
        $this->assertSame(PaymentState::REJECTED, $payment->refresh()->status);
        $this->assertSame('REJECTED', $payment->response_data['provider_status'] ?? null);
        $this->assertNull($order->paid_at);

        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::AVAILABLE->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_guest_cannot_call_the_admin_resolution_endpoint(): void
    {
        [, $order, $payment] = $this->orderUnderReview();

        // Clear the authentication state left by the setup requests.
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/admin/orders/{$order->id}/payments/{$payment->id}/resolve")
            ->assertUnauthorized();

        $this->assertSame(OrderStatus::PAYMENT_REVIEW, $order->refresh()->status);
    }

    public function test_regular_user_cannot_call_the_admin_resolution_endpoint(): void
    {
        [, $order, $payment] = $this->orderUnderReview();

        $intruder = User::factory()->create();

        $this->resolve($intruder, $order, $payment)->assertForbidden();

        $this->assertSame(OrderStatus::PAYMENT_REVIEW, $order->refresh()->status);
        $this->assertSame(PaymentState::UNKNOWN, $payment->refresh()->status);
    }

    public function test_admin_cannot_resolve_an_order_that_is_not_under_review(): void
    {
        $buyer = User::factory()->create();
        $order = $this->reservedOrder($buyer);
        $payment = $this->createCheckout($buyer, $order);

        $admin = User::factory()->admin()->create();

        $this->resolve($admin, $order, $payment)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_NOT_UNDER_REVIEW');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_admin_cannot_resolve_a_payment_that_belongs_to_another_order(): void
    {
        [, $order] = $this->orderUnderReview();

        $otherBuyer = User::factory()->create();
        $otherOrder = $this->reservedOrder($otherBuyer);
        $otherPayment = $this->createCheckout($otherBuyer, $otherOrder);

        $admin = User::factory()->admin()->create();

        $this->resolve($admin, $order, $otherPayment)->assertNotFound();

        $this->assertSame(OrderStatus::PAYMENT_REVIEW, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $otherPayment->refresh()->status);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $otherOrder->refresh()->status);
    }

    /**
     * @return array{0: User, 1: Order, 2: Payment}
     */
    private function orderUnderReview(): array
    {
        $buyer = User::factory()->create();
        $order = $this->reservedOrder($buyer);
        $payment = $this->createCheckout($buyer, $order);

        config(['rifacloud.payment_fake_outcome' => 'UNKNOWN']);

        $this->verify($buyer, $order, $payment)
            ->assertOk()
            ->assertJsonPath('data.status', 'PAYMENT_REVIEW');

        return [$buyer, $order, $payment];
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

        return Payment::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
    }

    private function verify(User $user, Order $order, Payment $payment): TestResponse
    {
        return $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments/{$payment->id}/verify");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolve(User $admin, Order $order, Payment $payment, array $payload = []): TestResponse
    {
        return $this->actingAs($admin)
            ->postJson(
                "/api/admin/orders/{$order->id}/payments/{$payment->id}/resolve",
                $payload,
            );
    }
}
