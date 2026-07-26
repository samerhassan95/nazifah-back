<?php

use Illuminate\Support\Facades\Route;
use Modules\Owner\Http\Controllers\OwnerController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('owners', OwnerController::class)->names('owner');
});
