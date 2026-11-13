<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary fixtures for the S3 end-to-end check against the development
 * database. It is deliberately a separate script (not a migration) and every
 * row it creates is removed by the `cleanup` mode.
 *
 * Usage:
 *   php scripts/orders-e2e-fixture.php create
 *   php scripts/orders-e2e-fixture.php status    --raffle=ID
 *   php scripts/orders-e2e-fixture.php order     --order=ID
 *   php scripts/orders-e2e-fixture.php expire    --order=ID
 *   php scripts/orders-e2e-fixture.php unknown   --order=ID
 *   php scripts/orders-e2e-fixture.php cleanup   --user=ID --admin=ID --raffle=ID
 */

$base = is_file('/var/www/vendor/autoload.php') ? '/var/www' : dirname(__DIR__).'/src';

require $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

date_default_timezone_set('UTC');

$mode = (string) ($argv[1] ?? '');
$options = [];

foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
    }
}

$database = (string) config('database.connections.mysql.database');

if ($database !== 'rifacloud') {
    fwrite(STDERR, "Refusing to write fixtures: DB_DATABASE must be rifacloud (got {$database}).\n");
    exit(2);
}

$appKey = (string) config('app.key');
$cookieName = (string) config('session.cookie', 'rifacloud-session');
$key = str_starts_with($appKey, 'base64:')
    ? base64_decode(substr($appKey, 7), true)
    : $appKey;

if (! is_string($key) || $key === '') {
    fwrite(STDERR, "APP_KEY is not configured.\n");
    exit(2);
}

/**
 * Builds a byte-identical Laravel encrypted cookie payload.
 */
function encryptedCookie(string $value, string $key): string
{
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($value, 'aes-256-cbc', $key, 0, $iv);

    if ($ciphertext === false) {
        throw new RuntimeException('openssl_encrypt failed.');
    }

    return base64_encode(json_encode([
        'iv' => base64_encode($iv),
        'value' => $ciphertext,
        'mac' => hash_hmac('sha256', base64_encode($iv).$ciphertext, $key),
        'tag' => '',
    ], JSON_THROW_ON_ERROR));
}

function cookiePrefix(string $name, string $key): string
{
    return hash_hmac('sha1', $name.'v2', $key).'|';
}

try {
    if ($mode === 'create') {
        $token = bin2hex(random_bytes(6));
        $passwordHash = password_hash('e2e-password', PASSWORD_BCRYPT);
        $passwordHashWeb = hash_hmac('sha256', $passwordHash, $appKey);

        $adminId = DB::table('users')->insertGetId([
            'name' => 'S3 e2e admin',
            'email' => "orders-e2e-admin-{$token}@example.invalid",
            'phone' => '0000000000',
            'password' => $passwordHash,
            'role' => 'ADMIN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'S3 e2e user',
            'email' => "orders-e2e-user-{$token}@example.invalid",
            'phone' => '0000000000',
            'password' => $passwordHash,
            'role' => 'USER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $csrf = bin2hex(random_bytes(20));
        $sessionId = bin2hex(random_bytes(20));

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 's3-e2e',
            'payload' => base64_encode(json_encode([
                '_token' => $csrf,
                'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => $userId,
                'password_hash_web' => $passwordHashWeb,
            ], JSON_THROW_ON_ERROR)),
            'last_activity' => time(),
        ]);

        $raffleId = DB::table('raffles')->insertGetId([
            'title' => "S3 e2e raffle {$token}",
            'description' => 'Temporary fixture for the orders-service E2E check.',
            'price_per_number' => 1500,
            'min_number' => 1,
            'max_number' => 20,
            'status' => 'PUBLISHED',
            'starts_at' => null,
            'ends_at' => null,
            'created_by' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $numbers = [];

        for ($number = 1; $number <= 20; $number++) {
            $numbers[] = [
                'raffle_id' => $raffleId,
                'number' => $number,
                'status' => 'AVAILABLE',
                'reserved_at' => null,
                'reserved_until' => null,
                'sold_at' => null,
                'order_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('raffle_numbers')->insert($numbers);

        $sessionCookie = encryptedCookie(cookiePrefix($cookieName, $key).$sessionId, $key);
        $xsrfHeader = encryptedCookie(cookiePrefix('XSRF-TOKEN', $key).$csrf, $key);

        echo json_encode([
            'token' => $token,
            'user_id' => $userId,
            'admin_id' => $adminId,
            'raffle_id' => $raffleId,
            'session_cookie' => $sessionCookie,
            'xsrf_header' => $xsrfHeader,
            'database' => $database,
        ], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'status') {
        $raffleId = (int) ($options['raffle'] ?? 0);

        $orderIds = DB::table('orders')->where('raffle_id', $raffleId)->pluck('id');
        $orders = DB::table('orders')
            ->where('raffle_id', $raffleId)
            ->orderBy('id')
            ->get(['id', 'status', 'order_number', 'expires_at'])
            ->toArray();

        echo json_encode([
            'orders' => $orders,
            'reserved' => DB::table('raffle_numbers')->where('raffle_id', $raffleId)->where('status', 'RESERVED')->count(),
            'available' => DB::table('raffle_numbers')->where('raffle_id', $raffleId)->where('status', 'AVAILABLE')->count(),
            'sold' => DB::table('raffle_numbers')->where('raffle_id', $raffleId)->where('status', 'SOLD')->count(),
            'payments' => $orderIds->isEmpty()
                ? 0
                : DB::table('payments')->whereIn('order_id', $orderIds)->count(),
        ], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'order') {
        $orderId = (int) ($options['order'] ?? 0);
        $order = DB::table('orders')->where('id', $orderId)->first();

        if ($order === null) {
            fwrite(STDERR, "Order {$orderId} not found.\n");
            exit(1);
        }

        echo json_encode($order, JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'expire' || $mode === 'unknown') {
        $orderId = (int) ($options['order'] ?? 0);
        $order = DB::table('orders')->where('id', $orderId)->first();

        if ($order === null) {
            fwrite(STDERR, "Order {$orderId} not found.\n");
            exit(1);
        }

        if ($mode === 'unknown') {
            DB::table('payments')->insert([
                'order_id' => $orderId,
                'provider' => 'MERCADOPAGO',
                'external_id' => 'e2e-'.bin2hex(random_bytes(6)),
                'status' => 'UNKNOWN',
                'amount' => (int) $order->total_amount,
                'currency' => 'COP',
                'checkout_url' => null,
                'verified_at' => null,
                'response_data' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('orders')->where('id', $orderId)->update([
            'expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)'),
            'updated_at' => now(),
        ]);
        DB::table('raffle_numbers')->where('order_id', $orderId)->update([
            'reserved_until' => DB::raw('DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)'),
            'updated_at' => now(),
        ]);

        echo json_encode(['order_id' => $orderId, 'prepared' => $mode], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'cleanup') {
        $userId = (int) ($options['user'] ?? 0);
        $adminId = (int) ($options['admin'] ?? 0);
        $raffleId = (int) ($options['raffle'] ?? 0);

        DB::transaction(function () use ($userId, $adminId, $raffleId): void {
            $orderIds = DB::table('orders')->where('raffle_id', $raffleId)->pluck('id');

            if ($orderIds->isNotEmpty()) {
                DB::table('payments')->whereIn('order_id', $orderIds)->delete();
                DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            }

            DB::table('raffle_numbers')->where('raffle_id', $raffleId)->update(['order_id' => null]);
            DB::table('orders')->where('raffle_id', $raffleId)->delete();
            DB::table('raffle_numbers')->where('raffle_id', $raffleId)->delete();
            DB::table('raffles')->where('id', $raffleId)->delete();

            foreach (array_filter([$userId, $adminId]) as $id) {
                DB::table('sessions')->where('user_id', $id)->delete();

                if (Schema::hasTable('user_consents')) {
                    DB::table('user_consents')->where('user_id', $id)->delete();
                }

                DB::table('users')->where('id', $id)->delete();
            }
        });

        echo json_encode(['cleaned' => true], JSON_THROW_ON_ERROR);

        exit(0);
    }

    fwrite(STDERR, "Unknown mode: {$mode}\n");
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Fixture error: '.$exception::class.' - '.$exception->getMessage()."\n");
    exit(1);
}
