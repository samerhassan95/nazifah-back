<?php

use Illuminate\Support\Facades\Route;
use Modules\Discount\Http\Controllers\DiscountController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('discounts', DiscountController::class)->names('discount');
});
