<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Draw;

/**
 * HMAC authentication for the scheduler -> raffles-service draw command.
 *
 * The secret is derived from the shared APP_KEY; no additional secret is
 * stored. Nginx never exposes /internal/ and the service publishes no port.
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
     * @throws InternalAuthException
     */
    public function assertValid(array $headers, string $body): void
    {
        if ($this->secret === '') {
            throw new InternalAuthException('Internal command authentication is not configured.', 503);
        }

        $timestamp = $headers['x-rifacloud-timestamp'] ?? null;
        $signature = $headers['x-rifacloud-signature'] ?? null;

        if (
            ! is_string($timestamp)
            || preg_match('/^[0-9]{1,12}$/', $timestamp) !== 1
            || ! is_string($signature)
            || $signature === ''
        ) {
            throw new InternalAuthException('Missing internal signature.');
        }

        if (abs(time() - (int) $timestamp) > $this->clockSkewSeconds) {
            throw new InternalAuthException('Expired internal signature.');
        }

        $expected = hash_hmac(
            'sha256',
            $timestamp."\n".hash('sha256', $body),
            $this->secret,
        );

        if (! hash_equals($expected, $signature)) {
            throw new InternalAuthException('Invalid internal signature.');
        }
    }
}
