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
use App\Modules\Payments\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    use CreatesRaffles;
    use RefreshDatabase;

    public function test_reconciliation_approves_a_pending_payment_without_creating_a_charge(): void
    {
        config([
            'rifacloud.payment_gateway' => 'fake',
            'rifacloud.payment_fake_outcome' => 'APPROVED',
        ]);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->payment($order, PaymentState::PENDING);

        $this->artisan('payments:reconcile')
            ->expectsOutput('Payments reconciled: 1')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reconciliation_resolves_a_payment_under_review(): void
    {
        config([
            'rifacloud.payment_gateway' => 'fake',
            'rifacloud.payment_fake_outcome' => 'APPROVED',
        ]);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $order->forceFill(['status' => OrderStatus::PAYMENT_REVIEW])->save();
        $payment = $this->payment($order, PaymentState::UNKNOWN);

        $this->artisan('payments:reconcile')
            ->expectsOutput('Payments reconciled: 1')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_dry_run_does_not_call_the_provider_or_change_state(): void
    {
        config(['rifacloud.payment_gateway' => 'fake']);

        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldNotReceive('verifyPayment');

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->payment($order, PaymentState::PENDING);

        $this->artisan('payments:reconcile', ['--dry-run' => true])
            ->expectsOutput('Payments to reconcile: 1')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_a_provider_timeout_marks_the_payment_unknown_without_a_new_charge(): void
    {
        config([
            'rifacloud.payment_gateway' => 'fake',
            'rifacloud.payment_fake_outcome' => 'TIMEOUT',
        ]);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->payment($order, PaymentState::PENDING);

        $this->artisan('payments:reconcile')
            ->expectsOutput('Payments reconciled: 1')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PAYMENT_REVIEW, $order->refresh()->status);
        $this->assertSame(PaymentState::UNKNOWN, $payment->refresh()->status);
        $this->assertTrue((bool) ($payment->response_data['timeout'] ?? false));
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reconciliation_ignores_terminal_payments(): void
    {
        config(['rifacloud.payment_gateway' => 'fake']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->payment($order, PaymentState::APPROVED);

        $this->artisan('payments:reconcile')
            ->expectsOutput('Payments reconciled: 0')
            ->assertExitCode(0);

        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    private function reservedOrder(User $user): Order
    {
        $raffle = $this->createRaffle(maxNumber: 2);

        return app(OrderReservationService::class)->create($user, $raffle->id, [1, 2]);
    }

    private function payment(Order $order, PaymentState $status): Payment
    {
        return Payment::query()->create([
            'order_id' => $order->id,
            'provider' => PaymentProvider::MERCADOPAGO,
            'external_id' => 'SIM-COP-'.$order->total_amount.'-RECONCILE',
            'status' => $status,
            'amount' => $order->total_amount,
            'currency' => 'COP',
        ]);
    }
}
