<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService;

final class JsonResponse
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly array $payload,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {}

    public function send(): void
    {
        // Apache maps non-standard status codes without a reason phrase to 500
        // when it builds the status line. 419 (Laravel CSRF mismatch) needs the
        // explicit status line to survive the Apache SAPI unchanged.
        if ($this->status === 419) {
            header('HTTP/1.1 419 Authentication Timeout', true, 419);
        } else {
            http_response_code($this->status);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-RifaCloud-Service: orders-service');

        foreach ($this->headers as $name => $value) {
            header($name.': '.$value);
        }

        // 204/304 must not carry a response body (GET routes do not use them,
        // but keeping the same contract as raffles-service avoids surprises).
        if ($this->status === 204 || $this->status === 304) {
            return;
        }

        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
