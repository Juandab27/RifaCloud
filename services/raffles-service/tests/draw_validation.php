<?php

declare(strict_types=1);

use RifaCloud\RafflesService\Admin\RaffleValidator;
use RifaCloud\RafflesService\Admin\ValidationException;

require __DIR__.'/../src/Admin/ValidationException.php';
require __DIR__.'/../src/Admin/RaffleValidator.php';

$checks = 0;
$failures = 0;

$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;

    if (! $condition) {
        $failures++;
        fwrite(STDERR, "FAIL {$message}\n");
    } else {
        echo "PASS {$message}\n";
    }
};

$validator = new RaffleValidator(10000, 30);

$base = [
    'title' => 'S12 validator',
    'price_per_number' => 1000,
    'min_number' => 1,
    'max_number' => 10,
    'starts_at' => '2026-11-14 10:00:00',
    'ends_at' => '2026-11-15 10:00:00',
    'draw_at' => '2026-11-15 10:30:00',
];

$data = $validator->validate($base + ['draw_mode' => 'AUTOMATIC']);
$check(($data['draw_mode'] ?? null) === 'AUTOMATIC', 'AUTOMATIC mode is accepted');
$check(($data['draw_at'] ?? null) === '2026-11-15 10:30:00', 'draw_at is normalized');

try {
    $validator->assertValidDrawSchedule('AUTOMATIC', '2026-11-15 10:00:00', '2026-11-15 10:30:00');
    $check(true, '30-minute buffer is accepted');
} catch (ValidationException) {
    $check(false, '30-minute buffer is accepted');
}

try {
    $validator->assertValidDrawSchedule('AUTOMATIC', '2026-11-15 10:00:00', '2026-11-15 10:29:59');
    $check(false, 'less than 30 minutes is rejected');
} catch (ValidationException) {
    $check(true, 'less than 30 minutes is rejected');
}

try {
    $validator->assertValidDrawSchedule('AUTOMATIC', '2026-11-15 10:00:00', null);
    $check(false, 'AUTOMATIC without draw_at is rejected');
} catch (ValidationException) {
    $check(true, 'AUTOMATIC without draw_at is rejected');
}

try {
    $validator->assertValidDrawSchedule('MANUAL', null, null);
    $check(true, 'MANUAL without dates remains valid');
} catch (ValidationException) {
    $check(false, 'MANUAL without dates remains valid');
}

echo "\nS12 draw validation: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
