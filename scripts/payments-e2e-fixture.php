<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Temporary fixtures for the S4 end-to-end check against the development
 * database. It is deliberately a separate script (not a migration) and every
 * row it creates is removed by the `cleanup` mode.
 *
 * Usage:
 *   php scripts/payments-e2e-fixture.php create
 *   php scripts/payments-e2e-fixture.php status   --raffle=ID
 *   php scripts/payments-e2e-fixture.php cleanup  --user=ID --admin=ID --raffle=ID
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

if (! in_array($database, ['rifacloud', 'rifacloud_test'], true)) {
    fwrite(STDERR, "Refusing to write fixtures: DB_DATABASE must be rifacloud or rifacloud_test (got {$database}).\n");
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
        $passwordHash = password_hash('payments-e2e-password', PASSWORD_BCRYPT);
        $passwordHashWeb = hash_hmac('sha256', $passwordHash, $appKey);

        $adminId = DB::table('users')->insertGetId([
            'name' => 'S4 e2e admin',
            'email' => "payments-e2e-admin-{$token}@example.invalid",
            'phone' => '0000000000',
            'password' => $passwordHash,
            'role' => 'ADMIN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'S4 e2e user',
            'email' => "payments-e2e-user-{$token}@example.invalid",
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
            'user_agent' => 's4-e2e',
            'payload' => base64_encode(json_encode([
                '_token' => $csrf,
                'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => $userId,
                'password_hash_web' => $passwordHashWeb,
            ], JSON_THROW_ON_ERROR)),
            'last_activity' => time(),
        ]);

        $raffleId = DB::table('raffles')->insertGetId([
            'title' => "S4 e2e raffle {$token}",
            'description' => 'Temporary fixture for the payments-service E2E check.',
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

        echo json_encode([
            'token' => $token,
            'user_id' => $userId,
            'admin_id' => $adminId,
            'raffle_id' => $raffleId,
            'session_cookie' => encryptedCookie(cookiePrefix($cookieName, $key).$sessionId, $key),
            'xsrf_header' => encryptedCookie(cookiePrefix('XSRF-TOKEN', $key).$csrf, $key),
            'database' => $database,
        ], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'status') {
        $raffleId = (int) ($options['raffle'] ?? 0);

        $orders = DB::table('orders')
            ->where('raffle_id', $raffleId)
            ->orderBy('id')
            ->get(['id', 'status', 'order_number'])
            ->map(function ($order) {
                $order->payments = DB::table('payments')
                    ->where('order_id', $order->id)
                    ->orderBy('id')
                    ->get(['id', 'external_id', 'status', 'amount'])
                    ->toArray();

                return $order;
            })
            ->toArray();

        $orderIds = DB::table('orders')->where('raffle_id', $raffleId)->pluck('id');
        $externalIds = $orderIds->isEmpty()
            ? []
            : DB::table('payments')->whereIn('order_id', $orderIds)->pluck('external_id')->toArray();

        $events = 0;

        foreach ($externalIds as $externalId) {
            $events += DB::table('payment_webhook_events')
                ->where('payload', 'like', '%'.$externalId.'%')
                ->count();
        }

        echo json_encode([
            'orders' => $orders,
            'reserved' => DB::table('raffle_numbers')->where('raffle_id', $raffleId)->where('status', 'RESERVED')->count(),
            'available' => DB::table('raffle_numbers')->where('raffle_id', $raffleId)->where('status', 'AVAILABLE')->count(),
            'sold' => DB::table('raffle_numbers')->where('raffle_id', $raffleId)->where('status', 'SOLD')->count(),
            'payments' => DB::table('payments')->whereIn('order_id', $orderIds)->count(),
            'events' => $events,
        ], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'cleanup') {
        $userId = (int) ($options['user'] ?? 0);
        $adminId = (int) ($options['admin'] ?? 0);
        $raffleId = (int) ($options['raffle'] ?? 0);
        $token = (string) ($options['token'] ?? '');

        DB::transaction(function () use ($userId, $adminId, $raffleId, $token): void {
            $orderIds = DB::table('orders')->where('raffle_id', $raffleId)->pluck('id');
            $externalIds = $orderIds->isEmpty()
                ? collect()
                : DB::table('payments')->whereIn('order_id', $orderIds)->pluck('external_id');

            foreach ($externalIds as $externalId) {
                DB::table('payment_webhook_events')
                    ->where('payload', 'like', '%'.$externalId.'%')
                    ->delete();
            }

            if ($token !== '') {
                DB::table('payment_webhook_events')
                    ->where('payload', 'like', '%'.$token.'%')
                    ->delete();
            }

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
