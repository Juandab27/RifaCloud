<?php

declare(strict_types=1);

/**
 * S11: reads the consent evidence written by auth-service for the temporary
 * integration user. Test-only and read-only.
 *
 * Environment:
 *   AUTH_TEST_EMAIL   email of the user created by the integration script
 *   DB_DATABASE       must be rifacloud_test
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$email = getenv('AUTH_TEST_EMAIL');

if ($email === false || trim($email) === '') {
    fwrite(STDERR, "AUTH_TEST_EMAIL is required.\n");
    exit(2);
}

if (getenv('DB_DATABASE') !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

require '/var/www/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require '/var/www/bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$user = DB::table('users')->where('email', $email)->first();

if ($user === null) {
    echo json_encode(['table' => Schema::hasTable('user_consents'), 'user' => null], JSON_THROW_ON_ERROR);
    exit(1);
}

if (! Schema::hasTable('user_consents')) {
    echo json_encode(['table' => false, 'user_id' => (int) $user->id, 'rows' => []], JSON_THROW_ON_ERROR);
    exit(1);
}

$rows = DB::table('user_consents')
    ->where('user_id', $user->id)
    ->orderBy('type')
    ->get(['type', 'document_version', 'accepted', 'accepted_at', 'source'])
    ->map(static fn ($row): array => [
        'type' => (string) $row->type,
        'document_version' => (string) $row->document_version,
        'accepted' => (bool) $row->accepted,
        'accepted_at' => (string) $row->accepted_at,
        'source' => (string) $row->source,
    ])
    ->all();

echo json_encode([
    'table' => true,
    'user_id' => (int) $user->id,
    'rows' => $rows,
], JSON_THROW_ON_ERROR);
