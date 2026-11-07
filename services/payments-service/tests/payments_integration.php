<?php

declare(strict_types=1);

use RifaCloud\PaymentsService\Auth\LaravelCrypto;
use RifaCloud\PaymentsService\Auth\UserSessionAuthenticator;
use RifaCloud\PaymentsService\Config;
use RifaCloud\PaymentsService\Database;
use RifaCloud\PaymentsService\Http\StreamHttpClient;
use RifaCloud\PaymentsService\Payments\CheckoutService;
use RifaCloud\PaymentsService\Payments\FakePaymentGateway;
use RifaCloud\PaymentsService\Payments\InternalRequestVerifier;
use RifaCloud\PaymentsService\Payments\OrderProjection;
use RifaCloud\PaymentsService\Payments\OrdersClient;
use RifaCloud\PaymentsService\Payments\PaymentApplication;
use RifaCloud\PaymentsService\Payments\PaymentRepository;
use RifaCloud\PaymentsService\Payments\VerificationService;
use RifaCloud\PaymentsService\Payments\WebhookService;

require __DIR__.'/../src/Config.php';
require __DIR__.'/../src/Database.php';
require __DIR__.'/../src/JsonResponse.php';
require __DIR__.'/../src/Http/HttpClient.php';
require __DIR__.'/../src/Http/HttpTransportException.php';
require __DIR__.'/../src/Http/StreamHttpClient.php';
require __DIR__.'/../src/Auth/AuthException.php';
require __DIR__.'/../src/Auth/CsrfTokenMismatchException.php';
require __DIR__.'/../src/Auth/LaravelCrypto.php';
require __DIR__.'/../src/Auth/SessionPayload.php';
require __DIR__.'/../src/Auth/UserSessionContext.php';
require __DIR__.'/../src/Auth/UserSessionAuthenticator.php';
require __DIR__.'/../src/Payments/PaymentState.php';
require __DIR__.'/../src/Payments/PaymentProvider.php';
require __DIR__.'/../src/Payments/WebhookStatus.php';
require __DIR__.'/../src/Payments/CheckoutSession.php';
require __DIR__.'/../src/Payments/PaymentStatus.php';
require __DIR__.'/../src/Payments/WebhookEvent.php';
require __DIR__.'/../src/Payments/PaymentConflictException.php';
require __DIR__.'/../src/Payments/PaymentGatewayException.php';
require __DIR__.'/../src/Payments/PaymentGatewayTimeoutException.php';
require __DIR__.'/../src/Payments/PaymentNotFoundException.php';
require __DIR__.'/../src/Payments/InvalidWebhookSignatureException.php';
require __DIR__.'/../src/Payments/InvalidWebhookPayloadException.php';
require __DIR__.'/../src/Payments/NotFoundException.php';
require __DIR__.'/../src/Payments/MalformedJsonException.php';
require __DIR__.'/../src/Payments/ServiceUnavailableException.php';
require __DIR__.'/../src/Payments/OrdersUnavailableException.php';
require __DIR__.'/../src/Payments/PaymentGateway.php';
require __DIR__.'/../src/Payments/FakePaymentGateway.php';
require __DIR__.'/../src/Payments/OrderProjection.php';
require __DIR__.'/../src/Payments/PaymentRepository.php';
require __DIR__.'/../src/Payments/OrdersClient.php';
require __DIR__.'/../src/Payments/CheckoutService.php';
require __DIR__.'/../src/Payments/VerificationService.php';
require __DIR__.'/../src/Payments/WebhookService.php';
require __DIR__.'/../src/Payments/InternalRequestVerifier.php';
require __DIR__.'/../src/Payments/PaymentApplication.php';

/**
 * Non-destructive S4 integration test.
 *
 * It runs the real payments-service code against `rifacloud_test` and calls
 * the real orders-service test container over HTTP for every order/number
 * transition, so the internal command contract is exercised end to end.
 *
 * It refuses to run unless PAYMENTS_TEST_DB_DATABASE is exactly
 * `rifacloud_test`.
 */

putenv('APP_URL=https://rifacloud.test');
date_default_timezone_set('UTC');

$database = Config::database();
$database['database'] = (string) (getenv('PAYMENTS_TEST_DB_DATABASE') ?: '');

if ($database['database'] !== 'rifacloud_test') {
    error_log("Refusing to run: PAYMENTS_TEST_DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

$appKey = Config::appKey();
$ordersUrl = Config::ordersServiceUrl();

if ($appKey === '') {
    error_log("Refusing to run: APP_KEY is empty.\n");
    exit(2);
}

if ($ordersUrl === '') {
    error_log("Refusing to run: ORDERS_SERVICE_URL is empty.\n");
    exit(2);
}

$key = LaravelCrypto::key($appKey);
$pdo = Database::connect($database);
$fixtureUsername = getenv('TEST_FIXTURE_DB_USERNAME') ?: 'rifacloud_test_fixture';
$fixturePassword = getenv('TEST_FIXTURE_DB_PASSWORD');
if (! is_string($fixturePassword) || $fixturePassword === '') {
    error_log("Refusing to run: TEST_FIXTURE_DB_PASSWORD is required (dedicated test account).\n");
    exit(2);
}
$fixtureConfig = $database;
$fixtureConfig['username'] = $fixtureUsername;
$fixtureConfig['password'] = $fixturePassword;
$fixturePdo = Database::connect($fixtureConfig);
$prefix = 's4-test-'.bin2hex(random_bytes(4));
$startedAt = gmdate('Y-m-d H:i:s');
$failures = 0;
$cookieName = 'rifacloud-session';
$sessionLifetime = 120;

$createdUserIds = [];
$createdRaffleIds = [];
$createdSessionIds = [];

function laravelEncrypt(string $value, string $key): string
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

function sessionCookie(string $sessionId, string $cookieName, string $key): string
{
    return laravelEncrypt(
        LaravelCrypto::cookiePrefix($cookieName, $key).$sessionId,
        $key,
    );
}

function xsrfCookie(string $token, string $key): string
{
    return laravelEncrypt(
        LaravelCrypto::cookiePrefix('XSRF-TOKEN', $key).$token,
        $key,
    );
}

function check(bool $condition, string $message): void
{
    global $failures;

    if ($condition) {
        echo "PASS  {$message}\n";
    } else {
        $failures++;
        error_log("FAIL: {$message}\n");
    }
}

/**
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $cookies
 * @return array{status: int, payload: array<string, mixed>}
 */
function call(
    PaymentApplication $app,
    string $method,
    string $path,
    array $body = [],
    array $cookies = [],
    array $headers = [],
): array {
    $raw = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);
    $response = $app->handle($method, $path, [], $headers, $cookies, $raw, 'http://localhost');

    return ['status' => $response->status, 'payload' => $response->payload];
}

/**
 * @param  array<string, string>  $headers
 * @return array{status: int, payload: array<string, mixed>}
 */
function callRaw(
    PaymentApplication $app,
    string $method,
    string $path,
    string $rawBody,
    array $headers = [],
): array {
    $response = $app->handle($method, $path, [], $headers, [], $rawBody, 'http://localhost');

    return ['status' => $response->status, 'payload' => $response->payload];
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $cookies
 * @return array{status: int, payload: array<string, mixed>}
 */
function postJson(
    PaymentApplication $app,
    string $path,
    array $body,
    array $cookies,
    array $headers,
): array {
    return call($app, 'POST', $path, $body, $cookies, $headers);
}

function signedInternalHeaders(string $body, string $secret, ?int $timestamp = null): array
{
    $timestamp = (string) ($timestamp ?? time());

    return [
        'content-type' => 'application/json',
        'x-rifacloud-timestamp' => $timestamp,
        'x-rifacloud-signature' => hash_hmac(
            'sha256',
            $timestamp."\n".hash('sha256', $body),
            $secret,
        ),
    ];
}

/**
 * @param  array<string, mixed>  $context
 */
function makeApp(
    PDO $pdo,
    string $appKey,
    string $cookieName,
    int $sessionLifetime,
    string $ordersUrl,
    string $outcome,
    string $driver = 'fake',
): PaymentApplication {
    $http = new StreamHttpClient();
    $gateway = new FakePaymentGateway($outcome, 'https://rifacloud.test');
    $repository = new PaymentRepository($pdo);
    $projection = new OrderProjection($pdo, $driver);
    $orders = new OrdersClient($http, $ordersUrl, Config::ordersInternalSecret(), 10);

    return new PaymentApplication(
        $pdo,
        new UserSessionAuthenticator($pdo, $appKey, $cookieName, $sessionLifetime, 'sessions'),
        new CheckoutService($pdo, $repository, $projection, $gateway),
        new VerificationService($repository, $gateway, $orders, true),
        new WebhookService($repository, $gateway, $orders),
        $projection,
        new InternalRequestVerifier(Config::internalSecret(), 60),
        $repository,
        50,
    );
}

try {
    // ---- Fixtures -------------------------------------------------------
    $insertUser = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO users (name, email, phone, password, role, created_at, updated_at)
            VALUES (:name, :email, '0000000000', :password, :role, NOW(), NOW())
        SQL,
    );

    $passwordHash = password_hash('s4-test-password', PASSWORD_BCRYPT);
    $passwordHashWeb = hash_hmac('sha256', $passwordHash, $appKey);
    $ownerCsrf = bin2hex(random_bytes(20));
    $otherCsrf = bin2hex(random_bytes(20));
    $adminCsrf = bin2hex(random_bytes(20));

    $insertUser->execute([
        ':name' => 'S4 owner',
        ':email' => $prefix.'-owner@example.invalid',
        ':password' => $passwordHash,
        ':role' => 'USER',
    ]);
    $ownerId = (int) $fixturePdo->lastInsertId();
    $createdUserIds[] = $ownerId;

    $insertUser->execute([
        ':name' => 'S4 other',
        ':email' => $prefix.'-other@example.invalid',
        ':password' => $passwordHash,
        ':role' => 'USER',
    ]);
    $otherId = (int) $fixturePdo->lastInsertId();
    $createdUserIds[] = $otherId;

    $insertUser->execute([
        ':name' => 'S4 admin',
        ':email' => $prefix.'-admin@example.invalid',
        ':password' => $passwordHash,
        ':role' => 'ADMIN',
    ]);
    $adminId = (int) $fixturePdo->lastInsertId();
    $createdUserIds[] = $adminId;

    $insertSession = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity)
            VALUES (:id, :user_id, '127.0.0.1', 's4-test', :payload, :last_activity)
        SQL,
    );

    $sessionPayload = static fn (string $csrf, int $userId): string => base64_encode(json_encode([
        '_token' => $csrf,
        'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => $userId,
        'password_hash_web' => $passwordHashWeb,
    ], JSON_THROW_ON_ERROR));

    $ownerSessionId = bin2hex(random_bytes(20));
    $otherSessionId = bin2hex(random_bytes(20));
    $adminSessionId = bin2hex(random_bytes(20));
    $expiredSessionId = bin2hex(random_bytes(20));

    foreach ([
        [$ownerSessionId, $ownerId, $ownerCsrf, time()],
        [$otherSessionId, $otherId, $otherCsrf, time()],
        [$adminSessionId, $adminId, $adminCsrf, time()],
        [$expiredSessionId, $ownerId, $ownerCsrf, time() - (($sessionLifetime + 5) * 60)],
    ] as [$sessionId, $userId, $csrf, $lastActivity]) {
        $insertSession->execute([
            ':id' => $sessionId,
            ':user_id' => $userId,
            ':payload' => $sessionPayload($csrf, $userId),
            ':last_activity' => $lastActivity,
        ]);
        $createdSessionIds[] = $sessionId;
    }

    $ownerCookies = [$cookieName => sessionCookie($ownerSessionId, $cookieName, $key)];
    $otherCookies = [$cookieName => sessionCookie($otherSessionId, $cookieName, $key)];
    $adminCookies = [$cookieName => sessionCookie($adminSessionId, $cookieName, $key)];
    $expiredCookies = [$cookieName => sessionCookie($expiredSessionId, $cookieName, $key)];
    $ownerHeaders = [
        'content-type' => 'application/json',
        'x-xsrf-token' => xsrfCookie($ownerCsrf, $key),
        'origin' => 'http://localhost',
    ];
    $otherHeaders = [
        'content-type' => 'application/json',
        'x-xsrf-token' => xsrfCookie($otherCsrf, $key),
        'origin' => 'http://localhost',
    ];
    $adminHeaders = [
        'content-type' => 'application/json',
        'x-xsrf-token' => xsrfCookie($adminCsrf, $key),
        'origin' => 'http://localhost',
    ];

    $insertRaffle = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO raffles
                (title, description, price_per_number, min_number, max_number, status, created_by, created_at, updated_at)
            VALUES (:title, 'Fixture', 1500, :min, :max, 'PUBLISHED', :created_by, NOW(), NOW())
        SQL,
    );
    $insertNumber = $fixturePdo->prepare(
        "INSERT INTO raffle_numbers (raffle_id, number, status, created_at, updated_at)
         VALUES (:raffle_id, :number, 'AVAILABLE', NOW(), NOW())",
    );

    $newRaffle = function (string $label, int $max = 6) use (
        $fixturePdo,
        $insertRaffle,
        $insertNumber,
        $adminId,
        $prefix,
        &$createdRaffleIds,
    ): int {
        $insertRaffle->execute([
            ':title' => $prefix.' '.$label,
            ':min' => 1,
            ':max' => $max,
            ':created_by' => $adminId,
        ]);
        $raffleId = (int) $fixturePdo->lastInsertId();
        $createdRaffleIds[] = $raffleId;

        for ($number = 1; $number <= $max; $number++) {
            $insertNumber->execute([':raffle_id' => $raffleId, ':number' => $number]);
        }

        return $raffleId;
    };

    $insertOrder = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO orders
                (user_id, raffle_id, order_number, total_amount, status, expires_at, paid_at, created_at, updated_at)
            VALUES
                (:user_id, :raffle_id, :order_number, :total_amount, :status, :expires_at, :paid_at, NOW(), NOW())
        SQL,
    );
    $insertItem = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO order_items (order_id, raffle_number_id, price_at_purchase, created_at, updated_at)
            VALUES (:order_id, :raffle_number_id, 1500, NOW(), NOW())
        SQL,
    );

    /**
     * @param  list<int>  $numbers
     */
    $newOrder = function (
        int $userId,
        int $raffleId,
        array $numbers,
        string $status = 'PENDING_PAYMENT',
        ?string $expiresAt = null,
        bool $reserve = true,
    ) use (
        $fixturePdo,
        $insertOrder,
        $insertItem,
        $prefix,
        &$createdRaffleIds,
    ): int {
        $insertOrder->execute([
            ':user_id' => $userId,
            ':raffle_id' => $raffleId,
            ':order_number' => $prefix.'-'.strtoupper(bin2hex(random_bytes(5))),
            ':total_amount' => 1500 * count($numbers),
            ':status' => $status,
            ':expires_at' => $expiresAt ?? gmdate('Y-m-d H:i:s', time() + 600),
            ':paid_at' => $status === 'PAID' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $orderId = (int) $fixturePdo->lastInsertId();

        $placeholders = implode(',', array_fill(0, count($numbers), '?'));
        $ids = $fixturePdo->prepare(
            'SELECT id, number FROM raffle_numbers WHERE raffle_id = ? AND number IN ('.$placeholders.') ORDER BY number',
        );
        $ids->execute([$raffleId, ...$numbers]);

        foreach ($ids->fetchAll() as $row) {
            $insertItem->execute([
                ':order_id' => $orderId,
                ':raffle_number_id' => (int) $row['id'],
            ]);

            if ($reserve) {
                $fixturePdo->prepare(
                    "UPDATE raffle_numbers
                     SET status = 'RESERVED', reserved_at = NOW(), reserved_until = :until, order_id = :order_id
                     WHERE id = :id",
                )->execute([
                    ':until' => $expiresAt ?? gmdate('Y-m-d H:i:s', time() + 600),
                    ':order_id' => $orderId,
                    ':id' => (int) $row['id'],
                ]);
            }
        }

        return $orderId;
    };

    $soldCount = static fn (int $raffleId): int => (int) $pdo->query(
        "SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = {$raffleId} AND status = 'SOLD'",
    )->fetchColumn();
    $reservedCount = static fn (int $raffleId): int => (int) $pdo->query(
        "SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = {$raffleId} AND status = 'RESERVED'",
    )->fetchColumn();
    $paymentRow = static fn (int $orderId): array => $pdo->query(
        'SELECT * FROM payments WHERE order_id = '.(int) $orderId,
    )->fetch();

    $app = makeApp($pdo, $appKey, $cookieName, $sessionLifetime, $ordersUrl, 'APPROVED');

    // ---- Authentication and CSRF --------------------------------------
    check(
        call($app, 'POST', '/api/orders/1/payments', [], [], ['content-type' => 'application/json'])['status'] === 401,
        'Anonymous checkout returns 401',
    );

    $raffleId = $newRaffle('auth');
    $authOrderId = $newOrder($ownerId, $raffleId, [1]);

    check(
        call($app, 'POST', '/api/orders/'.$authOrderId.'/payments', [], [], ['content-type' => 'application/json'])['status'] === 401,
        'Anonymous checkout returns 401',
    );
    check(
        call($app, 'POST', '/api/orders/'.$authOrderId.'/payments', [], [$cookieName => substr($ownerCookies[$cookieName], 0, -5)], ['content-type' => 'application/json'])['status'] === 401,
        'A tampered session cookie returns 401',
    );
    check(
        call($app, 'POST', '/api/orders/'.$authOrderId.'/payments', [], $expiredCookies, ['content-type' => 'application/json'])['status'] === 401,
        'An expired session returns 401',
    );
    check(
        call($app, 'POST', '/api/orders/'.$authOrderId.'/payments', [], $ownerCookies, [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
        ])['status'] === 419,
        'A stateful checkout without CSRF returns 419',
    );
    check(
        call($app, 'POST', '/api/orders/'.$authOrderId.'/payments', [], $otherCookies, $otherHeaders)['status'] === 404,
        "Another user's order returns 404",
    );

    // ---- Checkout contract --------------------------------------------
    $checkout = postJson($app, '/api/orders/'.$authOrderId.'/payments', [], $ownerCookies, $ownerHeaders);
    check($checkout['status'] === 201, 'Checkout returns 201');
    check(($checkout['payload']['data']['status'] ?? '') === 'PENDING_PAYMENT', 'Checkout does not mark the order paid');
    check(($checkout['payload']['data']['payments'][0]['status'] ?? '') === 'PENDING', 'The new payment is PENDING');
    check(($checkout['payload']['data']['payments'][0]['amount'] ?? 0) === 1500, 'Payment amount is in cents');
    check(($checkout['payload']['data']['payments'][0]['currency'] ?? '') === 'COP', 'Payment currency is COP');
    check((int) ($checkout['payload']['data']['payments'][0]['id'] ?? 0) > 0, 'The payment id is returned');
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = '.(int) $authOrderId)->fetchColumn() === 1,
        'Exactly one payment row exists',
    );

    $payment = $paymentRow($authOrderId);
    $paymentId = (int) $payment['id'];
    check(str_starts_with((string) $payment['external_id'], 'SIM-COP-1500-'), 'The fake provider external id is stored');

    $resumed = postJson($app, '/api/orders/'.$authOrderId.'/payments', [], $ownerCookies, $ownerHeaders);
    check($resumed['status'] === 200, 'A pending checkout is resumed with 200');
    check(
        (int) ($resumed['payload']['data']['payments'][0]['id'] ?? 0) === $paymentId
        && (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = '.(int) $authOrderId)->fetchColumn() === 1,
        'Resuming a checkout does not create a second payment',
    );

    // ---- Approved verification, idempotent sale -----------------------
    $verified = postJson($app, '/api/orders/'.$authOrderId.'/payments/'.$paymentId.'/verify', [], $ownerCookies, $ownerHeaders);
    check($verified['status'] === 200, 'Verification returns 200');
    check(($verified['payload']['data']['status'] ?? '') === 'PAID', 'An approved payment marks the order PAID');
    check(($verified['payload']['data']['payments'][0]['status'] ?? '') === 'APPROVED', 'The payment is APPROVED');
    check($soldCount($raffleId) === 1, 'The number is SOLD exactly once');
    check((string) $pdo->query('SELECT status FROM raffle_numbers WHERE raffle_id = '.(int) $raffleId.' AND number = 1')->fetchColumn() === 'SOLD', 'The number row is SOLD');

    $secondVerify = postJson($app, '/api/orders/'.$authOrderId.'/payments/'.$paymentId.'/verify', [], $ownerCookies, $ownerHeaders);
    check($secondVerify['status'] === 409 && ($secondVerify['payload']['code'] ?? '') === 'ORDER_NOT_VERIFIABLE', 'A second verification of a PAID order returns 409 ORDER_NOT_VERIFIABLE');
    check($soldCount($raffleId) === 1, 'The repeated verification did not create a second sale');

    // ---- UNKNOWN payment blocks expiry --------------------------------
    $unknownRaffleId = $newRaffle('unknown');
    $unknownOrderId = $newOrder($ownerId, $unknownRaffleId, [1, 2]);
    $unknownApp = makeApp($pdo, $appKey, $cookieName, $sessionLifetime, $ordersUrl, 'UNKNOWN');
    $unknownCheckout = postJson($unknownApp, '/api/orders/'.$unknownOrderId.'/payments', [], $ownerCookies, $ownerHeaders);
    $unknownPaymentId = (int) ($unknownCheckout['payload']['data']['payments'][0]['id'] ?? 0);
    $unknownVerify = postJson($unknownApp, '/api/orders/'.$unknownOrderId.'/payments/'.$unknownPaymentId.'/verify', [], $ownerCookies, $ownerHeaders);
    check($unknownVerify['status'] === 200, 'An UNKNOWN verification returns 200');
    check(($unknownVerify['payload']['data']['status'] ?? '') === 'PAYMENT_REVIEW', 'An UNKNOWN payment moves the order to PAYMENT_REVIEW');
    check(($unknownVerify['payload']['data']['payments'][0]['status'] ?? '') === 'UNKNOWN', 'The payment stays UNKNOWN');
    check($reservedCount($unknownRaffleId) === 2, 'UNKNOWN keeps both numbers RESERVED');

    // Expire through the real orders-service endpoint.
    $fixturePdo->exec('UPDATE orders SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = '.(int) $unknownOrderId);
    $expiryBody = '{}';
    $expirySecret = hash_hmac('sha256', 'rifacloud-orders-internal', $appKey);
    $expiry = (new StreamHttpClient())->request(
        'POST',
        rtrim($ordersUrl, '/').'/internal/orders/expire-reservations',
        ['Content-Type' => 'application/json', ...signedInternalHeaders($expiryBody, $expirySecret)],
        $expiryBody,
        10,
    );
    check($expiry['status'] === 200, 'The orders-service expiry command is reachable');
    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $unknownOrderId)->fetchColumn() === 'PAYMENT_REVIEW',
        'Expiry does not release a PAYMENT_REVIEW order with an UNKNOWN payment',
    );
    check($reservedCount($unknownRaffleId) === 2, 'The numbers stay RESERVED after the expiry run');

    // ---- Late approval after expiry -----------------------------------
    $lateRaffleId = $newRaffle('late');
    $lateOrderId = $newOrder($ownerId, $lateRaffleId, [1], expiresAt: gmdate('Y-m-d H:i:s', time() - 60));
    $lateCheckout = postJson($app, '/api/orders/'.$lateOrderId.'/payments', [], $ownerCookies, $ownerHeaders);
    $latePaymentId = (int) ($lateCheckout['payload']['data']['payments'][0]['id'] ?? 0);
    check($lateCheckout['status'] === 409 && ($lateCheckout['payload']['code'] ?? '') === 'ORDER_EXPIRED', 'Checkout on an expired reservation returns 409 ORDER_EXPIRED');

    // Create the payment directly (the order is already expired) and then
    // verify an approval: it must become a manual refund, never a sale.
    $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO payments
                (order_id, provider, external_id, status, amount, currency, checkout_url, response_data, created_at, updated_at)
            VALUES
                (:order_id, 'MERCADOPAGO', :external_id, 'PENDING', 1500, 'COP', NULL, :response_data, NOW(), NOW())
        SQL,
    )->execute([
        ':order_id' => $lateOrderId,
        ':external_id' => 'SIM-COP-1500-LATEAPPROVAL',
        ':response_data' => json_encode(['simulated' => true], JSON_THROW_ON_ERROR),
    ]);
    $latePaymentId = (int) $fixturePdo->lastInsertId();
    $lateVerify = postJson($app, '/api/orders/'.$lateOrderId.'/payments/'.$latePaymentId.'/verify', [], $ownerCookies, $ownerHeaders);
    check($lateVerify['status'] === 200, 'Late approval verification returns 200');
    check(($lateVerify['payload']['data']['status'] ?? '') === 'REQUIRES_REFUND', 'A late approval becomes REQUIRES_REFUND, not PAID');
    check(($lateVerify['payload']['data']['payments'][0]['status'] ?? '') === 'REFUND_REQUIRED', 'A late approval is REFUND_REQUIRED');
    check($soldCount($lateRaffleId) === 0, 'A late approval never sells or reassigns numbers');

    // ---- Rejection releases numbers -----------------------------------
    $rejectRaffleId = $newRaffle('reject');
    $rejectOrderId = $newOrder($ownerId, $rejectRaffleId, [1, 2]);
    $rejectApp = makeApp($pdo, $appKey, $cookieName, $sessionLifetime, $ordersUrl, 'REJECTED');
    $rejectCheckout = postJson($rejectApp, '/api/orders/'.$rejectOrderId.'/payments', [], $ownerCookies, $ownerHeaders);
    $rejectPaymentId = (int) ($rejectCheckout['payload']['data']['payments'][0]['id'] ?? 0);
    $rejectVerify = postJson($rejectApp, '/api/orders/'.$rejectOrderId.'/payments/'.$rejectPaymentId.'/verify', [], $ownerCookies, $ownerHeaders);
    check($rejectVerify['status'] === 200, 'A rejected verification returns 200');
    check(($rejectVerify['payload']['data']['status'] ?? '') === 'REJECTED', 'A rejection moves the order to REJECTED');
    check(($rejectVerify['payload']['data']['payments'][0]['status'] ?? '') === 'REJECTED', 'The payment is REJECTED');
    check(
        (int) $pdo->query("SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = {$rejectRaffleId} AND number IN (1, 2) AND status = 'AVAILABLE' AND order_id IS NULL")->fetchColumn() === 2,
        'A rejection releases both numbers',
    );

    // ---- Manual review resolution -------------------------------------
    $reviewRaffleId = $newRaffle('review');
    $reviewOrderId = $newOrder($ownerId, $reviewRaffleId, [1]);
    $reviewCheckout = postJson($unknownApp, '/api/orders/'.$reviewOrderId.'/payments', [], $ownerCookies, $ownerHeaders);
    $reviewPaymentId = (int) ($reviewCheckout['payload']['data']['payments'][0]['id'] ?? 0);
    postJson($unknownApp, '/api/orders/'.$reviewOrderId.'/payments/'.$reviewPaymentId.'/verify', [], $ownerCookies, $ownerHeaders);
    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $reviewOrderId)->fetchColumn() === 'PAYMENT_REVIEW',
        'The review order is in PAYMENT_REVIEW',
    );

    $ownerResolve = postJson($app, '/api/admin/orders/'.$reviewOrderId.'/payments/'.$reviewPaymentId.'/resolve', [], $ownerCookies, $ownerHeaders);
    check($ownerResolve['status'] === 403 && ($ownerResolve['payload']['message'] ?? '') === 'Forbidden.', 'A regular user cannot resolve a review (403)');

    $adminResolve = postJson($app, '/api/admin/orders/'.$reviewOrderId.'/payments/'.$reviewPaymentId.'/resolve', [], $adminCookies, $adminHeaders);
    check($adminResolve['status'] === 200, 'An admin can resolve the review');
    check(($adminResolve['payload']['data']['status'] ?? '') === 'PAID', 'The resolved order is PAID');
    check(($adminResolve['payload']['data']['payments'][0]['status'] ?? '') === 'APPROVED', 'The resolved payment is APPROVED');
    check($soldCount($reviewRaffleId) === 1, 'The resolved review sells the number once');

    // ---- Webhook: approved, duplicate, idempotency --------------------
    $webhookRaffleId = $newRaffle('webhook');
    $webhookOrderId = $newOrder($ownerId, $webhookRaffleId, [1, 2]);
    $webhookCheckout = postJson($app, '/api/orders/'.$webhookOrderId.'/payments', [], $ownerCookies, $ownerHeaders);
    $webhookPayment = (string) $paymentRow($webhookOrderId)['external_id'];
    $webhookPayload = json_encode([
        'type' => 'payment',
        'data' => ['id' => $webhookPayment],
        'status' => 'APPROVED',
    ], JSON_THROW_ON_ERROR);

    $webhook = callRaw($app, 'POST', '/api/webhooks/mercadopago', $webhookPayload, ['content-type' => 'application/json']);
    check($webhook['status'] === 200 && ($webhook['payload']['status'] ?? '') === 'processed', 'A valid webhook is processed');
    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $webhookOrderId)->fetchColumn() === 'PAID',
        'The webhook marks the order PAID',
    );
    check($soldCount($webhookRaffleId) === 2, 'The webhook sells both numbers');
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM payment_webhook_events WHERE created_at >= ' . $fixturePdo->quote($startedAt))->fetchColumn() === 1,
        'One webhook event row exists',
    );

    $duplicate = callRaw($app, 'POST', '/api/webhooks/mercadopago', $webhookPayload, ['content-type' => 'application/json']);
    check($duplicate['status'] === 200, 'A duplicate webhook returns 200');
    check($soldCount($webhookRaffleId) === 2, 'A duplicate webhook does not sell a second time');
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM payment_webhook_events WHERE created_at >= ' . $fixturePdo->quote($startedAt))->fetchColumn() === 1,
        'A duplicate webhook does not create a second event row',
    );

    // ---- Webhook mismatch and unknown reference -----------------------
    $mismatchPayload = json_encode([
        'type' => 'payment',
        'data' => ['id' => 'SIM-COP-9999-'.strtoupper(bin2hex(random_bytes(8)))],
        'status' => 'APPROVED',
    ], JSON_THROW_ON_ERROR);
    $unknownWebhook = callRaw($app, 'POST', '/api/webhooks/mercadopago', $mismatchPayload, ['content-type' => 'application/json']);
    check($unknownWebhook['status'] === 200 && ($unknownWebhook['payload']['status'] ?? '') === 'ignored', 'An unknown external reference is ignored');
    check($soldCount($webhookRaffleId) === 2, 'An ignored webhook does not change the sale');

    // ---- Refund after approval ----------------------------------------
    $refundPayload = json_encode([
        'type' => 'payment',
        'data' => ['id' => $webhookPayment],
        'status' => 'REFUND_REQUIRED',
    ], JSON_THROW_ON_ERROR);
    $refund = callRaw($app, 'POST', '/api/webhooks/mercadopago', $refundPayload, ['content-type' => 'application/json']);
    check($refund['status'] === 200 && ($refund['payload']['status'] ?? '') === 'processed', 'A refund notification is processed');
    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $webhookOrderId)->fetchColumn() === 'REQUIRES_REFUND',
        'A refund after approval requires a manual refund',
    );
    check($soldCount($webhookRaffleId) === 2, 'A refund keeps the numbers SOLD (no reassignment)');

    // ---- Internal reconciliation command ------------------------------
    $reconcileRaffleId = $newRaffle('reconcile');
    $reconcileOrderId = $newOrder($ownerId, $reconcileRaffleId, [1]);
    postJson($app, '/api/orders/'.$reconcileOrderId.'/payments', [], $ownerCookies, $ownerHeaders);

    $unsigned = callRaw($app, 'POST', '/internal/payments/reconcile', '{}', ['content-type' => 'application/json']);
    check($unsigned['status'] === 401, 'The internal reconcile endpoint rejects unsigned requests');

    $reconcileBody = '{"limit":50,"dry_run":false,"approved":false}';
    $reconcileResponse = callRaw(
        $app,
        'POST',
        '/internal/payments/reconcile',
        $reconcileBody,
        signedInternalHeaders($reconcileBody, Config::internalSecret()),
    );
    check($reconcileResponse['status'] === 200, 'A signed reconcile request returns 200');
    check((int) ($reconcileResponse['payload']['count'] ?? -1) >= 1, 'Reconciliation processed the pending payment');
    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $reconcileOrderId)->fetchColumn() === 'PAID',
        'Reconciliation approves the pending payment through orders-service',
    );

    $dryRunBody = '{"limit":50,"dry_run":true,"approved":false}';
    $dryRun = callRaw($app, 'POST', '/internal/payments/reconcile', $dryRunBody, signedInternalHeaders($dryRunBody, Config::internalSecret()));
    check($dryRun['status'] === 200 && ($dryRun['payload']['dry_run'] ?? false) === true, 'Dry-run reconciliation reports without writing');

    $auditBody = '{"limit":50,"dry_run":false,"approved":true}';
    $audit = callRaw($app, 'POST', '/internal/payments/reconcile', $auditBody, signedInternalHeaders($auditBody, Config::internalSecret()));
    check($audit['status'] === 200, 'The approved audit endpoint returns 200');
    check(($audit['payload']['mode'] ?? '') === 'approved' && ($audit['payload']['errors'] ?? -1) === 0, 'The approved audit reports no errors');

    // ---- orders-service unavailable: fail closed ----------------------
    $downApp = makeApp($pdo, $appKey, $cookieName, $sessionLifetime, 'http://127.0.0.1:1', 'APPROVED');
    $downRaffleId = $newRaffle('down');
    $downOrderId = $newOrder($ownerId, $downRaffleId, [1]);
    $downCheckout = postJson($downApp, '/api/orders/'.$downOrderId.'/payments', [], $ownerCookies, $ownerHeaders);
    $downPaymentId = (int) ($downCheckout['payload']['data']['payments'][0]['id'] ?? 0);
    check($downCheckout['status'] === 201, 'Checkout still works while orders-service is unreachable');
    $downVerify = postJson($downApp, '/api/orders/'.$downOrderId.'/payments/'.$downPaymentId.'/verify', [], $ownerCookies, $ownerHeaders);
    check($downVerify['status'] === 503 && ($downVerify['payload']['code'] ?? '') === 'ORDER_SERVICE_UNAVAILABLE', 'Verification fails closed with 503 ORDER_SERVICE_UNAVAILABLE');
    check(
        (string) $pdo->query('SELECT status FROM payments WHERE id = '.(int) $downPaymentId)->fetchColumn() === 'PENDING',
        'A failed confirmation does not mark the payment APPROVED',
    );
    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $downOrderId)->fetchColumn() === 'PENDING_PAYMENT',
        'A failed confirmation does not mark the order PAID',
    );

    // ---- Dashboard metrics (S10.3) -------------------------------------
    $incidentsBefore = (int) $pdo->query(
        "SELECT COUNT(*) FROM payments WHERE status IN ('UNKNOWN', 'REFUND_REQUIRED')",
    )->fetchColumn();

    $metricsRaffleId = $newRaffle('dashboard', 10);
    $insertMetricsPayment = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO payments
                (order_id, provider, external_id, status, amount, currency, checkout_url, response_data, created_at, updated_at)
            VALUES
                (:order_id, 'MERCADOPAGO', :external_id, :status, 1500, 'COP', NULL, :response_data, NOW(), NOW())
        SQL,
    );

    foreach (['UNKNOWN', 'REFUND_REQUIRED', 'APPROVED', 'REJECTED'] as $index => $paymentStatus) {
        $metricsOrderId = $newOrder($ownerId, $metricsRaffleId, [$index + 1]);
        $insertMetricsPayment->execute([
            ':order_id' => $metricsOrderId,
            ':external_id' => $prefix.'-metrics-'.strtolower($paymentStatus),
            ':status' => $paymentStatus,
            ':response_data' => json_encode(['simulated' => true], JSON_THROW_ON_ERROR),
        ]);
    }

    $metricsAnonymous = call($app, 'GET', '/api/admin/payments/incidents/metrics');
    check($metricsAnonymous['status'] === 401, 'Dashboard incident metrics require a session (401)');

    $metricsUser = call($app, 'GET', '/api/admin/payments/incidents/metrics', [], $ownerCookies);
    check($metricsUser['status'] === 403, 'Dashboard incident metrics reject a USER role (403)');

    $metricsAdmin = call($app, 'GET', '/api/admin/payments/incidents/metrics', [], $adminCookies);
    check($metricsAdmin['status'] === 200, 'Dashboard incident metrics return 200 for an ADMIN');
    check(
        (int) ($metricsAdmin['payload']['data']['pending_incidents'] ?? -1) === $incidentsBefore + 2,
        'pending_incidents counts only UNKNOWN and REFUND_REQUIRED payments',
    );

    $metricsWrongMethod = call($app, 'POST', '/api/admin/payments/incidents/metrics', [], $adminCookies, $adminHeaders);
    check($metricsWrongMethod['status'] === 405, 'POST on incident metrics returns 405');

    // ---- Routing surface ----------------------------------------------
    check(call($app, 'GET', '/api/orders/'.$authOrderId.'/payments')['status'] === 405, 'GET on the checkout path returns 405');
    check(call($app, 'GET', '/api/orders/not-a-number/payments')['status'] === 404, 'An unknown payment path returns 404');
    check(callRaw($app, 'POST', '/api/webhooks/mercadopago', '{invalid', ['content-type' => 'application/json'])['status'] === 400, 'Malformed webhook JSON returns 400');
    check(call($app, 'GET', '/health')['status'] === 200, 'GET /health returns 200');
} catch (Throwable $exception) {
    $failures++;
    error_log('ERROR: '.$exception::class.' - '.$exception->getMessage()."\n");
} finally {
    if ($createdRaffleIds !== []) {
        $ids = implode(',', array_map('intval', array_unique($createdRaffleIds)));
        $fixturePdo->exec('DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE raffle_id IN ('.$ids.'))');
        $fixturePdo->exec('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE raffle_id IN ('.$ids.'))');
        $fixturePdo->exec('UPDATE raffle_numbers SET order_id = NULL WHERE raffle_id IN ('.$ids.')');
        $fixturePdo->exec('DELETE FROM orders WHERE raffle_id IN ('.$ids.')');
        $fixturePdo->exec('DELETE FROM raffle_numbers WHERE raffle_id IN ('.$ids.')');
        $fixturePdo->exec('DELETE FROM raffles WHERE id IN ('.$ids.')');
    }

    $fixturePdo->exec(
        'DELETE FROM payment_webhook_events WHERE created_at >= '.$fixturePdo->quote($startedAt),
    );

    if ($createdSessionIds !== []) {
        $ids = implode(',', array_map(static fn (string $id): string => $fixturePdo->quote($id), array_unique($createdSessionIds)));
        $fixturePdo->exec('DELETE FROM sessions WHERE id IN ('.$ids.')');
    }

    if ($createdUserIds !== []) {
        $ids = implode(',', array_map('intval', array_unique($createdUserIds)));
        $fixturePdo->exec('DELETE FROM users WHERE id IN ('.$ids.')');
    }
}

if ($failures > 0) {
    error_log("\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll payments-service integration checks passed.\n";
