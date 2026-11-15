<?php

declare(strict_types=1);

use RifaCloud\RafflesService\Admin\AdminSessionAuthenticator;
use RifaCloud\RafflesService\Admin\LaravelCrypto;
use RifaCloud\RafflesService\Config;
use RifaCloud\RafflesService\Database;
use RifaCloud\RafflesService\Proposals\AdminProposalApplication;
use RifaCloud\RafflesService\Proposals\ProposalApplication;
use RifaCloud\RafflesService\Proposals\ProposalUserAuthenticator;
use RifaCloud\RafflesService\Proposals\RaffleProposalRepository;
use RifaCloud\RafflesService\Proposals\RaffleProposalService;

require __DIR__.'/../src/Config.php';
require __DIR__.'/../src/Database.php';
require __DIR__.'/../src/JsonResponse.php';
require __DIR__.'/../src/RafflePresenter.php';
require __DIR__.'/../src/Admin/AdminSession.php';
require __DIR__.'/../src/Admin/AdminSessionContext.php';
require __DIR__.'/../src/Admin/AdminAuthException.php';
require __DIR__.'/../src/Admin/CsrfTokenMismatchException.php';
require __DIR__.'/../src/Admin/NotFoundException.php';
require __DIR__.'/../src/Admin/ValidationException.php';
require __DIR__.'/../src/Admin/LaravelCrypto.php';
require __DIR__.'/../src/Admin/SessionPayload.php';
require __DIR__.'/../src/Admin/AdminSessionAuthenticator.php';
require __DIR__.'/../src/Admin/Pagination.php';
require __DIR__.'/../src/Admin/MalformedJsonException.php';
require __DIR__.'/../src/Proposals/ProposalUser.php';
require __DIR__.'/../src/Proposals/ProposalConflictException.php';
require __DIR__.'/../src/Proposals/ProposalRateLimitException.php';
require __DIR__.'/../src/Proposals/ProposalPresenter.php';
require __DIR__.'/../src/Proposals/RaffleProposalRepository.php';
require __DIR__.'/../src/Proposals/RaffleProposalService.php';
require __DIR__.'/../src/Proposals/ProposalUserAuthenticator.php';
require __DIR__.'/../src/Proposals/ProposalApplication.php';
require __DIR__.'/../src/Proposals/AdminProposalApplication.php';

/**
 * S13 raffle proposal integration test.
 *
 * Refuses to run unless RAFFLES_TEST_DB_DATABASE is exactly rifacloud_test.
 * It creates disposable users, sessions and proposals, then removes every
 * fixture in finally. `rifacloud` is never touched.
 */

$database = Config::database();
$database['database'] = (string) (getenv('RAFFLES_TEST_DB_DATABASE') ?: '');

if ($database['database'] !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: RAFFLES_TEST_DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

$appKey = Config::appKey();

if ($appKey === '') {
    fwrite(STDERR, "APP_KEY is required to build the session cookies.\n");
    exit(2);
}

$key = LaravelCrypto::key($appKey);
$servicePdo = Database::connect($database, readOnly: false);

$fixtureDatabase = $database;
$fixtureDatabase['username'] = (string) (getenv('RAFFLES_FIXTURE_DB_USERNAME') ?: $database['username']);
$fixtureDatabase['password'] = (string) (getenv('RAFFLES_FIXTURE_DB_PASSWORD') ?: $database['password']);
$fixturePdo = Database::connect($fixtureDatabase, readOnly: false);

$prefix = 's13-proposal-'.bin2hex(random_bytes(4));
$checks = 0;
$failures = 0;
$userIds = [];
$proposalIds = [];
$sessionIds = [];
$rafflesBefore = (int) $fixturePdo->query('SELECT COUNT(*) FROM raffles')->fetchColumn();

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
    return laravelEncrypt(LaravelCrypto::cookiePrefix($cookieName, $key).$sessionId, $key);
}

function xsrfCookie(string $token, string $key): string
{
    return laravelEncrypt(LaravelCrypto::cookiePrefix('XSRF-TOKEN', $key).$token, $key);
}

function check(bool $condition, string $message): void
{
    global $checks, $failures;

    $checks++;

    if ($condition) {
        echo "PASS  {$message}\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $cookies
 * @param  array<string, string>  $headers
 * @return array{status: int, payload: array<string, mixed>, headers: array<string, string>}
 */
function callPublic(
    ProposalApplication $app,
    string $method,
    string $path,
    array $body = [],
    array $cookies = [],
    array $headers = [],
): array {
    $raw = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);
    $response = $app->handle($method, $path, $headers, $cookies, $raw);

    return [
        'status' => $response->status,
        'payload' => $response->payload,
        'headers' => $response->headers,
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $cookies
 * @param  array<string, string>  $headers
 * @return array{status: int, payload: array<string, mixed>, headers: array<string, string>}
 */
function callAdmin(
    AdminProposalApplication $app,
    string $method,
    string $path,
    array $body = [],
    array $cookies = [],
    array $headers = [],
    array $query = [],
): array {
    $raw = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);
    $response = $app->handle(
        $method,
        $path,
        $query,
        $headers,
        $cookies,
        $raw,
        'http://localhost',
    );

    return [
        'status' => $response->status,
        'payload' => $response->payload,
        'headers' => $response->headers,
    ];
}

function proposalPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Rifa de prueba S13',
        'description' => 'Propuesta creada por la prueba de integración de propuestas.',
        'privacy_accepted' => true,
    ], $overrides);
}

try {
    $insertUser = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO users (name, email, phone, password, role, created_at, updated_at)
            VALUES (:name, :email, :phone, :password, :role, NOW(), NOW())
        SQL,
    );

    $passwordHash = password_hash('s13-test-password', PASSWORD_BCRYPT);
    $passwordMarker = hash_hmac('sha256', $passwordHash, $appKey);

    $insertUser->execute([
        ':name' => 'S13 Proposer',
        ':email' => $prefix.'-user@example.invalid',
        ':phone' => '+573001112233',
        ':password' => $passwordHash,
        ':role' => 'USER',
    ]);
    $userId = (int) $fixturePdo->lastInsertId();
    $userIds[] = $userId;

    $insertUser->execute([
        ':name' => 'S13 Admin',
        ':email' => $prefix.'-admin@example.invalid',
        ':phone' => '0000000000',
        ':password' => $passwordHash,
        ':role' => 'ADMIN',
    ]);
    $adminId = (int) $fixturePdo->lastInsertId();
    $userIds[] = $adminId;

    $userCsrf = bin2hex(random_bytes(20));
    $adminCsrf = bin2hex(random_bytes(20));
    $userSessionId = bin2hex(random_bytes(20));
    $adminSessionId = bin2hex(random_bytes(20));
    $sessionIds = [$userSessionId, $adminSessionId];

    $insertSession = $fixturePdo->prepare(
        <<<'SQL'
            INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity)
            VALUES (:id, :user_id, '127.0.0.1', 's13-test', :payload, :last_activity)
        SQL,
    );

    $sessionPayload = static fn (string $csrf, int $id): string => base64_encode(json_encode([
        '_token' => $csrf,
        'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => $id,
        'password_hash_web' => $passwordMarker,
    ], JSON_THROW_ON_ERROR));

    $insertSession->execute([
        ':id' => $userSessionId,
        ':user_id' => $userId,
        ':payload' => $sessionPayload($userCsrf, $userId),
        ':last_activity' => time(),
    ]);
    $insertSession->execute([
        ':id' => $adminSessionId,
        ':user_id' => $adminId,
        ':payload' => $sessionPayload($adminCsrf, $adminId),
        ':last_activity' => time(),
    ]);

    $cookieName = 'rifacloud-session';
    $userCookies = [
        $cookieName => sessionCookie($userSessionId, $cookieName, $key),
        'XSRF-TOKEN' => xsrfCookie($userCsrf, $key),
    ];
    $adminCookies = [
        $cookieName => sessionCookie($adminSessionId, $cookieName, $key),
        'XSRF-TOKEN' => xsrfCookie($adminCsrf, $key),
    ];
    $userHeaders = [
        'content-type' => 'application/json',
        'x-xsrf-token' => $userCookies['XSRF-TOKEN'],
    ];
    $adminHeaders = [
        'content-type' => 'application/json',
        'x-xsrf-token' => $adminCookies['XSRF-TOKEN'],
    ];

    $sessions = new AdminSessionAuthenticator($servicePdo, $appKey, $cookieName, 120, 'sessions');
    $repository = new RaffleProposalRepository($servicePdo);
    $service = new RaffleProposalService($repository, 10, 0, '2026-11-14');
    $publicApp = new ProposalApplication(
        $service,
        new ProposalUserAuthenticator($servicePdo, $sessions),
    );
    $adminApp = new AdminProposalApplication($service, $sessions);

    // ---- Schema ---------------------------------------------------------
    $columns = $fixturePdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = 'rifacloud_test' AND TABLE_NAME = 'raffle_proposals'",
    )->fetchAll(PDO::FETCH_COLUMN);
    $requiredColumns = [
        'id', 'user_id', 'title', 'description', 'contact_name', 'contact_email',
        'contact_phone', 'privacy_version', 'privacy_accepted_at', 'status',
        'decision_note', 'decided_by', 'decided_at', 'created_at', 'updated_at',
    ];
    check(
        array_diff($requiredColumns, $columns) === [],
        'raffle_proposals has every required column',
    );

    // ---- Authentication and CSRF ---------------------------------------
    $anonymous = callPublic($publicApp, 'POST', '/api/proposals', proposalPayload());
    check($anonymous['status'] === 401, 'submission without a session returns 401');

    $anonymousAdmin = callAdmin($adminApp, 'GET', '/api/admin/proposals');
    check($anonymousAdmin['status'] === 401, 'admin list without a session returns 401');

    $noCsrf = callPublic(
        $publicApp,
        'POST',
        '/api/proposals',
        proposalPayload(),
        $userCookies,
        ['content-type' => 'application/json'],
    );
    check($noCsrf['status'] === 419, 'authenticated submission without CSRF returns 419');

    $nonAdmin = callAdmin($adminApp, 'GET', '/api/admin/proposals', [], $userCookies);
    check($nonAdmin['status'] === 403, 'USER role cannot read admin proposals (403)');

    // ---- Submission, spoofing and contact source -----------------------
    $spoofed = proposalPayload([
        'user_id' => $adminId,
        'contact_name' => 'Spoofed Name',
        'contact_email' => 'spoofed@example.invalid',
        'contact_phone' => '9999999999',
        'privacy_version' => '1999-01-01',
        'status' => 'APPROVED',
    ]);
    $created = callPublic(
        $publicApp,
        'POST',
        '/api/proposals',
        $spoofed,
        $userCookies,
        $userHeaders,
    );

    check($created['status'] === 201, 'authenticated submission returns 201');
    $proposalId = (int) ($created['payload']['data']['id'] ?? 0);
    check($proposalId > 0, 'submission returns the proposal id');
    $proposalIds[] = $proposalId;

    $createdJson = json_encode($created['payload'], JSON_THROW_ON_ERROR);
    check(
        ! str_contains($createdJson, 'Spoofed Name')
        && ! str_contains($createdJson, 'spoofed@example.invalid')
        && ! str_contains($createdJson, '9999999999'),
        'public response does not echo spoofed contact data',
    );
    check(
        ! array_key_exists('contact', $created['payload']['data'] ?? [])
        && ! array_key_exists('user_id', $created['payload']['data'] ?? [])
        && ! array_key_exists('email', $created['payload']['data'] ?? []),
        'public response has no contact or identity fields',
    );

    $row = $fixturePdo->prepare(
        'SELECT user_id, contact_name, contact_email, contact_phone, privacy_version,
                privacy_accepted_at, status
         FROM raffle_proposals WHERE id = :id',
    );
    $row->execute([':id' => $proposalId]);
    $stored = $row->fetch();

    check((int) $stored['user_id'] === $userId, 'proposal is linked to the session user, not the body');
    check($stored['contact_name'] === 'S13 Proposer', 'contact name comes from users table');
    check($stored['contact_email'] === $prefix.'-user@example.invalid', 'contact email comes from users table');
    check($stored['contact_phone'] === '+573001112233', 'contact phone comes from users table');
    check($stored['privacy_version'] === '2026-11-14', 'consent version is set by the server');
    check($stored['privacy_accepted_at'] !== null, 'consent timestamp is recorded');
    check($stored['status'] === 'PENDING', 'a new proposal starts PENDING');

    // ---- Validation -----------------------------------------------------
    $shortTitle = callPublic(
        $publicApp,
        'POST',
        '/api/proposals',
        proposalPayload(['title' => 'Cort']),
        $userCookies,
        $userHeaders,
    );
    check(
        $shortTitle['status'] === 422
        && isset($shortTitle['payload']['errors']['title']),
        'short title returns 422 on title',
    );

    $shortDescription = callPublic(
        $publicApp,
        'POST',
        '/api/proposals',
        proposalPayload(['description' => 'Muy corta']),
        $userCookies,
        $userHeaders,
    );
    check(
        $shortDescription['status'] === 422
        && isset($shortDescription['payload']['errors']['description']),
        'short description returns 422 on description',
    );

    $noConsent = callPublic(
        $publicApp,
        'POST',
        '/api/proposals',
        proposalPayload(['privacy_accepted' => false]),
        $userCookies,
        $userHeaders,
    );
    check(
        $noConsent['status'] === 422
        && isset($noConsent['payload']['errors']['privacy_accepted']),
        'missing privacy consent returns 422',
    );

    // ---- Rate limiting (daily and cooldown) ----------------------------
    $limitedService = new RaffleProposalService($repository, 2, 0, '2026-11-14');
    $limitedApp = new ProposalApplication(
        $limitedService,
        new ProposalUserAuthenticator($servicePdo, $sessions),
    );

    $second = callPublic(
        $limitedApp,
        'POST',
        '/api/proposals',
        proposalPayload(['title' => 'Segunda propuesta S13']),
        $userCookies,
        $userHeaders,
    );
    check($second['status'] === 201, 'second proposal under the daily limit is accepted');
    $proposalIds[] = (int) $second['payload']['data']['id'];

    $third = callPublic(
        $limitedApp,
        'POST',
        '/api/proposals',
        proposalPayload(['title' => 'Tercera propuesta S13']),
        $userCookies,
        $userHeaders,
    );
    check($third['status'] === 429, 'daily limit returns 429');
    check(($third['headers']['Retry-After'] ?? '') !== '', 'daily limit returns Retry-After');

    $cooldownService = new RaffleProposalService($repository, 10, 3600, '2026-11-14');
    $cooldownApp = new ProposalApplication(
        $cooldownService,
        new ProposalUserAuthenticator($servicePdo, $sessions),
    );
    $cooldown = callPublic(
        $cooldownApp,
        'POST',
        '/api/proposals',
        proposalPayload(['title' => 'Propuesta en cooldown S13']),
        $userCookies,
        $userHeaders,
    );
    check($cooldown['status'] === 429, 'immediate repeat submission returns 429 cooldown');
    check(
        (int) ($cooldown['headers']['Retry-After'] ?? 0) > 0,
        'cooldown returns a positive Retry-After',
    );

    // ---- Admin list, detail and decisions ------------------------------
    $list = callAdmin($adminApp, 'GET', '/api/admin/proposals', [], $adminCookies);
    check($list['status'] === 200, 'ADMIN can list proposals');
    check(($list['payload']['meta']['total'] ?? 0) >= 2, 'admin list includes the submitted proposals');
    $firstListed = $list['payload']['data'][0] ?? [];
    check(
        isset($firstListed['contact']['name'], $firstListed['contact']['email'], $firstListed['contact']['phone']),
        'admin list exposes contact only to admins',
    );

    $metrics = callAdmin($adminApp, 'GET', '/api/admin/proposals/metrics', [], $adminCookies);
    check(
        $metrics['status'] === 200 && ($metrics['payload']['data']['pending'] ?? 0) >= 2,
        'pending counter is available to admins',
    );

    $detail = callAdmin(
        $adminApp,
        'GET',
        '/api/admin/proposals/'.$proposalId,
        [],
        $adminCookies,
    );
    check($detail['status'] === 200, 'ADMIN can read a proposal detail');
    check(
        ($detail['payload']['data']['contact']['phone'] ?? '') === '+573001112233',
        'detail includes the contact phone',
    );

    $approve = callAdmin(
        $adminApp,
        'POST',
        '/api/admin/proposals/'.$proposalId.'/approve',
        ['note' => 'Cumple las condiciones del prototipo.'],
        $adminCookies,
        $adminHeaders,
    );
    check($approve['status'] === 200, 'ADMIN can approve a pending proposal');
    check(($approve['payload']['data']['status'] ?? '') === 'APPROVED', 'approval persists APPROVED');
    check(
        ($approve['payload']['data']['decision']['by']['id'] ?? 0) === $adminId,
        'approval records the deciding admin',
    );
    check(
        ($approve['payload']['data']['decision']['decided_at'] ?? null) !== null,
        'approval records the decision timestamp',
    );
    check(
        ($approve['payload']['data']['decision']['note'] ?? null)
        === 'Cumple las condiciones del prototipo.',
        'approval records the internal note',
    );

    $again = callAdmin(
        $adminApp,
        'POST',
        '/api/admin/proposals/'.$proposalId.'/approve',
        [],
        $adminCookies,
        $adminHeaders,
    );
    check($again['status'] === 409, 'a second decision on the same proposal returns 409');

    $rejectId = (int) $second['payload']['data']['id'];
    $reject = callAdmin(
        $adminApp,
        'POST',
        '/api/admin/proposals/'.$rejectId.'/reject',
        ['note' => 'Falta detalle del premio.'],
        $adminCookies,
        $adminHeaders,
    );
    check($reject['status'] === 200, 'ADMIN can reject a pending proposal');
    check(($reject['payload']['data']['status'] ?? '') === 'REJECTED', 'rejection persists REJECTED');

    // ---- No side effects outside proposals -----------------------------
    $rafflesAfter = (int) $fixturePdo->query('SELECT COUNT(*) FROM raffles')->fetchColumn();
    check($rafflesAfter === $rafflesBefore, 'approval does not create a raffle');
    $raffleMatch = $fixturePdo->prepare('SELECT COUNT(*) FROM raffles WHERE title = :title');
    $raffleMatch->execute([':title' => 'Rifa de prueba S13']);
    check((int) $raffleMatch->fetchColumn() === 0, 'no raffle exists with the proposal title');

    $proposalCount = (int) $fixturePdo->query('SELECT COUNT(*) FROM raffle_proposals')->fetchColumn();
    check($proposalCount >= 2, 'proposals are persisted in their own table');
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, 'S13 proposal test failed: '.$exception::class.' - '.$exception->getMessage()."\n");
    fwrite(STDERR, $exception->getTraceAsString()."\n");
} finally {
    try {
        if ($proposalIds !== []) {
            $ids = implode(',', array_map('intval', array_unique($proposalIds)));
            $fixturePdo->exec("DELETE FROM raffle_proposals WHERE id IN ({$ids})");
        }

        if ($sessionIds !== []) {
            $ids = implode(',', array_map(static fn (string $id): string => $fixturePdo->quote($id), $sessionIds));
            $fixturePdo->exec("DELETE FROM sessions WHERE id IN ({$ids})");
        }

        if ($userIds !== []) {
            $ids = implode(',', array_map('intval', $userIds));
            $fixturePdo->exec("DELETE FROM users WHERE id IN ({$ids})");
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cleanup warning: '.$exception->getMessage()."\n");
    }
}

echo "\nS13 proposals: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
