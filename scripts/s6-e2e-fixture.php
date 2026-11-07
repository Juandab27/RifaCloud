<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Temporary fixtures for the S6 end-to-end check (session continuity,
 * reservation expiry and Mercado Pago return). It never runs migrations and
 * every row it creates is removed by the cleanup mode.
 *
 * Usage:
 *   php scripts/s6-e2e-fixture.php create-raffle
 *   php scripts/s6-e2e-fixture.php expired-order --raffle=ID --user=ID --numbers=1,2 --kind=unknown
 *   php scripts/s6-e2e-fixture.php status --raffle=ID
 *   php scripts/s6-e2e-fixture.php cleanup --raffle=ID --admin=ID --email=user@example.invalid
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

try {
    if ($mode === 'create-raffle') {
        $token = bin2hex(random_bytes(6));
        $password = password_hash('s6-e2e-password', PASSWORD_BCRYPT);

        $adminId = DB::table('users')->insertGetId([
            'name' => 'S6 e2e admin',
            'email' => "s6-e2e-admin-{$token}@example.invalid",
            'phone' => '0000000000',
            'password' => $password,
            'role' => 'ADMIN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $raffleId = DB::table('raffles')->insertGetId([
            'title' => "S6 e2e raffle {$token}",
            'description' => 'Temporary fixture for the S6 session/expiry/return check.',
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
            'raffle_id' => $raffleId,
            'admin_id' => $adminId,
            'database' => $database,
        ], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'expired-order') {
        $raffleId = (int) ($options['raffle'] ?? 0);
        $userId = (int) ($options['user'] ?? 0);
        $kind = (string) ($options['kind'] ?? 'no_payment');
        $numbers = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) ($options['numbers'] ?? '1')),
        )));
        $token = (string) ($options['token'] ?? bin2hex(random_bytes(4)));

        if ($raffleId <= 0 || $userId <= 0 || $numbers === []) {
            fwrite(STDERR, "Missing --raffle, --user or --numbers.\n");
            exit(2);
        }

        $status = match ($kind) {
            'approved' => 'PAID',
            'review' => 'PAYMENT_REVIEW',
            default => 'PENDING_PAYMENT',
        };

        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'raffle_id' => $raffleId,
            'order_number' => "S6-{$token}-".strtoupper(bin2hex(random_bytes(3))),
            'total_amount' => 1500 * count($numbers),
            'status' => $status,
            'expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)'),
            'paid_at' => $status === 'PAID' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = DB::table('raffle_numbers')
            ->where('raffle_id', $raffleId)
            ->whereIn('number', $numbers)
            ->orderBy('number')
            ->get(['id', 'number']);

        $sold = in_array($kind, ['approved', 'sold'], true);

        foreach ($rows as $row) {
            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'raffle_number_id' => $row->id,
                'price_at_purchase' => 1500,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('raffle_numbers')->where('id', $row->id)->update([
                'status' => $sold ? 'SOLD' : 'RESERVED',
                'reserved_at' => now(),
                'reserved_until' => DB::raw('DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)'),
                'sold_at' => $sold ? now() : null,
                'order_id' => $orderId,
                'updated_at' => now(),
            ]);
        }

        $paymentStatus = match ($kind) {
            'unknown', 'review' => 'UNKNOWN',
            'approved' => 'APPROVED',
            default => 'PENDING',
        };

        if ($kind !== 'no_payment') {
            DB::table('payments')->insert([
                'order_id' => $orderId,
                'provider' => 'MERCADOPAGO',
                'external_id' => 'S6-'.$token.'-'.strtoupper(bin2hex(random_bytes(6))),
                'status' => $paymentStatus,
                'amount' => 1500 * count($numbers),
                'currency' => 'COP',
                'checkout_url' => null,
                'verified_at' => $paymentStatus === 'APPROVED' ? now() : null,
                'response_data' => json_encode(['simulated' => true, 's6_fixture' => $kind], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        echo json_encode(['order_id' => $orderId, 'kind' => $kind], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'status') {
        $raffleId = (int) ($options['raffle'] ?? 0);

        $orders = DB::table('orders')
            ->where('raffle_id', $raffleId)
            ->orderBy('id')
            ->get(['id', 'status', 'expires_at'])
            ->map(function ($order) {
                $order->payments = DB::table('payments')
                    ->where('order_id', $order->id)
                    ->orderBy('id')
                    ->get(['id', 'status'])
                    ->toArray();

                return $order;
            })
            ->toArray();

        $numberRows = DB::table('raffle_numbers')
            ->where('raffle_id', $raffleId)
            ->get(['number', 'status'])
            ->toArray();

        echo json_encode([
            'orders' => $orders,
            'numbers' => $numberRows,
            'reserved' => count(array_filter($numberRows, static fn ($n): bool => $n->status === 'RESERVED')),
            'available' => count(array_filter($numberRows, static fn ($n): bool => $n->status === 'AVAILABLE')),
            'sold' => count(array_filter($numberRows, static fn ($n): bool => $n->status === 'SOLD')),
            'payments' => DB::table('payments')->whereIn(
                'order_id',
                DB::table('orders')->where('raffle_id', $raffleId)->pluck('id'),
            )->count(),
        ], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'cleanup') {
        $raffleId = (int) ($options['raffle'] ?? 0);
        $adminId = (int) ($options['admin'] ?? 0);
        $email = (string) ($options['email'] ?? '');

        DB::transaction(function () use ($raffleId, $adminId, $email): void {
            $orderIds = DB::table('orders')->where('raffle_id', $raffleId)->pluck('id');
            $externalIds = $orderIds->isEmpty()
                ? collect()
                : DB::table('payments')->whereIn('order_id', $orderIds)->pluck('external_id');

            foreach ($externalIds as $externalId) {
                DB::table('payment_webhook_events')->where('payload', 'like', '%'.$externalId.'%')->delete();
            }

            if ($orderIds->isNotEmpty()) {
                DB::table('payments')->whereIn('order_id', $orderIds)->delete();
                DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            }

            DB::table('raffle_numbers')->where('raffle_id', $raffleId)->update(['order_id' => null]);
            DB::table('orders')->where('raffle_id', $raffleId)->delete();
            DB::table('raffle_numbers')->where('raffle_id', $raffleId)->delete();
            DB::table('raffles')->where('id', $raffleId)->delete();

            $userIds = array_filter([$adminId]);

            if ($email !== '') {
                $userIds = array_merge(
                    $userIds,
                    DB::table('users')->where('email', $email)->pluck('id')->all(),
                );
            }

            foreach (array_unique($userIds) as $id) {
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
