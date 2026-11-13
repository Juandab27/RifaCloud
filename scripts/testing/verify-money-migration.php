<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Focused verification of the cents -> COP data migration.
 *
 * Runs only against `rifacloud_test`, inserts legacy fixtures inside a
 * transaction, executes the real migration class, checks the exact division
 * and the non-divisible guard, then rolls everything back. The main
 * `rifacloud` schema is never touched.
 *
 * Usage (inside the app container):
 *   php /scripts/testing/verify-money-migration.php
 */

$base = is_file('/var/www/vendor/autoload.php') ? '/var/www' : dirname(__DIR__, 2).'/src';

require $base.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $base.'/bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$database = (string) config('database.connections.mysql.database');

if ($database !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: DB_DATABASE must be rifacloud_test (got '{$database}').\n");
    exit(2);
}

$migrationPath = $base.'/database/migrations/2026_11_13_000012_convert_monetary_values_to_cop.php';

if (! is_file($migrationPath)) {
    fwrite(STDERR, "Migration file not found: {$migrationPath}\n");
    exit(2);
}

$checks = 0;

$check = static function (mixed $actual, mixed $expected, string $message) use (&$checks): void {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$message}: expected ".var_export($expected, true).', got '.var_export($actual, true)."\n");
        exit(1);
    }

    $checks++;
};

$now = date('Y-m-d H:i:s');
$suffix = strtoupper(bin2hex(random_bytes(6)));

DB::beginTransaction();

try {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Migration check user',
        'email' => 'money-migration-'.$suffix.'@example.invalid',
        'phone' => '0000000000',
        'password' => password_hash('migration-check', PASSWORD_BCRYPT),
        'role' => 'ADMIN',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $raffleId = (int) DB::table('raffles')->insertGetId([
        'title' => 'MIGRATION-CHECK-'.$suffix,
        'description' => 'Legacy cents fixture',
        'price_per_number' => 100000,
        'min_number' => 1,
        'max_number' => 1,
        'status' => 'PUBLISHED',
        'created_by' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $numberId = (int) DB::table('raffle_numbers')->insertGetId([
        'raffle_id' => $raffleId,
        'number' => 1,
        'status' => 'SOLD',
        'sold_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $orderId = (int) DB::table('orders')->insertGetId([
        'user_id' => $userId,
        'raffle_id' => $raffleId,
        'order_number' => 'MIGRATION-CHECK-'.$suffix,
        'total_amount' => 200000,
        'status' => 'PAID',
        'expires_at' => $now,
        'paid_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('order_items')->insert([
        'order_id' => $orderId,
        'raffle_number_id' => $numberId,
        'price_at_purchase' => 100000,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('payments')->insert([
        'order_id' => $orderId,
        'provider' => 'MERCADOPAGO',
        'external_id' => 'MIGRATION-CHECK-'.$suffix,
        'status' => 'APPROVED',
        'amount' => 200000,
        'currency' => 'COP',
        'verified_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    /** @var \Illuminate\Database\Migrations\Migration $migration */
    $migration = require $migrationPath;
    $migration->up();

    $check((int) DB::table('raffles')->where('id', $raffleId)->value('price_per_number'), 1000, 'raffle price converts to COP');
    $check((int) DB::table('orders')->where('id', $orderId)->value('total_amount'), 2000, 'order total converts to COP');
    $check((int) DB::table('order_items')->where('order_id', $orderId)->value('price_at_purchase'), 1000, 'order item snapshot converts to COP');
    $check((int) DB::table('payments')->where('order_id', $orderId)->value('amount'), 2000, 'payment amount converts to COP');

    $badRaffleId = (int) DB::table('raffles')->insertGetId([
        'title' => 'MIGRATION-CHECK-NON-DIVISIBLE-'.$suffix,
        'description' => 'Must block the migration',
        'price_per_number' => 1001,
        'min_number' => 1,
        'max_number' => 1,
        'status' => 'DRAFT',
        'created_by' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $blocked = false;

    try {
        $migration->up();
    } catch (RuntimeException $exception) {
        $blocked = str_contains($exception->getMessage(), 'not an exact multiple of 100');
    }

    $check($blocked, true, 'a non-divisible value blocks the migration');
    $check((int) DB::table('raffles')->where('id', $badRaffleId)->value('price_per_number'), 1001, 'the blocked migration leaves data unchanged');
    $check((int) DB::table('raffles')->where('id', $raffleId)->value('price_per_number'), 1000, 'the blocked migration does not divide twice');

    DB::rollBack();

    echo json_encode([
        'verified' => true,
        'database' => $database,
        'checks' => $checks,
    ], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    DB::rollBack();
    fwrite(STDERR, 'Migration verification failed: '.$exception::class.' - '.$exception->getMessage()."\n");
    exit(1);
}
