<?php

declare(strict_types=1);

use RifaCloud\RafflesService\Admin\AdminApplication;
use RifaCloud\RafflesService\Admin\AdminAuthException;
use RifaCloud\RafflesService\Admin\AdminSessionAuthenticator;
use RifaCloud\RafflesService\Admin\CsrfTokenMismatchException;
use RifaCloud\RafflesService\Admin\MalformedJsonException;
use RifaCloud\RafflesService\Admin\NotFoundException;
use RifaCloud\RafflesService\Admin\RaffleAdminRepository;
use RifaCloud\RafflesService\Admin\RaffleAdminService;
use RifaCloud\RafflesService\Admin\RaffleValidator;
use RifaCloud\RafflesService\Admin\ValidationException;
use RifaCloud\RafflesService\Application;
use RifaCloud\RafflesService\Config;
use RifaCloud\RafflesService\Database;
use RifaCloud\RafflesService\JsonResponse;
use RifaCloud\RafflesService\RafflePresenter;
use RifaCloud\RafflesService\RaffleRepository;

require __DIR__.'/../src/Config.php';
require __DIR__.'/../src/Database.php';
require __DIR__.'/../src/JsonResponse.php';
require __DIR__.'/../src/RafflePresenter.php';
require __DIR__.'/../src/RaffleRepository.php';
require __DIR__.'/../src/Application.php';
require __DIR__.'/../src/Admin/AdminSession.php';
require __DIR__.'/../src/Admin/AdminSessionContext.php';
require __DIR__.'/../src/Admin/AdminAuthException.php';
require __DIR__.'/../src/Admin/CsrfTokenMismatchException.php';
require __DIR__.'/../src/Admin/NotFoundException.php';
require __DIR__.'/../src/Admin/ValidationException.php';
require __DIR__.'/../src/Admin/LaravelCrypto.php';
require __DIR__.'/../src/Admin/SessionPayload.php';
require __DIR__.'/../src/Admin/AdminSessionAuthenticator.php';
require __DIR__.'/../src/Admin/RaffleValidator.php';
require __DIR__.'/../src/Admin/RaffleAdminRepository.php';
require __DIR__.'/../src/Admin/RaffleAdminService.php';
require __DIR__.'/../src/Admin/Pagination.php';
require __DIR__.'/../src/Admin/MalformedJsonException.php';
require __DIR__.'/../src/Admin/AdminApplication.php';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedPath = parse_url($requestUri, PHP_URL_PATH);
$path = is_string($parsedPath) ? $parsedPath : '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isAdminPath = preg_match('#^/api/admin/raffles(?:/|$)#', $path) === 1;

try {
    $pdo = Database::connect(Config::database(), readOnly: ! $isAdminPath);

    if ('/'.trim($path, '/') === '/health') {
        $pdo->query('SELECT 1');
        (new JsonResponse(['status' => 'ok']))->send();

        return;
    }

    if ($isAdminPath) {
        $appKey = Config::appKey();

        if ($appKey === '') {
            fwrite(STDERR, "[raffles-service] admin auth misconfigured: APP_KEY is empty\n");

            (new JsonResponse(['message' => 'Admin temporarily unavailable.'], 503))->send();

            return;
        }

        $repository = new RaffleAdminRepository(
            $pdo,
            Config::reservationMinutes(),
            Config::maxNumbersPerOrder(),
        );

        $admin = new AdminApplication(
            new RaffleAdminService(
                $pdo,
                $repository,
                new RaffleValidator(Config::raffleMaxNumbers()),
            ),
            new AdminSessionAuthenticator(
                $pdo,
                $appKey,
                Config::sessionCookie(),
                Config::sessionLifetimeMinutes(),
                Config::sessionTable(),
            ),
        );

        $admin
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

        return;
    }

    $repository = new RaffleRepository(
        $pdo,
        Config::reservationMinutes(),
        Config::maxNumbersPerOrder(),
    );

    (new Application($repository))
        ->handle($method, $path, $_GET, $scheme.'://'.$host)
        ->send();
} catch (PDOException $exception) {
    fwrite(STDERR, sprintf(
        "[raffles-service] database error: %s\n",
        (string) $exception->getCode(),
    ));

    $message = $isAdminPath
        ? 'Admin temporarily unavailable.'
        : 'Catalog temporarily unavailable.';

    (new JsonResponse(['message' => $message], 503))->send();
} catch (AdminAuthException|CsrfTokenMismatchException|ValidationException|NotFoundException|MalformedJsonException $exception) {
    // AdminApplication already converts these; this is a defensive fallback
    // for an exception raised outside the handler.
    $status = match (true) {
        $exception instanceof AdminAuthException => $exception->status,
        $exception instanceof CsrfTokenMismatchException => 419,
        $exception instanceof ValidationException => 422,
        $exception instanceof NotFoundException => 404,
        default => 400,
    };

    (new JsonResponse(['message' => $exception->getMessage()], $status))->send();
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        "[raffles-service] unexpected error: %s\n",
        $exception::class,
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

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (is_string($value)) {
                $headers[strtolower((string) $name)] = $value;
            }
        }
    }

    return $headers;
}
