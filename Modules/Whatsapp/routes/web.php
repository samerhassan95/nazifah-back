<?php

use Illuminate\Support\Facades\Route;
use Modules\Whatsapp\Http\Controllers\WhatsappController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('whatsapps', WhatsappController::class)->names('whatsapp');
});
