<?php

declare(strict_types=1);

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
 * Child worker for the concurrency check in orders_integration.php.
 *
 * Two of these run as separate PHP processes against the same raffle and the
 * same numbers, proving that the reservation transaction prevents double sale.
 */

$database = Config::database();
$database['database'] = (string) (getenv('ORDERS_TEST_DB_DATABASE') ?: '');

if ($database['database'] !== 'rifacloud_test') {
    error_log("Refusing to run: ORDERS_TEST_DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

$userId = (int) ($argv[1] ?? 0);
$raffleId = (int) ($argv[2] ?? 0);
$numbers = array_values(array_filter(array_map(
    static fn (string $value): int => (int) $value,
    explode(',', (string) ($argv[3] ?? '')),
), static fn (int $value): bool => $value > 0));

$sessionCookie = (string) (getenv('ORDERS_TEST_SESSION_COOKIE') ?: '');

if ($userId <= 0 || $raffleId <= 0 || $numbers === [] || $sessionCookie === '') {
    error_log("Missing concurrency worker arguments.\n");
    exit(2);
}

date_default_timezone_set('UTC');

$pdo = Database::connect($database);
$app = new OrderApplication(
    $pdo,
    new UserSessionAuthenticator(
        $pdo,
        Config::appKey(),
        Config::sessionCookie(),
        Config::sessionLifetimeMinutes(),
        Config::sessionTable(),
    ),
    new OrderReservationService($pdo, Config::reservationMinutes(), Config::maxNumbersPerOrder()),
    new OrderRepository($pdo),
    new OrderPresenter(Config::paymentGateway()),
    new ReservationExpiryService($pdo, Config::internalLockName()),
    new InternalRequestVerifier(Config::internalSecret(), Config::internalClockSkewSeconds()),
    new PaymentResultApplier($pdo),
    new InternalRequestVerifier(Config::paymentsInternalSecret(), Config::internalClockSkewSeconds()),
    Config::maxNumbersPerOrder(),
);

$response = $app->handle(
    'POST',
    '/api/orders',
    [],
    [
        'content-type' => 'application/json',
        'sec-fetch-site' => 'same-origin',
    ],
    ['rifacloud-session' => $sessionCookie],
    json_encode(['raffle_id' => $raffleId, 'numbers' => $numbers], JSON_THROW_ON_ERROR),
    'http://localhost',
);

echo json_encode([
    'status' => $response->status,
    'payload' => $response->payload,
], JSON_THROW_ON_ERROR);
