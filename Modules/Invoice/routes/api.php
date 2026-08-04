<?php

use Illuminate\Support\Facades\Route;
use Modules\Invoice\Http\Controllers\Api\V1\InvoiceController;

Route::middleware(['auth:sanctum'])->prefix('v1/user')->group(function () {
    Route::get('/orders/{order}/invoice', [InvoiceController::class, 'showForOrder'])
        ->name('invoice.user.orders.show');
});

Route::prefix('v1/invoices')->group(function () {
    Route::get('/{invoice}/share', [InvoiceController::class, 'share'])
        ->middleware('signed')
        ->name('invoice.share');
});
