<?php

declare(strict_types=1);

use RifaCloud\RafflesService\Config;
use RifaCloud\RafflesService\Database;
use RifaCloud\RafflesService\Draw\RaffleDrawRepository;
use RifaCloud\RafflesService\Draw\RaffleDrawService;

require __DIR__.'/../src/Config.php';
require __DIR__.'/../src/Database.php';
require __DIR__.'/../src/Draw/PublicWinnerName.php';
require __DIR__.'/../src/Draw/RaffleDrawRepository.php';
require __DIR__.'/../src/Draw/RaffleDrawService.php';

/**
 * S12 focused integration test for the automatic draw.
 *
 * Refuses to run unless RAFFLES_TEST_DB_DATABASE is rifacloud_test. It creates
 * disposable users/raffles/orders/payments, exercises the real draw service and
 * removes every fixture in finally. The main rifacloud schema is never touched.
 */

$database = Config::database();
$database['database'] = (string) (getenv('RAFFLES_TEST_DB_DATABASE') ?: '');

if ($database['database'] !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: RAFFLES_TEST_DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

$servicePdo = Database::connect($database, readOnly: false);

$fixtureDatabase = $database;
$fixtureDatabase['username'] = (string) (getenv('RAFFLES_FIXTURE_DB_USERNAME') ?: $database['username']);
$fixtureDatabase['password'] = (string) (getenv('RAFFLES_FIXTURE_DB_PASSWORD') ?: $database['password']);
$fixturePdo = Database::connect($fixtureDatabase, readOnly: false);
$prefix = 's12-draw-'.bin2hex(random_bytes(4));
$failures = 0;
$checks = 0;
$createdRaffles = [];
$createdUsers = [];

$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;

    if (! $condition) {
        $failures++;
        fwrite(STDERR, "FAIL {$message}\n");
    } else {
        echo "PASS {$message}\n";
    }
};

$now = time();
$endsAt = gmdate('Y-m-d H:i:s', $now - 3600);
$drawAt = gmdate('Y-m-d H:i:s', $now - 1800);
$past = gmdate('Y-m-d H:i:s', $now - 7200);

try {
    $insertUser = static function (string $suffix) use ($fixturePdo, $prefix, $now): int {
        return (int) $fixturePdo->query(
            "INSERT INTO users (name, email, phone, password, role, created_at, updated_at)
             VALUES ('S12 Draw Buyer', '{$prefix}-{$suffix}@example.invalid', '0000000000', 'x', 'USER', NOW(), NOW())",
        )->rowCount() === 1
            ? (int) $fixturePdo->lastInsertId()
            : throw new RuntimeException('Could not insert test user.');
    };

    $adminId = $insertUser('admin');
    $createdUsers[] = $adminId;
    $buyerId = $insertUser('buyer');
    $createdUsers[] = $buyerId;

    $seed = static function (
        string $suffix,
        int $soldCount,
        ?string $paymentStatus,
        bool $reserved,
        string $status = 'PUBLISHED',
    ) use ($fixturePdo, $adminId, $buyerId, $prefix, $endsAt, $drawAt, $past): int {
        $title = $prefix.'-'.$suffix;

        $raffle = $fixturePdo->prepare(
            "INSERT INTO raffles
                (title, description, price_per_number, min_number, max_number, status,
                 draw_mode, starts_at, ends_at, draw_at, created_by, created_at, updated_at)
             VALUES
                (:title, 'S12 fixture', 1000, 1, 5, :status,
                 'AUTOMATIC', :starts_at, :ends_at, :draw_at, :created_by, NOW(), NOW())",
        );
        $raffle->execute([
            ':title' => $title,
            ':status' => $status,
            ':starts_at' => $past,
            ':ends_at' => $endsAt,
            ':draw_at' => $drawAt,
            ':created_by' => $adminId,
        ]);
        $raffleId = (int) $fixturePdo->lastInsertId();

        $numberIds = [];

        for ($number = 1; $number <= 5; $number++) {
            $statement = $fixturePdo->prepare(
                "INSERT INTO raffle_numbers (raffle_id, number, status, created_at, updated_at)
                 VALUES (:raffle_id, :number, 'AVAILABLE', NOW(), NOW())",
            );
            $statement->execute([':raffle_id' => $raffleId, ':number' => $number]);
            $numberIds[$number] = (int) $fixturePdo->lastInsertId();
        }

        if ($soldCount > 0) {
            $total = 1000 * $soldCount;
            $order = $fixturePdo->prepare(
                "INSERT INTO orders
                    (user_id, raffle_id, order_number, total_amount, status, expires_at, paid_at, created_at, updated_at)
                 VALUES
                    (:user_id, :raffle_id, :order_number, :total_amount, 'PAID', :expires_at, NOW(), NOW(), NOW())",
            );
            $order->execute([
                ':user_id' => $buyerId,
                ':raffle_id' => $raffleId,
                ':order_number' => $prefix.'-'.$suffix.'-ORDER',
                ':total_amount' => $total,
                ':expires_at' => $past,
            ]);
            $orderId = (int) $fixturePdo->lastInsertId();

            $payment = $fixturePdo->prepare(
                "INSERT INTO payments
                    (order_id, provider, external_id, status, amount, currency, verified_at, created_at, updated_at)
                 VALUES
                    (:order_id, 'MERCADOPAGO', :external_id, :status, :amount, 'COP', NOW(), NOW(), NOW())",
            );
            $payment->execute([
                ':order_id' => $orderId,
                ':external_id' => $prefix.'-'.$suffix.'-PAYMENT',
                ':status' => $paymentStatus ?? 'APPROVED',
                ':amount' => $total,
            ]);

            for ($number = 1; $number <= $soldCount; $number++) {
                $fixturePdo->prepare(
                    'INSERT INTO order_items (order_id, raffle_number_id, price_at_purchase, created_at, updated_at)
                     VALUES (:order_id, :raffle_number_id, 1000, NOW(), NOW())',
                )->execute([
                    ':order_id' => $orderId,
                    ':raffle_number_id' => $numberIds[$number],
                ]);

                $fixturePdo->prepare(
                    "UPDATE raffle_numbers
                     SET status='SOLD', sold_at=NOW(), order_id=:order_id, updated_at=NOW()
                     WHERE id=:id",
                )->execute([':order_id' => $orderId, ':id' => $numberIds[$number]]);
            }
        }

        if ($reserved) {
            $fixturePdo->prepare(
                "UPDATE raffle_numbers SET status='RESERVED', reserved_at=NOW(), reserved_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), updated_at=NOW()
                 WHERE id=:id",
            )->execute([':id' => $numberIds[5]]);
        }

        return $raffleId;
    };

    $validId = $seed('valid', 3, 'APPROVED', false);
    $createdRaffles[] = $validId;
    $zeroId = $seed('zero', 0, null, false);
    $createdRaffles[] = $zeroId;
    $pendingId = $seed('pending', 1, 'PENDING', false);
    $createdRaffles[] = $pendingId;
    $unknownId = $seed('unknown', 1, 'UNKNOWN', false);
    $createdRaffles[] = $unknownId;
    $reservedId = $seed('reserved', 0, null, true);
    $createdRaffles[] = $reservedId;

    $service = new RaffleDrawService(
        $servicePdo,
        new RaffleDrawRepository($servicePdo),
        30,
        20,
    );

    $first = $service->processDue();
    $second = $service->processDue();

    $check($first['drawn'] === 1, 'exactly one valid raffle is drawn');
    $check($first['blocked'] >= 3, 'blocked raffles do not publish a result');
    $check($second['drawn'] === 0, 'a second run does not draw again');

    $result = $fixturePdo->prepare(
        'SELECT winning_number, public_winner_name, published_source, candidate_count,
                candidate_hash, selected_index, random_method, draw_version, winner_order_id
         FROM raffle_results WHERE raffle_id = :id',
    );
    $result->execute([':id' => $validId]);
    $row = $result->fetch();

    $check($row !== false, 'valid raffle has a published result');
    $check($row !== false && in_array((int) $row['winning_number'], [1, 2, 3], true), 'winner is one of the three SOLD numbers');
    $check($row !== false && $row['published_source'] === 'AUTOMATIC', 'result source is AUTOMATIC');
    $check($row !== false && (int) $row['candidate_count'] === 3, 'candidate snapshot contains only three SOLD numbers');
    $check($row !== false && is_string($row['public_winner_name']) && $row['public_winner_name'] !== '', 'public winner name is masked and present');
    $check($row !== false && strlen((string) $row['candidate_hash']) === 64, 'candidate hash is recorded');
    $check($row !== false && (int) $row['selected_index'] >= 0 && (int) $row['selected_index'] <= 2, 'selected index is within the candidate list');
    $check($row !== false && $row['random_method'] === 'php_random_int', 'CSPRNG method is recorded');
    $check($row !== false && $row['draw_version'] === 's12-v1', 'draw version is recorded');

    $check((int) $fixturePdo->query("SELECT COUNT(*) FROM raffle_results WHERE raffle_id = {$validId}")->fetchColumn() === 1, 'valid raffle has exactly one result');

    $status = $fixturePdo->query("SELECT status FROM raffles WHERE id = {$validId}")->fetchColumn();
    $check($status === 'FINISHED', 'valid raffle is FINISHED');

    $blockedReasons = [];

    foreach ([$zeroId, $pendingId, $unknownId, $reservedId] as $id) {
        $statement = $fixturePdo->prepare('SELECT status, draw_blocked_reason FROM raffles WHERE id = :id');
        $statement->execute([':id' => $id]);
        $blocked = $statement->fetch();
        $blockedReasons[$id] = $blocked['draw_blocked_reason'] ?? null;
        $check($blocked !== false && $blocked['status'] === 'CLOSED', "raffle {$id} remains CLOSED when blocked");
        $check((int) $fixturePdo->query("SELECT COUNT(*) FROM raffle_results WHERE raffle_id = {$id}")->fetchColumn() === 0, "raffle {$id} has no result");
    }

    $check($blockedReasons[$zeroId] === 'NO_SOLD_NUMBERS', 'zero-sold raffle is blocked with NO_SOLD_NUMBERS');
    $check($blockedReasons[$pendingId] === 'UNCERTAIN_PAYMENTS', 'PENDING payment blocks the draw');
    $check($blockedReasons[$unknownId] === 'UNCERTAIN_PAYMENTS', 'UNKNOWN payment blocks the draw');
    $check($blockedReasons[$reservedId] === 'ACTIVE_RESERVATIONS', 'active reservation blocks the draw');
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, 'S12 draw test failed: '.$exception::class.' - '.$exception->getMessage()."\n");
    fwrite(STDERR, $exception->getTraceAsString()."\n");
} finally {
    try {
        if ($createdRaffles !== []) {
            $ids = implode(',', array_map('intval', $createdRaffles));
            $fixturePdo->exec("DELETE FROM raffle_results WHERE raffle_id IN ({$ids})");
            $fixturePdo->exec("DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE raffle_id IN ({$ids}))");
            $fixturePdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE raffle_id IN ({$ids}))");
            $fixturePdo->exec("DELETE FROM raffle_numbers WHERE raffle_id IN ({$ids})");
            $fixturePdo->exec("DELETE FROM orders WHERE raffle_id IN ({$ids})");
            $fixturePdo->exec("DELETE FROM raffles WHERE id IN ({$ids})");
        }

        if ($createdUsers !== []) {
            $ids = implode(',', array_map('intval', $createdUsers));
            $fixturePdo->exec("DELETE FROM sessions WHERE user_id IN ({$ids})");
            $fixturePdo->exec("DELETE FROM users WHERE id IN ({$ids})");
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cleanup warning: '.$exception->getMessage()."\n");
    }
}

echo "\nS12 draw: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
