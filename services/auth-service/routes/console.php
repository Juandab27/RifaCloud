<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('auth-service:health', function (): void {
    $this->info('auth-service is running.');
})->purpose('Check the auth service process');

// auth-service owns no scheduled tasks. Reservation expiry, retries and
// reconciliation belong to the scheduler/owning business services.
