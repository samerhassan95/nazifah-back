<?php

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\PaymentController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::prefix('payments')->group(function () {
        // Initialize payment
        Route::post('/', [PaymentController::class, 'initializePayment'])->name('payment.initialize');

        // Verify payment
        Route::get('/{transactionId}/verify', [PaymentController::class, 'verifyPayment'])->name('payment.verify');

        // Get payment status
        Route::get('/{transactionId}/status', [PaymentController::class, 'getStatus'])->name('payment.status');

        // Capture authorized payment
        Route::post('/capture', [PaymentController::class, 'capturePayment'])->name('payment.capture');

        // Void (release) an authorized payment that was never captured
        Route::post('/void', [PaymentController::class, 'voidPayment'])->name('payment.void');

        // Process refund
        Route::post('/refund', [PaymentController::class, 'refund'])->name('payment.refund');

        // Get available gateways
        Route::get('/gateways', [PaymentController::class, 'getGateways'])->name('payment.gateways');
    });
});

// Public routes (no authentication required) - Payment gateways callbacks
Route::prefix('v1')->group(function () {
    Route::prefix('payments')->group(function () {
        // Payment callback (success/return) - supports both GET and POST
        Route::match(['get', 'post'], '/callback', [PaymentController::class, 'callback'])->name('payment.callback');

        // Payment cancellation - supports both GET and POST
        Route::match(['get', 'post'], '/cancel', [PaymentController::class, 'cancel'])->name('payment.cancel');

        // Moyasar server-to-server webhook (reliable settlement; register this URL
        // in the Moyasar dashboard). See MOYASAR_INTEGRATION.md.
        Route::post('/moyasar/webhook', [PaymentController::class, 'moyasarWebhook'])->name('payment.moyasar.webhook');

        // Local hosted checkout page (for Moyasar `hosted_local` mode)
        Route::get('/moyasar/checkout/{transactionId}', [PaymentController::class, 'showMoyasarCheckout'])->name('payment.moyasar.checkout');
    });
});
