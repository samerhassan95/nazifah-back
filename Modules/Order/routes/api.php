<?php

use Illuminate\Support\Facades\Route;
use Modules\Order\Http\Controllers\OrderCheckoutController;

/*
|--------------------------------------------------------------------------
| Order API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {

    Route::prefix('orders')->group(function () {

        Route::get('{orderId}/payment/status', [OrderCheckoutController::class, 'getPaymentStatus']);
    });

});

// Public callback routes (no auth required as they come from payment gateway).
// PayFort posts the result back, so both GET (browser redirect) and POST
// (server return) must be accepted — a GET-only route silently 405s on POST.
Route::prefix('v1/checkout')->group(function () {
    Route::match(['get', 'post'], 'callback', [OrderCheckoutController::class, 'handleCallback'])->name('checkout.callback');
    Route::match(['get', 'post'], 'cancel', [OrderCheckoutController::class, 'handleCancel'])->name('checkout.cancel');
});
