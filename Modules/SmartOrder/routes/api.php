<?php

use Illuminate\Support\Facades\Route;
use Modules\SmartOrder\Http\Controllers\SmartOrderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('smartorders', SmartOrderController::class)->names('smartorder');
});
