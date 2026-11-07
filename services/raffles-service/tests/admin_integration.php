<?php

declare(strict_types=1);

use RifaCloud\RafflesService\Admin\AdminApplication;
use RifaCloud\RafflesService\Admin\AdminSessionAuthenticator;
use RifaCloud\RafflesService\Admin\RaffleAdminRepository;
use RifaCloud\RafflesService\Admin\RaffleAdminService;
use RifaCloud\RafflesService\Admin\RaffleValidator;
use RifaCloud\RafflesService\Config;
use RifaCloud\RafflesService\Database;
use RifaCloud\RafflesService\RaffleRepository;

require __DIR__.'/../src/Config.php';
require __DIR__.'/../src/Database.php';
require __DIR__.'/../src/JsonResponse.php';
require __DIR__.'/../src/RafflePresenter.php';
require __DIR__.'/../src/RaffleRepository.php';
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

/**
 * S2 integration test for raffle administration.
 *
 * Refuses to run unless RAFFLES_TEST_DB_DATABASE is exactly `rifacloud_test`.
 * It creates two disposable users (ADMIN and USER), their sessions, and a
 * handful of raffles, then removes all of them in `finally`. The production
 * `rifacloud` schema is never touched.
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

$key = RifaCloud\RafflesService\Admin\LaravelCrypto::key($appKey);
$pdo = Database::connect($database, readOnly: false);
$prefix = 's2-test-'.bin2hex(random_bytes(4));
$failures = 0;
$adminId = null;
$userId = null;
$createdRaffleIds = [];
$cookieName = 'rifacloud-session';
$sessionLifetime = 120;

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
        RifaCloud\RafflesService\Admin\LaravelCrypto::cookiePrefix($cookieName, $key).$sessionId,
        $key,
    );
}

function xsrfCookie(string $token, string $key): string
{
    return laravelEncrypt(
        RifaCloud\RafflesService\Admin\LaravelCrypto::cookiePrefix('XSRF-TOKEN', $key).$token,
        $key,
    );
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $cookies
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $query
 * @return array{status: int, payload: array<string, mixed>}
 */
function call(
    AdminApplication $app,
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

    return ['status' => $response->status, 'payload' => $response->payload];
}

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

function payload(array $overrides = []): array
{
    return array_merge([
        'title' => 'S2 test raffle',
        'description' => 'Created by the S2 integration test.',
        'price_per_number' => 5000,
        'min_number' => 1,
        'max_number' => 100,
    ], $overrides);
}

try {
    $insertUser = $pdo->prepare(
        <<<'SQL'
            INSERT INTO users (name, email, phone, password, role, created_at, updated_at)
            VALUES (:name, :email, :phone, :password, :role, NOW(), NOW())
        SQL,
    );

    $passwordHash = password_hash('s2-test-password', PASSWORD_BCRYPT);
    $passwordHashWeb = hash_hmac('sha256', $passwordHash, $appKey);

    $insertUser->execute([
        ':name' => 'S2 admin',
        ':email' => $prefix.'-admin@example.invalid',
        ':phone' => '0000000000',
        ':password' => $passwordHash,
        ':role' => 'ADMIN',
    ]);
    $adminId = (int) $pdo->lastInsertId();

    $insertUser->execute([
        ':name' => 'S2 user',
        ':email' => $prefix.'-user@example.invalid',
        ':phone' => '0000000000',
        ':password' => $passwordHash,
        ':role' => 'USER',
    ]);
    $userId = (int) $pdo->lastInsertId();

    $adminCsrf = bin2hex(random_bytes(20));
    $userCsrf = bin2hex(random_bytes(20));
    $adminSessionId = bin2hex(random_bytes(20));
    $userSessionId = bin2hex(random_bytes(20));
    $expiredSessionId = bin2hex(random_bytes(20));
    $revokedSessionId = bin2hex(random_bytes(20));

    $insertSession = $pdo->prepare(
        <<<'SQL'
            INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity)
            VALUES (:id, :user_id, '127.0.0.1', 's2-test', :payload, :last_activity)
        SQL,
    );

    $sessionPayload = static fn (string $csrf, int $userId): string => base64_encode(json_encode([
        '_token' => $csrf,
        'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => $userId,
        'password_hash_web' => $passwordHashWeb,
    ], JSON_THROW_ON_ERROR));

    $insertSession->execute([
        ':id' => $adminSessionId,
        ':user_id' => $adminId,
        ':payload' => $sessionPayload($adminCsrf, $adminId),
        ':last_activity' => time(),
    ]);
    $insertSession->execute([
        ':id' => $userSessionId,
        ':user_id' => $userId,
        ':payload' => $sessionPayload($userCsrf, $userId),
        ':last_activity' => time(),
    ]);
    $insertSession->execute([
        ':id' => $expiredSessionId,
        ':user_id' => $adminId,
        ':payload' => $sessionPayload($adminCsrf, $adminId),
        ':last_activity' => time() - (($sessionLifetime + 5) * 60),
    ]);
    $insertSession->execute([
        ':id' => $revokedSessionId,
        ':user_id' => $adminId,
        ':payload' => base64_encode(json_encode([
            '_token' => $adminCsrf,
            'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => $adminId,
            'password_hash_web' => hash_hmac('sha256', password_hash('old-password', PASSWORD_BCRYPT), $appKey),
        ], JSON_THROW_ON_ERROR)),
        ':last_activity' => time(),
    ]);

    $adminCookies = [
        $cookieName => sessionCookie($adminSessionId, $cookieName, $key),
        'XSRF-TOKEN' => xsrfCookie($adminCsrf, $key),
    ];
    $userCookies = [
        $cookieName => sessionCookie($userSessionId, $cookieName, $key),
        'XSRF-TOKEN' => xsrfCookie($userCsrf, $key),
    ];
    $expiredCookies = [
        $cookieName => sessionCookie($expiredSessionId, $cookieName, $key),
        'XSRF-TOKEN' => xsrfCookie($adminCsrf, $key),
    ];
    $revokedCookies = [
        $cookieName => sessionCookie($revokedSessionId, $cookieName, $key),
        'XSRF-TOKEN' => xsrfCookie($adminCsrf, $key),
    ];

    $adminHeaders = [
        'content-type' => 'application/json',
        'x-xsrf-token' => $adminCookies['XSRF-TOKEN'],
    ];
    $userHeaders = [
        'content-type' => 'application/json',
        'x-xsrf-token' => $userCookies['XSRF-TOKEN'],
    ];

    $repository = new RaffleAdminRepository($pdo, 10, 50);
    $app = new AdminApplication(
        new RaffleAdminService($pdo, $repository, new RaffleValidator(10000)),
        new AdminSessionAuthenticator($pdo, $appKey, $cookieName, $sessionLifetime, 'sessions'),
    );

    // ---- Authorization -------------------------------------------------
    $anonymous = call($app, 'GET', '/api/admin/raffles');
    check($anonymous['status'] === 401, 'GET list without a session returns 401');

    $tamperedCookies = [$cookieName => substr($adminCookies[$cookieName], 0, -5)];
    $tampered = call($app, 'GET', '/api/admin/raffles', [], $tamperedCookies);
    check($tampered['status'] === 401, 'A tampered session cookie returns 401');

    $expired = call($app, 'GET', '/api/admin/raffles', [], $expiredCookies);
    check($expired['status'] === 401, 'An expired session returns 401');

    $revoked = call($app, 'GET', '/api/admin/raffles', [], $revokedCookies);
    check($revoked['status'] === 401, 'A session issued before a password change returns 401');

    $nonAdmin = call($app, 'GET', '/api/admin/raffles', [], $userCookies);
    check($nonAdmin['status'] === 403, 'A USER role gets 403 on admin routes');

    $nonAdminWrite = call($app, 'POST', '/api/admin/raffles', payload(), $userCookies, $userHeaders);
    check($nonAdminWrite['status'] === 403, 'A USER role cannot create raffles (403)');

    $noCsrf = call($app, 'POST', '/api/admin/raffles', payload(), $adminCookies, [
        'content-type' => 'application/json',
    ]);
    check($noCsrf['status'] === 419, 'POST without a CSRF token returns 419');

    $sameOrigin = call($app, 'POST', '/api/admin/raffles', payload(), $adminCookies, [
        'content-type' => 'application/json',
        'sec-fetch-site' => 'same-origin',
    ]);
    check($sameOrigin['status'] === 201, 'same-origin browser requests pass CSRF like Laravel 12');
    if ($sameOrigin['status'] === 201) {
        $createdRaffleIds[] = (int) $sameOrigin['payload']['data']['id'];
    }

    $badJson = $app->handle(
        'POST',
        '/api/admin/raffles',
        [],
        $adminHeaders,
        $adminCookies,
        '{"title":',
        'http://localhost',
    );
    check($badJson->status === 400, 'Malformed JSON returns 400');

    $unknown = call($app, 'GET', '/api/admin/raffles/not-a-number', [], $adminCookies);
    check($unknown['status'] === 404, 'An unknown admin path returns 404');

    $wrongMethod = call($app, 'PUT', '/api/admin/raffles', [], $adminCookies, $adminHeaders);
    check(
        $wrongMethod['status'] === 405 && ($wrongMethod['payload']['message'] ?? '') === 'Method Not Allowed',
        'An unsupported method returns 405',
    );

    // ---- List / create ------------------------------------------------
    $list = call($app, 'GET', '/api/admin/raffles', [], $adminCookies);
    check($list['status'] === 200, 'GET /api/admin/raffles returns 200 for an admin');
    check(isset($list['payload']['data'], $list['payload']['links'], $list['payload']['meta']), 'The list keeps data/links/meta');
    check((int) ($list['payload']['meta']['per_page'] ?? 0) === 15, 'The admin list keeps per_page=15');

    $created = call($app, 'POST', '/api/admin/raffles', payload(), $adminCookies, $adminHeaders);
    check($created['status'] === 201, 'POST creates a raffle with 201');
    $createdId = (int) ($created['payload']['data']['id'] ?? 0);
    if ($createdId > 0) {
        $createdRaffleIds[] = $createdId;
    }
    check(($created['payload']['data']['status'] ?? '') === 'DRAFT', 'New raffles start as DRAFT');
    check((int) ($created['payload']['data']['total_numbers'] ?? 0) === 100, 'total_numbers is min/max inclusive');
    check((int) ($created['payload']['data']['numbers_count'] ?? 0) === 100, 'numbers_count reflects generated rows');

    $row = $pdo->query('SELECT * FROM raffles WHERE id = '.(int) $createdId)->fetch();
    $numberStats = $pdo->query(
        'SELECT COUNT(*) AS total, COUNT(DISTINCT number) AS unique_numbers, MIN(number) AS min_number, MAX(number) AS max_number FROM raffle_numbers WHERE raffle_id = '.(int) $createdId,
    )->fetch();

    check((string) ($row['status'] ?? '') === 'DRAFT', 'The stored status is DRAFT');
    check((int) ($row['created_by'] ?? 0) === $adminId, 'created_by is the authenticated admin id');
    check((int) $numberStats['total'] === 100, '100 number rows were generated');
    check((int) $numberStats['unique_numbers'] === 100, 'Number rows are unique');
    check((int) $numberStats['min_number'] === 1 && (int) $numberStats['max_number'] === 100, 'The generated range is exact');
    check(
        (int) $pdo->query("SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = ".(int) $createdId." AND status = 'AVAILABLE'")->fetchColumn() === 100,
        'All generated numbers start AVAILABLE',
    );

    $chunked = call($app, 'POST', '/api/admin/raffles', payload(['max_number' => 2500]), $adminCookies, $adminHeaders);
    $chunkedId = (int) ($chunked['payload']['data']['id'] ?? 0);
    if ($chunkedId > 0) {
        $createdRaffleIds[] = $chunkedId;
    }
    check($chunked['status'] === 201, 'Creating a 2500-number raffle returns 201');
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = '.(int) $chunkedId)->fetchColumn() === 2500,
        'Generation handles ranges larger than one chunk (2500 rows)',
    );

    // ---- Validation ----------------------------------------------------
    $invalidRange = call($app, 'POST', '/api/admin/raffles', payload(['min_number' => 50, 'max_number' => 10]), $adminCookies, $adminHeaders);
    check($invalidRange['status'] === 422 && isset($invalidRange['payload']['errors']['max_number']), 'min_number > max_number is rejected on max_number');

    $tooLarge = call($app, 'POST', '/api/admin/raffles', payload(['max_number' => 10001]), $adminCookies, $adminHeaders);
    check($tooLarge['status'] === 422 && isset($tooLarge['payload']['errors']['max_number']), 'The number range limit is enforced');

    $equalSchedule = call($app, 'POST', '/api/admin/raffles', payload([
        'starts_at' => '2026-11-01 10:00:00',
        'ends_at' => '2026-11-01 10:00:00',
    ]), $adminCookies, $adminHeaders);
    check($equalSchedule['status'] === 422 && isset($equalSchedule['payload']['errors']['ends_at']), 'Equal start/end dates are rejected');

    // ---- Show / update ------------------------------------------------
    $shown = call($app, 'GET', '/api/admin/raffles/'.$createdId, [], $adminCookies);
    check($shown['status'] === 200 && (int) ($shown['payload']['data']['id'] ?? 0) === $createdId, 'GET show returns the raffle');
    check(($shown['payload']['data']['reservation_minutes'] ?? null) === 10, 'The resource keeps reservation_minutes');
    check(($shown['payload']['data']['max_numbers_per_order'] ?? null) === 50, 'The resource keeps max_numbers_per_order');

    $updated = call($app, 'PUT', '/api/admin/raffles/'.$createdId, payload([
        'title' => 'S2 updated raffle',
        'price_per_number' => 7500,
    ]), $adminCookies, $adminHeaders);
    check($updated['status'] === 200, 'PUT updates a DRAFT raffle');
    check(($updated['payload']['data']['title'] ?? '') === 'S2 updated raffle', 'The title update is persisted');
    check((int) ($updated['payload']['data']['price_per_number'] ?? 0) === 7500, 'The price update is persisted in cents');

    $rangeUpdate = call($app, 'PUT', '/api/admin/raffles/'.$createdId, payload([
        'min_number' => 5,
        'max_number' => 9,
    ]), $adminCookies, $adminHeaders);
    check($rangeUpdate['status'] === 200 && (int) ($rangeUpdate['payload']['data']['numbers_count'] ?? 0) === 5, 'A DRAFT range change regenerates numbers');
    $numbers = $pdo->query('SELECT number FROM raffle_numbers WHERE raffle_id = '.(int) $createdId.' ORDER BY number')->fetchAll(PDO::FETCH_COLUMN);
    check(array_map('intval', $numbers) === [5, 6, 7, 8, 9], 'The regenerated range is exactly 5..9');

    // Partial update: starts_at only must not leave an invalid stored schedule.
    $schedule = call($app, 'PUT', '/api/admin/raffles/'.$createdId, payload([
        'min_number' => 5,
        'max_number' => 9,
        'starts_at' => '2026-11-01 10:00:00',
        'ends_at' => '2026-11-02 10:00:00',
    ]), $adminCookies, $adminHeaders);
    check($schedule['status'] === 200, 'A valid schedule is accepted');

    $partial = call($app, 'PUT', '/api/admin/raffles/'.$createdId, payload([
        'min_number' => 5,
        'max_number' => 9,
        'starts_at' => '2026-11-03 10:00:00',
    ]), $adminCookies, $adminHeaders);
    check($partial['status'] === 422 && isset($partial['payload']['errors']['ends_at']), 'A partial update cannot leave an invalid schedule');
    check(
        (string) $pdo->query('SELECT starts_at FROM raffles WHERE id = '.(int) $createdId)->fetchColumn() === '2026-11-01 10:00:00',
        'The rejected partial update did not change starts_at',
    );

    // ---- Reserved/sold guard ------------------------------------------
    $guardRaffle = call($app, 'POST', '/api/admin/raffles', payload(), $adminCookies, $adminHeaders);
    $guardId = (int) ($guardRaffle['payload']['data']['id'] ?? 0);
    if ($guardId > 0) {
        $createdRaffleIds[] = $guardId;
    }
    $pdo->exec("UPDATE raffle_numbers SET status = 'RESERVED', reserved_at = NOW(), reserved_until = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE raffle_id = ".(int) $guardId.' AND number = 1');

    $guardedRange = call($app, 'PUT', '/api/admin/raffles/'.$guardId, payload([
        'min_number' => 2,
        'max_number' => 50,
    ]), $adminCookies, $adminHeaders);
    check($guardedRange['status'] === 422 && isset($guardedRange['payload']['errors']['min_number']), 'A range change is blocked once a number is RESERVED');
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = '.(int) $guardId)->fetchColumn() === 100,
        'The blocked range change left the number rows intact',
    );

    $guardedDelete = call($app, 'DELETE', '/api/admin/raffles/'.$guardId, [], $adminCookies, $adminHeaders);
    check($guardedDelete['status'] === 422 && isset($guardedDelete['payload']['errors']['status']), 'A raffle with a RESERVED number cannot be deleted');

    // ---- Publish / close ----------------------------------------------
    $published = call($app, 'POST', '/api/admin/raffles/'.$guardId.'/publish', [], $adminCookies, $adminHeaders);
    check($published['status'] === 200 && ($published['payload']['data']['status'] ?? '') === 'PUBLISHED', 'Publishing a DRAFT raffle works');

    // ---- Dashboard metrics (S10.3) ------------------------------------
    $metricsAnonymous = call($app, 'GET', '/api/admin/raffles/metrics');
    check($metricsAnonymous['status'] === 401, 'Dashboard raffle metrics require a session (401)');

    $metricsUser = call($app, 'GET', '/api/admin/raffles/metrics', [], $userCookies);
    check($metricsUser['status'] === 403, 'Dashboard raffle metrics reject a USER role (403)');

    $metricsAdmin = call($app, 'GET', '/api/admin/raffles/metrics', [], $adminCookies);
    check($metricsAdmin['status'] === 200, 'Dashboard raffle metrics return 200 for an ADMIN');
    $expectedPublished = (int) $pdo->query("SELECT COUNT(*) FROM raffles WHERE status = 'PUBLISHED'")->fetchColumn();
    check(
        (int) ($metricsAdmin['payload']['data']['published_raffles'] ?? -1) === $expectedPublished,
        'published_raffles counts only PUBLISHED raffles',
    );
    check($expectedPublished >= 1, 'The published fixture is included in the metric');

    $metricsWrongMethod = call($app, 'POST', '/api/admin/raffles/metrics', [], $adminCookies, $adminHeaders);
    check($metricsWrongMethod['status'] === 405, 'POST on raffle metrics returns 405');

    $reconfigurePublished = call($app, 'PUT', '/api/admin/raffles/'.$guardId, payload(['title' => 'nope']), $adminCookies, $adminHeaders);
    check($reconfigurePublished['status'] === 422 && isset($reconfigurePublished['payload']['errors']['status']), 'A PUBLISHED raffle cannot be reconfigured');

    $deletePublished = call($app, 'DELETE', '/api/admin/raffles/'.$guardId, [], $adminCookies, $adminHeaders);
    check($deletePublished['status'] === 422 && isset($deletePublished['payload']['errors']['status']), 'A PUBLISHED raffle cannot be deleted');

    $closed = call($app, 'POST', '/api/admin/raffles/'.$guardId.'/close', [], $adminCookies, $adminHeaders);
    check($closed['status'] === 200 && ($closed['payload']['data']['status'] ?? '') === 'CLOSED', 'Closing a PUBLISHED raffle works');

    $closeDraft = call($app, 'POST', '/api/admin/raffles/'.$createdId.'/close', [], $adminCookies, $adminHeaders);
    check($closeDraft['status'] === 422 && isset($closeDraft['payload']['errors']['status']), 'A DRAFT raffle cannot be closed');

    // Invalid stored schedule is rejected on publish.
    $pdo->exec("UPDATE raffles SET starts_at = '2026-11-02 10:00:00', ends_at = '2026-11-01 10:00:00' WHERE id = ".(int) $createdId);
    $badPublish = call($app, 'POST', '/api/admin/raffles/'.$createdId.'/publish', [], $adminCookies, $adminHeaders);
    check($badPublish['status'] === 422 && isset($badPublish['payload']['errors']['ends_at']), 'Publish rejects an invalid stored schedule');
    check(
        (string) $pdo->query('SELECT status FROM raffles WHERE id = '.(int) $createdId)->fetchColumn() === 'DRAFT',
        'The rejected publish left the raffle in DRAFT',
    );

    // ---- Delete --------------------------------------------------------
    $doomed = call($app, 'POST', '/api/admin/raffles', payload(['max_number' => 10]), $adminCookies, $adminHeaders);
    $doomedId = (int) ($doomed['payload']['data']['id'] ?? 0);
    if ($doomedId > 0) {
        $createdRaffleIds[] = $doomedId;
    }
    $deleted = call($app, 'DELETE', '/api/admin/raffles/'.$doomedId, [], $adminCookies, $adminHeaders);
    check($deleted['status'] === 204, 'Deleting a DRAFT raffle with only AVAILABLE numbers returns 204');
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM raffles WHERE id = '.(int) $doomedId)->fetchColumn() === 0,
        'The deleted raffle is gone',
    );
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = '.(int) $doomedId)->fetchColumn() === 0,
        'The FK cascade removed its numbers',
    );

    $missing = call($app, 'GET', '/api/admin/raffles/999999999', [], $adminCookies);
    check($missing['status'] === 404, 'A missing raffle returns 404');

    // ---- Public catalog still uses the read model ----------------------
    $reader = Database::connect($database);
    $catalog = (new RaffleRepository($reader, 10, 50))->paginatePublished(1);
    check(array_key_exists('data', $catalog) && array_key_exists('total', $catalog), 'The public catalog projection still works');

    // ---- Sessions/users are never written by the service --------------
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM sessions WHERE id IN ('.implode(',', array_map(static fn (string $id): string => $pdo->quote($id), [$adminSessionId, $userSessionId, $expiredSessionId])).')')->fetchColumn() === 3,
        'Admin requests did not delete the session rows',
    );
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, 'ERROR: '.$exception::class.' - '.$exception->getMessage()."\n");
} finally {
    if ($createdRaffleIds !== []) {
        $ids = implode(',', array_map('intval', array_unique($createdRaffleIds)));
        $pdo->exec('DELETE FROM raffle_numbers WHERE raffle_id IN ('.$ids.')');
        $pdo->exec('DELETE FROM raffles WHERE id IN ('.$ids.')');
    }

    if ($adminId !== null || $userId !== null) {
        $ids = implode(',', array_filter([$adminId, $userId], static fn ($id): bool => $id !== null));
        $pdo->exec('DELETE FROM sessions WHERE user_id IN ('.$ids.')');
        $pdo->exec('DELETE FROM users WHERE id IN ('.$ids.')');
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed.\n");
    exit(1);
}

echo "raffles-service S2 admin integration test: OK\n";
