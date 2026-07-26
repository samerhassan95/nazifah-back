<?php

use Illuminate\Support\Facades\Route;
use Modules\BannerOffer\Http\Controllers\BannerOfferController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('banneroffers', BannerOfferController::class)->names('banneroffer');
});
