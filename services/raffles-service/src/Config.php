<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService;

/**
 * Environment-only configuration for the raffles service.
 *
 * The service deliberately has no .env loader: Docker Compose (or the
 * platform) is the only configuration source, and no secret is ever read from
 * the repository.
 *
 * S2 adds the shared Laravel session contract (APP_KEY, session cookie and
 * session table). Those values are read from the same environment file as the
 * monolith so an admin session issued by auth-service can be validated here.
 */
final class Config
{
    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public static function database(): array
    {
        return [
            'host' => self::string('RAFFLES_DB_HOST', 'mysql'),
            'port' => self::int('RAFFLES_DB_PORT', 3306),
            'database' => self::string('RAFFLES_DB_DATABASE', 'rifacloud'),
            'username' => self::string('RAFFLES_DB_USERNAME', 'rifacloud'),
            'password' => self::string('RAFFLES_DB_PASSWORD', ''),
        ];
    }

    public static function reservationMinutes(): int
    {
        // Single source of truth: RESERVATION_MINUTES from src/.env (shared with
        // the monolith and orders-service). RAFFLES_* remains a service-specific
        // override for isolated deployments.
        return self::int('RAFFLES_RESERVATION_MINUTES', self::int('RESERVATION_MINUTES', 10));
    }

    public static function maxNumbersPerOrder(): int
    {
        return self::int('RAFFLES_ORDER_MAX_NUMBERS', self::int('ORDER_MAX_NUMBERS', 50));
    }

    public static function raffleMaxNumbers(): int
    {
        return self::int('RAFFLE_MAX_NUMBERS', 10000);
    }

    public static function drawBufferMinutes(): int
    {
        // S12: the approved minimum is 30 minutes between sales end and draw.
        return max(30, self::int('DRAW_BUFFER_MINUTES', 30));
    }

    public static function drawBatchLimit(): int
    {
        return min(100, self::int('DRAW_BATCH_LIMIT', 20));
    }

    public static function internalClockSkewSeconds(): int
    {
        return self::int('RAFFLES_INTERNAL_CLOCK_SKEW', 60);
    }

    /**
     * Proposal throttling is per authenticated user and evaluated in MySQL;
     * it survives container restarts and needs no shared cache.
     */
    public static function proposalDailyLimit(): int
    {
        return min(20, self::int('PROPOSAL_DAILY_LIMIT', 3));
    }

    public static function proposalCooldownSeconds(): int
    {
        return min(3600, self::nonNegativeInt('PROPOSAL_COOLDOWN_SECONDS', 30));
    }

    public static function legalConsentVersion(): string
    {
        return self::rawString('LEGAL_CONSENT_VERSION', '2026-11-15');
    }

    public static function internalSecret(): string
    {
        $appKey = self::appKey();

        return $appKey === ''
            ? ''
            : hash_hmac('sha256', 'rifacloud-raffles-internal', $appKey);
    }

    /**
     * APP_KEY is shared with the monolith and auth-service so the Laravel
     * session cookie can be decrypted during the transition (ADR-001 §5).
     */
    public static function appKey(): string
    {
        return self::rawString('APP_KEY', self::rawString('RAFFLES_APP_KEY', ''));
    }

    public static function sessionCookie(): string
    {
        return self::rawString('SESSION_COOKIE', 'rifacloud-session');
    }

    public static function sessionLifetimeMinutes(): int
    {
        return self::int('SESSION_LIFETIME', 120);
    }

    public static function sessionTable(): string
    {
        $table = self::rawString('SESSION_TABLE', 'sessions');

        // The table name cannot be bound as a parameter; refuse anything that
        // is not a plain identifier instead of interpolating arbitrary input.
        return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1 ? $table : 'sessions';
    }

    private static function string(string $key, string $default): string
    {
        return self::rawString($key, $default);
    }

    private static function rawString(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    private static function int(string $key, int $default): int
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return max(1, $default);
        }

        return max(1, (int) $value);
    }

    /**
     * Allows an explicit zero (for example, disabling the proposal cooldown
     * in a controlled test) while never returning a negative value.
     */
    private static function nonNegativeInt(string $key, int $default): int
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return max(0, $default);
        }

        return max(0, (int) $value);
    }
}
