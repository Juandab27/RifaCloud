<?php

use App\Modules\Payments\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Provider webhooks
|--------------------------------------------------------------------------
|
| These routes are intentionally outside auth:sanctum: Mercado Pago cannot
| present a session. Authenticity is established by the provider signature and
| the server-side payment lookup performed by the gateway.
|
*/

Route::post('/webhooks/mercadopago', [WebhookController::class, 'store'])
    ->name('webhooks.mercadopago');
