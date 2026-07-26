<?php

use Illuminate\Support\Facades\Route;
use Modules\Whatsapp\Http\Controllers\WhatsappController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('whatsapps', WhatsappController::class)->names('whatsapp');
});
