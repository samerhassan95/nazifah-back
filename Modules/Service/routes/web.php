<?php

use Illuminate\Support\Facades\Route;
use Modules\Service\Http\Controllers\serviceController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('services', serviceController::class)->names('service');
});
