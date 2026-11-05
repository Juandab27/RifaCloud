<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mercado Pago Checkout Pro (sandbox)
    |--------------------------------------------------------------------------
    |
    | Credentials are read from the server environment only. Never expose the
    | access token to the browser, logs or the payments response_data column.
    | The webhook secret authenticates notifications; the payment is always
    | fetched again from the API and the notification body is never trusted.
    |
    */

    'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),

    'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),

    'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),

    /*
    | Absolute URL that Mercado Pago calls for notifications. When empty,
    | APP_URL + /api/webhooks/mercadopago is used. Outside local development
    | this must be a publicly reachable HTTPS URL (Q-06).
    */
    'webhook_url' => env('MERCADOPAGO_WEBHOOK_URL'),

    'sandbox' => (bool) env('MERCADOPAGO_SANDBOX', true),

    'base_url' => rtrim((string) env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'), '/'),

    'timeout' => max(1, (int) env('MERCADOPAGO_TIMEOUT', 10)),

    /*
    | Absolute base used to build back_urls. When empty, APP_URL is used.
    | Mercado Pago requires absolute HTTPS URLs outside local development.
    */
    'back_url' => env('MERCADOPAGO_BACK_URL'),

];
