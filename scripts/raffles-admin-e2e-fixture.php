<?php

declare(strict_types=1);

/**
 * Disposable fixtures for the S2 raffle-administration E2E test.
 *
 * Actions:
 *   create   Inserts a temporary user (default role ADMIN) directly in the
 *            isolated `rifacloud_test` database and prints its credentials as
 *            JSON.
 *   cleanup  Removes the temporary users listed in RAFFLES_E2E_EMAILS and every
 *            order, payment, order item, raffle and raffle number created by
 *            them during the test.
 *
 * Safety:
 *   - Refuses to run unless DB_DATABASE is exactly `rifacloud_test`.
 *   - Refuses any email that does not match the dedicated prefixed pattern.
 *   - Only touches rows owned by those temporary users (created_by/user_id),
 *     never pre-existing production rows.
 *
 * The test script always runs cleanup in a `finally` block.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require '/var/www/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require '/var/www/bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$database = (string) config('database.connections.mysql.database');

if ($database !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: database must be rifacloud_test, got '{$database}'.\n");
    exit(2);
}

$action = $argv[1] ?? '';

$emailPattern = '/^raffles-e2e-[a-z0-9]+@example\.invalid$/';

if ($action === 'create') {
    $token = strtolower(bin2hex(random_bytes(6)));
    $email = getenv('RAFFLES_E2E_EMAIL') ?: "raffles-e2e-{$token}@example.invalid";
    $role = strtoupper((string) (getenv('RAFFLES_E2E_ROLE') ?: 'ADMIN'));
    $password = (string) (getenv('RAFFLES_E2E_PASSWORD') ?: bin2hex(random_bytes(12)));

    if (preg_match($emailPattern, $email) !== 1) {
        fwrite(STDERR, "Refusing to create a user with a non-test email: {$email}\n");
        exit(2);
    }

    if (! in_array($role, ['USER', 'ADMIN'], true)) {
        fwrite(STDERR, "Invalid RAFFLES_E2E_ROLE '{$role}'.\n");
        exit(2);
    }

    $existing = DB::table('users')->where('email', $email)->value('id');

    if ($existing !== null) {
        fwrite(STDERR, "A user already exists for {$email}; refusing to overwrite.\n");
        exit(2);
    }

    $id = DB::table('users')->insertGetId([
        'name' => 'Raffles E2E '.$role,
        'email' => $email,
        'phone' => '0000000000',
        'password' => password_hash($password, PASSWORD_BCRYPT),
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo json_encode([
        'id' => (int) $id,
        'email' => $email,
        'password' => $password,
        'role' => $role,
    ], JSON_THROW_ON_ERROR)."\n";

    exit(0);
}

if ($action === 'cleanup') {
    $rawEmails = (string) (getenv('RAFFLES_E2E_EMAILS') ?: '');
    $emails = array_values(array_filter(array_map('trim', explode(',', $rawEmails))));

    if ($emails === []) {
        echo "cleanup: nothing to do (RAFFLES_E2E_EMAILS is empty)\n";
        exit(0);
    }

    foreach ($emails as $email) {
        if (preg_match($emailPattern, $email) !== 1) {
            fwrite(STDERR, "Refusing cleanup for non-test email: {$email}\n");
            exit(2);
        }
    }

    $userIds = DB::table('users')->whereIn('email', $emails)->pluck('id')->map(
        static fn ($id): int => (int) $id,
    )->all();

    if ($userIds === []) {
        echo "cleanup: no test users found\n";
        exit(0);
    }

    $raffleIds = DB::table('raffles')->whereIn('created_by', $userIds)->pluck('id')->map(
        static fn ($id): int => (int) $id,
    )->all();

    $orderIds = DB::table('orders')
        ->where(static function ($query) use ($userIds, $raffleIds): void {
            $query->whereIn('user_id', $userIds);

            if ($raffleIds !== []) {
                $query->orWhereIn('raffle_id', $raffleIds);
            }
        })
        ->pluck('id')
        ->map(static fn ($id): int => (int) $id)
        ->all();

    $deleted = DB::transaction(static function () use ($userIds, $raffleIds, $orderIds): array {
        $counts = [];

        if ($orderIds !== []) {
            // S9.1 notification rows are nullable on order delete; remove them
            // explicitly so a test run cannot leave audit/email residue.
            if (Schema::hasTable('notifications')) {
                $counts['notifications'] = DB::table('notifications')->whereIn('order_id', $orderIds)->delete();
            }

            $counts['payments'] = DB::table('payments')->whereIn('order_id', $orderIds)->delete();
            $counts['order_items'] = DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        }

        // Numbers that belong to a temporary raffle are deleted with it.
        $counts['raffle_numbers'] = $raffleIds === []
            ? 0
            : DB::table('raffle_numbers')->whereIn('raffle_id', $raffleIds)->delete();

        if ($orderIds !== []) {
            // Defensive: if a test order ever reserved numbers on a pre-existing
            // raffle, release them instead of deleting production rows.
            DB::table('raffle_numbers')
                ->whereIn('order_id', $orderIds)
                ->whereNotIn('raffle_id', $raffleIds === [] ? [0] : $raffleIds)
                ->update([
                    'order_id' => null,
                    'status' => 'AVAILABLE',
                    'reserved_at' => null,
                    'reserved_until' => null,
                    'sold_at' => null,
                ]);
        }

        if ($orderIds !== []) {
            $counts['orders'] = DB::table('orders')->whereIn('id', $orderIds)->delete();
        }

        if ($raffleIds !== []) {
            $counts['raffles'] = DB::table('raffles')->whereIn('id', $raffleIds)->delete();
        }

        $counts['sessions'] = DB::table('sessions')->whereIn('user_id', $userIds)->delete();
        $counts['users'] = DB::table('users')->whereIn('id', $userIds)->delete();

        return $counts;
    });

    echo 'cleanup: '.json_encode($deleted, JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

fwrite(STDERR, "Usage: php raffles-admin-e2e-fixture.php create|cleanup\n");
exit(2);
