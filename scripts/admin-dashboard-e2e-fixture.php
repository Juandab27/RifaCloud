<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Disposable fixtures for the S10.3 admin-dashboard E2E test.
 *
 * Actions:
 *   create   Inserts a temporary ADMIN and USER, one PUBLISHED raffle with
 *            known SOLD numbers, one DRAFT raffle, PAID orders and payment
 *            incidents. Prints ids, credentials and the exact metric totals.
 *   cleanup  Removes every row created by the fixture (and its sessions).
 *
 * Safety:
 *   - refuses to run unless DB_DATABASE is exactly `rifacloud_test`;
 *   - only touches rows created by the temporary admin/user;
 *   - never runs migrations and never changes the main schema.
 */

$base = is_file('/var/www/vendor/autoload.php') ? '/var/www' : dirname(__DIR__).'/src';

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

$action = $argv[1] ?? '';
$options = [];

foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
    }
}

/**
 * @param  list<int>  $raffleIds
 */
function cleanupFixture(array $raffleIds, array $userIds): void
{
    if ($raffleIds !== []) {
        $orders = DB::table('orders')->whereIn('raffle_id', $raffleIds)->pluck('id')->all();

        if ($orders !== []) {
            // notifications.order_id is nullable on delete; remove the rows
            // explicitly to keep the test schema free of delivery residue.
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->whereIn('order_id', $orders)->delete();
            }

            DB::table('payments')->whereIn('order_id', $orders)->delete();
            DB::table('order_items')->whereIn('order_id', $orders)->delete();
        }

        DB::table('raffle_numbers')->whereIn('raffle_id', $raffleIds)->update(['order_id' => null]);
        DB::table('raffle_results')->whereIn('raffle_id', $raffleIds)->delete();
        DB::table('orders')->whereIn('raffle_id', $raffleIds)->delete();
        DB::table('raffle_numbers')->whereIn('raffle_id', $raffleIds)->delete();
        DB::table('raffles')->whereIn('id', $raffleIds)->delete();
    }

    if ($userIds !== []) {
        DB::table('sessions')->whereIn('user_id', $userIds)->delete();

        if (Schema::hasTable('user_consents')) {
            DB::table('user_consents')->whereIn('user_id', $userIds)->delete();
        }

        DB::table('users')->whereIn('id', $userIds)->delete();
    }
}

if ($action === 'cleanup') {
    $raffleIds = array_values(array_filter(array_map(
        static fn (string $id): int => (int) $id,
        explode(',', (string) ($options['raffles'] ?? (getenv('DASH_E2E_RAFFLES') ?: ''))),
    )));
    $userIds = array_values(array_filter(array_map(
        static fn (string $id): int => (int) $id,
        explode(',', (string) ($options['users'] ?? (getenv('DASH_E2E_USERS') ?: ''))),
    )));

    cleanupFixture($raffleIds, $userIds);

    echo json_encode(['cleaned' => true], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action !== 'create') {
    fwrite(STDERR, "Usage: admin-dashboard-e2e-fixture.php create|cleanup\n");
    exit(2);
}

$token = strtolower(bin2hex(random_bytes(6)));
$adminEmail = (string) ($options['admin_email'] ?? (getenv('DASH_E2E_ADMIN_EMAIL') ?: "dashboard-e2e-a{$token}@example.invalid"));
$userEmail = (string) ($options['user_email'] ?? (getenv('DASH_E2E_USER_EMAIL') ?: "dashboard-e2e-u{$token}@example.invalid"));
$adminPassword = (string) ($options['admin_password'] ?? (getenv('DASH_E2E_ADMIN_PASSWORD') ?: 'Dashboard-e2e-admin1!'));
$userPassword = (string) ($options['user_password'] ?? (getenv('DASH_E2E_USER_PASSWORD') ?: 'Dashboard-e2e-user1!'));

if (preg_match('/^dashboard-e2e-[a-z0-9]+@example\.invalid$/', $adminEmail) !== 1
    || preg_match('/^dashboard-e2e-[a-z0-9]+@example\.invalid$/', $userEmail) !== 1) {
    fwrite(STDERR, "Refusing non-test email addresses.\n");
    exit(2);
}

$adminId = DB::table('users')->insertGetId([
    'name' => 'Dashboard E2E admin',
    'email' => $adminEmail,
    'phone' => '0000000000',
    'password' => password_hash($adminPassword, PASSWORD_BCRYPT),
    'role' => 'ADMIN',
    'created_at' => now(),
    'updated_at' => now(),
]);

$userId = DB::table('users')->insertGetId([
    'name' => 'Dashboard E2E user',
    'email' => $userEmail,
    'phone' => '0000000000',
    'password' => password_hash($userPassword, PASSWORD_BCRYPT),
    'role' => 'USER',
    'created_at' => now(),
    'updated_at' => now(),
]);

$publishedId = DB::table('raffles')->insertGetId([
    'title' => 'Dashboard E2E published '.$token,
    'description' => 'S10.3 dashboard fixture.',
    'price_per_number' => 100000,
    'min_number' => 1,
    'max_number' => 10,
    'status' => 'PUBLISHED',
    'created_by' => $adminId,
    'created_at' => now(),
    'updated_at' => now(),
]);

$draftId = DB::table('raffles')->insertGetId([
    'title' => 'Dashboard E2E draft '.$token,
    'description' => 'S10.3 dashboard fixture (not published).',
    'price_per_number' => 100000,
    'min_number' => 1,
    'max_number' => 3,
    'status' => 'DRAFT',
    'created_by' => $adminId,
    'created_at' => now(),
    'updated_at' => now(),
]);

$numberIds = [];

for ($number = 1; $number <= 10; $number++) {
    $numberIds[$number] = DB::table('raffle_numbers')->insertGetId([
        'raffle_id' => $publishedId,
        'number' => $number,
        'status' => 'AVAILABLE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

for ($number = 1; $number <= 3; $number++) {
    DB::table('raffle_numbers')->insert([
        'raffle_id' => $draftId,
        'number' => $number,
        'status' => 'AVAILABLE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

$insertOrder = static function (string $status) use ($publishedId, $userId, $token): int {
    return DB::table('orders')->insertGetId([
        'user_id' => $userId,
        'raffle_id' => $publishedId,
        'order_number' => 'DASH-'.$token.'-'.strtoupper(bin2hex(random_bytes(3))),
        'total_amount' => 100000,
        'status' => $status,
        'expires_at' => now()->addMinutes(10),
        'paid_at' => $status === 'PAID' ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
};

$paidOne = $insertOrder('PAID');
$paidTwo = $insertOrder('PAID');
$reviewOrder = $insertOrder('PAYMENT_REVIEW');
$refundOrder = $insertOrder('REQUIRES_REFUND');
$rejectedOrder = $insertOrder('REJECTED');
$insertOrder('PENDING_PAYMENT');

DB::table('order_items')->insert([
    ['order_id' => $paidOne, 'raffle_number_id' => $numberIds[1], 'price_at_purchase' => 100000, 'created_at' => now(), 'updated_at' => now()],
    ['order_id' => $paidTwo, 'raffle_number_id' => $numberIds[2], 'price_at_purchase' => 100000, 'created_at' => now(), 'updated_at' => now()],
]);

foreach ([1 => $paidOne, 2 => $paidTwo] as $number => $orderId) {
    DB::table('raffle_numbers')->where('id', $numberIds[$number])->update([
        'status' => 'SOLD',
        'sold_at' => now(),
        'order_id' => $orderId,
        'updated_at' => now(),
    ]);
}

$insertPayment = static function (int $orderId, string $status, string $externalId): void {
    DB::table('payments')->insert([
        'order_id' => $orderId,
        'provider' => 'MERCADOPAGO',
        'external_id' => $externalId,
        'status' => $status,
        'amount' => 100000,
        'currency' => 'COP',
        'checkout_url' => null,
        'response_data' => json_encode(['simulated' => true], JSON_THROW_ON_ERROR),
        'verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
};

$insertPayment($paidOne, 'APPROVED', 'DASH-'.$token.'-APPROVED-1');
$insertPayment($paidTwo, 'APPROVED', 'DASH-'.$token.'-APPROVED-2');
$insertPayment($reviewOrder, 'UNKNOWN', 'DASH-'.$token.'-UNKNOWN');
$insertPayment($refundOrder, 'REFUND_REQUIRED', 'DASH-'.$token.'-REFUND');
$insertPayment($rejectedOrder, 'REJECTED', 'DASH-'.$token.'-REJECTED');

$expected = [
    'published_raffles' => DB::table('raffles')->where('status', 'PUBLISHED')->count(),
    'paid_orders' => DB::table('orders')->where('status', 'PAID')->count(),
    'sold_numbers' => DB::table('raffle_numbers')->where('status', 'SOLD')->count(),
    'pending_incidents' => DB::table('payments')
        ->whereIn('status', ['UNKNOWN', 'REFUND_REQUIRED'])
        ->count(),
];

echo json_encode([
    'admin_id' => $adminId,
    'user_id' => $userId,
    'admin_email' => $adminEmail,
    'user_email' => $userEmail,
    'admin_password' => $adminPassword,
    'user_password' => $userPassword,
    'raffle_ids' => [$publishedId, $draftId],
    'expected' => $expected,
], JSON_THROW_ON_ERROR);
