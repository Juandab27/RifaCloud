<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Raffle number generation guard
    |--------------------------------------------------------------------------
    |
    | Technical protection against oversized ranges, not a business rule.
    | It bounds how many rows a single raffle creation can insert.
    |
    */

    'raffle_max_numbers' => (int) env('RAFFLE_MAX_NUMBERS', 10000),

    /*
    |--------------------------------------------------------------------------
    | Payment gateway driver
    |--------------------------------------------------------------------------
    |
    | Only the contract exists in Phase 1. The simulated driver is implemented
    | in Phase 2; real Mercado Pago credentials are a P2 dependency (Q-05).
    |
    */

    'payment_gateway' => env('PAYMENT_GATEWAY', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Admin bootstrap
    |--------------------------------------------------------------------------
    |
    | Used only by AdminUserSeeder. When email or password are empty the
    | seeder is skipped, so no default credentials exist in the codebase.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'RifaCloud Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'phone' => env('ADMIN_PHONE', '0000000000'),
    ],

];
