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
    | Reservation settings
    |--------------------------------------------------------------------------
    |
    | Reservation duration is 10 minutes by default and configurable per the
    | approved SDD. order_max_numbers is a technical payload guard, not a
    | business purchase limit.
    |
    */

    'reservation_minutes' => max(1, (int) env('RESERVATION_MINUTES', 10)),

    'order_max_numbers' => max(1, (int) env('ORDER_MAX_NUMBERS', 50)),

    /*
    |--------------------------------------------------------------------------
    | Payment gateway driver
    |--------------------------------------------------------------------------
    |
    | The simulated driver is the only implementation in Block B; real Mercado
    | Pago integration is a P2 dependency (Q-05). The application refuses to
    | boot in production with PAYMENT_GATEWAY=fake. PAYMENT_FAKE_OUTCOME
    | accepts APPROVED, REJECTED, TIMEOUT or UNKNOWN.
    |
    */

    'payment_gateway' => env('PAYMENT_GATEWAY', 'fake'),

    'payment_fake_outcome' => strtoupper((string) env('PAYMENT_FAKE_OUTCOME', 'UNKNOWN')),

    /*
    |--------------------------------------------------------------------------
    | orders-service (S3)
    |--------------------------------------------------------------------------
    |
    | The monolith scheduler delegates reservation expiry to orders-service.
    | It never releases numbers locally, so only one owner can expire a
    | reservation. The service must run for the command to succeed; when it is
    | unavailable the command fails closed and the next scheduled run retries.
    |
    */

    'orders_service_url' => env('ORDERS_SERVICE_URL', 'http://orders-service'),

    'orders_internal_timeout' => max(1, (int) env('ORDERS_INTERNAL_TIMEOUT', 5)),

    /*
    |--------------------------------------------------------------------------
    | payments-service (S4)
    |--------------------------------------------------------------------------
    |
    | The monolith scheduler delegates provider reconciliation to
    | payments-service. It never queries the provider or writes payments
    | locally, so only one owner can reconcile a payment.
    |
    */

    'payments_service_url' => env('PAYMENTS_SERVICE_URL', 'http://payments-service'),

    'payments_internal_timeout' => max(1, (int) env('PAYMENTS_INTERNAL_TIMEOUT', 10)),

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
