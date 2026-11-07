<?php

use Illuminate\Support\Facades\Route;
use RifaCloud\AuthService\Http\Controllers\AuthController;
use RifaCloud\AuthService\Http\Controllers\HealthController;

Route::get('/auth/health', HealthController::class);

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});
