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
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

class ApprovedPaymentAuditTest extends TestCase
{
    use CreatesRaffles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'rifacloud.payment_gateway' => 'mercadopago',
            'mercadopago.access_token' => 'test-access-token',
            'mercadopago.sandbox' => true,
            'mercadopago.base_url' => 'https://api.mercadopago.test',
            'mercadopago.timeout' => 5,
            'mercadopago.webhook_secret' => 'test-webhook-secret',
        ]);
    }

    public function test_an_approved_payment_audit_escalates_a_second_approved_charge_without_releasing_numbers(): void
    {
        [$order, $payment] = $this->paidOrder();

        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [
                    $this->paymentResult($order, 111111),
                    $this->paymentResult($order, 222222),
                ],
            ]),
        ]);

        $result = app(\App\Modules\Payments\Services\PaymentService::class)->reconcileApproved();

        $this->assertSame(1, $result['inspected']);
        $this->assertSame(1, $result['incidents']);
        $this->assertSame(0, $result['errors']);

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->status);
        $this->assertTrue((bool) ($payment->response_data['multiple_approved_payments'] ?? false));
        $this->assertTrue((bool) ($payment->response_data['detected_by_reconciliation'] ?? false));
        $this->assertSame(2, $payment->response_data['approved_payment_count']);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::AVAILABLE->value)->count());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_an_approved_payment_audit_leaves_a_single_charge_untouched(): void
    {
        [$order, $payment] = $this->paidOrder();

        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$this->paymentResult($order, 111111)],
            ]),
        ]);

        $result = app(\App\Modules\Payments\Services\PaymentService::class)->reconcileApproved();

        $this->assertSame(1, $result['inspected']);
        $this->assertSame(0, $result['incidents']);
        $this->assertSame(0, $result['errors']);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_an_unconfirmed_approved_payment_audit_fails_without_changing_state(): void
    {
        [$order, $payment] = $this->paidOrder();

        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([], 503),
        ]);

        $result = app(\App\Modules\Payments\Services\PaymentService::class)->reconcileApproved();

        $this->assertSame(1, $result['inspected']);
        $this->assertSame(0, $result['incidents']);
        $this->assertSame(1, $result['errors']);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
        $this->assertFalse((bool) ($payment->response_data['multiple_approved_payments'] ?? false));
        $this->assertFalse((bool) ($payment->response_data['detected_by_reconciliation'] ?? false));
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_the_approved_payment_audit_dry_run_does_not_call_the_provider(): void
    {
        [$order, $payment] = $this->paidOrder();

        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldNotReceive('verifyPayment');

        $result = app(\App\Modules\Payments\Services\PaymentService::class)->reconcileApproved(50, true);

        $this->assertSame(1, $result['inspected']);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function paidOrder(): array
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle(maxNumber: 2);
        $order = app(OrderReservationService::class)->create($user, $raffle->id, [1, 2]);

        $order->forceFill([
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
        ])->save();

        RaffleNumber::query()
            ->where('order_id', $order->id)
            ->update([
                'status' => NumberStatus::SOLD->value,
                'sold_at' => now(),
                'reserved_until' => null,
            ]);

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'provider' => PaymentProvider::MERCADOPAGO,
            'external_id' => 'MP-ORDER-'.$order->id,
            'status' => PaymentState::APPROVED,
            'amount' => $order->total_amount,
            'currency' => 'COP',
            'verified_at' => now(),
            'response_data' => [
                'mp_payment_id' => '111111',
            ],
        ]);

        return [$order, $payment];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentResult(Order $order, int $providerId): array
    {
        return [
            'id' => $providerId,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => $order->total_amount,
            'currency_id' => 'COP',
            'external_reference' => 'MP-ORDER-'.$order->id,
            'date_created' => '2026-10-25T00:00:00.000-04:00',
            'date_last_updated' => '2026-10-25T00:00:02.000-04:00',
            'live_mode' => true,
        ];
    }
}
