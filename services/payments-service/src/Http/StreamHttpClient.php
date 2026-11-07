<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Http;

/**
 * `file_get_contents` based transport.
 *
 * The service only needs two provider endpoints and one internal command, so a
 * full HTTP client dependency is unnecessary. TLS is provided by the openssl
 * extension of the php:8.4-apache base image.
 */
final class StreamHttpClient implements HttpClient
{
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
    ): array {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        $options = [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headerLines),
            'timeout' => max(1, $timeoutSeconds),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 1,
        ];

        if ($body !== null) {
            $options['content'] = $body;
        }

        $context = stream_context_create(['http' => $options]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new HttpTransportException('The HTTP request could not be completed.');
        }

        // file_get_contents defines $http_response_header in the calling scope.
        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/[0-9.]+\s+([0-9]{3})#', (string) $line, $matches) === 1) {
                $status = (int) $matches[1];
                break;
            }
        }

        if ($status === 0) {
            throw new HttpTransportException('The HTTP response has no status line.');
        }

        return ['status' => $status, 'body' => $response];
    }
}
