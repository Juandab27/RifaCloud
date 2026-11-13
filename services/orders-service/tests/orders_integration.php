<?php

declare(strict_types=1);

use RifaCloud\OrdersService\Auth\LaravelCrypto;
use RifaCloud\OrdersService\Auth\UserSessionAuthenticator;
use RifaCloud\OrdersService\Config;
use RifaCloud\OrdersService\Database;
use RifaCloud\OrdersService\Orders\InternalRequestVerifier;
use RifaCloud\OrdersService\Orders\OrderApplication;
use RifaCloud\OrdersService\Orders\OrderPresenter;
use RifaCloud\OrdersService\Orders\OrderRepository;
use RifaCloud\OrdersService\Orders\OrderReservationService;
use RifaCloud\OrdersService\Orders\PaymentResultApplier;
use RifaCloud\OrdersService\Orders\ReservationExpiryService;

require __DIR__.'/../src/Config.php';
require __DIR__.'/../src/Database.php';
require __DIR__.'/../src/JsonResponse.php';
require __DIR__.'/../src/Auth/AuthException.php';
require __DIR__.'/../src/Auth/CsrfTokenMismatchException.php';
require __DIR__.'/../src/Auth/LaravelCrypto.php';
require __DIR__.'/../src/Auth/SessionPayload.php';
require __DIR__.'/../src/Auth/UserSessionContext.php';
require __DIR__.'/../src/Auth/UserSessionAuthenticator.php';
require __DIR__.'/../src/Orders/NotFoundException.php';
require __DIR__.'/../src/Orders/MalformedJsonException.php';
require __DIR__.'/../src/Orders/ServiceUnavailableException.php';
require __DIR__.'/../src/Orders/ValidationException.php';
require __DIR__.'/../src/Orders/OrderConflictException.php';
require __DIR__.'/../src/Orders/PaymentConflictException.php';
require __DIR__.'/../src/Orders/ForbiddenException.php';
require __DIR__.'/../src/Orders/OrderStatus.php';
require __DIR__.'/../src/Orders/PaymentState.php';
require __DIR__.'/../src/Orders/OrderRepository.php';
require __DIR__.'/../src/Orders/OrderPresenter.php';
require __DIR__.'/../src/Orders/OrderReservationService.php';
require __DIR__.'/../src/Orders/ReservationExpiryService.php';
require __DIR__.'/../src/Orders/PaymentResultApplier.php';
require __DIR__.'/../src/Orders/InternalRequestVerifier.php';
require __DIR__.'/../src/Orders/OrderApplication.php';

/**
 * Non-destructive integration test for orders-service.
 *
 * It refuses to run unless ORDERS_TEST_DB_DATABASE is exactly `rifacloud_test`
 * so it can never write fixtures to the development or production schema.
 */

putenv('PAYMENT_GATEWAY=mercadopago');
date_default_timezone_set('UTC');

$database = Config::database();
$database['database'] = (string) (getenv('ORDERS_TEST_DB_DATABASE') ?: '');

if ($database['database'] !== 'rifacloud_test') {
    error_log("Refusing to run: ORDERS_TEST_DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

$appKey = Config::appKey();

if ($appKey === '') {
    error_log("Refusing to run: APP_KEY is empty.\n");
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
$prefix = 's3-test-'.bin2hex(random_bytes(4));
$failures = 0;
$cookieName = 'rifacloud-session';
$sessionLifetime = 120;

$createdUserIds = [];
$createdRaffleIds = [];
$createdSessionIds = [];

/**
 * Builds a byte-identical Laravel encrypted cookie payload.
 */
function laravelEncrypt(string $value, string $key): string
{
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($value, 'aes-256-cbc', $key, 0, $iv);

    if ($ciphertext === false) {
        throw new RuntimeException('openssl_encrypt failed.');
    }

    $mac = hash_hmac('sha256', base64_encode($iv).$ciphertext, $key);

    return base64_encode(json_encode([
        'iv' => base64_encode($iv),
        'value' => $ciphertext,
        'mac' => $mac,
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
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $cookies
 * @param  array<string, string>  $headers
 * @return array{status: int, payload: array<string, mixed>}
 */
function call(
    OrderApplication $app,
    string $method,
    string $path,
    array $body = [],
    array $cookies = [],
    array $headers = [],
    array $query = [],
): array {
    $raw = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);
    $response = $app->handle($method, $path, $query, $headers, $cookies, $raw, 'http://localhost');

    return ['status' => $response->status, 'payload' => $response->payload];
}

/**
 * @param  array<string, mixed>  $cookies
 * @param  array<string, string>  $headers
 * @return array{status: int, payload: array<string, mixed>}
 */
function callRaw(
    OrderApplication $app,
    string $method,
    string $path,
    string $rawBody,
    array $cookies = [],
    array $headers = [],
): array {
    $response = $app->handle($method, $path, [], $headers, $cookies, $rawBody, 'http://localhost');

    return ['status' => $response->status, 'payload' => $response->payload];
}

/**
 * @param  array<string, mixed>  $cookies
 * @param  list<int>  $numbers
 * @return array{status: int, payload: array<string, mixed>}
 */
function createOrder(OrderApplication $app, array $cookies, int $raffleId, array $numbers): array
{
    return call(
        $app,
        'POST',
        '/api/orders',
        ['raffle_id' => $raffleId, 'numbers' => $numbers],
        $cookies,
        ['content-type' => 'application/json'],
    );
}

function internalHeaders(string $body, string $secret, ?int $timestamp = null): array
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

try {
    $insertUser = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO users (name, email, phone, password, role, created_at, updated_at)
            VALUES (:name, :email, :phone, :password, :role, NOW(), NOW())
        SQL,
    );

    $passwordHash = password_hash('s3-test-password', PASSWORD_BCRYPT);
    $passwordHashWeb = hash_hmac('sha256', $passwordHash, $appKey);

    $insertUser->execute([
        ':name' => 'S3 owner',
        ':email' => $prefix.'-owner@example.invalid',
        ':phone' => '0000000000',
        ':password' => $passwordHash,
        ':role' => 'USER',
    ]);
    $ownerId = (int) $fixturePdo->lastInsertId();
    $createdUserIds[] = $ownerId;

    $insertUser->execute([
        ':name' => 'S3 other',
        ':email' => $prefix.'-other@example.invalid',
        ':phone' => '0000000000',
        ':password' => $passwordHash,
        ':role' => 'USER',
    ]);
    $otherId = (int) $fixturePdo->lastInsertId();
    $createdUserIds[] = $otherId;

    $insertUser->execute([
        ':name' => 'S3 admin',
        ':email' => $prefix.'-admin@example.invalid',
        ':phone' => '0000000000',
        ':password' => $passwordHash,
        ':role' => 'ADMIN',
    ]);
    $adminId = (int) $fixturePdo->lastInsertId();
    $createdUserIds[] = $adminId;

    $ownerCsrf = bin2hex(random_bytes(20));
    $otherCsrf = bin2hex(random_bytes(20));
    $adminCsrf = bin2hex(random_bytes(20));
    $ownerSessionId = bin2hex(random_bytes(20));
    $otherSessionId = bin2hex(random_bytes(20));
    $adminSessionId = bin2hex(random_bytes(20));
    $expiredSessionId = bin2hex(random_bytes(20));

    $insertSession = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity)
            VALUES (:id, :user_id, '127.0.0.1', 's3-test', :payload, :last_activity)
        SQL,
    );

    $sessionPayload = static fn (string $csrf, int $userId): string => base64_encode(json_encode([
        '_token' => $csrf,
        'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => $userId,
        'password_hash_web' => $passwordHashWeb,
    ], JSON_THROW_ON_ERROR));

    $insertSession->execute([
        ':id' => $ownerSessionId,
        ':user_id' => $ownerId,
        ':payload' => $sessionPayload($ownerCsrf, $ownerId),
        ':last_activity' => time(),
    ]);
    $createdSessionIds[] = $ownerSessionId;

    $insertSession->execute([
        ':id' => $otherSessionId,
        ':user_id' => $otherId,
        ':payload' => $sessionPayload($otherCsrf, $otherId),
        ':last_activity' => time(),
    ]);
    $createdSessionIds[] = $otherSessionId;

    $insertSession->execute([
        ':id' => $adminSessionId,
        ':user_id' => $adminId,
        ':payload' => $sessionPayload($adminCsrf, $adminId),
        ':last_activity' => time(),
    ]);
    $createdSessionIds[] = $adminSessionId;

    $insertSession->execute([
        ':id' => $expiredSessionId,
        ':user_id' => $ownerId,
        ':payload' => $sessionPayload($ownerCsrf, $ownerId),
        ':last_activity' => time() - (($sessionLifetime + 5) * 60),
    ]);
    $createdSessionIds[] = $expiredSessionId;

    $ownerCookies = [
        $cookieName => sessionCookie($ownerSessionId, $cookieName, $key),
    ];
    $otherCookies = [
        $cookieName => sessionCookie($otherSessionId, $cookieName, $key),
    ];
    $adminCookies = [
        $cookieName => sessionCookie($adminSessionId, $cookieName, $key),
    ];
    $expiredCookies = [
        $cookieName => sessionCookie($expiredSessionId, $cookieName, $key),
    ];
    $ownerHeaders = [
        'content-type' => 'application/json',
        'x-xsrf-token' => xsrfCookie($ownerCsrf, $key),
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
                (title, description, price_per_number, min_number, max_number, status, starts_at, ends_at, created_by, created_at, updated_at)
            VALUES
                (:title, :description, :price, :min, :max, :status, :starts_at, :ends_at, :created_by, NOW(), NOW())
        SQL,
    );
    $insertNumber = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO raffle_numbers (raffle_id, number, status, created_at, updated_at)
            VALUES (:raffle_id, :number, 'AVAILABLE', NOW(), NOW())
        SQL,
    );

    $newRaffle = function (
        string $label,
        string $status = 'PUBLISHED',
        int $min = 1,
        int $max = 10,
        ?string $startsAt = null,
        ?string $endsAt = null,
    ) use ($fixturePdo, $insertRaffle, $insertNumber, $adminId, $prefix, &$createdRaffleIds): int {
        $insertRaffle->execute([
            ':title' => $prefix.' '.$label,
            ':description' => 'Fixture for the orders-service integration test.',
            ':price' => 1500,
            ':min' => $min,
            ':max' => $max,
            ':status' => $status,
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':created_by' => $adminId,
        ]);
        $raffleId = (int) $fixturePdo->lastInsertId();
        $createdRaffleIds[] = $raffleId;

        for ($number = $min; $number <= $max; $number++) {
            $insertNumber->execute([':raffle_id' => $raffleId, ':number' => $number]);
        }

        return $raffleId;
    };

    $publishedId = $newRaffle('published');
    $csrfRaffleId = $newRaffle('csrf');
    $otherRaffleId = $newRaffle('other', min: 101, max: 110);
    $draftId = $newRaffle('draft', status: 'DRAFT');
    $futureId = $newRaffle('future', startsAt: gmdate('Y-m-d H:i:s', time() + 3600));
    $endedId = $newRaffle('ended', endsAt: gmdate('Y-m-d H:i:s', time() - 3600));

    $authenticator = new UserSessionAuthenticator($pdo, $appKey, $cookieName, $sessionLifetime, 'sessions');
    $reservations = new OrderReservationService($pdo, 10, 50);
    $repository = new OrderRepository($pdo);
    $expiry = new ReservationExpiryService($pdo, Config::internalLockName());
    $secret = Config::internalSecret();

    $app = new OrderApplication(
        $pdo,
        $authenticator,
        $reservations,
        $repository,
        new OrderPresenter('mercadopago'),
        $expiry,
        new InternalRequestVerifier($secret, 60),
        new PaymentResultApplier($pdo),
        new InternalRequestVerifier(Config::paymentsInternalSecret(), 60),
        50,
    );

    // ---- Authentication and CSRF --------------------------------------
    check(
        call($app, 'POST', '/api/orders', ['raffle_id' => $publishedId, 'numbers' => [1]])['status'] === 401,
        'Anonymous POST returns 401',
    );

    $tampered = [$cookieName => substr($ownerCookies[$cookieName], 0, -5)];
    check(
        call($app, 'POST', '/api/orders', ['raffle_id' => $publishedId, 'numbers' => [1]], $tampered, ['content-type' => 'application/json'])['status'] === 401,
        'A tampered session cookie returns 401',
    );

    check(
        call($app, 'POST', '/api/orders', ['raffle_id' => $publishedId, 'numbers' => [1]], $expiredCookies, ['content-type' => 'application/json'])['status'] === 401,
        'An expired session returns 401',
    );

    check(
        call($app, 'POST', '/api/orders', ['raffle_id' => $publishedId, 'numbers' => [1]], $ownerCookies, [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
        ])['status'] === 419,
        'A stateful POST without a CSRF token returns 419',
    );

    check(
        call($app, 'POST', '/api/orders', ['raffle_id' => $csrfRaffleId, 'numbers' => [1]], $ownerCookies, [
            'content-type' => 'application/json',
            'sec-fetch-site' => 'same-origin',
        ])['status'] === 201,
        'A same-origin browser POST passes CSRF',
    );

    // ---- Validation ----------------------------------------------------
    $missingNumbers = call($app, 'POST', '/api/orders', ['raffle_id' => $publishedId], $ownerCookies, $ownerHeaders);
    check(
        $missingNumbers['status'] === 422 && isset($missingNumbers['payload']['errors']['numbers']),
        'Missing numbers return 422 on numbers',
    );

    $duplicates = call($app, 'POST', '/api/orders', ['raffle_id' => $publishedId, 'numbers' => [1, 1]], $ownerCookies, $ownerHeaders);
    check(
        $duplicates['status'] === 422 && isset($duplicates['payload']['errors']['numbers.1']),
        'Duplicate numbers return 422 on numbers.1',
    );

    $outOfRange = call($app, 'POST', '/api/orders', ['raffle_id' => $publishedId, 'numbers' => [11]], $ownerCookies, $ownerHeaders);
    check(
        $outOfRange['status'] === 422 && isset($outOfRange['payload']['errors']['numbers']),
        'Numbers outside the raffle range return 422',
    );

    $missingRaffle = call($app, 'POST', '/api/orders', ['raffle_id' => 999999999, 'numbers' => [1]], $ownerCookies, $ownerHeaders);
    check(
        $missingRaffle['status'] === 422 && isset($missingRaffle['payload']['errors']['raffle_id']),
        'A non-existent raffle returns 422 on raffle_id',
    );

    $foreignNumbers = call($app, 'POST', '/api/orders', ['raffle_id' => $publishedId, 'numbers' => [105]], $ownerCookies, $ownerHeaders);
    check(
        $foreignNumbers['status'] === 422 && isset($foreignNumbers['payload']['errors']['numbers']),
        'Numbers from another raffle return 422',
    );

    // ---- Business conflicts --------------------------------------------
    $draft = call($app, 'POST', '/api/orders', ['raffle_id' => $draftId, 'numbers' => [1]], $ownerCookies, $ownerHeaders);
    check(
        $draft['status'] === 409 && ($draft['payload']['code'] ?? '') === 'RAFFLE_NOT_AVAILABLE',
        'A DRAFT raffle returns 409 RAFFLE_NOT_AVAILABLE',
    );

    $future = call($app, 'POST', '/api/orders', ['raffle_id' => $futureId, 'numbers' => [1]], $ownerCookies, $ownerHeaders);
    check(
        $future['status'] === 409 && ($future['payload']['code'] ?? '') === 'RAFFLE_NOT_STARTED',
        'A future raffle returns 409 RAFFLE_NOT_STARTED',
    );

    $ended = call($app, 'POST', '/api/orders', ['raffle_id' => $endedId, 'numbers' => [1]], $ownerCookies, $ownerHeaders);
    check(
        $ended['status'] === 409 && ($ended['payload']['code'] ?? '') === 'RAFFLE_ENDED',
        'An ended raffle returns 409 RAFFLE_ENDED',
    );

    // ---- Successful reservation and contract ---------------------------
    $created = createOrder($app, $ownerCookies, $publishedId, [7, 2, 5]);
    check($created['status'] === 201, 'POST /api/orders returns 201');

    $orderId = (int) ($created['payload']['data']['id'] ?? 0);
    check($orderId > 0, 'The response contains the new order id');
    check(($created['payload']['data']['status'] ?? '') === 'PENDING_PAYMENT', 'New orders start as PENDING_PAYMENT');
    check((int) ($created['payload']['data']['total_amount'] ?? 0) === 4500, 'total_amount is price * count in COP');
    check((int) ($created['payload']['data']['raffle']['id'] ?? 0) === $publishedId, 'The resource includes the raffle');
    check(count($created['payload']['data']['numbers'] ?? []) === 3, 'The resource includes the three reserved numbers');
    check(($created['payload']['data']['simulated_payments'] ?? true) === false, 'simulated_payments follows PAYMENT_GATEWAY');
    check(($created['payload']['data']['payments'] ?? null) === [], 'A new order has an empty payments array');

    $orderRow = $pdo->query('SELECT * FROM orders WHERE id = '.(int) $orderId)->fetch();
    check((int) $orderRow['user_id'] === $ownerId, 'The order belongs to the authenticated user');
    check((int) $orderRow['raffle_id'] === $publishedId, 'The order references the raffle');
    check($orderRow['order_number'] !== '', 'The order number is generated');
    check($orderRow['expires_at'] !== null, 'The reservation has an expiry timestamp');

    $reservedRows = $pdo->query(
        'SELECT number, status, order_id, reserved_at, reserved_until FROM raffle_numbers WHERE raffle_id = '.(int) $publishedId.' AND number IN (2, 5, 7) ORDER BY number',
    )->fetchAll();
    check(count($reservedRows) === 3, 'All requested numbers exist');
    foreach ($reservedRows as $row) {
        check((string) $row['status'] === 'RESERVED', 'Number '.$row['number'].' is RESERVED');
        check((int) $row['order_id'] === (int) $orderId, 'Number '.$row['number'].' points to the order');
        check($row['reserved_at'] !== null && $row['reserved_until'] !== null, 'Number '.$row['number'].' has reservation timestamps');
    }
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM order_items WHERE order_id = '.(int) $orderId)->fetchColumn() === 3,
        'Three order_items rows were written',
    );
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM order_items WHERE order_id = '.(int) $orderId." AND price_at_purchase = 1500")->fetchColumn() === 3,
        'order_items keep price_at_purchase in COP',
    );

    $again = createOrder($app, $ownerCookies, $publishedId, [2, 3]);
    check($again['status'] === 409, 'Reserving an already reserved number returns 409');
    check(($again['payload']['code'] ?? '') === 'NUMBERS_UNAVAILABLE', 'The conflict code is NUMBERS_UNAVAILABLE');
    check(in_array(2, $again['payload']['unavailable_numbers'] ?? [], true), 'unavailable_numbers lists the taken number');
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE raffle_id = '.(int) $publishedId)->fetchColumn() === 1,
        'The failed request did not create a second order',
    );
    check(
        (string) $pdo->query('SELECT status FROM raffle_numbers WHERE raffle_id = '.(int) $publishedId.' AND number = 3')->fetchColumn() === 'AVAILABLE',
        'The failed request did not reserve the available number',
    );

    // ---- Ownership -----------------------------------------------------
    $showOwn = call($app, 'GET', '/api/orders/'.$orderId, [], $ownerCookies);
    check($showOwn['status'] === 200, 'The owner can read the order');
    check((int) ($showOwn['payload']['data']['id'] ?? 0) === $orderId, 'GET returns the requested order');
    check(count($showOwn['payload']['data']['numbers'] ?? []) === 3, 'GET returns the reserved numbers');
    check(($showOwn['payload']['data']['payments'] ?? null) === [], 'GET returns an empty payments projection');

    $showOther = call($app, 'GET', '/api/orders/'.$orderId, [], $otherCookies);
    check($showOther['status'] === 404, "Another user's order returns 404");
    check(($showOther['payload']['message'] ?? '') === 'No encontrado.', 'The 404 keeps the Laravel message');
    check(
        ! str_contains(json_encode($showOther['payload'], JSON_THROW_ON_ERROR), (string) $orderRow['order_number']),
        'The 404 does not disclose the order number',
    );

    check(
        call($app, 'GET', '/api/orders/'.$orderId)['status'] === 401,
        'Anonymous GET returns 401',
    );

    // ---- My purchases (list endpoint) ---------------------------------
    $otherOrder = createOrder($app, $otherCookies, $publishedId, [4]);
    $otherOrderId = (int) ($otherOrder['payload']['data']['id'] ?? 0);
    check($otherOrder['status'] === 201, 'The other user can create an order');

    $listOwn = call($app, 'GET', '/api/orders', [], $ownerCookies);
    $listedIds = array_map(
        static fn (array $order): int => (int) $order['id'],
        $listOwn['payload']['data'] ?? [],
    );
    $firstEntry = $listOwn['payload']['data'][0] ?? [];

    check($listOwn['status'] === 200, 'The owner can list their orders');
    check(($listOwn['payload']['meta']['current_page'] ?? 0) === 1, 'The list meta starts at page 1');
    check(($listOwn['payload']['meta']['per_page'] ?? 0) === 10, 'The list defaults to 10 per page');
    check(($listOwn['payload']['meta']['total'] ?? 0) >= 2, 'The list reports the owner total');
    check(count($listOwn['payload']['data'] ?? []) >= 2, 'The list returns several owner orders');
    check(in_array($orderId, $listedIds, true), 'The list includes the existing order');
    check(! in_array($otherOrderId, $listedIds, true), "The list does not include another user's order");
    check(($firstEntry['order_number'] ?? '') !== '', 'The list entry includes the order number');
    check(($firstEntry['status'] ?? '') !== '', 'The list entry includes the status');
    check(($firstEntry['raffle']['title'] ?? '') !== '', 'The list entry includes the raffle title');
    check(is_array($firstEntry['numbers'] ?? null), 'The list entry includes the numbers');
    check(array_key_exists('paid_at', $firstEntry), 'The list entry includes paid_at');
    check(array_key_exists('created_at', $firstEntry), 'The list entry includes created_at');
    check($listedIds === array_values(array_unique($listedIds)), 'The list has no duplicate orders');

    $pageOne = call($app, 'GET', '/api/orders', [], $ownerCookies, [], ['page' => 1, 'per_page' => 1]);
    $pageTwo = call($app, 'GET', '/api/orders', [], $ownerCookies, [], ['page' => 2, 'per_page' => 1]);
    $pageOneId = (int) ($pageOne['payload']['data'][0]['id'] ?? 0);
    $pageTwoId = (int) ($pageTwo['payload']['data'][0]['id'] ?? 0);

    check(count($pageOne['payload']['data'] ?? []) === 1, 'per_page=1 returns one order');
    check(($pageOne['payload']['meta']['per_page'] ?? 0) === 1, 'The meta reflects per_page=1');
    check(($pageOne['payload']['meta']['last_page'] ?? 0) >= 2, 'The meta reports multiple pages');
    check($pageOneId > $pageTwoId, 'The list is newest first');
    check($pageOneId !== $pageTwoId, 'Pagination does not repeat orders');

    $clamped = call($app, 'GET', '/api/orders', [], $ownerCookies, [], ['per_page' => 100]);
    check(($clamped['payload']['meta']['per_page'] ?? 0) === 50, 'per_page is clamped to 50');

    $listOther = call($app, 'GET', '/api/orders', [], $otherCookies);
    $otherIds = array_map(
        static fn (array $order): int => (int) $order['id'],
        $listOther['payload']['data'] ?? [],
    );

    check($listOther['status'] === 200, 'Another user can list their own orders');
    check($otherIds === [$otherOrderId], "Another user's list contains only their order");
    check(! in_array($orderId, $otherIds, true), "Another user's list does not include the owner's order");

    check(call($app, 'GET', '/api/orders')['status'] === 401, 'Anonymous list returns 401');

    // ---- Real concurrency: two processes, same numbers ----------------
    $concurrencyRaffleId = $newRaffle('concurrency', max: 5);
    $lockPdo = Database::connect($database);
    $lockPdo->beginTransaction();
    $lockPdo->query('SELECT id FROM raffles WHERE id = '.(int) $concurrencyRaffleId.' FOR UPDATE')->fetchAll();

    $children = [];
    $childEnv = array_merge(getenv(), [
        'ORDERS_TEST_DB_DATABASE' => 'rifacloud_test',
        'ORDERS_DB_DATABASE' => 'rifacloud_test',
        'ORDERS_TEST_SESSION_COOKIE' => $ownerCookies[$cookieName],
        'PAYMENT_GATEWAY' => 'mercadopago',
    ]);

    for ($i = 0; $i < 2; $i++) {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__.'/concurrent_create.php',
                (string) $ownerId,
                (string) $concurrencyRaffleId,
                '1,2',
            ],
            $descriptors,
            $pipes,
            null,
            $childEnv,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the concurrency child process.');
        }

        $children[] = ['process' => $process, 'pipes' => $pipes];
    }

    usleep(300_000);
    $lockPdo->commit();
    $lockPdo->exec('SELECT RELEASE_ALL_LOCKS()');

    $childResults = [];
    foreach ($children as $child) {
        $stdout = stream_get_contents($child['pipes'][1]);
        $stderr = stream_get_contents($child['pipes'][2]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['process']);

        $decoded = is_string($stdout) ? json_decode($stdout, true) : null;

        if (! is_array($decoded)) {
            throw new RuntimeException('Concurrency child produced invalid output: '.$stdout.' '.$stderr);
        }

        $childResults[] = $decoded;
    }

    $codes = array_map(static fn (array $result): int => (int) ($result['status'] ?? 0), $childResults);
    sort($codes);
    check($codes === [201, 409], 'Two concurrent requests for the same numbers yield exactly one 201 and one 409');
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE raffle_id = '.(int) $concurrencyRaffleId)->fetchColumn() === 1,
        'Concurrency created exactly one order (no double sale)',
    );
    check(
        (int) $pdo->query("SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = ".(int) $concurrencyRaffleId." AND status = 'RESERVED'")->fetchColumn() === 2,
        'Concurrency reserved the numbers exactly once',
    );

    // ---- Expiry: releases numbers and is idempotent -------------------
    $expiryRaffleId = $newRaffle('expiry');
    $expiring = createOrder($app, $ownerCookies, $expiryRaffleId, [1, 2]);
    $expiringId = (int) $expiring['payload']['data']['id'];
    $fixturePdo->exec('UPDATE orders SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = '.(int) $expiringId);

    $expiredRun = $expiry->expireWithLock();
    check($expiredRun === ['expired' => 1, 'skipped' => false], 'Expiry releases one expired order');

    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $expiringId)->fetchColumn() === 'EXPIRED',
        'The expired order moves to EXPIRED',
    );
    check(
        (int) $pdo->query("SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = ".(int) $expiryRaffleId." AND number IN (1, 2) AND status = 'AVAILABLE' AND order_id IS NULL AND reserved_at IS NULL AND reserved_until IS NULL")->fetchColumn() === 2,
        'Expiry releases both numbers completely',
    );
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM order_items WHERE order_id = '.(int) $expiringId)->fetchColumn() === 2,
        'Expiry keeps order_items for audit',
    );
    check(
        $expiry->expireWithLock() === ['expired' => 0, 'skipped' => false],
        'Expiry is idempotent',
    );

    // ---- Expiry: active reservation is untouched ----------------------
    $activeRaffleId = $newRaffle('active-expiry');
    $active = createOrder($app, $ownerCookies, $activeRaffleId, [1]);
    $activeId = (int) $active['payload']['data']['id'];
    check($expiry->expireWithLock() === ['expired' => 0, 'skipped' => false], 'An active reservation is not expired');
    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $activeId)->fetchColumn() === 'PENDING_PAYMENT',
        'The active order remains PENDING_PAYMENT',
    );

    // ---- Expiry: protected order states -------------------------------
    foreach (['PAYMENT_REVIEW', 'REQUIRES_REFUND', 'PAID'] as $protected) {
        $protectedRaffleId = $newRaffle('protected-'.$protected);
        $protectedOrder = createOrder($app, $ownerCookies, $protectedRaffleId, [1, 2]);
        $protectedOrderId = (int) $protectedOrder['payload']['data']['id'];
        $fixturePdo->exec("UPDATE orders SET status = '{$protected}', expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ".(int) $protectedOrderId);

        $expiry->expireWithLock();

        check(
            (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $protectedOrderId)->fetchColumn() === $protected,
            "A {$protected} order is never expired",
        );
        check(
            (int) $pdo->query("SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = ".(int) $protectedRaffleId." AND status = 'RESERVED'")->fetchColumn() === 2,
            "A {$protected} order keeps its numbers RESERVED",
        );
    }

    // ---- Expiry: payment uncertainty blocks release -------------------
    $paymentInsert = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO payments
                (order_id, provider, external_id, status, amount, currency, checkout_url, response_data, verified_at, created_at, updated_at)
            VALUES
                (:order_id, 'MERCADOPAGO', :external_id, :status, 1500, 'COP', NULL, :response_data, NULL, NOW(), NOW())
        SQL,
    );

    foreach (['UNKNOWN', 'APPROVED', 'REFUND_REQUIRED'] as $blocking) {
        $blockedRaffleId = $newRaffle('payment-'.$blocking);
        $blockedOrder = createOrder($app, $ownerCookies, $blockedRaffleId, [1]);
        $blockedOrderId = (int) $blockedOrder['payload']['data']['id'];
        $fixturePdo->exec('UPDATE orders SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = '.(int) $blockedOrderId);
        $paymentInsert->execute([
            ':order_id' => $blockedOrderId,
            ':external_id' => $prefix.'-'.$blocking,
            ':status' => $blocking,
            ':response_data' => null,
        ]);

        $expiry->expireWithLock();

        check(
            (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $blockedOrderId)->fetchColumn() === 'PENDING_PAYMENT',
            "A {$blocking} payment blocks expiry",
        );
        check(
            (int) $pdo->query("SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = ".(int) $blockedRaffleId." AND status = 'RESERVED'")->fetchColumn() === 1,
            "A {$blocking} payment keeps the number RESERVED",
        );
    }

    $releasedRaffleId = $newRaffle('payment-PENDING');
    $releasedOrder = createOrder($app, $ownerCookies, $releasedRaffleId, [1]);
    $releasedOrderId = (int) $releasedOrder['payload']['data']['id'];
    $fixturePdo->exec('UPDATE orders SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = '.(int) $releasedOrderId);
    $paymentInsert->execute([
        ':order_id' => $releasedOrderId,
        ':external_id' => $prefix.'-PENDING',
        ':status' => 'PENDING',
        ':response_data' => null,
    ]);
    $expiry->expireWithLock();
    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $releasedOrderId)->fetchColumn() === 'EXPIRED',
        'A PENDING payment does not block expiry',
    );

    // ---- Expiry: SOLD numbers are never released ----------------------
    $soldRaffleId = $newRaffle('sold-expiry');
    $soldOrder = createOrder($app, $ownerCookies, $soldRaffleId, [1, 2]);
    $soldOrderId = (int) $soldOrder['payload']['data']['id'];
    $fixturePdo->exec("UPDATE raffle_numbers SET status = 'SOLD', sold_at = UTC_TIMESTAMP() WHERE raffle_id = ".(int) $soldRaffleId.' AND number = 1');
    $fixturePdo->exec('UPDATE orders SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = '.(int) $soldOrderId);

    $expiry->expireWithLock();

    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $soldOrderId)->fetchColumn() === 'PENDING_PAYMENT',
        'An order with a SOLD number is not expired',
    );
    check(
        (string) $pdo->query('SELECT status FROM raffle_numbers WHERE raffle_id = '.(int) $soldRaffleId.' AND number = 2')->fetchColumn() === 'RESERVED',
        'The other number stays RESERVED when one is SOLD',
    );

    // ---- Expiry: advisory lock prevents a second run ------------------
    $lockRaffleId = $newRaffle('advisory-lock');
    $lockOrder = createOrder($app, $ownerCookies, $lockRaffleId, [1]);
    $lockOrderId = (int) $lockOrder['payload']['data']['id'];
    $fixturePdo->exec('UPDATE orders SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = '.(int) $lockOrderId);

    $otherConnection = Database::connect($database);
    $acquired = $otherConnection->query(
        "SELECT GET_LOCK('".Config::internalLockName()."', 0)",
    )->fetchColumn();
    check((int) $acquired === 1, 'A second connection can take the advisory lock');

    $skipped = $expiry->expireWithLock();
    check($skipped === ['expired' => 0, 'skipped' => true], 'Expiry is skipped while the advisory lock is held');
    check(
        (string) $pdo->query('SELECT status FROM orders WHERE id = '.(int) $lockOrderId)->fetchColumn() === 'PENDING_PAYMENT',
        'The locked run did not expire the order',
    );

    $otherConnection->query("SELECT RELEASE_LOCK('".Config::internalLockName()."')")->fetchColumn();
    check($expiry->expireWithLock() === ['expired' => 1, 'skipped' => false], 'Expiry resumes after the lock is released');

    // ---- Internal endpoint authentication -----------------------------
    $internalRaffleId = $newRaffle('internal');
    $internalOrder = createOrder($app, $ownerCookies, $internalRaffleId, [1]);
    $internalOrderId = (int) $internalOrder['payload']['data']['id'];
    $fixturePdo->exec('UPDATE orders SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = '.(int) $internalOrderId);

    $unsigned = callRaw($app, 'POST', '/internal/orders/expire-reservations', '{}', [], ['content-type' => 'application/json']);
    check($unsigned['status'] === 401, 'The internal endpoint rejects an unsigned request');

    $wrongSignature = callRaw(
        $app,
        'POST',
        '/internal/orders/expire-reservations',
        '{}',
        [],
        ['content-type' => 'application/json', 'x-rifacloud-timestamp' => (string) time(), 'x-rifacloud-signature' => 'nope'],
    );
    check($wrongSignature['status'] === 401, 'The internal endpoint rejects an invalid signature');

    $staleHeaders = internalHeaders('{}', $secret, time() - 3600);
    $stale = callRaw($app, 'POST', '/internal/orders/expire-reservations', '{}', [], $staleHeaders);
    check($stale['status'] === 401, 'The internal endpoint rejects a stale timestamp');

    $valid = callRaw($app, 'POST', '/internal/orders/expire-reservations', '{}', [], internalHeaders('{}', $secret));
    check($valid['status'] === 200, 'A signed internal request returns 200');
    check(($valid['payload']['expired'] ?? null) === 1, 'The signed request expired the prepared order');
    check(($valid['payload']['skipped'] ?? null) === false, 'The signed request reports skipped=false');

    // ---- Payments projection (regression, no real Mercado Pago) --------
    $paymentRaffleId = $newRaffle('payment-projection');
    $paymentOrder = createOrder($app, $ownerCookies, $paymentRaffleId, [1]);
    $paymentOrderId = (int) $paymentOrder['payload']['data']['id'];
    $paymentInsert->execute([
        ':order_id' => $paymentOrderId,
        ':external_id' => $prefix.'-projection',
        ':status' => 'APPROVED',
        ':response_data' => json_encode(['simulated' => true], JSON_THROW_ON_ERROR),
    ]);

    $withPayment = call($app, 'GET', '/api/orders/'.$paymentOrderId, [], $ownerCookies);
    check($withPayment['status'] === 200, 'The order detail still returns 200 with a payment');
    check(count($withPayment['payload']['data']['payments'] ?? []) === 1, 'The payment projection is included');
    $paymentPayload = $withPayment['payload']['data']['payments'][0] ?? [];
    check(($paymentPayload['provider'] ?? '') === 'MERCADOPAGO', 'The payment provider is preserved');
    check(($paymentPayload['status'] ?? '') === 'APPROVED', 'The payment status is preserved');
    check(($paymentPayload['amount'] ?? 0) === 1500, 'The payment amount is preserved in COP');
    check(($paymentPayload['currency'] ?? '') === 'COP', 'The payment currency is preserved');
    check(($paymentPayload['simulated'] ?? false) === true, 'response_data.simulated is preserved');

    // ---- Dashboard metrics (S10.3) -------------------------------------
    $paidBefore = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'PAID'")->fetchColumn();
    $soldBefore = (int) $pdo->query("SELECT COUNT(*) FROM raffle_numbers WHERE status = 'SOLD'")->fetchColumn();

    $metricsRaffleId = $newRaffle('dashboard', min: 201, max: 210);

    foreach ([201, 202] as $soldNumber) {
        $metricsOrder = createOrder($app, $ownerCookies, $metricsRaffleId, [$soldNumber]);
        $metricsOrderId = (int) ($metricsOrder['payload']['data']['id'] ?? 0);
        $fixturePdo->exec("UPDATE orders SET status = 'PAID', paid_at = UTC_TIMESTAMP() WHERE id = ".$metricsOrderId);
        $fixturePdo->exec("UPDATE raffle_numbers SET status = 'SOLD', sold_at = UTC_TIMESTAMP() WHERE raffle_id = ".$metricsRaffleId.' AND number = '.$soldNumber);
    }

    $rejectedMetricsOrder = createOrder($app, $ownerCookies, $metricsRaffleId, [203]);
    $rejectedMetricsOrderId = (int) ($rejectedMetricsOrder['payload']['data']['id'] ?? 0);
    $fixturePdo->exec("UPDATE orders SET status = 'REJECTED' WHERE id = ".$rejectedMetricsOrderId);
    $fixturePdo->exec("UPDATE raffle_numbers SET status = 'AVAILABLE', order_id = NULL, reserved_at = NULL, reserved_until = NULL WHERE raffle_id = ".$metricsRaffleId.' AND number = 203');

    $metricsAnonymous = call($app, 'GET', '/api/admin/orders/metrics');
    check($metricsAnonymous['status'] === 401, 'Dashboard order metrics require a session (401)');

    $metricsUser = call($app, 'GET', '/api/admin/orders/metrics', [], $ownerCookies);
    check($metricsUser['status'] === 403, 'Dashboard order metrics reject a USER role (403)');

    $metricsAdmin = call($app, 'GET', '/api/admin/orders/metrics', [], $adminCookies);
    check($metricsAdmin['status'] === 200, 'Dashboard order metrics return 200 for an ADMIN');
    check(
        (int) ($metricsAdmin['payload']['data']['paid_orders'] ?? -1) === $paidBefore + 2,
        'paid_orders counts only the two new PAID orders',
    );
    check(
        (int) ($metricsAdmin['payload']['data']['sold_numbers'] ?? -1) === $soldBefore + 2,
        'sold_numbers counts only the two new SOLD numbers',
    );

    $metricsWrongMethod = call($app, 'POST', '/api/admin/orders/metrics', [], $adminCookies, $adminHeaders);
    check($metricsWrongMethod['status'] === 405, 'POST on order metrics returns 405');

    // ---- Routing surface ----------------------------------------------
    check(call($app, 'GET', '/api/orders')['status'] === 401, 'Anonymous GET /api/orders returns 401');
    check(call($app, 'PUT', '/api/orders')['status'] === 405, 'PUT /api/orders returns 405');
    check(call($app, 'POST', '/api/orders/'.$orderId, [], $ownerCookies, $ownerHeaders)['status'] === 405, 'POST /api/orders/{id} returns 405');
    check(call($app, 'GET', '/api/orders/not-a-number')['status'] === 404, 'An unknown order path returns 404');
    check(call($app, 'GET', '/api/orders/'.$orderId.'/payments')['status'] === 404, 'Payment paths are not owned by this service');
    check(call($app, 'GET', '/health')['status'] === 200, 'GET /health returns 200');
} catch (Throwable $exception) {
    $failures++;
    error_log('ERROR: '.$exception::class.' - '.$exception->getMessage()."\n");
} finally {
    // Cleanup in FK order: payments -> order_items -> orders -> numbers ->
    // raffles -> sessions -> users.
    if ($createdRaffleIds !== []) {
        $ids = implode(',', array_map('intval', array_unique($createdRaffleIds)));
        $fixturePdo->exec('DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE raffle_id IN ('.$ids.'))');
        $fixturePdo->exec('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE raffle_id IN ('.$ids.'))');
        $fixturePdo->exec('UPDATE raffle_numbers SET order_id = NULL WHERE raffle_id IN ('.$ids.')');
        $fixturePdo->exec('DELETE FROM orders WHERE raffle_id IN ('.$ids.')');
        $fixturePdo->exec('DELETE FROM raffle_numbers WHERE raffle_id IN ('.$ids.')');
        $fixturePdo->exec('DELETE FROM raffles WHERE id IN ('.$ids.')');
    }

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

echo "\nAll orders-service integration checks passed.\n";
