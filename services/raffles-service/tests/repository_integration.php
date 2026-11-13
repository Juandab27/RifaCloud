<?php

declare(strict_types=1);

use RifaCloud\RafflesService\Config;
use RifaCloud\RafflesService\Database;
use RifaCloud\RafflesService\RaffleRepository;

require __DIR__.'/../src/Config.php';
require __DIR__.'/../src/Database.php';
require __DIR__.'/../src/RafflePresenter.php';
require __DIR__.'/../src/RaffleRepository.php';

/**
 * Non-destructive integration test for the read model.
 *
 * It refuses to run unless RAFFLES_TEST_DB_DATABASE is exactly
 * `rifacloud_test`, so it can never write fixtures to the development or
 * production schema.
 */

$database = Config::database();
$database['database'] = (string) (getenv('RAFFLES_TEST_DB_DATABASE') ?: '');

if ($database['database'] !== 'rifacloud_test') {
    fwrite(STDERR, "Refusing to run: RAFFLES_TEST_DB_DATABASE must be rifacloud_test.\n");
    exit(2);
}

$writer = Database::connect($database, readOnly: false);
$prefix = 'svc-test-'.bin2hex(random_bytes(4));
$userId = null;
$publishedId = null;
$draftId = null;
$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;

    if (! $condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

try {
    $insertUser = $writer->prepare(
        <<<'SQL'
            INSERT INTO users (name, email, phone, password, role, created_at, updated_at)
            VALUES (:name, :email, :phone, :password, 'ADMIN', NOW(), NOW())
        SQL,
    );
    $insertUser->execute([
        ':name' => 'Raffles service test',
        ':email' => $prefix.'@example.invalid',
        ':phone' => '0000000000',
        ':password' => password_hash('test-password', PASSWORD_BCRYPT),
    ]);
    $userId = (int) $writer->lastInsertId();

    $insertRaffle = $writer->prepare(
        <<<'SQL'
            INSERT INTO raffles
                (title, description, price_per_number, min_number, max_number, status, created_by, created_at, updated_at)
            VALUES
                (:title, :description, :price, :min, :max, :status, :created_by, NOW(), NOW())
        SQL,
    );

    $insertRaffle->execute([
        ':title' => $prefix.' published',
        ':description' => 'Fixture for the raffles-service integration test.',
        ':price' => 5000,
        ':min' => 1,
        ':max' => 5,
        ':status' => 'PUBLISHED',
        ':created_by' => $userId,
    ]);
    $publishedId = (int) $writer->lastInsertId();

    $insertRaffle->execute([
        ':title' => $prefix.' draft',
        ':description' => null,
        ':price' => 5000,
        ':min' => 1,
        ':max' => 5,
        ':status' => 'DRAFT',
        ':created_by' => $userId,
    ]);
    $draftId = (int) $writer->lastInsertId();

    $insertNumber = $writer->prepare(
        <<<'SQL'
            INSERT INTO raffle_numbers
                (raffle_id, number, status, reserved_at, reserved_until, sold_at, created_at, updated_at)
            VALUES
                (:raffle_id, :number, :status, :reserved_at, :reserved_until, :sold_at, NOW(), NOW())
        SQL,
    );

    $statuses = ['AVAILABLE', 'RESERVED', 'SOLD', 'AVAILABLE', 'AVAILABLE'];

    foreach ($statuses as $index => $status) {
        $insertNumber->execute([
            ':raffle_id' => $publishedId,
            ':number' => $index + 1,
            ':status' => $status,
            ':reserved_at' => $status === 'RESERVED' ? date('Y-m-d H:i:s') : null,
            ':reserved_until' => $status === 'RESERVED' ? date('Y-m-d H:i:s', time() + 600) : null,
            ':sold_at' => $status === 'SOLD' ? date('Y-m-d H:i:s') : null,
        ]);
    }

    $insertNumber->execute([
        ':raffle_id' => $draftId,
        ':number' => 1,
        ':status' => 'AVAILABLE',
        ':reserved_at' => null,
        ':reserved_until' => null,
        ':sold_at' => null,
    ]);

    $reader = Database::connect($database);
    $repository = new RaffleRepository($reader, 10, 50);

    $catalog = $repository->paginatePublished(1);
    $published = null;

    foreach ($catalog['data'] as $row) {
        if ($row['id'] === $publishedId) {
            $published = $row;
            break;
        }
    }

    check($published !== null, 'the published fixture appears in the catalog');
    check($catalog['total'] >= 1, 'the catalog total counts published raffles');
    check(
        array_reduce(
            $catalog['data'],
            static fn (bool $carry, array $row): bool => $carry && $row['id'] !== $draftId,
            true,
        ),
        'the draft fixture is not exposed in the catalog',
    );

    if ($published !== null) {
        check($published['title'] === $prefix.' published', 'the catalog title matches');
        check($published['status'] === 'PUBLISHED', 'the catalog status matches');
        check($published['total_numbers'] === 5, 'total_numbers is min/max inclusive');
        check($published['numbers_count'] === 5, 'numbers_count is projected');
        check($published['reservation_minutes'] === 10, 'reservation_minutes matches the contract');
        check($published['max_numbers_per_order'] === 50, 'max_numbers_per_order matches the contract');
        check($published['price_per_number'] === 5000, 'price_per_number is an integer in COP');
    }

    check($repository->findPublished($publishedId) !== null, 'published raffle detail is available');
    check($repository->findPublished($draftId) === null, 'draft raffle detail is hidden');

    $numbers = $repository->numbersForPublishedRaffle($publishedId, 1, 2);

    check($numbers !== null, 'number availability is returned for a published raffle');
    check($repository->numbersForPublishedRaffle($draftId, 1, 2) === null, 'draft numbers are hidden');

    if ($numbers !== null) {
        check($numbers['meta']['total'] === 5, 'numbers total is correct');
        check($numbers['meta']['last_page'] === 3, 'numbers pagination is correct');
        check($numbers['meta']['per_page'] === 2, 'numbers per_page is respected');
        check(count($numbers['data']) === 2, 'numbers page size is respected');
        check($numbers['data'][0]['number'] === 1, 'numbers are ordered by number');
        check($numbers['meta']['counts'] === [
            'available' => 3,
            'reserved' => 1,
            'sold' => 1,
        ], 'status counts are correct');
    }

    $writeBlocked = false;

    try {
        $reader->exec('UPDATE raffles SET title = title WHERE id = '.(int) $publishedId);
    } catch (PDOException) {
        $writeBlocked = true;
    }

    check($writeBlocked, 'the read-only session rejects writes');
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, 'ERROR: '.$exception::class.' - '.$exception->getMessage()."\n");
} finally {
    foreach ([$publishedId, $draftId] as $raffleId) {
        if ($raffleId !== null) {
            $writer->prepare('DELETE FROM raffle_numbers WHERE raffle_id = :raffle_id')
                ->execute([':raffle_id' => $raffleId]);
            $writer->prepare('DELETE FROM raffles WHERE id = :id')
                ->execute([':id' => $raffleId]);
        }
    }

    if ($userId !== null) {
        $writer->prepare('DELETE FROM users WHERE id = :id')
            ->execute([':id' => $userId]);
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed.\n");
    exit(1);
}

echo "raffles-service repository integration test: OK\n";
