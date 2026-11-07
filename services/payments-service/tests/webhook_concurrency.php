<?php

declare(strict_types=1);

use RifaCloud\PaymentsService\Config;
use RifaCloud\PaymentsService\Database;

require __DIR__.'/../src/Config.php';
require __DIR__.'/../src/Database.php';

/**
 * Real two-process webhook concurrency test.
 *
 * Creates one reserved order with one PENDING payment, then releases two
 * independent PHP processes that POST the same simulated notification at the
 * same instant. Exactly one payment event row must exist, the number must be
 * SOLD exactly once, and the order must be PAID exactly once.
 */

date_default_timezone_set('UTC');
putenv('APP_URL=https://rifacloud.test');

$database = Config::database();
$database['database'] = (string) (getenv('PAYMENTS_TEST_DB_DATABASE') ?: '');

if ($database['database'] !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: PAYMENTS_TEST_DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

$pdo = Database::connect($database);
$prefix = 's4-conc-'.bin2hex(random_bytes(4));
$startedAt = gmdate('Y-m-d H:i:s');
$failures = 0;
$userIds = [];
$raffleIds = [];
$tempFiles = [];

function check(bool $condition, string $message): void
{
    global $failures;

    if ($condition) {
        echo "PASS  {$message}\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

try {
    $passwordHash = password_hash('s4-conc-password', PASSWORD_BCRYPT);

    $pdo->prepare(
        "INSERT INTO users (name, email, phone, password, role, created_at, updated_at)
         VALUES ('S4 conc', :email, '0000000000', :password, 'USER', NOW(), NOW())",
    )->execute([
        ':email' => $prefix.'@example.invalid',
        ':password' => $passwordHash,
    ]);
    $userId = (int) $pdo->lastInsertId();
    $userIds[] = $userId;

    $pdo->prepare(
        "INSERT INTO users (name, email, phone, password, role, created_at, updated_at)
         VALUES ('S4 conc admin', :email, '0000000000', :password, 'ADMIN', NOW(), NOW())",
    )->execute([
        ':email' => $prefix.'-admin@example.invalid',
        ':password' => $passwordHash,
    ]);
    $adminId = (int) $pdo->lastInsertId();
    $userIds[] = $adminId;

    $pdo->prepare(
        "INSERT INTO raffles
            (title, description, price_per_number, min_number, max_number, status, created_by, created_at, updated_at)
         VALUES (:title, 'Fixture', 1500, 1, 4, 'PUBLISHED', :created_by, NOW(), NOW())",
    )->execute([':title' => $prefix.' raffle', ':created_by' => $adminId]);
    $raffleId = (int) $pdo->lastInsertId();
    $raffleIds[] = $raffleId;

    for ($number = 1; $number <= 4; $number++) {
        $pdo->prepare(
            "INSERT INTO raffle_numbers (raffle_id, number, status, created_at, updated_at)
             VALUES (:raffle_id, :number, 'AVAILABLE', NOW(), NOW())",
        )->execute([':raffle_id' => $raffleId, ':number' => $number]);
    }

    $expiresAt = gmdate('Y-m-d H:i:s', time() + 600);

    $pdo->prepare(
        "INSERT INTO orders
            (user_id, raffle_id, order_number, total_amount, status, expires_at, created_at, updated_at)
         VALUES (:user_id, :raffle_id, :order_number, 3000, 'PENDING_PAYMENT', :expires_at, NOW(), NOW())",
    )->execute([
        ':user_id' => $userId,
        ':raffle_id' => $raffleId,
        ':order_number' => $prefix.'-ORDER',
        ':expires_at' => $expiresAt,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    foreach ([1, 2] as $number) {
        $id = $pdo->prepare('SELECT id FROM raffle_numbers WHERE raffle_id = :raffle_id AND number = :number');
        $id->execute([':raffle_id' => $raffleId, ':number' => $number]);
        $numberId = (int) $id->fetchColumn();

        $pdo->prepare(
            'INSERT INTO order_items (order_id, raffle_number_id, price_at_purchase, created_at, updated_at)
             VALUES (:order_id, :raffle_number_id, 1500, NOW(), NOW())',
        )->execute([':order_id' => $orderId, ':raffle_number_id' => $numberId]);

        $pdo->prepare(
            "UPDATE raffle_numbers
             SET status = 'RESERVED', reserved_at = NOW(), reserved_until = :until, order_id = :order_id
             WHERE id = :id",
        )->execute([
            ':until' => $expiresAt,
            ':order_id' => $orderId,
            ':id' => $numberId,
        ]);
    }

    $externalId = 'SIM-COP-3000-'.strtoupper(bin2hex(random_bytes(10)));

    $pdo->prepare(
        "INSERT INTO payments
            (order_id, provider, external_id, status, amount, currency, checkout_url, response_data, created_at, updated_at)
         VALUES (:order_id, 'MERCADOPAGO', :external_id, 'PENDING', 3000, 'COP', NULL, :response_data, NOW(), NOW())",
    )->execute([
        ':order_id' => $orderId,
        ':external_id' => $externalId,
        ':response_data' => json_encode(['simulated' => true], JSON_THROW_ON_ERROR),
    ]);

    $payload = json_encode([
        'type' => 'payment',
        'data' => ['id' => $externalId],
        'status' => 'APPROVED',
    ], JSON_THROW_ON_ERROR);

    $payloadFile = sys_get_temp_dir().'/s4-webhook-'.bin2hex(random_bytes(6)).'.json';
    $barrierFile = sys_get_temp_dir().'/s4-barrier-'.bin2hex(random_bytes(6)).'.lock';
    file_put_contents($payloadFile, $payload);
    $tempFiles[] = $payloadFile;
    $tempFiles[] = $barrierFile;

    $environment = array_merge(getenv(), [
        'PAYMENTS_TEST_DB_DATABASE' => 'rifacloud_test',
        'PAYMENTS_DB_DATABASE' => 'rifacloud_test',
        'PAYMENTS_TEST_PAYLOAD_FILE' => $payloadFile,
        'PAYMENTS_TEST_BARRIER_FILE' => $barrierFile,
    ]);

    $children = [];

    for ($i = 0; $i < 2; $i++) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/concurrent_webhook.php'],
            $descriptors,
            $pipes,
            null,
            $environment,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the webhook concurrency child process.');
        }

        $children[] = ['process' => $process, 'pipes' => $pipes];
    }

    usleep(400_000);
    file_put_contents($barrierFile, 'go');

    $results = [];

    foreach ($children as $child) {
        $stdout = stream_get_contents($child['pipes'][1]);
        $stderr = stream_get_contents($child['pipes'][2]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['process']);

        $decoded = is_string($stdout) ? json_decode($stdout, true) : null;

        if (! is_array($decoded)) {
            throw new RuntimeException('Webhook child produced invalid output: '.$stdout.' '.$stderr);
        }

        $results[] = $decoded;
    }

    foreach ($results as $result) {
        check((int) ($result['status'] ?? 0) === 200, 'A simultaneous webhook returns 200');
        check(
            in_array((string) ($result['payload']['status'] ?? ''), ['processed', 'ignored'], true),
            'A simultaneous webhook reports a final status',
        );
    }

    check(
        (int) $pdo->query('SELECT COUNT(*) FROM payment_webhook_events WHERE created_at >= '.$pdo->quote($startedAt))->fetchColumn() === 1,
        'Two simultaneous webhooks create exactly one event row',
    );
    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $orderId)->fetchColumn() === 'PAID',
        'Two simultaneous webhooks leave the order PAID',
    );
    check(
        (int) $pdo->query("SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = {$raffleId} AND status = 'SOLD'")->fetchColumn() === 2,
        'Two simultaneous webhooks sell the numbers exactly once',
    );
    check(
        (string) $pdo->query('SELECT status FROM payments WHERE order_id = '.(int) $orderId)->fetchColumn() === 'APPROVED',
        'Two simultaneous webhooks leave the payment APPROVED',
    );
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE raffle_id = '.(int) $raffleId)->fetchColumn() === 1,
        'No second order was created by the concurrent webhooks',
    );
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, 'ERROR: '.$exception::class.' - '.$exception->getMessage()."\n");
} finally {
    foreach ($tempFiles as $file) {
        @unlink($file);
    }

    if ($raffleIds !== []) {
        $ids = implode(',', array_map('intval', $raffleIds));
        $pdo->exec('DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE raffle_id IN ('.$ids.'))');
        $pdo->exec('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE raffle_id IN ('.$ids.'))');
        $pdo->exec('UPDATE raffle_numbers SET order_id = NULL WHERE raffle_id IN ('.$ids.')');
        $pdo->exec('DELETE FROM orders WHERE raffle_id IN ('.$ids.')');
        $pdo->exec('DELETE FROM raffle_numbers WHERE raffle_id IN ('.$ids.')');
        $pdo->exec('DELETE FROM raffles WHERE id IN ('.$ids.')');
    }

    $pdo->exec('DELETE FROM payment_webhook_events WHERE created_at >= '.$pdo->quote($startedAt));

    if ($userIds !== []) {
        $ids = implode(',', array_map('intval', $userIds));
        $pdo->exec('DELETE FROM users WHERE id IN ('.$ids.')');
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll webhook concurrency checks passed.\n";
