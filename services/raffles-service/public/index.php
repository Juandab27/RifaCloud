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
use RifaCloud\RafflesService\Draw\InternalApplication;
use RifaCloud\RafflesService\Draw\InternalRequestVerifier;
use RifaCloud\RafflesService\Draw\RaffleDrawRepository;
use RifaCloud\RafflesService\Draw\RaffleDrawService;
use RifaCloud\RafflesService\JsonResponse;
use RifaCloud\RafflesService\Proposals\AdminProposalApplication;
use RifaCloud\RafflesService\Proposals\ProposalApplication;
use RifaCloud\RafflesService\Proposals\ProposalConflictException;
use RifaCloud\RafflesService\Proposals\ProposalRateLimitException;
use RifaCloud\RafflesService\Proposals\ProposalUserAuthenticator;
use RifaCloud\RafflesService\Proposals\RaffleProposalRepository;
use RifaCloud\RafflesService\Proposals\RaffleProposalService;
use RifaCloud\RafflesService\RafflePresenter;
use RifaCloud\RafflesService\RaffleRepository;

date_default_timezone_set('UTC');

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
require __DIR__.'/../src/Draw/InternalAuthException.php';
require __DIR__.'/../src/Draw/PublicWinnerName.php';
require __DIR__.'/../src/Draw/InternalRequestVerifier.php';
require __DIR__.'/../src/Draw/RaffleDrawRepository.php';
require __DIR__.'/../src/Draw/RaffleDrawService.php';
require __DIR__.'/../src/Draw/InternalApplication.php';
require __DIR__.'/../src/Proposals/ProposalUser.php';
require __DIR__.'/../src/Proposals/ProposalConflictException.php';
require __DIR__.'/../src/Proposals/ProposalRateLimitException.php';
require __DIR__.'/../src/Proposals/ProposalPresenter.php';
require __DIR__.'/../src/Proposals/RaffleProposalRepository.php';
require __DIR__.'/../src/Proposals/RaffleProposalService.php';
require __DIR__.'/../src/Proposals/ProposalUserAuthenticator.php';
require __DIR__.'/../src/Proposals/ProposalApplication.php';
require __DIR__.'/../src/Proposals/AdminProposalApplication.php';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedPath = parse_url($requestUri, PHP_URL_PATH);
$path = is_string($parsedPath) ? $parsedPath : '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isAdminPath = preg_match('#^/api/admin/raffles(?:/|$)#', $path) === 1;
$isInternalPath = preg_match('#^/internal/raffles(?:/|$)#', $path) === 1;
$isProposalsPath = preg_match('#^/api/proposals(?:/|$)#', $path) === 1;
$isAdminProposalsPath = preg_match('#^/api/admin/proposals(?:/|$)#', $path) === 1;

try {
    $pdo = Database::connect(
        Config::database(),
        readOnly: ! ($isAdminPath || $isInternalPath || $isProposalsPath || $isAdminProposalsPath),
    );

    if ('/'.trim($path, '/') === '/health') {
        $pdo->query('SELECT 1');
        (new JsonResponse(['status' => 'ok']))->send();

        return;
    }

    if ($isInternalPath) {
        $secret = Config::internalSecret();

        if ($secret === '') {
            error_log('[raffles-service] internal auth misconfigured: APP_KEY is empty');
            (new JsonResponse(['message' => 'Draw temporarily unavailable.'], 503))->send();

            return;
        }

        $draws = new RaffleDrawService(
            $pdo,
            new RaffleDrawRepository($pdo),
            Config::drawBufferMinutes(),
            Config::drawBatchLimit(),
        );

        (new InternalApplication(
            $draws,
            new InternalRequestVerifier($secret, Config::internalClockSkewSeconds()),
        ))
            ->handle($method, $path, normalizeHeaders(), (string) file_get_contents('php://input'))
            ->send();

        return;
    }

    if ($isProposalsPath || $isAdminProposalsPath) {
        $appKey = Config::appKey();

        if ($appKey === '') {
            error_log('[raffles-service] proposal auth misconfigured: APP_KEY is empty');

            (new JsonResponse(['message' => 'Proposals temporarily unavailable.'], 503))->send();

            return;
        }

        $adminSessions = new AdminSessionAuthenticator(
            $pdo,
            $appKey,
            Config::sessionCookie(),
            Config::sessionLifetimeMinutes(),
            Config::sessionTable(),
        );
        $proposalService = new RaffleProposalService(
            new RaffleProposalRepository($pdo),
            Config::proposalDailyLimit(),
            Config::proposalCooldownSeconds(),
            Config::legalConsentVersion(),
        );

        if ($isAdminProposalsPath) {
            (new AdminProposalApplication($proposalService, $adminSessions))
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

        (new ProposalApplication(
            $proposalService,
            new ProposalUserAuthenticator($pdo, $adminSessions),
        ))
            ->handle(
                $method,
                $path,
                normalizeHeaders(),
                $_COOKIE,
                (string) file_get_contents('php://input'),
            )
            ->send();

        return;
    }

    if ($isAdminPath) {
        $appKey = Config::appKey();

        if ($appKey === '') {
            error_log('[raffles-service] admin auth misconfigured: APP_KEY is empty');

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
                new RaffleValidator(Config::raffleMaxNumbers(), Config::drawBufferMinutes()),
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
    error_log(sprintf(
        '[raffles-service] database error: %s',
        (string) $exception->getCode(),
    ));

    $message = $isInternalPath
        ? 'Draw temporarily unavailable.'
        : ($isProposalsPath || $isAdminProposalsPath
            ? 'Proposals temporarily unavailable.'
            : ($isAdminPath
                ? 'Admin temporarily unavailable.'
                : 'Catalog temporarily unavailable.'));

    (new JsonResponse(['message' => $message], 503))->send();
} catch (AdminAuthException|CsrfTokenMismatchException|ValidationException|NotFoundException|MalformedJsonException|ProposalConflictException|ProposalRateLimitException $exception) {
    // Proposal and admin applications already convert these; this is a
    // defensive fallback for an exception raised outside the handler.
    $status = match (true) {
        $exception instanceof AdminAuthException => $exception->status,
        $exception instanceof CsrfTokenMismatchException => 419,
        $exception instanceof ValidationException => 422,
        $exception instanceof NotFoundException => 404,
        $exception instanceof ProposalConflictException => 409,
        $exception instanceof ProposalRateLimitException => 429,
        default => 400,
    };

    (new JsonResponse(['message' => $exception->getMessage()], $status))->send();
} catch (Throwable $exception) {
    error_log(sprintf(
        '[raffles-service] unexpected error: %s: %s',
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

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (is_string($value)) {
                $headers[strtolower((string) $name)] = $value;
            }
        }
    }

    return $headers;
}
