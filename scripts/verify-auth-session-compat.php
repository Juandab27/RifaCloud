<?php

declare(strict_types=1);

/**
 * Proves that a session issued by auth-service is accepted by the monolith.
 *
 * It boots the monolith application with DB_DATABASE pointed at rifacloud_test
 * (provided by the caller) and dispatches a real /api/me request with the
 * auth-service session cookie. It performs no writes.
 *
 * Required environment:
 *   AUTH_SESSION_COOKIE   raw (URL-encoded) value of the rifacloud-session cookie
 *   DB_DATABASE           rifacloud_test
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;

require '/var/www/vendor/autoload.php';

$rawCookie = getenv('AUTH_SESSION_COOKIE');

if ($rawCookie === false || trim($rawCookie) === '') {
    fwrite(STDERR, "AUTH_SESSION_COOKIE is required.\n");
    exit(2);
}

if (getenv('DB_DATABASE') !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

// The value read from a Netscape cookie jar is URL-encoded. Symfony would
// normally receive the already-decoded $_COOKIE value from PHP, so decode it
// here before handing it to the request.
$cookie = urldecode(trim($rawCookie));

/** @var \Illuminate\Foundation\Application $app */
$app = require '/var/www/bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (getenv('AUTH_DEBUG') === '1') {
    $out = static function (string $line): void {
        echo $line."\n";
    };

    $out('db='.config('database.connections.mysql.database'));
    $out('session.driver='.config('session.driver'));
    $out('session.cookie='.config('session.cookie'));
    $out('session.domain='.config('session.domain'));
    $out('app_key_sha256='.hash('sha256', (string) config('app.key')));

    try {
        $decrypted = \Illuminate\Support\Facades\Crypt::decryptString($cookie);
        $sessionId = CookieValuePrefix::validate(
            'rifacloud-session',
            $decrypted,
            $app['encrypter']->getAllKeys(),
        );

        $out('cookie_prefix_valid='.($sessionId === null ? 'no' : 'yes'));
        $out('session_id='.($sessionId ?? 'invalid'));

        if ($sessionId !== null) {
            $row = \Illuminate\Support\Facades\DB::table('sessions')->where('id', $sessionId)->first();
            $out('session_row='.($row === null ? 'missing' : 'present'));

            if ($row !== null) {
                $payload = @unserialize(base64_decode($row->payload, true), ['allowed_classes' => false]);
                $out('payload_keys='.implode(',', array_keys(is_array($payload) ? $payload : [])));
                $out('payload_user_id='.($payload['login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d'] ?? 'none'));
            }
        }
    } catch (\Throwable $exception) {
        $out('decrypt_error='.$exception::class.' - '.$exception->getMessage());
    }
}

$request = Request::create(
    '/api/me',
    'GET',
    [],
    ['rifacloud-session' => $cookie],
    [],
    [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_ORIGIN' => 'http://localhost',
    ],
);

$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo 'status='.$response->getStatusCode()."\n";
echo $response->getContent()."\n";

exit($response->getStatusCode() === 200 ? 0 : 1);
