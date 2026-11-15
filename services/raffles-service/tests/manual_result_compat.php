<?php

declare(strict_types=1);

use RifaCloud\RafflesService\Admin\AdminSession;
use RifaCloud\RafflesService\Admin\NotFoundException;
use RifaCloud\RafflesService\Admin\RaffleAdminRepository;
use RifaCloud\RafflesService\Admin\RaffleAdminService;
use RifaCloud\RafflesService\Admin\RaffleValidator;
use RifaCloud\RafflesService\Admin\ValidationException;
use RifaCloud\RafflesService\Config;
use RifaCloud\RafflesService\Database;

require __DIR__.'/../src/Config.php';
require __DIR__.'/../src/Database.php';
require __DIR__.'/../src/RafflePresenter.php';
require __DIR__.'/../src/Draw/PublicWinnerName.php';
require __DIR__.'/../src/Admin/AdminSession.php';
require __DIR__.'/../src/Admin/AdminAuthException.php';
require __DIR__.'/../src/Admin/CsrfTokenMismatchException.php';
require __DIR__.'/../src/Admin/NotFoundException.php';
require __DIR__.'/../src/Admin/ValidationException.php';
require __DIR__.'/../src/Admin/RaffleValidator.php';
require __DIR__.'/../src/Admin/RaffleAdminRepository.php';
require __DIR__.'/../src/Admin/RaffleAdminService.php';

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

$prefix = 's12-manual-'.bin2hex(random_bytes(4));
$checks = 0;
$failures = 0;
$raffleId = null;
$adminId = null;
$buyerId = null;

$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (! $condition) {
        $failures++;
        fwrite(STDERR, "FAIL {$message}\n");
    } else {
        echo "PASS {$message}\n";
    }
};

try {
    $fixturePdo->exec(
        "INSERT INTO users (name, email, phone, password, role, created_at, updated_at)
         VALUES ('S12 Admin', '{$prefix}-admin@example.invalid', '0000000000', 'x', 'ADMIN', NOW(), NOW())",
    );
    $adminId = (int) $fixturePdo->lastInsertId();
    $fixturePdo->exec(
        "INSERT INTO users (name, email, phone, password, role, created_at, updated_at)
         VALUES ('S12 Buyer', '{$prefix}-buyer@example.invalid', '0000000000', 'x', 'USER', NOW(), NOW())",
    );
    $buyerId = (int) $fixturePdo->lastInsertId();

    $fixturePdo->prepare(
        "INSERT INTO raffles
            (title, description, price_per_number, min_number, max_number, status,
             draw_mode, starts_at, ends_at, created_by, created_at, updated_at)
         VALUES
            (:title, 'manual result fixture', 1000, 1, 3, 'CLOSED', 'MANUAL',
             DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR),
             :created_by, NOW(), NOW())",
    )->execute([':title' => $prefix, ':created_by' => $adminId]);
    $raffleId = (int) $fixturePdo->lastInsertId();

    $fixturePdo->prepare(
        "INSERT INTO raffle_numbers (raffle_id, number, status, created_at, updated_at)
         VALUES (:raffle_id, 1, 'AVAILABLE', NOW(), NOW())",
    )->execute([':raffle_id' => $raffleId]);
    $numberId = (int) $fixturePdo->lastInsertId();

    $fixturePdo->prepare(
        "INSERT INTO orders
            (user_id, raffle_id, order_number, total_amount, status, expires_at, paid_at, created_at, updated_at)
         VALUES (:user_id, :raffle_id, :order_number, 1000, 'PAID', NULL, NOW(), NOW(), NOW())",
    )->execute([':user_id' => $buyerId, ':raffle_id' => $raffleId, ':order_number' => $prefix.'-ORDER']);
    $orderId = (int) $fixturePdo->lastInsertId();

    $fixturePdo->prepare(
        'INSERT INTO order_items (order_id, raffle_number_id, price_at_purchase, created_at, updated_at)
         VALUES (:order_id, :raffle_number_id, 1000, NOW(), NOW())',
    )->execute([':order_id' => $orderId, ':raffle_number_id' => $numberId]);

    $fixturePdo->prepare(
        "INSERT INTO payments
            (order_id, provider, external_id, status, amount, currency, verified_at, created_at, updated_at)
         VALUES (:order_id, 'MERCADOPAGO', :external_id, 'APPROVED', 1000, 'COP', NOW(), NOW(), NOW())",
    )->execute([':order_id' => $orderId, ':external_id' => $prefix.'-PAYMENT']);

    $fixturePdo->prepare(
        "UPDATE raffle_numbers SET status='SOLD', sold_at=NOW(), order_id=:order_id WHERE id=:id",
    )->execute([':order_id' => $orderId, ':id' => $numberId]);

    $repository = new RaffleAdminRepository($servicePdo, 10, 50);
    $service = new RaffleAdminService($servicePdo, $repository, new RaffleValidator(10000, 30));
    $session = new AdminSession($adminId, 'S12 Admin', $prefix.'-admin@example.invalid', 'csrf');

    $result = $service->publishResult($raffleId, [
        'winning_number' => 1,
        'evidence_description' => 'Manual result compatibility check',
    ], $session);

    $check(($result['status'] ?? '') === 'FINISHED', 'manual publication finishes the raffle');
    $check(($result['result']['source'] ?? '') === 'MANUAL', 'manual result keeps MANUAL source');
    $check(($result['result']['public_winner_name'] ?? '') === 'S12 B.', 'manual result stores a masked public name');
    $check(($result['result']['winning_number'] ?? 0) === 1, 'manual result keeps the winning number');

    $row = $fixturePdo->query(
        "SELECT published_by, published_source, public_winner_name, winner_order_id, winner_user_id
         FROM raffle_results WHERE raffle_id = {$raffleId}",
    )->fetch();
    $check((int) $row['published_by'] === $adminId, 'manual result keeps the admin publisher');
    $check($row['published_source'] === 'MANUAL', 'manual result source is persisted');
    $check((int) $row['winner_order_id'] === $orderId && (int) $row['winner_user_id'] === $buyerId, 'manual result snapshots the owner references');
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, 'Manual result test failed: '.$exception::class.' - '.$exception->getMessage()."\n");
    fwrite(STDERR, $exception->getTraceAsString()."\n");
} finally {
    try {
        if ($raffleId !== null) {
            $fixturePdo->exec("DELETE FROM raffle_results WHERE raffle_id = {$raffleId}");
            $fixturePdo->exec("DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE raffle_id = {$raffleId})");
            $fixturePdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE raffle_id = {$raffleId})");
            $fixturePdo->exec("DELETE FROM raffle_numbers WHERE raffle_id = {$raffleId}");
            $fixturePdo->exec("DELETE FROM orders WHERE raffle_id = {$raffleId}");
            $fixturePdo->exec("DELETE FROM raffles WHERE id = {$raffleId}");
        }
        if ($adminId !== null) {
            $fixturePdo->exec("DELETE FROM users WHERE id IN ({$adminId}, {$buyerId})");
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cleanup warning: '.$exception->getMessage()."\n");
    }
}

echo "\nS12 manual result: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
