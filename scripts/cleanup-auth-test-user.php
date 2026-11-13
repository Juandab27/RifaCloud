<?php

declare(strict_types=1);

/**
 * Removes a user created by the auth-service integration test from the test
 * database only. Refuses to run against anything except rifacloud_test.
 *
 * Environment:
 *   AUTH_TEST_EMAIL   email of the test user to delete
 *   DB_DATABASE       must be rifacloud_test
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require '/var/www/vendor/autoload.php';

$email = getenv('AUTH_TEST_EMAIL');

if ($email === false || trim($email) === '') {
    fwrite(STDERR, "AUTH_TEST_EMAIL is required.\n");
    exit(2);
}

if (getenv('DB_DATABASE') !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

/** @var \Illuminate\Foundation\Application $app */
$app = require '/var/www/bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$user = DB::table('users')->where('email', $email)->first();

if ($user === null) {
    echo "cleanup: no user found for {$email}\n";
    exit(0);
}

DB::table('sessions')->where('user_id', $user->id)->delete();

// S11 consent evidence uses restrictOnDelete. Test cleanup must remove its own
// rows explicitly; production deletion requires a retention decision instead.
if (Schema::hasTable('user_consents')) {
    DB::table('user_consents')->where('user_id', $user->id)->delete();
}

DB::table('users')->where('id', $user->id)->delete();

echo "cleanup: deleted user {$user->id} ({$email}), its sessions and consent evidence\n";
