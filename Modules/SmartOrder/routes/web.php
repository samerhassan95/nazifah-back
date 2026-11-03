<?php

use Illuminate\Support\Facades\Route;
use Modules\SmartOrder\Http\Controllers\SmartOrderController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('smartorders', SmartOrderController::class)->names('smartorder');
});
