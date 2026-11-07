<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService;

/**
 * Environment-only configuration for the orders service.
 *
 * Like raffles-service, this process has no .env loader: Docker Compose is the
 * only configuration source and no secret is read from the repository.
 *
 * S3 shares the Laravel session contract and the MySQL schema with the
 * monolith during the transition (ADR-001 §5). The service owns writes to
 * `orders`, `order_items` and the reservation columns of `raffle_numbers`.
 */
final class Config
{
    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public static function database(): array
    {
        return [
            'host' => self::string('ORDERS_DB_HOST', self::string('DB_HOST', 'mysql')),
            'port' => self::int('ORDERS_DB_PORT', self::int('DB_PORT', 3306)),
            'database' => self::string('ORDERS_DB_DATABASE', self::string('DB_DATABASE', 'rifacloud')),
            'username' => self::string('ORDERS_DB_USERNAME', self::string('DB_USERNAME', 'rifacloud')),
            'password' => self::rawString('ORDERS_DB_PASSWORD', self::rawString('DB_PASSWORD', '')),
        ];
    }

    public static function reservationMinutes(): int
    {
        return self::int(
            'ORDERS_RESERVATION_MINUTES',
            self::int('RESERVATION_MINUTES', 10),
        );
    }

    public static function maxNumbersPerOrder(): int
    {
        return self::int(
            'ORDERS_ORDER_MAX_NUMBERS',
            self::int('ORDER_MAX_NUMBERS', 50),
        );
    }

    /**
     * Mirror of config('rifacloud.payment_gateway') so the resource keeps the
     * exact `simulated_payments` / `simulated` flags expected by React.
     */
    public static function paymentGateway(): string
    {
        return strtolower(self::rawString('PAYMENT_GATEWAY', 'fake'));
    }

    /**
     * APP_KEY is shared with the monolith and auth-service so the Laravel
     * session cookie can be validated here (ADR-001 §4).
     */
    public static function appKey(): string
    {
        return self::rawString('APP_KEY', self::rawString('ORDERS_APP_KEY', ''));
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

        return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1 ? $table : 'sessions';
    }

    /**
     * Key used by the monolith scheduler to authenticate the internal expiry
     * command. Deriving it from APP_KEY avoids a second shared secret while
     * keeping the value out of the repository (ADR-001 §4).
     */
    public static function internalSecret(): string
    {
        $appKey = self::appKey();

        return $appKey === ''
            ? ''
            : hash_hmac('sha256', 'rifacloud-orders-internal', $appKey);
    }

    /**
     * Key used by payments-service to authenticate the payment-result command.
     *
     * A separate derivation from the scheduler key keeps the two internal
     * directions independent: leaking one does not authorize the other.
     */
    public static function paymentsInternalSecret(): string
    {
        $appKey = self::appKey();

        return $appKey === ''
            ? ''
            : hash_hmac('sha256', 'rifacloud-payments-internal', $appKey);
    }

    public static function internalClockSkewSeconds(): int
    {
        return self::int('ORDERS_INTERNAL_CLOCK_SKEW', 60);
    }

    public static function internalLockName(): string
    {
        return 'rifacloud_orders_expire_reservations';
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
}
