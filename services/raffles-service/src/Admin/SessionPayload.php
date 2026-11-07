<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

/**
 * Decodes the `sessions.payload` column written by Laravel.
 *
 * Laravel 11+ serializes session attributes as JSON by default
 * (config/session.php: 'serialization' => 'json'). PHP serialization and
 * SESSION_ENCRYPT=true are supported as fallbacks so an environment change on
 * the monolith does not silently break admin authorization.
 */
final class SessionPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(string $payload, string $appKey): array
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false || $decoded === '') {
            return [];
        }

        $json = json_decode($decoded, true);

        if (is_array($json)) {
            return $json;
        }

        $serialized = @unserialize($decoded, ['allowed_classes' => false]);

        if (is_array($serialized)) {
            return $serialized;
        }

        try {
            $plain = LaravelCrypto::decrypt($decoded, LaravelCrypto::key($appKey), false);
            $serialized = @unserialize($plain, ['allowed_classes' => false]);

            if (is_array($serialized)) {
                return $serialized;
            }
        } catch (\Throwable) {
            // Fall through to an empty payload; the caller rejects the session.
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function csrfToken(array $data): ?string
    {
        $token = $data['_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function passwordHash(array $data): ?string
    {
        $hash = $data['password_hash_web'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }
}
