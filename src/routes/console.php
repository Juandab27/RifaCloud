<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Releases numbers from expired reservations (FR-007, AC-012). Orders under
// payment review or requiring a refund are never released by this job. The
// five-minute lock prevents an interrupted run from blocking expiry for the
// framework default of 24 hours.
Schedule::command('orders:expire-reservations')->everyMinute()->withoutOverlapping(5);
