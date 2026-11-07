<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService;

/**
 * Environment-only configuration for the payments service.
 *
 * The service has no .env loader: Docker Compose is the only configuration
 * source and no secret is read from the repository.
 *
 * S4 shares the Laravel session contract and the MySQL schema with the other
 * services during the transition (ADR-001 §5). The service owns writes to
 * `payments` and `payment_webhook_events`; order and number state changes are
 * requested through the signed internal command of orders-service.
 */
final class Config
{
    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public static function database(): array
    {
        return [
            'host' => self::string('PAYMENTS_DB_HOST', self::string('DB_HOST', 'mysql')),
            'port' => self::int('PAYMENTS_DB_PORT', self::int('DB_PORT', 3306)),
            'database' => self::string('PAYMENTS_DB_DATABASE', self::string('DB_DATABASE', 'rifacloud')),
            'username' => self::string('PAYMENTS_DB_USERNAME', self::string('DB_USERNAME', 'rifacloud')),
            'password' => self::rawString('PAYMENTS_DB_PASSWORD', self::rawString('DB_PASSWORD', '')),
        ];
    }

    public static function paymentGateway(): string
    {
        return strtolower(self::rawString('PAYMENT_GATEWAY', 'fake'));
    }

    public static function fakeOutcome(): string
    {
        return strtoupper(self::rawString('PAYMENT_FAKE_OUTCOME', 'UNKNOWN'));
    }

    public static function appKey(): string
    {
        return self::rawString('APP_KEY', self::rawString('PAYMENTS_APP_KEY', ''));
    }

    public static function appUrl(): string
    {
        return rtrim(self::rawString('APP_URL', 'http://localhost'), '/');
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
     * @return array{access_token: string, webhook_secret: string, sandbox: bool, base_url: string, timeout: int, back_url: string, webhook_url: string}
     */
    public static function mercadoPago(): array
    {
        return [
            'access_token' => self::rawString('MERCADOPAGO_ACCESS_TOKEN', ''),
            'webhook_secret' => self::rawString('MERCADOPAGO_WEBHOOK_SECRET', ''),
            'sandbox' => in_array(
                strtolower(self::rawString('MERCADOPAGO_SANDBOX', 'true')),
                ['1', 'true', 'yes', 'on'],
                true,
            ),
            'base_url' => rtrim(self::rawString('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'), '/'),
            'timeout' => self::int('MERCADOPAGO_TIMEOUT', 10),
            'back_url' => self::rawString('MERCADOPAGO_BACK_URL', ''),
            'webhook_url' => self::rawString('MERCADOPAGO_WEBHOOK_URL', ''),
        ];
    }

    public static function ordersServiceUrl(): string
    {
        return rtrim(self::rawString('ORDERS_SERVICE_URL', 'http://orders-service'), '/');
    }

    public static function ordersCommandTimeout(): int
    {
        return self::int('ORDERS_INTERNAL_TIMEOUT', 5);
    }

    /**
     * Key presented to orders-service on the payment-result command. It must
     * match Config::paymentsInternalSecret() in orders-service.
     */
    public static function ordersInternalSecret(): string
    {
        $appKey = self::appKey();

        return $appKey === ''
            ? ''
            : hash_hmac('sha256', 'rifacloud-payments-internal', $appKey);
    }

    /**
     * Key the scheduler presents to this service for reconciliation. It is a
     * different derivation than the orders command, so the two directions are
     * independent.
     */
    public static function internalSecret(): string
    {
        $appKey = self::appKey();

        return $appKey === ''
            ? ''
            : hash_hmac('sha256', 'rifacloud-payments-reconcile', $appKey);
    }

    public static function internalClockSkewSeconds(): int
    {
        return self::int('PAYMENTS_INTERNAL_CLOCK_SKEW', 60);
    }

    public static function reconcileBatchSize(): int
    {
        return self::int('PAYMENTS_RECONCILE_LIMIT', 50);
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
