<?php

use App\Modules\Payments\Http\Controllers\Admin\PaymentReviewController;
use App\Modules\Raffles\Http\Controllers\Admin\RaffleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function (): void {
        Route::get('/raffles', [RaffleController::class, 'index']);
        Route::post('/raffles', [RaffleController::class, 'store']);
        Route::get('/raffles/{raffle}', [RaffleController::class, 'show']);
        Route::put('/raffles/{raffle}', [RaffleController::class, 'update']);
        Route::delete('/raffles/{raffle}', [RaffleController::class, 'destroy']);
        Route::post('/raffles/{raffle}/publish', [RaffleController::class, 'publish']);
        Route::post('/raffles/{raffle}/close', [RaffleController::class, 'close']);

        // Resolves PAYMENT_REVIEW incidents by querying the payment provider.
        Route::post('/orders/{order}/payments/{payment}/resolve', [PaymentReviewController::class, 'resolve']);
    });
