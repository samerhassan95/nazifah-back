<?php

use Illuminate\Support\Facades\Route;
use Modules\Zone\Http\Controllers\ZoneController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('zones', ZoneController::class)->names('zone');
});
