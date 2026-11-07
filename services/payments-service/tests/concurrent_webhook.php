<?php

declare(strict_types=1);

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
 * Child worker: sends one simulated webhook after the shared barrier appears.
 *
 * Two of these run as separate PHP processes with the same notification, the
 * same database and the real orders-service test container, proving that
 * duplicate simultaneous webhooks cannot sell numbers twice.
 */

date_default_timezone_set('UTC');

$database = Config::database();
$database['database'] = (string) (getenv('PAYMENTS_TEST_DB_DATABASE') ?: '');

if ($database['database'] !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: PAYMENTS_TEST_DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

$payloadFile = (string) (getenv('PAYMENTS_TEST_PAYLOAD_FILE') ?: '');
$barrierFile = (string) (getenv('PAYMENTS_TEST_BARRIER_FILE') ?: '');

if ($payloadFile === '' || $barrierFile === '' || ! is_file($payloadFile)) {
    fwrite(STDERR, "Missing webhook concurrency arguments.\n");
    exit(2);
}

$payload = (string) file_get_contents($payloadFile);
$pdo = Database::connect($database);
$gateway = new FakePaymentGateway('APPROVED', 'https://rifacloud.test');
$repository = new PaymentRepository($pdo);
$projection = new OrderProjection($pdo, 'fake');
$orders = new OrdersClient(
    new StreamHttpClient(),
    Config::ordersServiceUrl(),
    Config::ordersInternalSecret(),
    10,
);

$app = new PaymentApplication(
    $pdo,
    new UserSessionAuthenticator(
        $pdo,
        Config::appKey(),
        Config::sessionCookie(),
        Config::sessionLifetimeMinutes(),
        Config::sessionTable(),
    ),
    new CheckoutService($pdo, $repository, $projection, $gateway),
    new VerificationService($repository, $gateway, $orders, true),
    new WebhookService($repository, $gateway, $orders),
    $projection,
    new InternalRequestVerifier(Config::internalSecret(), 60),
    50,
);

while (! is_file($barrierFile)) {
    usleep(20_000);
}

$response = $app->handle(
    'POST',
    '/api/webhooks/mercadopago',
    [],
    ['content-type' => 'application/json'],
    [],
    $payload,
    'http://localhost',
);

echo json_encode([
    'status' => $response->status,
    'payload' => $response->payload,
], JSON_THROW_ON_ERROR);
