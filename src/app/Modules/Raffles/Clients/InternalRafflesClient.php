<?php

namespace App\Modules\Raffles\Clients;

use App\Modules\Raffles\Contracts\RaffleDrawClient;
use RuntimeException;

/**
 * HTTP client for POST /internal/raffles/process-due.
 *
 * The request is HMAC-signed with a value derived from APP_KEY, matching the
 * other scheduler-to-service commands. Nginx blocks /internal/ externally.
 */
final class InternalRafflesClient implements RaffleDrawClient
{
    private const INTERNAL_CONTEXT = 'rifacloud-raffles-internal';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appKey,
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function processDue(int $limit): array
    {
        $body = json_encode(['limit' => max(1, $limit)], JSON_THROW_ON_ERROR);
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

        $url = rtrim($this->baseUrl, '/').'/internal/raffles/process-due';
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('raffles-service is unreachable.');
        }

        $status = $this->statusFromHeaders($http_response_header ?? []);

        if ($status !== 200) {
            throw new RuntimeException("raffles-service returned HTTP {$status}.");
        }

        $payload = json_decode($response, true);

        if (! is_array($payload)) {
            throw new RuntimeException('raffles-service returned an invalid draw payload.');
        }

        $result = [];

        foreach (['closed', 'drawn', 'blocked', 'skipped', 'errors'] as $key) {
            if (! isset($payload[$key]) || ! is_int($payload[$key])) {
                throw new RuntimeException('raffles-service returned an invalid draw payload.');
            }

            $result[$key] = (int) $payload[$key];
        }

        return $result;
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
