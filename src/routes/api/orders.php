<?php

use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/payments', [PaymentController::class, 'store']);
    Route::post('/orders/{order}/payments/{payment}/verify', [PaymentController::class, 'verify']);
});
