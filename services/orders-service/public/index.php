<?php

declare(strict_types=1);

use RifaCloud\OrdersService\Auth\LaravelCrypto;
use RifaCloud\OrdersService\Auth\SessionPayload;
use RifaCloud\OrdersService\Auth\UserSessionAuthenticator;
use RifaCloud\OrdersService\Config;
use RifaCloud\OrdersService\Database;
use RifaCloud\OrdersService\JsonResponse;
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

date_default_timezone_set('UTC');
ini_set('display_errors', '0');

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedPath = parse_url($requestUri, PHP_URL_PATH);
$path = is_string($parsedPath) ? $parsedPath : '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

try {
    $pdo = Database::connect(Config::database());

    if ('/'.trim($path, '/') === '/health') {
        $pdo->query('SELECT 1');
        (new JsonResponse(['status' => 'ok']))->send();

        return;
    }

    $appKey = Config::appKey();

    if ($appKey === '') {
        error_log("[orders-service] APP_KEY is empty\n");
        (new JsonResponse(['message' => 'Orders temporarily unavailable.'], 503))->send();

        return;
    }

    $authenticator = new UserSessionAuthenticator(
        $pdo,
        $appKey,
        Config::sessionCookie(),
        Config::sessionLifetimeMinutes(),
        Config::sessionTable(),
    );

    $application = new OrderApplication(
        $pdo,
        $authenticator,
        new OrderReservationService(
            $pdo,
            Config::reservationMinutes(),
            Config::maxNumbersPerOrder(),
        ),
        new OrderRepository($pdo),
        new OrderPresenter(Config::paymentGateway()),
        new ReservationExpiryService($pdo, Config::internalLockName()),
        new InternalRequestVerifier(
            Config::internalSecret(),
            Config::internalClockSkewSeconds(),
        ),
        new PaymentResultApplier($pdo),
        new InternalRequestVerifier(
            Config::paymentsInternalSecret(),
            Config::internalClockSkewSeconds(),
        ),
        Config::maxNumbersPerOrder(),
    );

    $application
        ->handle(
            $method,
            $path,
            $_GET,
            normalizeHeaders(),
            $_COOKIE,
            (string) file_get_contents('php://input'),
            $scheme.'://'.$host,
        )
        ->send();
} catch (PDOException $exception) {
    error_log(sprintf(
        "[orders-service] database error: %s\n",
        (string) $exception->getCode(),
    ));

    (new JsonResponse(['message' => 'Orders temporarily unavailable.'], 503))->send();
} catch (Throwable $exception) {
    error_log(sprintf(
        "[orders-service] unexpected error: %s: %s\n",
        $exception::class,
        $exception->getMessage(),
    ));

    (new JsonResponse(['message' => 'Internal Server Error'], 500))->send();
}

/**
 * @return array<string, string>
 */
function normalizeHeaders(): array
{
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
            $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
        }
    }

    if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
    }

    if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH'])) {
        $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (is_string($value)) {
                $headers[strtolower((string) $name)] = $value;
            }
        }
    }

    return $headers;
}
