<?php

namespace Tests\Feature\Payments;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReservationService;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\PaymentWebhookStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

class MercadoPagoWebhookTest extends TestCase
{
    use CreatesRaffles;
    use RefreshDatabase;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $paymentResults = [];

    /**
     * @var array<string, int>
     */
    private array $paymentFailures = [];

    /**
     * @var list<array<string, mixed>>|null
     */
    private ?array $searchResults = null;

    private ?int $searchFailureStatus = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // Http::fake() appends stubs and keeps earlier matches, so a single
        // dynamic stub is registered per test and its data is replaced as the
        // provider state changes (e.g. a 503 followed by a successful retry).
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/v1/payments/search')) {
                if ($this->searchFailureStatus !== null) {
                    return Http::response([], $this->searchFailureStatus);
                }

                return Http::response(['results' => $this->searchResults ?? []]);
            }

            if (preg_match('#/v1/payments/([^/?]+)#', $url, $matches) === 1) {
                $paymentId = rawurldecode($matches[1]);

                if (isset($this->paymentFailures[$paymentId])) {
                    return Http::response(
                        ['message' => 'Provider temporarily unavailable.'],
                        $this->paymentFailures[$paymentId],
                    );
                }

                if (isset($this->paymentResults[$paymentId])) {
                    return Http::response($this->paymentResults[$paymentId]);
                }
            }

            return Http::response(['message' => 'Payment not found.'], 404);
        });

        config([
            'app.url' => 'https://rifacloud.test',
            'rifacloud.payment_gateway' => 'mercadopago',
            'mercadopago.access_token' => 'test-access-token',
            'mercadopago.sandbox' => true,
            'mercadopago.base_url' => 'https://api.mercadopago.test',
            'mercadopago.timeout' => 5,
            'mercadopago.back_url' => null,
            'mercadopago.webhook_url' => 'https://rifacloud.test/api/webhooks/mercadopago',
            'mercadopago.webhook_secret' => 'test-webhook-secret',
        ]);
    }

    public function test_a_missing_signature_returns_401_without_touching_data(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->postWebhook($this->payload(), ['x-request-id' => 'request-1'])
            ->assertStatus(401)
            ->assertExactJson(['status' => 'invalid_signature']);

        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_an_invalid_signature_returns_401_without_calling_the_provider(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $this->pendingPayment($order);

        $this->postWebhook($this->payload(), [
            'x-signature' => 'ts=1704908010,v1=invalid',
            'x-request-id' => 'request-1',
        ])->assertStatus(401);

        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_an_approved_webhook_marks_the_order_paid_and_sells_the_numbers(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->fakeWebhookPayment($this->paymentResult($order, 'approved'));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))
            ->assertOk()
            ->assertExactJson(['status' => 'processed']);

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(PaymentState::APPROVED, $payment->status);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame('123456', $payment->response_data['mp_payment_id']);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertSame(PaymentWebhookStatus::PROCESSED, PaymentWebhookEvent::query()->firstOrFail()->status);
    }

    public function test_a_repeated_webhook_is_applied_only_once(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->fakeWebhookPayment($this->paymentResult($order, 'approved'));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();
        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_an_amount_mismatch_is_ignored_without_changing_state(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->fakeWebhookPayment($this->paymentResult($order, 'approved', [
            'transaction_amount' => 19.00,
        ]));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))
            ->assertOk()
            ->assertExactJson(['status' => 'ignored']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $event = PaymentWebhookEvent::query()->firstOrFail();
        $this->assertSame(PaymentWebhookStatus::IGNORED, $event->status);
        $this->assertSame('verification_mismatch', $event->payload['reason']);
    }

    public function test_a_currency_mismatch_is_ignored_without_changing_state(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->fakeWebhookPayment($this->paymentResult($order, 'approved', [
            'currency_id' => 'USD',
        ]));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))
            ->assertOk()
            ->assertExactJson(['status' => 'ignored']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);
    }

    public function test_an_unknown_external_reference_is_ignored(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->fakeWebhookPayment($this->paymentResult($order, 'approved', [
            'external_reference' => 'MP-ORDER-999',
        ]));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))
            ->assertOk()
            ->assertExactJson(['status' => 'ignored']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);

        $event = PaymentWebhookEvent::query()->firstOrFail();
        $this->assertSame(PaymentWebhookStatus::IGNORED, $event->status);
        $this->assertSame('unknown_external_reference', $event->payload['reason']);
    }

    public function test_a_late_approval_keeps_reserved_numbers_and_requires_a_refund(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->travel(11)->minutes();

        $this->fakeWebhookPayment($this->paymentResult($order, 'approved'));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->status);
        $this->assertTrue((bool) ($payment->response_data['late_approval'] ?? false));
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    public function test_a_late_approval_never_reassigns_numbers_owned_by_another_order(): void
    {
        $this->travelTo(now()->startOfSecond());

        $raffle = $this->createRaffle(maxNumber: 2);

        $buyer = User::factory()->create();
        $order = $this->reservedOrder($buyer, raffle: $raffle);
        $payment = $this->pendingPayment($order);

        $this->travel(11)->minutes();
        app(\App\Modules\Orders\Services\ReservationExpiryService::class)->expire();

        $otherBuyer = User::factory()->create();
        $otherOrder = $this->reservedOrder($otherBuyer, raffle: $raffle);

        $this->fakeWebhookPayment($this->paymentResult($order, 'approved'));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->status);

        foreach (RaffleNumber::query()->whereIn('number', [1, 2])->get() as $number) {
            $this->assertSame(NumberStatus::RESERVED, $number->status);
            $this->assertSame($otherOrder->id, $number->order_id);
        }

        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    public function test_a_rejected_webhook_rejects_the_order_and_releases_the_numbers(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->fakeWebhookPayment($this->paymentResult($order, 'rejected'));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $this->assertSame(OrderStatus::REJECTED, $order->refresh()->status);
        $this->assertSame(PaymentState::REJECTED, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::AVAILABLE->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_a_refund_after_approval_requires_a_refund_and_keeps_the_numbers_sold(): void
    {
        [$order, $payment] = $this->paidOrderThroughWebhook();

        $this->fakeWebhookPayment($this->paymentResult($order, 'refunded', [
            'date_last_updated' => '2026-10-25T00:10:00.000-04:00',
        ]));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->status);
        $this->assertTrue((bool) ($payment->response_data['refund_incident'] ?? false));
        $this->assertSame('refunded', $payment->response_data['provider_refund_status']);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_a_chargeback_after_approval_requires_a_refund_and_keeps_the_numbers_sold(): void
    {
        [$order, $payment] = $this->paidOrderThroughWebhook();

        $this->fakeWebhookPayment($this->paymentResult($order, 'charged_back', [
            'date_last_updated' => '2026-10-25T00:10:00.000-04:00',
        ]));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->refresh()->status);
        $this->assertSame('charged_back', $payment->response_data['provider_refund_status']);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_mediation_after_approval_is_a_refund_incident_not_a_downgrade(): void
    {
        [$order, $payment] = $this->paidOrderThroughWebhook();

        $this->fakeWebhookPayment($this->paymentResult($order, 'in_mediation', [
            'date_last_updated' => '2026-10-25T00:10:00.000-04:00',
        ]));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->refresh()->status);
        $this->assertTrue((bool) ($payment->response_data['mediation_after_approval'] ?? false));
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_mediation_before_approval_goes_to_payment_review(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->fakeWebhookPayment($this->paymentResult($order, 'in_mediation'));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $this->assertSame(OrderStatus::PAYMENT_REVIEW, $order->refresh()->status);
        $this->assertSame(PaymentState::UNKNOWN, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
    }

    public function test_a_provider_timeout_returns_503_and_a_retry_can_succeed(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->fakeProviderFailure('123456', 503);

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'retry']);

        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);

        $this->fakeWebhookPayment($this->paymentResult($order, 'approved'));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))
            ->assertOk()
            ->assertExactJson(['status' => 'processed']);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
    }

    public function test_multiple_approved_payments_require_a_refund_without_selling_numbers(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $first = $this->paymentResult($order, 'approved', ['id' => 111111]);
        $second = $this->paymentResult($order, 'approved', [
            'id' => 222222,
            'date_created' => '2026-10-25T00:05:00.000-04:00',
        ]);

        $this->paymentResults['111111'] = $first;
        $this->paymentResults['222222'] = $second;
        $this->searchResults = [$first, $second];

        $this->postWebhook($this->payload('111111'), $this->signedHeaders('111111'))->assertOk();

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->status);
        $this->assertTrue((bool) ($payment->response_data['multiple_approved_payments'] ?? false));
        $this->assertSame(2, $payment->response_data['approved_payment_count']);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_a_stale_pending_notification_does_not_downgrade_an_approved_payment(): void
    {
        [$order, $payment] = $this->paidOrderThroughWebhook();

        $this->fakeWebhookPayment($this->paymentResult($order, 'pending', [
            'date_last_updated' => '2026-10-25T00:20:00.000-04:00',
        ]));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $event = PaymentWebhookEvent::query()->latest('id')->firstOrFail();
        $this->assertSame(PaymentWebhookStatus::IGNORED, $event->status);
        $this->assertSame('stale_event', $event->payload['reason']);
    }

    public function test_the_webhook_response_and_audit_row_do_not_persist_sensitive_data(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->fakeWebhookPayment($this->paymentResult($order, 'approved'));

        $response = $this->postWebhook($this->payload(), $this->signedHeaders('123456'));

        $response->assertOk()->assertExactJson(['status' => 'processed']);

        $event = PaymentWebhookEvent::query()->firstOrFail();
        $payload = json_encode($event->payload);

        foreach (['payer', 'buyer@example.test', 'last_four_digits', '4242', 'access_token', 'test-access-token'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, (string) $payload);
        }

        $payment->refresh();
        $stored = json_encode($payment->response_data);

        foreach (['payer', 'buyer@example.test', 'last_four_digits', '4242', 'access_token', 'test-access-token'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, (string) $stored);
        }
    }

    public function test_a_duplicate_search_failure_keeps_the_case_in_review_without_selling_numbers(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->paymentResults['123456'] = $this->paymentResult($order, 'approved');
        $this->searchFailureStatus = 503;

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))
            ->assertOk()
            ->assertExactJson(['status' => 'processed']);

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->status);
        $this->assertTrue((bool) ($payment->response_data['approved_payment_count_unconfirmed'] ?? false));
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::RESERVED->value)->count());
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $event = PaymentWebhookEvent::query()->firstOrFail();
        $this->assertSame(PaymentWebhookStatus::PROCESSED, $event->status);
        $this->assertTrue((bool) ($event->payload['mp_approved_payment_count_unconfirmed'] ?? false));
    }

    public function test_a_second_approved_payment_in_another_notification_requires_a_refund(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $first = $this->paymentResult($order, 'approved', ['id' => 111111]);
        $second = $this->paymentResult($order, 'approved', [
            'id' => 222222,
            'date_created' => '2026-10-25T00:05:00.000-04:00',
            'date_last_updated' => '2026-10-25T00:05:02.000-04:00',
        ]);

        $this->paymentResults['111111'] = $first;
        $this->searchResults = [$first];

        $this->postWebhook($this->payload('111111'), $this->signedHeaders('111111'))->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->paymentResults['222222'] = $second;
        $this->searchResults = [$first, $second];

        $this->postWebhook($this->payload('222222'), $this->signedHeaders('222222'))->assertOk();

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->status);
        $this->assertTrue((bool) ($payment->response_data['multiple_approved_payments'] ?? false));
        $this->assertSame(2, $payment->response_data['approved_payment_count']);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
    }

    public function test_a_missing_webhook_secret_returns_503_without_touching_data(): void
    {
        config(['mercadopago.webhook_secret' => '']);

        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'retry']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);
    }

    public function test_malformed_json_returns_400_without_touching_data(): void
    {
        $response = $this->call(
            'POST',
            '/api/webhooks/mercadopago',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"type":',
        );

        $response->assertStatus(400)->assertExactJson(['status' => 'invalid_payload']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_chargeback_and_claim_topics_are_acknowledged_but_not_processed(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $topics = ['topic_chargebacks_wh', 'topic_claims_integration_wh'];

        foreach ($topics as $index => $topic) {
            $this->postWebhook(
                ['type' => $topic, 'action' => $topic, 'data' => ['id' => 'EVENT-'.$index]],
                $this->signedHeaders('EVENT-'.$index, 'request-'.$index),
            )->assertOk()->assertExactJson(['status' => 'ignored']);
        }

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(PaymentState::PENDING, $payment->refresh()->status);

        $events = PaymentWebhookEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);

        foreach ($topics as $index => $topic) {
            $this->assertSame($topic, $events[$index]->event_type);
            $this->assertSame(PaymentWebhookStatus::IGNORED, $events[$index]->status);
            $this->assertSame('unsupported_topic', $events[$index]->payload['reason']);
        }
    }

    public function test_a_failed_event_can_be_retried_and_processed(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $result = $this->paymentResult($order, 'approved');
        $this->paymentResults['123456'] = $result;
        $this->searchResults = [$result];

        $providerEventId = hash('sha256', implode('|', [
            'payment',
            '123456',
            'approved',
            'accredited',
            '2026-10-25T00:00:02.000-04:00',
        ]));

        PaymentWebhookEvent::query()->create([
            'provider' => PaymentProvider::MERCADOPAGO,
            'provider_event_id' => $providerEventId,
            'event_type' => 'payment',
            'payload' => ['reason' => 'processing_failed'],
            'status' => PaymentWebhookStatus::FAILED,
            'processed_at' => now(),
        ]);

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))
            ->assertOk()
            ->assertExactJson(['status' => 'processed']);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);

        $event = PaymentWebhookEvent::query()->firstOrFail();
        $this->assertSame(PaymentWebhookStatus::PROCESSED, $event->status);
    }

    public function test_live_mode_false_is_preserved_in_the_event_evidence(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $this->pendingPayment($order);

        $result = $this->paymentResult($order, 'approved', ['live_mode' => false]);
        $this->paymentResults['123456'] = $result;
        $this->searchResults = [$result];

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $event = PaymentWebhookEvent::query()->firstOrFail();

        $this->assertArrayHasKey('mp_live_mode', $event->payload);
        $this->assertFalse($event->payload['mp_live_mode']);
    }

    public function test_an_absent_live_mode_is_distinguishable_from_false(): void
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $this->pendingPayment($order);

        $result = $this->paymentResult($order, 'approved');
        unset($result['live_mode']);

        $this->paymentResults['123456'] = $result;
        $this->searchResults = [$result];

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $event = PaymentWebhookEvent::query()->firstOrFail();

        $this->assertArrayNotHasKey('mp_live_mode', $event->payload);
    }

    public function test_a_long_unsupported_topic_is_safely_bounded(): void
    {
        $topic = 'topic_'.str_repeat('x', 200);

        $this->postWebhook(
            ['type' => $topic, 'data' => ['id' => 'LONG-1']],
            $this->signedHeaders('LONG-1'),
        )->assertOk()->assertExactJson(['status' => 'ignored']);

        $event = PaymentWebhookEvent::query()->firstOrFail();

        $this->assertLessThanOrEqual(100, mb_strlen($event->event_type));
        $this->assertLessThanOrEqual(255, mb_strlen($event->provider_event_id));
        $this->assertSame(PaymentWebhookStatus::IGNORED, $event->status);
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function paidOrderThroughWebhook(): array
    {
        $user = User::factory()->create();
        $order = $this->reservedOrder($user);
        $payment = $this->pendingPayment($order);

        $this->fakeWebhookPayment($this->paymentResult($order, 'approved'));

        $this->postWebhook($this->payload(), $this->signedHeaders('123456'))->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);

        return [$order, $payment->refresh()];
    }

    private function reservedOrder(User $user, array $numbers = [1, 2], ?Raffle $raffle = null): Order
    {
        $raffle ??= $this->createRaffle(maxNumber: 2);

        return app(OrderReservationService::class)->create($user, $raffle->id, $numbers);
    }

    private function pendingPayment(Order $order): Payment
    {
        return Payment::query()->create([
            'order_id' => $order->id,
            'provider' => PaymentProvider::MERCADOPAGO,
            'external_id' => 'MP-ORDER-'.$order->id,
            'status' => PaymentState::PENDING,
            'amount' => $order->total_amount,
            'currency' => 'COP',
            'response_data' => [
                'preference_id' => 'pref-123',
                'sandbox' => true,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function paymentResult(Order $order, string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => 123456,
            'status' => $status,
            'status_detail' => $status === 'approved' ? 'accredited' : $status,
            'transaction_amount' => $order->total_amount,
            'currency_id' => 'COP',
            'external_reference' => 'MP-ORDER-'.$order->id,
            'payment_method_id' => 'visa',
            'payment_type_id' => 'credit_card',
            'live_mode' => true,
            'date_created' => '2026-10-25T00:00:00.000-04:00',
            'date_approved' => '2026-10-25T00:00:01.000-04:00',
            'date_last_updated' => '2026-10-25T00:00:02.000-04:00',
            'payer' => [
                'email' => 'buyer@example.test',
                'identification' => ['number' => '123456789'],
            ],
            'card' => ['last_four_digits' => '4242'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function fakeWebhookPayment(array $result): void
    {
        $paymentId = (string) $result['id'];

        unset($this->paymentFailures[$paymentId]);

        $this->paymentResults[$paymentId] = $result;
        $this->searchResults = [$result];
    }

    private function fakeProviderFailure(string $paymentId, int $status): void
    {
        $this->paymentFailures[$paymentId] = $status;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function postWebhook(array $payload, array $headers): TestResponse
    {
        $dataId = (string) ($payload['data']['id'] ?? '');

        return $this->withHeaders($headers)->postJson(
            '/api/webhooks/mercadopago?data.id='.urlencode($dataId).'&type=payment',
            $payload,
        );
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $dataId, string $requestId = 'request-1'): array
    {
        $timestamp = 1704908010;
        $manifest = 'id:'.strtolower($dataId).';request-id:'.$requestId.';ts:'.$timestamp.';';

        return [
            'x-signature' => 'ts='.$timestamp.',v1='.hash_hmac('sha256', $manifest, 'test-webhook-secret'),
            'x-request-id' => $requestId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $paymentId = '123456'): array
    {
        return [
            'id' => 987654,
            'live_mode' => true,
            'type' => 'payment',
            'action' => 'payment.updated',
            'data' => ['id' => $paymentId],
        ];
    }
}
