<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Only the four API surfaces are exposed:
|   /api/v1/user/*
|   /api/v1/admin/*
|   /api/v1/vendor/*
|   /api/v1/driver/*
|
| Module RouteServiceProviders register admin/vendor/driver/payment/order/etc.
| User routes live in routes/api/v1/user.php
*/

Route::prefix('v1/user')->group(base_path('routes/api/v1/user.php'));
