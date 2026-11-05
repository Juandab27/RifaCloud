<?php

namespace Tests\Feature\Payments;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Payments\Exceptions\PaymentGatewayException;
use App\Modules\Payments\Exceptions\PaymentGatewayTimeoutException;
use App\Modules\Payments\Exceptions\PaymentNotFoundException;
use App\Modules\Payments\Gateways\MercadoPagoGateway;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'app.url' => 'https://rifacloud.test',
            'mercadopago.access_token' => 'test-access-token',
            'mercadopago.sandbox' => true,
            'mercadopago.base_url' => 'https://api.mercadopago.test',
            'mercadopago.timeout' => 5,
            'mercadopago.back_url' => null,
            'mercadopago.webhook_url' => null,
        ]);
    }

    public function test_creating_a_preference_uses_a_deterministic_reference_and_sandbox_url(): void
    {
        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response([
                'id' => 'pref-123',
                'init_point' => 'https://mercadopago.test/init',
                'sandbox_init_point' => 'https://mercadopago.test/sandbox',
                'live_mode' => false,
            ], 201),
        ]);

        $session = $this->gateway()->createCheckout($this->order());

        $this->assertSame('MP-ORDER-7', $session->externalId);
        $this->assertSame('https://mercadopago.test/sandbox', $session->checkoutUrl);
        $this->assertSame('pref-123', $session->raw['preference_id']);
        $this->assertTrue($session->raw['sandbox']);
        $this->assertFalse($session->raw['live_mode']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.mercadopago.test/checkout/preferences'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request->hasHeader('X-Idempotency-Key', 'MP-ORDER-7')
                && $request['external_reference'] === 'MP-ORDER-7'
                && $request['items'][0]['title'] === 'Rifa: Rifa de prueba'
                && $request['items'][0]['quantity'] === 1
                && (float) $request['items'][0]['unit_price'] === 20.0
                && $request['items'][0]['currency_id'] === 'COP'
                && $request['back_urls']['success'] === 'https://rifacloud.test/orders/7?pago=retorno'
                && $request['notification_url'] === 'https://rifacloud.test/api/webhooks/mercadopago'
                && $request['metadata']['order_id'] === 7;
        });
    }

    public function test_the_same_order_always_uses_the_same_external_reference_and_idempotency_key(): void
    {
        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response([
                'id' => 'pref-123',
                'init_point' => 'https://mercadopago.test/init',
                'sandbox_init_point' => 'https://mercadopago.test/sandbox',
            ], 201),
        ]);

        $first = $this->gateway()->createCheckout($this->order());
        $second = $this->gateway()->createCheckout($this->order());

        $this->assertSame($first->externalId, $second->externalId);

        Http::assertSentCount(2);
        Http::assertSent(
            fn (Request $request): bool => $request->hasHeader('X-Idempotency-Key', 'MP-ORDER-7'),
        );
    }

    public function test_non_sandbox_mode_uses_the_production_init_point(): void
    {
        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response([
                'id' => 'pref-123',
                'init_point' => 'https://mercadopago.test/init',
                'sandbox_init_point' => 'https://mercadopago.test/sandbox',
            ], 201),
        ]);

        $session = $this->gateway(sandbox: false)->createCheckout($this->order());

        $this->assertSame('https://mercadopago.test/init', $session->checkoutUrl);
        $this->assertFalse($session->raw['sandbox']);
    }

    public function test_create_preference_rejects_an_incomplete_provider_response(): void
    {
        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response([
                'id' => 'pref-123',
            ], 201),
        ]);

        $this->expectException(PaymentGatewayException::class);

        $this->gateway()->createCheckout($this->order());
    }

    public function test_provider_4xx_errors_are_not_treated_as_uncertain_payments(): void
    {
        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response([
                'error' => 'invalid_token',
                'message' => 'Invalid access token',
            ], 401),
        ]);

        $this->expectException(PaymentGatewayException::class);

        $this->gateway()->createCheckout($this->order());
    }

    public function test_provider_5xx_errors_are_timeouts(): void
    {
        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response([], 503),
        ]);

        $this->expectException(PaymentGatewayTimeoutException::class);

        $this->gateway()->createCheckout($this->order());
    }

    public function test_connection_errors_are_timeouts(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

        $this->expectException(PaymentGatewayTimeoutException::class);

        $this->gateway()->createCheckout($this->order());
    }

    public function test_no_payment_found_is_neither_rejected_nor_unknown(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [],
            ]),
        ]);

        $this->expectException(PaymentNotFoundException::class);

        $this->gateway()->verifyPayment('MP-ORDER-7');
    }

    public function test_verification_searches_by_external_reference_and_converts_amounts_to_cents(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$this->paymentResult(['transaction_amount' => 20.55])],
            ]),
        ]);

        $status = $this->gateway()->verifyPayment('MP-ORDER-7');

        $this->assertSame(PaymentState::APPROVED, $status->status);
        $this->assertSame(2055, $status->amount);
        $this->assertSame('COP', $status->currency);
        $this->assertSame('MP-ORDER-7', $status->externalId);
        $this->assertSame('123456', $status->raw['mp_payment_id']);
        $this->assertSame('accredited', $status->raw['mp_status_detail']);
        $this->assertArrayNotHasKey('payer', $status->raw);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/v1/payments/search')
                && str_contains($request->url(), 'external_reference=MP-ORDER-7')
                && $request->hasHeader('Authorization', 'Bearer test-access-token');
        });
    }

    public function test_verification_ignores_payments_from_another_external_reference(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [
                    $this->paymentResult(['external_reference' => 'MP-ORDER-OTHER']),
                ],
            ]),
        ]);

        $this->expectException(PaymentNotFoundException::class);

        $this->gateway()->verifyPayment('MP-ORDER-7');
    }

    public function test_mercadopago_statuses_are_mapped_to_the_internal_states(): void
    {
        $cases = [
            'approved' => PaymentState::APPROVED,
            'pending' => PaymentState::PENDING,
            'in_process' => PaymentState::PENDING,
            'authorized' => PaymentState::PENDING,
            'rejected' => PaymentState::REJECTED,
            'cancelled' => PaymentState::REJECTED,
            'refunded' => PaymentState::REFUND_REQUIRED,
            'charged_back' => PaymentState::REFUND_REQUIRED,
            'in_mediation' => PaymentState::UNKNOWN,
            'unexpected_status' => PaymentState::UNKNOWN,
        ];

        $current = null;

        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => function () use (&$current) {
                return Http::response([
                    'results' => [$current],
                ]);
            },
        ]);

        foreach ($cases as $mpStatus => $expected) {
            $current = $this->paymentResult(['status' => $mpStatus]);

            $status = $this->gateway()->verifyPayment('MP-ORDER-7');

            $this->assertSame($expected, $status->status, "Unexpected mapping for {$mpStatus}");
            $this->assertSame($mpStatus, $status->raw['mp_status']);
        }
    }

    public function test_an_approved_attempt_wins_over_rejected_and_pending_attempts(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [
                    $this->paymentResult(['id' => 1, 'status' => 'rejected']),
                    $this->paymentResult(['id' => 2, 'status' => 'approved']),
                    $this->paymentResult(['id' => 3, 'status' => 'pending']),
                ],
            ]),
        ]);

        $status = $this->gateway()->verifyPayment('MP-ORDER-7');

        $this->assertSame(PaymentState::APPROVED, $status->status);
        $this->assertSame('2', $status->raw['mp_payment_id']);
    }

    public function test_a_refund_wins_over_a_newer_pending_attempt(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [
                    $this->paymentResult(['id' => 1, 'status' => 'refunded']),
                    $this->paymentResult(['id' => 2, 'status' => 'pending']),
                ],
            ]),
        ]);

        $status = $this->gateway()->verifyPayment('MP-ORDER-7');

        $this->assertSame(PaymentState::REFUND_REQUIRED, $status->status);
    }

    public function test_verification_5xx_is_a_timeout(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([], 502),
        ]);

        $this->expectException(PaymentGatewayTimeoutException::class);

        $this->gateway()->verifyPayment('MP-ORDER-7');
    }

    public function test_payment_live_mode_preserves_an_absent_field_as_null(): void
    {
        $result = $this->paymentResult();
        unset($result['live_mode']);

        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$result],
            ]),
        ]);

        $status = $this->gateway()->verifyPayment('MP-ORDER-7');

        $this->assertArrayHasKey('mp_live_mode', $status->raw);
        $this->assertNull($status->raw['mp_live_mode']);
    }

    public function test_payment_live_mode_preserves_an_explicit_true_value(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$this->paymentResult(['live_mode' => true])],
            ]),
        ]);

        $status = $this->gateway()->verifyPayment('MP-ORDER-7');

        $this->assertTrue($status->raw['mp_live_mode']);
    }

    public function test_an_empty_access_token_is_rejected_explicitly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MERCADOPAGO_ACCESS_TOKEN');

        new MercadoPagoGateway('', true, 'https://api.mercadopago.test', 5);
    }

    public function test_live_mode_preserves_an_absent_field_as_null(): void
    {
        $response = [
            'id' => 'pref-123',
            'init_point' => 'https://mercadopago.test/init',
            'sandbox_init_point' => 'https://mercadopago.test/sandbox',
        ];

        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response($response, 201),
        ]);

        $session = $this->gateway()->createCheckout($this->order());

        $this->assertArrayHasKey('live_mode', $session->raw);
        $this->assertNull($session->raw['live_mode']);
    }

    public function test_live_mode_preserves_an_explicit_true_value(): void
    {
        Http::fake([
            'https://api.mercadopago.test/checkout/preferences' => Http::response([
                'id' => 'pref-123',
                'init_point' => 'https://mercadopago.test/init',
                'sandbox_init_point' => 'https://mercadopago.test/sandbox',
                'live_mode' => true,
            ], 201),
        ]);

        $session = $this->gateway()->createCheckout($this->order());

        $this->assertTrue($session->raw['live_mode']);
    }

    public function test_webhook_signature_is_validated_and_the_payment_is_fetched_from_the_api(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/123456' => Http::response(
                $this->paymentResult(),
            ),
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$this->paymentResult()],
            ]),
        ]);

        $event = $this->gateway()->parseWebhook(
            [
                'type' => 'payment',
                'action' => 'payment.updated',
                'data' => ['id' => '123456'],
            ],
            [
                'x-signature' => $this->signature('123456'),
                'x-request-id' => 'request-1',
            ],
        );

        $this->assertSame('MP-ORDER-7', $event->externalId);
        $this->assertSame(PaymentState::APPROVED, $event->payment?->status);
        $this->assertSame('123456', $event->payment?->raw['mp_payment_id']);
        $this->assertSame('approved', $event->payload['mp_status']);
        $this->assertArrayNotHasKey('payer', $event->payload);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/payments/123456')
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer test-access-token'));
    }

    public function test_webhook_with_an_invalid_signature_is_rejected_without_calling_the_api(): void
    {
        Http::fake();

        $this->expectException(InvalidWebhookSignatureException::class);

        $this->gateway()->parseWebhook(
            [
                'type' => 'payment',
                'data' => ['id' => '123456'],
            ],
            [
                'x-signature' => 'ts=1704908010,v1=invalid',
                'x-request-id' => 'request-1',
            ],
        );

        Http::assertNothingSent();
    }

    public function test_webhook_without_a_signature_is_rejected(): void
    {
        Http::fake();

        $this->expectException(InvalidWebhookSignatureException::class);

        $this->gateway()->parseWebhook(
            [
                'type' => 'payment',
                'data' => ['id' => '123456'],
            ],
            ['x-request-id' => 'request-1'],
        );
    }

    public function test_an_unsupported_topic_is_acknowledged_without_calling_the_api(): void
    {
        Http::fake();

        $event = $this->gateway()->parseWebhook(
            [
                'type' => 'merchant_order',
                'data' => ['id' => 'ORDER-1'],
            ],
            [
                'x-signature' => $this->signature('ORDER-1'),
                'x-request-id' => 'request-1',
            ],
        );

        $this->assertNull($event->payment);
        $this->assertNull($event->externalId);
        $this->assertSame('merchant_order', $event->eventType);

        Http::assertNothingSent();
    }

    public function test_webhook_data_id_is_lowercased_in_the_signature_manifest(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/ABC123' => Http::response(
                $this->paymentResult(),
            ),
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$this->paymentResult()],
            ]),
        ]);

        $event = $this->gateway()->parseWebhook(
            [
                'type' => 'payment',
                'data' => ['id' => 'ABC123'],
            ],
            [
                'x-signature' => $this->signature('abc123'),
                'x-request-id' => 'request-1',
            ],
        );

        $this->assertSame('MP-ORDER-7', $event->externalId);
    }

    public function test_an_approved_webhook_with_a_failed_duplicate_search_is_marked_unconfirmed(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/123456' => Http::response(
                $this->paymentResult(),
            ),
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([], 503),
        ]);

        $event = $this->gateway()->parseWebhook(
            [
                'type' => 'payment',
                'data' => ['id' => '123456'],
            ],
            [
                'x-signature' => $this->signature('123456'),
                'x-request-id' => 'request-1',
            ],
        );

        $this->assertSame(PaymentState::APPROVED, $event->payment?->status);
        $this->assertArrayHasKey('mp_approved_payment_count', $event->payment?->raw ?? []);
        $this->assertNull($event->payment?->raw['mp_approved_payment_count']);
        $this->assertTrue((bool) ($event->payment?->raw['mp_approved_payment_count_unconfirmed'] ?? false));
    }

    public function test_an_approved_webhook_with_a_confirmed_search_reports_a_count_of_one(): void
    {
        $payment = $this->paymentResult();

        Http::fake([
            'https://api.mercadopago.test/v1/payments/123456' => Http::response($payment),
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$payment],
            ]),
        ]);

        $event = $this->gateway()->parseWebhook(
            [
                'type' => 'payment',
                'data' => ['id' => '123456'],
            ],
            [
                'x-signature' => $this->signature('123456'),
                'x-request-id' => 'request-1',
            ],
        );

        $this->assertSame(1, $event->payment?->raw['mp_approved_payment_count']);
        $this->assertFalse((bool) ($event->payment?->raw['mp_approved_payment_count_unconfirmed'] ?? false));
        $this->assertSame(['123456'], $event->payment?->raw['mp_approved_payment_ids']);
    }

    public function test_duplicate_search_entries_are_counted_once_by_provider_id(): void
    {
        $payment = $this->paymentResult();

        Http::fake([
            'https://api.mercadopago.test/v1/payments/123456' => Http::response($payment),
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$payment, $payment],
            ]),
        ]);

        $event = $this->gateway()->parseWebhook(
            [
                'type' => 'payment',
                'data' => ['id' => '123456'],
            ],
            [
                'x-signature' => $this->signature('123456'),
                'x-request-id' => 'request-1',
            ],
        );

        $this->assertSame(1, $event->payment?->raw['mp_approved_payment_count']);
        $this->assertSame(['123456'], $event->payment?->raw['mp_approved_payment_ids']);
    }

    public function test_a_search_without_the_notified_payment_is_unconfirmed(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/123456' => Http::response(
                $this->paymentResult(),
            ),
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$this->paymentResult(['id' => 999999])],
            ]),
        ]);

        $event = $this->gateway()->parseWebhook(
            [
                'type' => 'payment',
                'data' => ['id' => '123456'],
            ],
            [
                'x-signature' => $this->signature('123456'),
                'x-request-id' => 'request-1',
            ],
        );

        $this->assertNull($event->payment?->raw['mp_approved_payment_count']);
        $this->assertTrue((bool) ($event->payment?->raw['mp_approved_payment_count_unconfirmed'] ?? false));
    }

    public function test_a_paginated_search_is_unconfirmed(): void
    {
        $payment = $this->paymentResult();

        Http::fake([
            'https://api.mercadopago.test/v1/payments/123456' => Http::response($payment),
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$payment],
                'paging' => ['total' => 60, 'limit' => 50, 'offset' => 0],
            ]),
        ]);

        $event = $this->gateway()->parseWebhook(
            [
                'type' => 'payment',
                'data' => ['id' => '123456'],
            ],
            [
                'x-signature' => $this->signature('123456'),
                'x-request-id' => 'request-1',
            ],
        );

        $this->assertNull($event->payment?->raw['mp_approved_payment_count']);
        $this->assertTrue((bool) ($event->payment?->raw['mp_approved_payment_count_unconfirmed'] ?? false));
    }

    public function test_uppercase_signature_keys_and_header_names_are_normalized(): void
    {
        $payment = $this->paymentResult();
        $timestamp = 1704908010;
        $manifest = 'id:123456;request-id:request-1;ts:'.$timestamp.';';
        $hash = hash_hmac('sha256', $manifest, 'test-webhook-secret');

        Http::fake([
            'https://api.mercadopago.test/v1/payments/123456' => Http::response($payment),
            'https://api.mercadopago.test/v1/payments/search*' => Http::response([
                'results' => [$payment],
            ]),
        ]);

        $event = $this->gateway()->parseWebhook(
            [
                'type' => 'payment',
                'data' => ['id' => '123456'],
            ],
            [
                'X-Signature' => 'TS='.$timestamp.',V1='.$hash,
                'X-Request-Id' => 'request-1',
            ],
        );

        $this->assertSame('MP-ORDER-7', $event->externalId);
    }

    public function test_a_429_on_the_payment_fetch_is_a_retryable_timeout(): void
    {
        Http::fake([
            'https://api.mercadopago.test/v1/payments/123456' => Http::response([], 429),
        ]);

        $this->expectException(PaymentGatewayTimeoutException::class);

        $this->gateway()->fetchPayment('123456');
    }

    private function signature(
        string $dataId,
        string $requestId = 'request-1',
        int $timestamp = 1704908010,
        string $secret = 'test-webhook-secret',
    ): string {
        $manifest = 'id:'.strtolower($dataId).';request-id:'.$requestId.';ts:'.$timestamp.';';

        return 'ts='.$timestamp.',v1='.hash_hmac('sha256', $manifest, $secret);
    }

    private function gateway(bool $sandbox = true, string $webhookSecret = 'test-webhook-secret'): MercadoPagoGateway
    {
        return new MercadoPagoGateway(
            'test-access-token',
            $sandbox,
            'https://api.mercadopago.test',
            5,
            $webhookSecret,
        );
    }

    private function order(int $id = 7, int $amount = 2000): Order
    {
        $order = new Order([
            'order_number' => 'RC-TEST-'.$id,
            'total_amount' => $amount,
        ]);
        $order->id = $id;
        $order->setRelation('raffle', new Raffle(['title' => 'Rifa de prueba']));

        return $order;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function paymentResult(array $overrides = []): array
    {
        return array_merge([
            'id' => 123456,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => 20.0,
            'currency_id' => 'COP',
            'external_reference' => 'MP-ORDER-7',
            'payment_method_id' => 'visa',
            'payment_type_id' => 'credit_card',
            'live_mode' => false,
            'date_created' => '2026-10-25T00:00:00.000-04:00',
            'date_approved' => '2026-10-25T00:00:01.000-04:00',
            'payer' => ['email' => 'buyer@example.test'],
        ], $overrides);
    }
}
