<?php

use App\Modules\Numbers\Http\Controllers\RaffleNumberController;
use App\Modules\Raffles\Http\Controllers\PublicRaffleController;
use Illuminate\Support\Facades\Route;

Route::get('/raffles', [PublicRaffleController::class, 'index']);
Route::get('/raffles/{raffle}', [PublicRaffleController::class, 'show']);
Route::get('/raffles/{raffle}/numbers', [RaffleNumberController::class, 'index']);
