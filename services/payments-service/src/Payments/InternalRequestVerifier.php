<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RifaCloud\PaymentsService\Auth\AuthException;

/**
 * Authenticates the monolith scheduler's reconciliation command.
 *
 * HMAC-SHA256(timestamp "\n" SHA256(raw_body)) with a key derived from the
 * shared APP_KEY. Requests outside the clock window are rejected (replay
 * protection). Nginx never proxies /internal/.
 */
final class InternalRequestVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $clockSkewSeconds,
    ) {}

    /**
     * @param  array<string, string>  $headers
     *
     * @throws AuthException
     * @throws ServiceUnavailableException
     */
    public function assertValid(array $headers, string $body): void
    {
        if ($this->secret === '') {
            throw new ServiceUnavailableException('Internal command authentication is not configured.');
        }

        $timestamp = $headers['x-rifacloud-timestamp'] ?? null;
        $signature = $headers['x-rifacloud-signature'] ?? null;

        if (
            ! is_string($timestamp)
            || preg_match('/^[0-9]{1,12}$/', $timestamp) !== 1
            || ! is_string($signature)
            || $signature === ''
        ) {
            throw new AuthException('Missing internal signature.', 401);
        }

        if (abs(time() - (int) $timestamp) > $this->clockSkewSeconds) {
            throw new AuthException('Expired internal signature.', 401);
        }

        $expected = hash_hmac(
            'sha256',
            $timestamp."\n".hash('sha256', $body),
            $this->secret,
        );

        if (! hash_equals($expected, $signature)) {
            throw new AuthException('Invalid internal signature.', 401);
        }
    }
}
