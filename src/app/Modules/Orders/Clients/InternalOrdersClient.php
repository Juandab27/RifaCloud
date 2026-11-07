<?php

namespace App\Modules\Orders\Clients;

use App\Modules\Orders\Contracts\ReservationExpiryClient;
use RuntimeException;

/**
 * HTTP client for POST /internal/orders/expire-reservations.
 *
 * The request is signed with an HMAC derived from APP_KEY so no extra secret
 * is stored. `file_get_contents` is used instead of curl because the app
 * container does not install the curl extension (the stream transport is
 * enough for this internal, single-request command).
 */
final class InternalOrdersClient implements ReservationExpiryClient
{
    private const INTERNAL_CONTEXT = 'rifacloud-orders-internal';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appKey,
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function expire(): int
    {
        $body = '{}';
        $timestamp = (string) time();
        $secret = hash_hmac('sha256', self::INTERNAL_CONTEXT, $this->appKey);
        $signature = hash_hmac(
            'sha256',
            $timestamp."\n".hash('sha256', $body),
            $secret,
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Content-Length: '.strlen($body),
                    'X-RifaCloud-Timestamp: '.$timestamp,
                    'X-RifaCloud-Signature: '.$signature,
                ]),
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $url = rtrim($this->baseUrl, '/').'/internal/orders/expire-reservations';
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('orders-service is unreachable.');
        }

        $status = $this->statusFromHeaders($http_response_header ?? []);

        if ($status !== 200) {
            throw new RuntimeException("orders-service returned HTTP {$status}.");
        }

        $payload = json_decode($response, true);

        if (! is_array($payload) || ! isset($payload['expired']) || ! is_int($payload['expired'])) {
            throw new RuntimeException('orders-service returned an invalid expiry payload.');
        }

        return (int) $payload['expired'];
    }

    /**
     * @param  list<string>  $headers
     */
    private function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/[0-9.]+\s+([0-9]{3})#', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
