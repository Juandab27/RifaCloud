<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\DTOs\CheckoutSession;
use App\Modules\Payments\DTOs\PaymentStatus;
use App\Modules\Payments\DTOs\WebhookEvent;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Exceptions\InvalidWebhookPayloadException;
use App\Modules\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Payments\Exceptions\PaymentGatewayException;
use App\Modules\Payments\Exceptions\PaymentGatewayTimeoutException;
use App\Modules\Payments\Exceptions\PaymentNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Mercado Pago Checkout Pro adapter (sandbox/homepage redirect).
 *
 * The external reference is deterministic per order (MP-ORDER-{id}) and is
 * reused as the X-Idempotency-Key. If the HTTP response is lost after the
 * provider created the preference, retrying the checkout reuses both values,
 * so no second logical checkout is created. Preference creation does not
 * charge the buyer; the real payment is always verified server-side.
 *
 * Webhook notifications are validated with the official HMAC-SHA256 manifest
 * and the payment is always fetched again from the provider API. The body of
 * a notification is never used as proof that a payment is approved.
 */
class MercadoPagoGateway implements PaymentGateway
{
    /**
     * The duplicate-approval search runs before the webhook is acknowledged.
     * Capping it keeps the worst case (payment fetch + duplicate search) well
     * inside Mercado Pago's 22-second confirmation window. A slow search is
     * treated as an unconfirmed count, never as "exactly one payment".
     */
    private const DUPLICATE_SEARCH_TIMEOUT_SECONDS = 5;

    private const EVENT_TYPE_MAX_LENGTH = 100;

    public function __construct(
        private readonly string $accessToken,
        private readonly bool $sandbox,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly string $webhookSecret = '',
    ) {
        if ($this->accessToken === '') {
            throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN is not configured.');
        }
    }

    public function createCheckout(Order $order): CheckoutSession
    {
        $externalId = $this->externalReference($order);

        $payload = [
            'items' => [[
                'id' => (string) $order->id,
                'title' => $this->itemTitle($order),
                'quantity' => 1,
                'unit_price' => $order->total_amount,
                'currency_id' => 'COP',
            ]],
            'external_reference' => $externalId,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
        ];

        $backUrl = $this->backUrl();

        if ($backUrl !== '') {
            $returnUrl = $backUrl.'/orders/'.$order->id.'?pago=retorno';

            $payload['back_urls'] = [
                'success' => $returnUrl,
                'failure' => $returnUrl,
                'pending' => $returnUrl,
            ];
        }

        $notificationUrl = $this->webhookUrl();

        if ($notificationUrl !== '') {
            $payload['notification_url'] = $notificationUrl;
        }

        $response = $this->send(
            'POST',
            '/checkout/preferences',
            $payload,
            ['X-Idempotency-Key' => $externalId],
        );

        $preferenceId = $this->text($response->json('id'));
        $initPoint = $this->text($response->json('init_point'));
        $sandboxPoint = $this->text($response->json('sandbox_init_point'));

        $checkoutUrl = $this->sandbox
            ? ($sandboxPoint ?? $initPoint)
            : $initPoint;

        if ($preferenceId === null || $checkoutUrl === null) {
            Log::warning('Mercado Pago preference response is incomplete.', [
                'order_id' => $order->id,
                'has_preference_id' => $preferenceId !== null,
                'has_checkout_url' => $checkoutUrl !== null,
            ]);

            throw new PaymentGatewayException('Mercado Pago returned an incomplete preference.');
        }

        return new CheckoutSession($externalId, $checkoutUrl, [
            'preference_id' => $preferenceId,
            'sandbox' => $this->sandbox,
            // Absent, explicit false and explicit true are preserved as
            // null/false/true. This field is not proof of the environment.
            'live_mode' => $this->boolOrNull($response->json('live_mode')),
        ]);
    }

    public function verifyPayment(string $externalId): PaymentStatus
    {
        $response = $this->send('GET', '/v1/payments/search', [
            'external_reference' => $externalId,
            'sort' => 'date_created',
            'criteria' => 'desc',
            'limit' => 50,
        ]);

        $results = $response->json('results');
        $matches = [];
        $approvedIds = [];
        $paymentIds = [];

        if (is_array($results)) {
            foreach ($results as $result) {
                if (! is_array($result)) {
                    continue;
                }

                if ($this->text($result['external_reference'] ?? null) !== $externalId) {
                    continue;
                }

                $matches[] = $result;
                $paymentId = $this->text($result['id'] ?? null);

                if ($paymentId === null) {
                    continue;
                }

                $paymentIds[] = $paymentId;

                if ($this->normalizeStatus($result['status'] ?? null) === 'approved') {
                    // Count unique provider IDs: a paginated or duplicated
                    // search result must not inflate the incident count.
                    $approvedIds[$paymentId] = true;
                }
            }
        }

        if ($matches === []) {
            // The buyer has not completed the checkout yet. This is neither a
            // rejection nor an uncertain result: leave the order untouched.
            throw new PaymentNotFoundException(
                'No Mercado Pago payment exists for this checkout yet.',
            );
        }

        $selected = $this->selectPayment($matches);
        $status = $this->mapPayment($selected);

        return new PaymentStatus(
            $externalId,
            $status->status,
            $status->amount,
            $status->currency,
            [
                ...$status->raw,
                'mp_external_reference' => $externalId,
                'mp_approved_payment_count' => count($approvedIds),
                'mp_payment_ids' => array_values(array_unique($paymentIds)),
                'mp_approved_payment_ids' => array_map('strval', array_keys($approvedIds)),
            ],
        );
    }

    public function parseWebhook(array $payload, array $headers = []): WebhookEvent
    {
        $this->assertValidSignature($payload, $headers);

        $type = strtolower(trim($this->text($payload['type'] ?? $payload['topic'] ?? null) ?? ''));

        if ($type === '') {
            throw new InvalidWebhookPayloadException('Notification type is missing.');
        }

        $dataId = $this->notificationDataId($payload);

        if ($dataId === null) {
            throw new InvalidWebhookPayloadException('Notification data.id is missing.');
        }

        // Checkout Pro payment notifications are the supported topic. Other
        // topics are acknowledged without side effects.
        //
        // Chargebacks (`topic_chargebacks_wh`) and claims
        // (`topic_claims_integration_wh`) are NOT processed here. This platform
        // currently detects a refund or chargeback only when the payment status
        // becomes `refunded` or `charged_back` through the payment topic. The
        // dedicated endpoints need the seller ID (`X-Caller-Id`) and a separate
        // incident mapping; adding them is a scope decision, not a silent
        // fallback.
        if (! in_array($type, ['payment', 'topic_payment_wh'], true)) {
            return new WebhookEvent(
                'topic:'.hash('sha256', $type.'|'.$dataId),
                mb_substr($type, 0, self::EVENT_TYPE_MAX_LENGTH),
                null,
                null,
                $this->safeWebhookPayload($payload, $dataId, null),
            );
        }

        // Never trust the notification body: fetch the payment again.
        $payment = $this->fetchPayment($dataId);

        // Detect a second approved charge for the same order. The count is
        // only evidence when the provider confirms it; a failed or incomplete
        // search is persisted as "unconfirmed" so the caller keeps the case
        // under manual review instead of assuming a single sale.
        if ($payment->status === PaymentState::APPROVED) {
            $knownPaymentId = $this->text($payment->raw['mp_payment_id'] ?? null);

            $approvedIds = $knownPaymentId === null
                ? null
                : $this->approvedPaymentIds($payment->externalId, $knownPaymentId);

            $raw = [
                ...$payment->raw,
                'mp_approved_payment_count' => $approvedIds === null ? null : count($approvedIds),
            ];

            if ($approvedIds === null) {
                $raw['mp_approved_payment_count_unconfirmed'] = true;
            } else {
                $raw['mp_approved_payment_ids'] = $approvedIds;
            }

            $payment = new PaymentStatus(
                $payment->externalId,
                $payment->status,
                $payment->amount,
                $payment->currency,
                $raw,
            );
        }

        $raw = $payment->raw;
        $externalId = $payment->externalId !== '' ? $payment->externalId : null;

        // Mercado Pago does not provide a stable event ID. Derive one from the
        // authoritative payment state so a retry collapses but a later status
        // change is still processed.
        $providerEventId = hash('sha256', implode('|', [
            'payment',
            $dataId,
            (string) ($raw['mp_status'] ?? ''),
            (string) ($raw['mp_status_detail'] ?? ''),
            (string) ($raw['mp_date_last_updated'] ?? $raw['mp_date_created'] ?? ''),
        ]));

        return new WebhookEvent(
            $providerEventId,
            'payment',
            $externalId,
            $payment,
            $this->safeWebhookPayload($payload, $dataId, $payment),
        );
    }

    /**
     * Fetch one payment by its provider ID. Used by the webhook path so the
     * notification body is never trusted.
     */
    public function fetchPayment(string $providerPaymentId): PaymentStatus
    {
        $response = $this->send(
            'GET',
            '/v1/payments/'.rawurlencode($providerPaymentId),
            [],
            [],
            true,
        );

        $payment = $response->json();

        if (! is_array($payment)) {
            throw new PaymentGatewayException('Mercado Pago returned an invalid payment.');
        }

        return $this->mapPayment($payment);
    }

    /**
     * Deterministic per order: reused by retries and as idempotency key.
     */
    private function externalReference(Order $order): string
    {
        return 'MP-ORDER-'.$order->id;
    }

    private function itemTitle(Order $order): string
    {
        $raffleTitle = $order->relationLoaded('raffle') ? $order->raffle?->title : null;

        return $raffleTitle !== null && $raffleTitle !== ''
            ? 'Rifa: '.$raffleTitle
            : 'Orden '.$order->order_number;
    }

    private function backUrl(): string
    {
        $configured = $this->text(config('mercadopago.back_url'));

        if ($configured !== null) {
            return rtrim($configured, '/');
        }

        return rtrim((string) config('app.url'), '/');
    }

    private function webhookUrl(): string
    {
        $configured = $this->text(config('mercadopago.webhook_url'));

        if ($configured !== null) {
            return $configured;
        }

        $baseUrl = rtrim((string) config('app.url'), '/');

        return $baseUrl === '' ? '' : $baseUrl.'/api/webhooks/mercadopago';
    }

    /**
     * Validate the official Mercado Pago HMAC-SHA256 manifest:
     * id:{data.id};request-id:{x-request-id};ts:{ts};
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function assertValidSignature(array $payload, array $headers): void
    {
        $secret = trim($this->webhookSecret);

        if ($secret === '') {
            throw new PaymentGatewayException('MERCADOPAGO_WEBHOOK_SECRET is not configured.');
        }

        $signatureHeader = $this->headerValue($headers, 'x-signature');
        $requestId = $this->headerValue($headers, 'x-request-id');

        if ($signatureHeader === '' || $requestId === '') {
            throw new InvalidWebhookSignatureException('Webhook signature is missing.');
        }

        $timestamp = null;
        $hash = null;

        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            $key = strtolower(trim($pair[0]));
            $value = trim($pair[1]);

            if ($key === 'ts') {
                $timestamp = $value;
            }

            if ($key === 'v1') {
                $hash = $value;
            }
        }

        if ($timestamp === null || $timestamp === '' || $hash === null || $hash === '') {
            throw new InvalidWebhookSignatureException('Webhook signature is malformed.');
        }

        $dataId = strtolower($this->notificationDataId($payload) ?? '');
        $parts = [];

        if ($dataId !== '') {
            $parts[] = 'id:'.$dataId;
        }

        if ($requestId !== '') {
            $parts[] = 'request-id:'.$requestId;
        }

        $parts[] = 'ts:'.$timestamp;
        $manifest = implode(';', $parts).';';
        $computed = hash_hmac('sha256', $manifest, $secret);

        if (! hash_equals($computed, strtolower($hash))) {
            throw new InvalidWebhookSignatureException('Webhook signature does not match.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notificationDataId(array $payload): ?string
    {
        $data = $payload['data'] ?? null;
        $value = is_array($data) ? ($data['id'] ?? null) : null;
        $value ??= $payload['data_id'] ?? null;

        return $this->text($value);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            return is_scalar($value) ? trim((string) $value) : '';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeWebhookPayload(array $payload, string $dataId, ?PaymentStatus $payment): array
    {
        $raw = $payment?->raw ?? [];

        return array_filter([
            'type' => $this->text($payload['type'] ?? $payload['topic'] ?? null),
            'action' => $this->text($payload['action'] ?? null),
            'data_id' => $dataId,
            'mp_payment_id' => $raw['mp_payment_id'] ?? null,
            'mp_status' => $raw['mp_status'] ?? null,
            'mp_status_detail' => $raw['mp_status_detail'] ?? null,
            'mp_external_reference' => $raw['mp_external_reference'] ?? null,
            'transaction_amount' => $raw['transaction_amount'] ?? null,
            'currency_id' => $raw['currency_id'] ?? null,
            'mp_live_mode' => $raw['mp_live_mode'] ?? null,
            'mp_approved_payment_count' => $raw['mp_approved_payment_count'] ?? null,
            'mp_approved_payment_ids' => $raw['mp_approved_payment_ids'] ?? null,
            'mp_approved_payment_count_unconfirmed' => $raw['mp_approved_payment_count_unconfirmed'] ?? null,
            'mp_date_created' => $raw['mp_date_created'] ?? null,
            'mp_date_approved' => $raw['mp_date_approved'] ?? null,
            'mp_date_last_updated' => $raw['mp_date_last_updated'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function send(
        string $method,
        string $path,
        array $data = [],
        array $headers = [],
        bool $missingIsNotFound = false,
        ?int $timeout = null,
    ): Response {
        $effectiveTimeout = max(1, $timeout ?? $this->timeout);

        try {
            $request = Http::withToken($this->accessToken)
                ->acceptJson()
                ->timeout($effectiveTimeout)
                ->connectTimeout(min(5, $effectiveTimeout))
                ->withHeaders($headers);

            $response = $method === 'GET'
                ? $request->get($this->baseUrl.$path, $data)
                : $request->post($this->baseUrl.$path, $data);
        } catch (ConnectionException $exception) {
            Log::warning('Mercado Pago connection failed.', [
                'endpoint' => $path,
                'message' => $exception->getMessage(),
            ]);

            throw new PaymentGatewayTimeoutException('Mercado Pago did not respond.', 0, $exception);
        }

        if ($missingIsNotFound && $response->status() === 404) {
            throw new PaymentNotFoundException('Mercado Pago payment was not found.');
        }

        if ($response->successful()) {
            return $response;
        }

        Log::warning('Mercado Pago request failed.', [
            'endpoint' => $path,
            'status' => $response->status(),
            'error' => $this->text($response->json('error')),
            'message' => $this->text($response->json('message')),
        ]);

        if ($response->serverError() || in_array($response->status(), [408, 429], true)) {
            throw new PaymentGatewayTimeoutException('Mercado Pago is temporarily unavailable.');
        }

        // 4xx: invalid credentials, malformed preference, etc. This is not an
        // uncertain payment; it must not freeze the order in PAYMENT_REVIEW.
        throw new PaymentGatewayException('Mercado Pago rejected the request.');
    }

    /**
     * List the unique approved provider payment IDs for one external reference.
     *
     * Returns null when the count cannot be confirmed: provider error,
     * invalid response, paginated results, an approved row without an ID, or
     * a result set that does not include the notified payment. A null must
     * never be interpreted as "exactly one payment".
     *
     * @return list<string>|null
     */
    private function approvedPaymentIds(string $externalId, ?string $knownPaymentId = null): ?array
    {
        if ($externalId === '') {
            return null;
        }

        try {
            $response = $this->send(
                'GET',
                '/v1/payments/search',
                [
                    'external_reference' => $externalId,
                    'sort' => 'date_created',
                    'criteria' => 'desc',
                    'limit' => 50,
                ],
                [],
                false,
                min($this->timeout, self::DUPLICATE_SEARCH_TIMEOUT_SECONDS),
            );
        } catch (PaymentGatewayException|PaymentGatewayTimeoutException $exception) {
            Log::warning('Mercado Pago duplicate-approval check could not be confirmed.', [
                'external_reference' => $externalId,
                'reason' => $exception::class,
            ]);

            return null;
        }

        $results = $response->json('results');

        if (! is_array($results)) {
            Log::warning('Mercado Pago duplicate-approval check returned an invalid response.', [
                'external_reference' => $externalId,
            ]);

            return null;
        }

        $paging = $response->json('paging');

        if (is_array($paging)
            && isset($paging['total'])
            && (int) $paging['total'] > count($results)) {
            Log::warning('Mercado Pago duplicate-approval check was paginated; count is unconfirmed.', [
                'external_reference' => $externalId,
                'total' => (int) $paging['total'],
                'returned' => count($results),
            ]);

            return null;
        }

        $approved = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            if ($this->text($result['external_reference'] ?? null) !== $externalId) {
                continue;
            }

            if ($this->normalizeStatus($result['status'] ?? null) !== 'approved') {
                continue;
            }

            $paymentId = $this->text($result['id'] ?? null);

            if ($paymentId === null) {
                // An approved attempt without an ID cannot be deduplicated.
                return null;
            }

            $approved[$paymentId] = true;
        }

        if ($approved === []) {
            return null;
        }

        $approvedIds = array_map('strval', array_keys($approved));

        if ($knownPaymentId !== null && ! in_array($knownPaymentId, $approvedIds, true)) {
            Log::warning('Mercado Pago duplicate-approval check does not include the notified payment.', [
                'external_reference' => $externalId,
            ]);

            return null;
        }

        return $approvedIds;
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private function selectPayment(array $matches): array
    {
        usort($matches, function (array $left, array $right): int {
            $priority = $this->priority($right) <=> $this->priority($left);

            if ($priority !== 0) {
                return $priority;
            }

            return strcmp(
                $this->text($right['date_created'] ?? null) ?? '',
                $this->text($left['date_created'] ?? null) ?? '',
            );
        });

        return $matches[0];
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function priority(array $payment): int
    {
        return match ($this->normalizeStatus($payment['status'] ?? null)) {
            'approved' => 6,
            'refunded', 'charged_back' => 5,
            'in_mediation' => 4,
            'pending', 'in_process', 'authorized' => 3,
            'rejected', 'cancelled' => 2,
            // Unknown statuses are treated as uncertain so they reach review.
            default => 4,
        };
    }

    private function normalizeStatus(mixed $status): string
    {
        return strtolower(trim($this->text($status) ?? ''));
    }

    private function mapStatus(string $mpStatus): PaymentState
    {
        return match ($mpStatus) {
            'approved' => PaymentState::APPROVED,
            'pending', 'in_process', 'authorized' => PaymentState::PENDING,
            'rejected', 'cancelled' => PaymentState::REJECTED,
            // A refund or chargeback is never a simple rejection: it becomes a
            // manual refund incident and the numbers are not released.
            'refunded', 'charged_back' => PaymentState::REFUND_REQUIRED,
            'in_mediation' => PaymentState::UNKNOWN,
            default => PaymentState::UNKNOWN,
        };
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function mapPayment(array $payment): PaymentStatus
    {
        $mpStatus = $this->normalizeStatus($payment['status'] ?? null);
        $amount = $this->providerAmountInCop($payment['transaction_amount'] ?? null);
        $currency = strtoupper($this->text($payment['currency_id'] ?? null) ?? '');
        $paymentId = $this->text($payment['id'] ?? null);

        return new PaymentStatus(
            $this->text($payment['external_reference'] ?? null) ?? '',
            $this->mapStatus($mpStatus),
            $amount,
            $currency,
            [
                'mp_payment_id' => $paymentId,
                'mp_status' => $mpStatus,
                'mp_status_detail' => $this->text($payment['status_detail'] ?? null),
                'mp_payment_method_id' => $this->text($payment['payment_method_id'] ?? null),
                'mp_payment_type_id' => $this->text($payment['payment_type_id'] ?? null),
                'mp_external_reference' => $this->text($payment['external_reference'] ?? null),
                'mp_date_approved' => $this->text($payment['date_approved'] ?? null),
                'mp_date_created' => $this->text($payment['date_created'] ?? null),
                'mp_date_last_updated' => $this->text($payment['date_last_updated'] ?? null),
                // Absent, false and true are preserved separately.
                'mp_live_mode' => $this->boolOrNull($payment['live_mode'] ?? null),
                'transaction_amount' => (float) ($payment['transaction_amount'] ?? 0),
                'currency_id' => $currency,
                'mp_approved_payment_count' => 1,
                'mp_payment_ids' => $paymentId === null ? [] : [$paymentId],
            ],
        );
    }

    private function providerAmountInCop(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new PaymentGatewayException('Mercado Pago returned an invalid amount.');
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
        }

        if (in_array($value, [1, '1', 'true'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false'], true)) {
            return false;
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 255);
    }
}
