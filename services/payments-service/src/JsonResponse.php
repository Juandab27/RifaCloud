<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService;

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
        if ($this->status === 419) {
            header('HTTP/1.1 419 Authentication Timeout', true, 419);
        } else {
            http_response_code($this->status);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-RifaCloud-Service: payments-service');

        foreach ($this->headers as $name => $value) {
            header($name.': '.$value);
        }

        if ($this->status === 204 || $this->status === 304) {
            return;
        }

        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
