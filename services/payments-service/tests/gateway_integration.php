<?php

declare(strict_types=1);

use RifaCloud\PaymentsService\Http\HttpClient;
use RifaCloud\PaymentsService\Http\HttpTransportException;
use RifaCloud\PaymentsService\Payments\InvalidWebhookPayloadException;
use RifaCloud\PaymentsService\Payments\InvalidWebhookSignatureException;
use RifaCloud\PaymentsService\Payments\MercadoPagoGateway;
use RifaCloud\PaymentsService\Payments\PaymentGatewayException;
use RifaCloud\PaymentsService\Payments\PaymentGatewayTimeoutException;
use RifaCloud\PaymentsService\Payments\PaymentNotFoundException;
use RifaCloud\PaymentsService\Payments\PaymentState;

require __DIR__.'/../src/Http/HttpClient.php';
require __DIR__.'/../src/Http/HttpTransportException.php';
require __DIR__.'/../src/Payments/PaymentState.php';
require __DIR__.'/../src/Payments/PaymentProvider.php';
require __DIR__.'/../src/Payments/WebhookStatus.php';
require __DIR__.'/../src/Payments/CheckoutSession.php';
require __DIR__.'/../src/Payments/PaymentStatus.php';
require __DIR__.'/../src/Payments/WebhookEvent.php';
require __DIR__.'/../src/Payments/PaymentConflictException.php';
require __DIR__.'/../src/Payments/PaymentGatewayException.php';
require __DIR__.'/../src/Payments/PaymentGatewayTimeoutException.php';
require __DIR__.'/../src/Payments/PaymentNotFoundException.php';
require __DIR__.'/../src/Payments/InvalidWebhookSignatureException.php';
require __DIR__.'/../src/Payments/InvalidWebhookPayloadException.php';
require __DIR__.'/../src/Payments/PaymentGateway.php';
require __DIR__.'/../src/Payments/MercadoPagoGateway.php';

/**
 * Offline contract test for the Mercado Pago adapter.
 *
 * A fake transport replaces the network so the official signature manifest,
 * the authoritative payment fetch, the duplicate-approval search and the
 * provider error mapping can be verified deterministically without sandbox
 * credentials.
 */

final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: int}> */
    public array $requests = [];

    /** @var list<array{status: int, body: string}>|list<Throwable> */
    public array $responses = [];

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
    ): array {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeoutSeconds,
        ];

        $response = array_shift($this->responses);

        if ($response instanceof Throwable) {
            throw $response;
        }

        if (! is_array($response)) {
            throw new RuntimeException('No fake response queued.');
        }

        return $response;
    }

    public function lastRequest(): array
    {
        return $this->requests[count($this->requests) - 1];
    }
}

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;

    if ($condition) {
        echo "PASS  {$message}\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param  array<string, mixed>  $payload
 */
function jsonResponse(array $payload, int $status = 200): array
{
    return ['status' => $status, 'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
}

function signature(array $payload, string $requestId, int $timestamp, string $secret): string
{
    $dataId = strtolower((string) ($payload['data']['id'] ?? ''));
    $manifest = 'id:'.$dataId.';request-id:'.$requestId.';ts:'.$timestamp.';';

    return 'ts='.$timestamp.',v1='.hash_hmac('sha256', $manifest, $secret);
}

$secret = 'test-webhook-secret';

$gateway = static function (FakeHttpClient $http) use ($secret): MercadoPagoGateway {
    return new MercadoPagoGateway(
        http: $http,
        accessToken: 'test-access-token',
        sandbox: true,
        baseUrl: 'https://api.mercadopago.test',
        timeout: 10,
        webhookSecret: $secret,
        backUrl: 'https://rifacloud.test',
        appUrl: 'https://rifacloud.test',
        webhookUrl: 'https://rifacloud.test/api/webhooks/mercadopago',
    );
};

try {
    // ---- Checkout preference -------------------------------------------
    $http = new FakeHttpClient();
    $http->responses[] = jsonResponse([
        'id' => 'pref-123',
        'init_point' => 'https://mp.test/init',
        'sandbox_init_point' => 'https://mp.test/sandbox',
        'live_mode' => false,
    ]);

    $session = $gateway($http)->createCheckout([
        'id' => 7,
        'order_number' => 'RC-1',
        'total_amount' => 4500,
        'raffle_title' => 'Rifa demo',
    ]);

    $request = $http->lastRequest();
    $body = json_decode((string) $request['body'], true);

    check($request['method'] === 'POST', 'Checkout uses POST');
    check($request['url'] === 'https://api.mercadopago.test/checkout/preferences', 'Checkout hits the preferences endpoint');
    check($request['headers']['Authorization'] === 'Bearer test-access-token', 'Checkout sends the bearer token');
    check($request['headers']['X-Idempotency-Key'] === 'MP-ORDER-7', 'Checkout uses the deterministic idempotency key');
    check($body['external_reference'] === 'MP-ORDER-7', 'The external reference is deterministic per order');
    check((float) $body['items'][0]['unit_price'] === 4500.0, 'The unit price is sent in COP without conversion');
    check($body['items'][0]['currency_id'] === 'COP', 'The checkout currency is COP');
    check($body['items'][0]['title'] === 'Rifa: Rifa demo', 'The item title uses the raffle title');
    check($body['back_urls']['success'] === 'https://rifacloud.test/orders/7?pago=retorno', 'The back URL preserves the React route');
    check($body['notification_url'] === 'https://rifacloud.test/api/webhooks/mercadopago', 'The notification URL is the public webhook');
    check($session->externalId === 'MP-ORDER-7', 'The session keeps the external reference');
    check($session->checkoutUrl === 'https://mp.test/sandbox', 'Sandbox mode prefers sandbox_init_point');
    check($session->raw['preference_id'] === 'pref-123', 'The preference id is persisted as evidence');
    check($session->raw['live_mode'] === false, 'An explicit false live_mode is preserved');

    // ---- Verification search -------------------------------------------
    $http = new FakeHttpClient();
    $http->responses[] = jsonResponse([
        'results' => [
            [
                'id' => 111,
                'status' => 'pending',
                'transaction_amount' => 4500,
                'currency_id' => 'COP',
                'external_reference' => 'MP-ORDER-7',
                'date_created' => '2026-10-25T00:00:00.000-04:00',
            ],
            [
                'id' => 222,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'transaction_amount' => 4500,
                'currency_id' => 'COP',
                'external_reference' => 'MP-ORDER-7',
                'date_created' => '2026-10-25T00:00:02.000-04:00',
                'date_last_updated' => '2026-10-25T00:00:03.000-04:00',
                'live_mode' => true,
            ],
        ],
    ]);

    $status = $gateway($http)->verifyPayment('MP-ORDER-7');
    check($status->status === PaymentState::APPROVED, 'An approved result wins over a pending one');
    check($status->amount === 4500, 'The amount is preserved in COP');
    check($status->currency === 'COP', 'The currency is preserved');
    check($status->raw['mp_payment_id'] === '222', 'The selected provider payment id is recorded');
    check($status->raw['mp_approved_payment_count'] === 1, 'The approved count is recorded');
    check($http->lastRequest()['url'] === 'https://api.mercadopago.test/v1/payments/search?external_reference=MP-ORDER-7&sort=date_created&criteria=desc&limit=50', 'Verification queries the search endpoint with the reference');

    // ---- No payment yet ------------------------------------------------
    $http = new FakeHttpClient();
    $http->responses[] = jsonResponse(['results' => []]);
    $notFound = false;

    try {
        $gateway($http)->verifyPayment('MP-ORDER-8');
    } catch (PaymentNotFoundException) {
        $notFound = true;
    }

    check($notFound, 'No matching provider payment raises PaymentNotFoundException');

    // ---- Provider error mapping ---------------------------------------
    $http = new FakeHttpClient();
    $http->responses[] = jsonResponse(['message' => 'down'], 503);
    $timeout = false;

    try {
        $gateway($http)->verifyPayment('MP-ORDER-9');
    } catch (PaymentGatewayTimeoutException) {
        $timeout = true;
    }

    check($timeout, 'A 5xx provider response maps to a retryable timeout');

    $http = new FakeHttpClient();
    $http->responses[] = jsonResponse(['message' => 'bad request'], 400);
    $rejected = false;

    try {
        $gateway($http)->verifyPayment('MP-ORDER-10');
    } catch (PaymentGatewayException) {
        $rejected = true;
    }

    check($rejected, 'A 4xx provider response maps to a non-retryable gateway error');

    $http = new FakeHttpClient();
    $http->responses[] = new HttpTransportException('connection refused');
    $transport = false;

    try {
        $gateway($http)->verifyPayment('MP-ORDER-11');
    } catch (PaymentGatewayTimeoutException) {
        $transport = true;
    }

    check($transport, 'A transport failure maps to a retryable timeout');

    // ---- Webhook signature and authoritative fetch --------------------
    $payload = ['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => '123456']];
    $headers = [
        'x-signature' => signature($payload, 'request-1', 1704908010, $secret),
        'x-request-id' => 'request-1',
    ];

    $http = new FakeHttpClient();
    $http->responses[] = jsonResponse([
        'id' => '123456',
        'status' => 'approved',
        'status_detail' => 'accredited',
        'transaction_amount' => 4500,
        'currency_id' => 'COP',
        'external_reference' => 'MP-ORDER-7',
        'date_created' => '2026-10-25T00:00:00.000-04:00',
        'date_last_updated' => '2026-10-25T00:00:02.000-04:00',
        'live_mode' => true,
        'payer' => ['email' => 'secret@example.invalid'],
    ]);
    $http->responses[] = jsonResponse(['results' => [
        [
            'id' => '123456',
            'status' => 'approved',
            'external_reference' => 'MP-ORDER-7',
        ],
    ]]);

    $event = $gateway($http)->parseWebhook($payload, $headers);
    $serialized = json_encode($event->payload, JSON_THROW_ON_ERROR);

    check($event->externalId === 'MP-ORDER-7', 'The webhook uses the authoritative external reference');
    check($event->payment?->status === PaymentState::APPROVED, 'The webhook uses the fetched provider status');
    check($event->eventType === 'payment', 'The event type is normalized');
    check(strlen($event->providerEventId) === 64, 'The provider event id is a stable hash');
    check(! str_contains($serialized, 'secret@example.invalid'), 'Payer data is never stored');
    check(
        $http->requests[0]['url'] === 'https://api.mercadopago.test/v1/payments/123456',
        'The notification body is never trusted: the payment is fetched again',
    );

    $http->responses[] = jsonResponse([
        'id' => '123456',
        'status' => 'approved',
        'status_detail' => 'accredited',
        'transaction_amount' => 4500,
        'currency_id' => 'COP',
        'external_reference' => 'MP-ORDER-7',
        'date_created' => '2026-10-25T00:00:00.000-04:00',
        'date_last_updated' => '2026-10-25T00:00:02.000-04:00',
        'live_mode' => true,
    ]);
    $http->responses[] = jsonResponse(['results' => [
        [
            'id' => '123456',
            'status' => 'approved',
            'external_reference' => 'MP-ORDER-7',
        ],
    ]]);

    $repeated = $gateway($http)->parseWebhook($payload, $headers);
    check($repeated->providerEventId === $event->providerEventId, 'The same notification derives the same idempotency key');

    // ---- Webhook rejection paths --------------------------------------
    $badSignature = false;

    try {
        $bad = new FakeHttpClient();
        $badGateway = $gateway($bad);
        $badGateway->parseWebhook($payload, [
            'x-signature' => 'ts=1704908010,v1=invalid',
            'x-request-id' => 'request-1',
        ]);
    } catch (InvalidWebhookSignatureException) {
        $badSignature = true;
    }

    check($badSignature, 'An invalid signature is rejected');

    $missingSignature = false;

    try {
        $gateway(new FakeHttpClient())->parseWebhook($payload, []);
    } catch (InvalidWebhookSignatureException) {
        $missingSignature = true;
    }

    check($missingSignature, 'A missing signature is rejected');

    $malformed = false;
    $payloadWithoutType = ['data' => ['id' => '1']];

    try {
        $gateway(new FakeHttpClient())->parseWebhook($payloadWithoutType, [
            'x-signature' => signature($payloadWithoutType, 'request-4', 1704908010, $secret),
            'x-request-id' => 'request-4',
        ]);
    } catch (InvalidWebhookPayloadException) {
        $malformed = true;
    }

    check($malformed, 'A notification without a type is rejected');

    $http = new FakeHttpClient();
    $unsupported = $gateway($http)->parseWebhook(
        ['type' => 'topic_chargebacks_wh', 'data' => ['id' => '99']],
        ['x-signature' => signature(['type' => 'topic_chargebacks_wh', 'data' => ['id' => '99']], 'request-2', 1704908010, $secret), 'x-request-id' => 'request-2'],
    );
    check($unsupported->externalId === null, 'An unsupported topic is acknowledged without a payment');
    check($http->requests === [], 'An unsupported topic does not call the provider');

    // ---- Duplicate-approval search failure -----------------------------
    $http = new FakeHttpClient();
    $http->responses[] = jsonResponse([
        'id' => '777',
        'status' => 'approved',
        'transaction_amount' => 4500,
        'currency_id' => 'COP',
        'external_reference' => 'MP-ORDER-12',
        'date_created' => '2026-10-25T00:00:00.000-04:00',
        'date_last_updated' => '2026-10-25T00:00:02.000-04:00',
        'live_mode' => true,
    ]);
    $http->responses[] = jsonResponse([], 503);

    $event = $gateway($http)->parseWebhook(
        ['type' => 'payment', 'data' => ['id' => '777']],
        [
            'x-signature' => signature(['type' => 'payment', 'data' => ['id' => '777']], 'request-3', 1704908010, $secret),
            'x-request-id' => 'request-3',
        ],
    );

    check(
        ($event->payment?->raw['mp_approved_payment_count_unconfirmed'] ?? false) === true,
        'A failed duplicate search is marked unconfirmed, never "exactly one"',
    );

    // ---- Missing webhook secret ---------------------------------------
    $noSecretGateway = new MercadoPagoGateway(
        http: new FakeHttpClient(),
        accessToken: 'token',
        sandbox: true,
        baseUrl: 'https://api.mercadopago.test',
        timeout: 10,
        webhookSecret: '',
    );
    $configError = false;

    try {
        $noSecretGateway->parseWebhook($payload, $headers);
    } catch (PaymentGatewayException) {
        $configError = true;
    }

    check($configError, 'A missing webhook secret fails closed (retryable 503 at the boundary)');
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, 'ERROR: '.$exception::class.' - '.$exception->getMessage()."\n");
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll Mercado Pago adapter checks passed.\n";
