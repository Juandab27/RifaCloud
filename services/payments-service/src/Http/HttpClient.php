<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Http;

/**
 * Minimal HTTP transport so the Mercado Pago adapter can be tested without a
 * network and without pulling a framework into this service.
 */
interface HttpClient
{
    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     *
     * @throws HttpTransportException when no response could be read
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
    ): array;
}
