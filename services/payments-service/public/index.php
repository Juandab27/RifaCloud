<?php

declare(strict_types=1);

use RifaCloud\PaymentsService\Auth\UserSessionAuthenticator;
use RifaCloud\PaymentsService\Config;
use RifaCloud\PaymentsService\Database;
use RifaCloud\PaymentsService\Http\StreamHttpClient;
use RifaCloud\PaymentsService\JsonResponse;
use RifaCloud\PaymentsService\Payments\CheckoutService;
use RifaCloud\PaymentsService\Payments\FakePaymentGateway;
use RifaCloud\PaymentsService\Payments\InternalRequestVerifier;
use RifaCloud\PaymentsService\Payments\MercadoPagoGateway;
use RifaCloud\PaymentsService\Payments\OrderProjection;
use RifaCloud\PaymentsService\Payments\OrdersClient;
use RifaCloud\PaymentsService\Payments\PaymentApplication;
use RifaCloud\PaymentsService\Payments\PaymentGateway;
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
require __DIR__.'/../src/Payments/MercadoPagoGateway.php';
require __DIR__.'/../src/Payments/FakePaymentGateway.php';
require __DIR__.'/../src/Payments/OrderProjection.php';
require __DIR__.'/../src/Payments/PaymentRepository.php';
require __DIR__.'/../src/Payments/OrdersClient.php';
require __DIR__.'/../src/Payments/CheckoutService.php';
require __DIR__.'/../src/Payments/VerificationService.php';
require __DIR__.'/../src/Payments/WebhookService.php';
require __DIR__.'/../src/Payments/InternalRequestVerifier.php';
require __DIR__.'/../src/Payments/PaymentApplication.php';

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
        error_log("[payments-service] APP_KEY is empty\n");
        (new JsonResponse(['message' => 'Payments temporarily unavailable.'], 503))->send();

        return;
    }

    $gatewayDriver = Config::paymentGateway();

    if ($gatewayDriver === 'fake' && getenv('APP_ENV') === 'production') {
        error_log("[payments-service] PAYMENT_GATEWAY=fake is not allowed in production\n");
        (new JsonResponse(['message' => 'Payments temporarily unavailable.'], 503))->send();

        return;
    }

    $http = new StreamHttpClient();
    $mercadoPago = Config::mercadoPago();

    $gateway = match ($gatewayDriver) {
        'fake' => new FakePaymentGateway(Config::fakeOutcome(), Config::appUrl()),
        'mercadopago' => new MercadoPagoGateway(
            http: $http,
            accessToken: $mercadoPago['access_token'],
            sandbox: $mercadoPago['sandbox'],
            baseUrl: $mercadoPago['base_url'],
            timeout: $mercadoPago['timeout'],
            webhookSecret: $mercadoPago['webhook_secret'],
            backUrl: $mercadoPago['back_url'],
            appUrl: Config::appUrl(),
            webhookUrl: $mercadoPago['webhook_url'],
        ),
        default => throw new RuntimeException('Unsupported payment gateway driver.'),
    };

    $repository = new PaymentRepository($pdo);
    $projection = new OrderProjection($pdo, $gatewayDriver);
    $orders = new OrdersClient(
        $http,
        Config::ordersServiceUrl(),
        Config::ordersInternalSecret(),
        Config::ordersCommandTimeout(),
    );

    $application = new PaymentApplication(
        $pdo,
        new UserSessionAuthenticator(
            $pdo,
            $appKey,
            Config::sessionCookie(),
            Config::sessionLifetimeMinutes(),
            Config::sessionTable(),
        ),
        new CheckoutService($pdo, $repository, $projection, $gateway),
        new VerificationService($repository, $gateway, $orders, $gatewayDriver === 'fake'),
        new WebhookService($repository, $gateway, $orders),
        $projection,
        new InternalRequestVerifier(
            Config::internalSecret(),
            Config::internalClockSkewSeconds(),
        ),
        $repository,
        Config::reconcileBatchSize(),
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
        "[payments-service] database error: %s\n",
        (string) $exception->getCode(),
    ));

    (new JsonResponse(['message' => 'Payments temporarily unavailable.'], 503))->send();
} catch (Throwable $exception) {
    error_log(sprintf(
        "[payments-service] unexpected error: %s: %s\n",
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
