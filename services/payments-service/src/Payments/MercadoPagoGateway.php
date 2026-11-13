<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RifaCloud\PaymentsService\Http\HttpClient;
use RifaCloud\PaymentsService\Http\HttpTransportException;

/**
 * Mercado Pago Checkout Pro adapter (sandbox/homepage redirect).
 *
 * Port of App\Modules\Payments\Gateways\MercadoPagoGateway. The external
 * reference is deterministic per order (MP-ORDER-{id}) and is reused as the
 * X-Idempotency-Key, so a retried checkout never creates a second logical
 * preference. Creating a preference does not charge the buyer; the real
 * payment is always verified server-side.
 *
 * Webhook notifications are validated with the official HMAC-SHA256 manifest
 * and the payment is fetched again from the provider API. The notification body
 * is never treated as proof of payment.
 */
final class MercadoPagoGateway implements PaymentGateway
{
    private const DUPLICATE_SEARCH_TIMEOUT_SECONDS = 5;

    private const EVENT_TYPE_MAX_LENGTH = 100;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $accessToken,
        private readonly bool $sandbox,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly string $webhookSecret = '',
        private readonly string $backUrl = '',
        private readonly string $appUrl = 'http://localhost',
        private readonly string $webhookUrl = '',
    ) {
        if ($this->accessToken === '') {
            throw new \RuntimeException('MERCADOPAGO_ACCESS_TOKEN is not configured.');
        }
    }

    /**
     * @param  array{id: int, order_number: string, total_amount: int, raffle_title: string|null}  $order
     */
    public function createCheckout(array $order): CheckoutSession
    {
        $externalId = $this->externalReference($order);

        $payload = [
            'items' => [[
                'id' => (string) $order['id'],
                'title' => $this->itemTitle($order),
                'quantity' => 1,
                'unit_price' => $order['total_amount'],
                'currency_id' => 'COP',
            ]],
            'external_reference' => $externalId,
            'metadata' => [
                'order_id' => $order['id'],
                'order_number' => $order['order_number'],
            ],
        ];

        $backUrl = $this->backUrl();

        if ($backUrl !== '') {
            $returnUrl = $backUrl.'/orders/'.$order['id'].'?pago=retorno';

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

        [$status, $json] = $this->send(
            'POST',
            '/checkout/preferences',
            $payload,
            ['X-Idempotency-Key' => $externalId],
        );

        $preferenceId = $this->text($json['id'] ?? null);
        $initPoint = $this->text($json['init_point'] ?? null);
        $sandboxPoint = $this->text($json['sandbox_init_point'] ?? null);

        $checkoutUrl = $this->sandbox
            ? ($sandboxPoint ?? $initPoint)
            : $initPoint;

        if ($preferenceId === null || $checkoutUrl === null) {
            error_log(sprintf(
                "[payments-service] incomplete preference for order %d (preference=%s, url=%s)\n",
                $order['id'],
                $preferenceId === null ? 'no' : 'yes',
                $checkoutUrl === null ? 'no' : 'yes',
            ));

            throw new PaymentGatewayException('Mercado Pago returned an incomplete preference.');
        }

        return new CheckoutSession($externalId, $checkoutUrl, [
            'preference_id' => $preferenceId,
            'sandbox' => $this->sandbox,
            'live_mode' => $this->boolOrNull($json['live_mode'] ?? null),
        ]);
    }

    public function verifyPayment(string $externalId): PaymentStatus
    {
        [, $json] = $this->send('GET', '/v1/payments/search', [
            'external_reference' => $externalId,
            'sort' => 'date_created',
            'criteria' => 'desc',
            'limit' => 50,
        ]);

        $results = $json['results'] ?? null;
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
                    $approvedIds[$paymentId] = true;
                }
            }
        }

        if ($matches === []) {
            throw new PaymentNotFoundException('No Mercado Pago payment exists for this checkout yet.');
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

    public function fetchPayment(string $providerPaymentId): PaymentStatus
    {
        [, $json] = $this->send(
            'GET',
            '/v1/payments/'.rawurlencode($providerPaymentId),
            [],
            [],
            true,
        );

        if ($json === null) {
            throw new PaymentGatewayException('Mercado Pago returned an invalid payment.');
        }

        return $this->mapPayment($json);
    }

    /**
     * @param  array{id: int, order_number: string, raffle_title: string|null}  $order
     */
    private function externalReference(array $order): string
    {
        return 'MP-ORDER-'.$order['id'];
    }

    /**
     * @param  array{order_number: string, raffle_title: string|null}  $order
     */
    private function itemTitle(array $order): string
    {
        $raffleTitle = $order['raffle_title'] ?? null;

        return $raffleTitle !== null && $raffleTitle !== ''
            ? 'Rifa: '.$raffleTitle
            : 'Orden '.$order['order_number'];
    }

    private function backUrl(): string
    {
        $configured = $this->text($this->backUrl);

        return $configured !== null
            ? rtrim($configured, '/')
            : rtrim($this->appUrl, '/');
    }

    private function webhookUrl(): string
    {
        $configured = $this->text($this->webhookUrl);

        if ($configured !== null) {
            return rtrim($configured, '/');
        }

        $baseUrl = rtrim($this->appUrl, '/');

        return $baseUrl === '' ? '' : $baseUrl.'/api/webhooks/mercadopago';
    }

    /**
     * Official manifest: id:{data.id};request-id:{x-request-id};ts:{ts};
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function assertValidSignature(array $payload, array $headers): void
    {
        $secret = trim($this->webhookSecret);

        if ($secret === '') {
            // Configuration error: the caller maps this to 503 (retry), never
            // to an accepted notification.
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

        $computed = hash_hmac('sha256', implode(';', $parts).';', $secret);

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
     * @return array{int, array<string, mixed>|null}
     */
    private function send(
        string $method,
        string $path,
        array $data = [],
        array $headers = [],
        bool $missingIsNotFound = false,
        ?int $timeout = null,
    ): array {
        $effectiveTimeout = max(1, $timeout ?? $this->timeout);
        $url = $this->baseUrl.$path;
        $body = null;

        $requestHeaders = [
            'Authorization' => 'Bearer '.$this->accessToken,
            'Accept' => 'application/json',
        ];

        if (strtoupper($method) === 'GET') {
            if ($data !== []) {
                $url .= '?'.http_build_query($data);
            }
        } else {
            $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $requestHeaders['Content-Type'] = 'application/json';
        }

        foreach ($headers as $name => $value) {
            $requestHeaders[$name] = $value;
        }

        try {
            $response = $this->http->request($method, $url, $requestHeaders, $body, $effectiveTimeout);
        } catch (HttpTransportException) {
            error_log("[payments-service] Mercado Pago connection failed on {$path}\n");

            throw new PaymentGatewayTimeoutException('Mercado Pago did not respond.');
        }

        $status = $response['status'];
        $json = json_decode($response['body'], true);

        if (! is_array($json)) {
            $json = null;
        }

        if ($missingIsNotFound && $status === 404) {
            throw new PaymentNotFoundException('Mercado Pago payment was not found.');
        }

        if ($status >= 200 && $status < 300) {
            return [$status, $json];
        }

        error_log(sprintf(
            "[payments-service] Mercado Pago request failed: endpoint=%s status=%d\n",
            $path,
            $status,
        ));

        if ($status >= 500 || in_array($status, [408, 429], true)) {
            throw new PaymentGatewayTimeoutException('Mercado Pago is temporarily unavailable.');
        }

        throw new PaymentGatewayException('Mercado Pago rejected the request.');
    }

    /**
     * @return list<string>|null
     */
    private function approvedPaymentIds(string $externalId, ?string $knownPaymentId = null): ?array
    {
        if ($externalId === '') {
            return null;
        }

        try {
            [, $json] = $this->send(
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
        } catch (PaymentGatewayException|PaymentGatewayTimeoutException) {
            error_log("[payments-service] duplicate-approval check unconfirmed for {$externalId}\n");

            return null;
        }

        $results = $json['results'] ?? null;

        if (! is_array($results)) {
            return null;
        }

        $paging = $json['paging'] ?? null;

        if (is_array($paging) && isset($paging['total']) && (int) $paging['total'] > count($results)) {
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
                return null;
            }

            $approved[$paymentId] = true;
        }

        if ($approved === []) {
            return null;
        }

        $approvedIds = array_map('strval', array_keys($approved));

        if ($knownPaymentId !== null && ! in_array($knownPaymentId, $approvedIds, true)) {
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
