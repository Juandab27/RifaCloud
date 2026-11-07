<?php

namespace App\Modules\Payments\Clients;

use App\Modules\Payments\Contracts\PaymentsReconciliationClient;
use RuntimeException;

/**
 * HTTP client for POST /internal/payments/reconcile.
 *
 * Signed with an HMAC derived from APP_KEY (context
 * `rifacloud-payments-reconcile`), so no extra secret is stored. The stream
 * transport keeps the monolith free of another HTTP dependency, matching the
 * orders-service client.
 */
final class InternalPaymentsClient implements PaymentsReconciliationClient
{
    private const INTERNAL_CONTEXT = 'rifacloud-payments-reconcile';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appKey,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function reconcile(int $limit, bool $dryRun): int
    {
        $payload = $this->send([
            'limit' => max(1, $limit),
            'dry_run' => $dryRun,
            'approved' => false,
        ]);

        if (! isset($payload['count']) || ! is_int($payload['count'])) {
            throw new RuntimeException('payments-service returned an invalid reconciliation payload.');
        }

        return (int) $payload['count'];
    }

    public function auditApproved(int $limit, bool $dryRun): array
    {
        $payload = $this->send([
            'limit' => max(1, $limit),
            'dry_run' => $dryRun,
            'approved' => true,
        ]);

        foreach (['inspected', 'incidents', 'errors'] as $key) {
            if (! isset($payload[$key]) || ! is_int($payload[$key])) {
                throw new RuntimeException('payments-service returned an invalid audit payload.');
            }
        }

        return [
            'inspected' => (int) $payload['inspected'],
            'incidents' => (int) $payload['incidents'],
            'errors' => (int) $payload['errors'],
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function send(array $command): array
    {
        $body = json_encode($command, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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

        $url = rtrim($this->baseUrl, '/').'/internal/payments/reconcile';
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('payments-service is unreachable.');
        }

        $status = $this->statusFromHeaders($http_response_header ?? []);

        if ($status !== 200) {
            throw new RuntimeException("payments-service returned HTTP {$status}.");
        }

        $payload = json_decode($response, true);

        if (! is_array($payload)) {
            throw new RuntimeException('payments-service returned an invalid response.');
        }

        return $payload;
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
