<?php

use Illuminate\Support\Facades\Route;
use Modules\Vendor\Http\Controllers\VendorController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('vendors', VendorController::class)->names('vendor');
});
