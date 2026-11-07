<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RifaCloud\PaymentsService\Http\HttpClient;
use RifaCloud\PaymentsService\Http\HttpTransportException;

/**
 * Signed client for orders-service's payment-result command.
 *
 * Contract: POST /internal/orders/payment-result
 *   X-RifaCloud-Timestamp, X-RifaCloud-Signature
 *   HMAC-SHA256(timestamp + "\n" + SHA256(raw_body)) with a key derived from
 *   APP_KEY ("rifacloud-payments-internal").
 *
 * The command is idempotent by state convergence: replaying the same provider
 * result cannot sell a number twice or reopen a final order.
 */
final class OrdersClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $secret,
        private readonly int $timeoutSeconds = 5,
    ) {}

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     * @throws PaymentConflictException
     * @throws OrdersUnavailableException
     */
    public function applyPaymentResult(array $command): array
    {
        if ($this->secret === '') {
            throw new OrdersUnavailableException('The internal command secret is not configured.');
        }

        $body = json_encode($command, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac(
            'sha256',
            $timestamp."\n".hash('sha256', $body),
            $this->secret,
        );

        $url = rtrim($this->baseUrl, '/').'/internal/orders/payment-result';

        try {
            $response = $this->http->request(
                'POST',
                $url,
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-RifaCloud-Timestamp' => $timestamp,
                    'X-RifaCloud-Signature' => $signature,
                ],
                $body,
                $this->timeoutSeconds,
            );
        } catch (HttpTransportException) {
            throw new OrdersUnavailableException('orders-service is unreachable.');
        }

        $json = json_decode($response['body'], true);
        $payload = is_array($json) ? $json : [];
        $status = $response['status'];

        if ($status === 200 && is_array($json) && isset($json['payment_status'])) {
            return $json;
        }

        if ($status === 404) {
            throw new NotFoundException((string) ($payload['message'] ?? 'No encontrado.'));
        }

        if ($status === 409 && isset($payload['code'])) {
            $context = $payload;
            unset($context['message'], $context['code']);

            throw PaymentConflictException::fromCode(
                (string) $payload['code'],
                (string) ($payload['message'] ?? ''),
                $context,
            );
        }

        error_log(sprintf(
            "[payments-service] orders-service command failed: status=%d code=%s\n",
            $status,
            (string) ($payload['code'] ?? ($payload['message'] ?? 'unexpected')),
        ));

        throw new OrdersUnavailableException('orders-service did not confirm the payment result.');
    }
}
