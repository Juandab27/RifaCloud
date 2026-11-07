<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Releases numbers from expired reservations (FR-007, AC-012). Orders under
// payment review or requiring a refund are never released by this job. The
// five-minute lock prevents an interrupted run from blocking expiry for the
// framework default of 24 hours.
Schedule::command('orders:expire-reservations')->everyMinute()->withoutOverlapping(5);

// Recovers PENDING/UNKNOWN payments by querying the provider through the
// signed payments-service command. The lock prevents two reconcile runs from
// overlapping; the command itself is idempotent and fails closed when the
// payment service is unavailable.
Schedule::command('payments:reconcile --limit=50')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Queue purchase confirmations and payment/refund incidents, then deliver them
// with retries. Both commands only touch the notifications table; a mail
// failure never changes an order or a payment.
Schedule::command('notifications:queue --limit=100')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->when(static fn (): bool => Schema::hasTable('notifications'));

Schedule::command('notifications:dispatch --limit=50')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->when(static fn (): bool => Schema::hasTable('notifications'));

// Liveness heartbeat for the scheduler container healthcheck. A dead
// `schedule:work` process stops updating this file.
Schedule::call(static function (): void {
    @file_put_contents('/tmp/rifacloud-scheduler-heartbeat', (string) time());
})->everyMinute();