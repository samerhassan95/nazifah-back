<?php

use Illuminate\Support\Facades\Route;
use Modules\Discount\Http\Controllers\Client\CouponController as ClientCouponController;

/*
|--------------------------------------------------------------------------
| Client Coupon Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:client', 'banned'])->prefix('v1/user')->group(function () {
    // Client coupon endpoints
    Route::prefix('coupons')->group(function () {
        Route::get('/', [ClientCouponController::class, 'index']);
        Route::post('/validate', [ClientCouponController::class, 'validate']);
        Route::post('/apply', [ClientCouponController::class, 'apply']);
        Route::get('/by-code', [ClientCouponController::class, 'getByCode']);
    });
});
