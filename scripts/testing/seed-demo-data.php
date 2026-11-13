<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent demo fixtures for manual functional testing (S10.4).
 *
 * Scope and safety:
 *   - refuses to run unless DB_DATABASE is exactly `rifacloud_test`;
 *   - never runs migrations, destroys the schema or writes to `rifacloud`;
 *   - only deletes users with the fixed demo emails and rows marked with the
 *     `[DEMO]` title prefix / `DEMO-` order-number prefix;
 *   - creates internally consistent snapshots for states that cannot be
 *     reached without waiting or a controlled provider outcome;
 *   - does not execute or simulate a financial transition.
 *
 * Usage (inside the app container):
 *   php /scripts/testing/seed-demo-data.php seed
 *   php /scripts/testing/seed-demo-data.php cleanup
 *   php /scripts/testing/seed-demo-data.php cleanup --purge-users
 *
 * Passwords are supplied through environment variables by
 * scripts/prepare-demo-environment.ps1; they are never printed here.
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

$action = $argv[1] ?? 'seed';
$purgeUsers = in_array('--purge-users', $argv, true);

$emails = [
    'admin' => 'demo-admin@rifacloud.test',
    'buyer1' => 'demo-buyer1@rifacloud.test',
    'buyer2' => 'demo-buyer2@rifacloud.test',
];

$passwords = [
    'admin' => (string) getenv('DEMO_ADMIN_PASSWORD'),
    'buyer1' => (string) getenv('DEMO_BUYER1_PASSWORD'),
    'buyer2' => (string) getenv('DEMO_BUYER2_PASSWORD'),
];

$now = Carbon::now();
$format = static fn (Carbon $value): string => $value->format('Y-m-d H:i:s');

/**
 * Remove only the demo fixtures. Manual orders created by the demo users on
 * non-demo raffles are preserved; cleanup reports them and keeps the users.
 *
 * @param  array<string, string>  $emails
 * @return array{users: int, raffles: int, orders: int, purged_users: int, kept_users: int}
 */
function removeDemoData(array $emails, bool $purgeUsers): array
{
    $userIds = DB::table('users')->whereIn('email', array_values($emails))->pluck('id')->all();
    $raffleIds = DB::table('raffles')
        ->where('title', 'like', '[DEMO]%')
        ->pluck('id')
        ->all();

    $orderIds = DB::table('orders')
        ->where(function ($query) use ($raffleIds): void {
            $query->where('order_number', 'like', 'DEMO-%');

            if ($raffleIds !== []) {
                $query->orWhereIn('raffle_id', $raffleIds);
            }
        })
        ->pluck('id')
        ->all();

    if ($orderIds !== []) {
        DB::table('notifications')->whereIn('order_id', $orderIds)->delete();
        DB::table('payments')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('raffle_numbers')->whereIn('order_id', $orderIds)->update([
            'order_id' => null,
            'reserved_at' => null,
            'reserved_until' => null,
            'updated_at' => now(),
        ]);
        DB::table('orders')->whereIn('id', $orderIds)->delete();
    }

    if ($raffleIds !== []) {
        DB::table('raffle_results')->whereIn('raffle_id', $raffleIds)->delete();
        DB::table('raffle_numbers')->whereIn('raffle_id', $raffleIds)->delete();
        DB::table('raffles')->whereIn('id', $raffleIds)->delete();
    }

    if ($userIds !== []) {
        DB::table('sessions')->whereIn('user_id', $userIds)->delete();
    }

    $purged = 0;
    $kept = 0;

    if ($purgeUsers && $userIds !== []) {
        foreach ($userIds as $userId) {
            $remaining = DB::table('orders')->where('user_id', $userId)->count();

            if ($remaining === 0) {
                DB::table('users')->where('id', $userId)->delete();
                $purged++;
            } else {
                $kept++;
            }
        }
    }

    return [
        'users' => count($userIds),
        'raffles' => count($raffleIds),
        'orders' => count($orderIds),
        'purged_users' => $purged,
        'kept_users' => $kept,
    ];
}

if ($action === 'cleanup') {
    $summary = removeDemoData($emails, $purgeUsers);

    echo json_encode([
        'cleaned' => true,
        'removed' => $summary,
        'database' => $database,
    ], JSON_THROW_ON_ERROR)."\n";

    exit(0);
}

if ($action !== 'seed') {
    fwrite(STDERR, "Usage: seed-demo-data.php seed|cleanup [--purge-users]\n");
    exit(2);
}

foreach ($passwords as $role => $password) {
    if (strlen($password) < 12) {
        fwrite(STDERR, "Refusing to seed: DEMO_".strtoupper($role)."_PASSWORD is missing or shorter than 12 characters.\n");
        exit(2);
    }
}

try {
    $summary = DB::transaction(function () use ($emails, $passwords, $now, $format): array {
        removeDemoData($emails, false);

        $upsertUser = static function (string $email, string $name, string $role, string $password): int {
            $existing = DB::table('users')->where('email', $email)->first();

            if ($existing !== null) {
                DB::table('users')->where('id', $existing->id)->update([
                    'name' => $name,
                    'phone' => '0000000000',
                    'password' => password_hash($password, PASSWORD_BCRYPT),
                    'role' => $role,
                    'updated_at' => now(),
                ]);

                return (int) $existing->id;
            }

            return (int) DB::table('users')->insertGetId([
                'name' => $name,
                'email' => $email,
                'phone' => '0000000000',
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        $adminId = $upsertUser($emails['admin'], 'Demo administrador', 'ADMIN', $passwords['admin']);
        $buyer1Id = $upsertUser($emails['buyer1'], 'Demo comprador uno', 'USER', $passwords['buyer1']);
        $buyer2Id = $upsertUser($emails['buyer2'], 'Demo comprador dos', 'USER', $passwords['buyer2']);

        $createRaffle = static function (
            string $title,
            string $status,
            Carbon $startsAt,
            Carbon $endsAt,
            int $maxNumber,
            int $adminId,
        ) use ($format): array {
            $raffleId = (int) DB::table('raffles')->insertGetId([
                'title' => '[DEMO] '.$title,
                'description' => 'Datos de demostración para pruebas manuales. No usar en producción.',
                'price_per_number' => 100000,
                'min_number' => 1,
                'max_number' => $maxNumber,
                'status' => $status,
                'starts_at' => $format($startsAt),
                'ends_at' => $format($endsAt),
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $numbers = [];

            for ($number = 1; $number <= $maxNumber; $number++) {
                $numbers[$number] = (int) DB::table('raffle_numbers')->insertGetId([
                    'raffle_id' => $raffleId,
                    'number' => $number,
                    'status' => 'AVAILABLE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return ['id' => $raffleId, 'numbers' => $numbers];
        };

        $open = $createRaffle('Rifa disponible con números libres', 'PUBLISHED', $now->copy()->subDays(2), $now->copy()->addDays(7), 30, $adminId);
        $upcoming = $createRaffle('Rifa próximamente', 'PUBLISHED', $now->copy()->addDays(2), $now->copy()->addDays(9), 10, $adminId);
        $ended = $createRaffle('Rifa con ventana de venta terminada', 'PUBLISHED', $now->copy()->subDays(10), $now->copy()->subDay(), 10, $adminId);
        $closed = $createRaffle('Rifa cerrada con ventas para publicar resultado', 'CLOSED', $now->copy()->subDays(20), $now->copy()->subDays(5), 10, $adminId);
        $finished = $createRaffle('Rifa finalizada con resultado y evidencia', 'FINISHED', $now->copy()->subDays(30), $now->copy()->subDays(15), 10, $adminId);

        $createOrder = static function (
            int $userId,
            array $raffle,
            string $orderNumber,
            string $status,
            array $numbers,
            Carbon $expiresAt,
            ?Carbon $paidAt,
            ?string $paymentStatus,
            string $paymentExternalId,
        ) use ($format): int {
            $orderId = (int) DB::table('orders')->insertGetId([
                'user_id' => $userId,
                'raffle_id' => $raffle['id'],
                'order_number' => $orderNumber,
                'total_amount' => 100000 * count($numbers),
                'status' => $status,
                'expires_at' => $format($expiresAt),
                'paid_at' => $paidAt === null ? null : $format($paidAt),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($numbers as $number) {
                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'raffle_number_id' => $raffle['numbers'][$number],
                    'price_at_purchase' => 100000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($status === 'PAID') {
                    DB::table('raffle_numbers')->where('id', $raffle['numbers'][$number])->update([
                        'status' => 'SOLD',
                        'sold_at' => now(),
                        'order_id' => $orderId,
                        'updated_at' => now(),
                    ]);
                } elseif (in_array($status, ['PENDING_PAYMENT', 'PAYMENT_REVIEW'], true)) {
                    DB::table('raffle_numbers')->where('id', $raffle['numbers'][$number])->update([
                        'status' => 'RESERVED',
                        'reserved_at' => now(),
                        'reserved_until' => $format($expiresAt),
                        'order_id' => $orderId,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('raffle_numbers')->where('id', $raffle['numbers'][$number])->update([
                        'status' => 'AVAILABLE',
                        'reserved_at' => null,
                        'reserved_until' => null,
                        'sold_at' => null,
                        'order_id' => null,
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($paymentStatus !== null) {
                DB::table('payments')->insert([
                    'order_id' => $orderId,
                    'provider' => 'MERCADOPAGO',
                    'external_id' => $paymentExternalId,
                    'status' => $paymentStatus,
                    'amount' => 100000 * count($numbers),
                    'currency' => 'COP',
                    'checkout_url' => null,
                    'verified_at' => now(),
                    'response_data' => json_encode(['simulated' => true, 'demo' => true], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $orderId;
        };

        $paidClosedOrder = $createOrder(
            $buyer1Id,
            $closed,
            'DEMO-PAID-CLOSED',
            'PAID',
            [1, 2, 3],
            $now->copy()->subDays(6),
            $now->copy()->subDays(6),
            'APPROVED',
            'DEMO-PAID-CLOSED-PAYMENT',
        );

        $paidFinishedOrder = $createOrder(
            $buyer1Id,
            $finished,
            'DEMO-PAID-FINISHED',
            'PAID',
            [4, 5],
            $now->copy()->subDays(16),
            $now->copy()->subDays(16),
            'APPROVED',
            'DEMO-PAID-FINISHED-PAYMENT',
        );

        $pendingB1 = $createOrder(
            $buyer1Id,
            $open,
            'DEMO-PENDING-B1',
            'PENDING_PAYMENT',
            [29, 30],
            $now->copy()->addMinutes(60),
            null,
            null,
            '',
        );

        $pendingB2 = $createOrder(
            $buyer2Id,
            $open,
            'DEMO-PENDING-B2',
            'PENDING_PAYMENT',
            [24],
            $now->copy()->addMinutes(60),
            null,
            null,
            '',
        );

        $expiredB1 = $createOrder(
            $buyer1Id,
            $open,
            'DEMO-EXPIRED-B1',
            'EXPIRED',
            [28],
            $now->copy()->subMinutes(30),
            null,
            null,
            '',
        );

        $rejectedB1 = $createOrder(
            $buyer1Id,
            $open,
            'DEMO-REJECTED-B1',
            'REJECTED',
            [27],
            $now->copy()->subMinutes(30),
            null,
            'REJECTED',
            'DEMO-REJECTED-B1-PAYMENT',
        );

        $reviewB1 = $createOrder(
            $buyer1Id,
            $open,
            'DEMO-REVIEW-B1',
            'PAYMENT_REVIEW',
            [25, 26],
            $now->copy()->subMinutes(10),
            null,
            'UNKNOWN',
            'DEMO-REVIEW-B1-PAYMENT',
        );

        DB::table('raffle_results')->insert([
            'raffle_id' => $finished['id'],
            'winning_number' => 4,
            'winning_raffle_number_id' => $finished['numbers'][4],
            'published_by' => $adminId,
            'published_at' => $format($now->copy()->subDay()),
            'evidence_description' => 'Acta de demostración: resultado de prueba, no oficial.',
            'evidence_url' => 'https://example.invalid/acta-demo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'admin_id' => $adminId,
            'buyer1_id' => $buyer1Id,
            'buyer2_id' => $buyer2Id,
            'raffles' => [
                'open' => $open,
                'upcoming' => $upcoming,
                'ended' => $ended,
                'closed' => $closed,
                'finished' => $finished,
            ],
            'orders' => [
                'paid_closed' => $paidClosedOrder,
                'paid_finished' => $paidFinishedOrder,
                'pending_b1' => $pendingB1,
                'pending_b2' => $pendingB2,
                'expired_b1' => $expiredB1,
                'rejected_b1' => $rejectedB1,
                'review_b1' => $reviewB1,
            ],
        ];
    }, 3);

    echo json_encode([
        'seeded' => true,
        'database' => $database,
        'users' => [
            'admin' => $emails['admin'],
            'buyer1' => $emails['buyer1'],
            'buyer2' => $emails['buyer2'],
        ],
        'ids' => [
            'admin' => $summary['admin_id'],
            'buyer1' => $summary['buyer1_id'],
            'buyer2' => $summary['buyer2_id'],
            'raffles' => [
                'open' => $summary['raffles']['open']['id'],
                'upcoming' => $summary['raffles']['upcoming']['id'],
                'ended' => $summary['raffles']['ended']['id'],
                'closed' => $summary['raffles']['closed']['id'],
                'finished' => $summary['raffles']['finished']['id'],
            ],
            'orders' => $summary['orders'],
        ],
        'counts' => [
            'published_raffles' => DB::table('raffles')->where('status', 'PUBLISHED')->count(),
            'paid_orders' => DB::table('orders')->where('status', 'PAID')->count(),
            'sold_numbers' => DB::table('raffle_numbers')->where('status', 'SOLD')->count(),
            'pending_incidents' => DB::table('payments')
                ->whereIn('status', ['UNKNOWN', 'REFUND_REQUIRED'])
                ->count(),
        ],
    ], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Seed failed: '.$exception::class.' - '.$exception->getMessage()."\n");
    exit(1);
}
