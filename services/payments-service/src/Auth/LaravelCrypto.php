<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Auth;

/**
 * Minimal, read-only implementation of the Laravel encrypter/cookie contract.
 *
 * Only authenticated decryption is exposed. Any malformed, tampered or
 * truncated value is rejected by throwing RuntimeException; callers translate
 * that into 401, never into a fallback identity.
 */
final class LaravelCrypto
{
    /**
     * @throws \RuntimeException
     */
    public static function key(string $appKey): string
    {
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is not configured.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);

            if ($decoded === false || $decoded === '') {
                throw new \RuntimeException('APP_KEY is not valid base64.');
            }

            return $decoded;
        }

        return $appKey;
    }

    /**
     * @throws \RuntimeException
     */
    public static function cookiePrefix(string $cookieName, string $key): string
    {
        return hash_hmac('sha1', $cookieName.'v2', $key).'|';
    }

    /**
     * @throws \RuntimeException
     */
    public static function decrypt(string $payload, string $key, bool $unserialize = false): mixed
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new \RuntimeException('The payload is not valid base64.');
        }

        $json = json_decode($decoded, true);

        if (! is_array($json)) {
            throw new \RuntimeException('The payload is not valid JSON.');
        }

        foreach (['iv', 'value', 'mac'] as $item) {
            if (! isset($json[$item]) || ! is_string($json[$item])) {
                throw new \RuntimeException('The payload is invalid.');
            }
        }

        $iv = base64_decode($json['iv'], true);

        if ($iv === false || strlen($iv) !== 16) {
            throw new \RuntimeException('The payload IV is invalid.');
        }

        // Laravel computes the MAC over the base64-encoded IV string exactly as
        // it appears in the payload.
        $expectedMac = hash_hmac('sha256', $json['iv'].$json['value'], $key);

        if (! hash_equals($expectedMac, $json['mac'])) {
            throw new \RuntimeException('The MAC is invalid.');
        }

        $decrypted = openssl_decrypt($json['value'], 'aes-256-cbc', $key, 0, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException('Could not decrypt the data.');
        }

        if (! $unserialize) {
            return $decrypted;
        }

        $value = @unserialize($decrypted, ['allowed_classes' => false]);

        if ($value === false && $decrypted !== serialize(false)) {
            throw new \RuntimeException('Could not unserialize the data.');
        }

        return $value;
    }
}
