<?php

use Illuminate\Support\Facades\Route;
use Modules\Ad\Http\Controllers\AdController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('ads', AdController::class)->names('ad');
});
