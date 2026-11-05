<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (module route files)
|--------------------------------------------------------------------------
*/

require __DIR__.'/api/auth.php';
require __DIR__.'/api/raffles.php';
require __DIR__.'/api/orders.php';
require __DIR__.'/api/admin.php';
require __DIR__.'/api/webhooks.php';
