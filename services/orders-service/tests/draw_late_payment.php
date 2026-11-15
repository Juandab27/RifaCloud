<?php

declare(strict_types=1);

use RifaCloud\OrdersService\Config;
use RifaCloud\OrdersService\Database;
use RifaCloud\OrdersService\Orders\ForbiddenException;
use RifaCloud\OrdersService\Orders\NotFoundException;
use RifaCloud\OrdersService\Orders\OrderStatus;
use RifaCloud\OrdersService\Orders\PaymentConflictException;
use RifaCloud\OrdersService\Orders\PaymentResultApplier;
use RifaCloud\OrdersService\Orders\PaymentState;
use RifaCloud\OrdersService\Orders\ValidationException;

require __DIR__.'/../src/Config.php';
require __DIR__.'/../src/Database.php';
require __DIR__.'/../src/Orders/OrderStatus.php';
require __DIR__.'/../src/Orders/PaymentState.php';
require __DIR__.'/../src/Orders/ValidationException.php';
require __DIR__.'/../src/Orders/NotFoundException.php';
require __DIR__.'/../src/Orders/ForbiddenException.php';
require __DIR__.'/../src/Orders/PaymentConflictException.php';
require __DIR__.'/../src/Orders/PaymentResultApplier.php';

/**
 * S12 late-payment guard.
 *
 * Creates a raffle whose automatic draw cutoff has passed and an order that is
 * still PENDING_PAYMENT with a future expiry. An APPROVED result must become a
 * refund incident and must never mark the number SOLD.
 */

$database = Config::database();
$database['database'] = (string) (getenv('ORDERS_TEST_DB_DATABASE') ?: '');

if ($database['database'] !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: ORDERS_TEST_DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

$servicePdo = Database::connect($database, readOnly: false);
$fixtureDatabase = $database;
$fixtureDatabase['username'] = (string) (getenv('ORDERS_FIXTURE_DB_USERNAME') ?: $database['username']);
$fixtureDatabase['password'] = (string) (getenv('ORDERS_FIXTURE_DB_PASSWORD') ?: $database['password']);
$fixturePdo = Database::connect($fixtureDatabase, readOnly: false);

$prefix = 's12-late-'.bin2hex(random_bytes(4));
$failures = 0;
$checks = 0;
$raffleId = null;
$orderId = null;
$paymentId = null;
$userId = null;

$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
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
         VALUES ('S12 Late Buyer', '{$prefix}@example.invalid', '0000000000', 'x', 'USER', NOW(), NOW())",
    );
    $userId = (int) $fixturePdo->lastInsertId();

    $fixturePdo->prepare(
        "INSERT INTO raffles
            (title, description, price_per_number, min_number, max_number, status,
             draw_mode, starts_at, ends_at, draw_at, created_by, created_at, updated_at)
         VALUES
            (:title, 'S12 late fixture', 1000, 1, 1, 'CLOSED',
             'AUTOMATIC', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR),
             DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR),
             DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE), :created_by, NOW(), NOW())",
    )->execute([':title' => $prefix, ':created_by' => $userId]);
    $raffleId = (int) $fixturePdo->lastInsertId();

    $fixturePdo->prepare(
        "INSERT INTO orders
            (user_id, raffle_id, order_number, total_amount, status, expires_at, created_at, updated_at)
         VALUES
            (:user_id, :raffle_id, :order_number, 1000, 'PENDING_PAYMENT',
             DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), NOW(), NOW())",
    )->execute([
        ':user_id' => $userId,
        ':raffle_id' => $raffleId,
        ':order_number' => $prefix.'-ORDER',
    ]);
    $orderId = (int) $fixturePdo->lastInsertId();

    $fixturePdo->prepare(
        "INSERT INTO raffle_numbers
            (raffle_id, number, status, reserved_at, reserved_until, order_id, created_at, updated_at)
         VALUES
            (:raffle_id, 1, 'RESERVED', NOW(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), :order_id, NOW(), NOW())",
    )->execute([':raffle_id' => $raffleId, ':order_id' => $orderId]);
    $numberId = (int) $fixturePdo->lastInsertId();

    $fixturePdo->prepare(
        'INSERT INTO order_items (order_id, raffle_number_id, price_at_purchase, created_at, updated_at)
         VALUES (:order_id, :raffle_number_id, 1000, NOW(), NOW())',
    )->execute([':order_id' => $orderId, ':raffle_number_id' => $numberId]);

    $fixturePdo->prepare(
        "INSERT INTO payments
            (order_id, provider, external_id, status, amount, currency, created_at, updated_at)
         VALUES
            (:order_id, 'MERCADOPAGO', :external_id, 'PENDING', 1000, 'COP', NOW(), NOW())",
    )->execute([':order_id' => $orderId, ':external_id' => $prefix.'-PAYMENT']);
    $paymentId = (int) $fixturePdo->lastInsertId();

    $applier = new PaymentResultApplier($servicePdo);
    $result = $applier->apply([
        'order_id' => $orderId,
        'payment_id' => $paymentId,
        'external_id' => $prefix.'-PAYMENT',
        'amount' => 1000,
        'currency' => 'COP',
        'status' => 'APPROVED',
        'source' => 'verify',
        'actor_role' => 'user',
        'raw' => [],
    ]);

    $check($result['order_status'] === OrderStatus::REQUIRES_REFUND->value, 'late approval becomes REQUIRES_REFUND');
    $check($result['payment_status'] === PaymentState::REFUND_REQUIRED->value, 'late approval becomes REFUND_REQUIRED');
    $check(($result['evidence']['after_draw'] ?? false) === true, 'audit evidence records after_draw');

    $numberStatus = $fixturePdo->query("SELECT status FROM raffle_numbers WHERE id = {$numberId}")->fetchColumn();
    $check($numberStatus === 'RESERVED', 'the number is never marked SOLD after the draw cutoff');

    $orderStatus = $fixturePdo->query("SELECT status FROM orders WHERE id = {$orderId}")->fetchColumn();
    $check($orderStatus === 'REQUIRES_REFUND', 'the order preserves the refund incident');

    $paymentStatus = $fixturePdo->query("SELECT status FROM payments WHERE id = {$paymentId}")->fetchColumn();
    $check($paymentStatus === 'PENDING', 'orders-service does not write the payment row; payments-service owns it');
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, 'S12 late-payment test failed: '.$exception::class.' - '.$exception->getMessage()."\n");
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

        if ($userId !== null) {
            $fixturePdo->exec("DELETE FROM sessions WHERE user_id = {$userId}");
            $fixturePdo->exec("DELETE FROM users WHERE id = {$userId}");
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cleanup warning: '.$exception->getMessage()."\n");
    }
}

echo "\nS12 late payment: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
