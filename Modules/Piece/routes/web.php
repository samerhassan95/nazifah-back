<?php

use Illuminate\Support\Facades\Route;
use Modules\Piece\Http\Controllers\PieceController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('pieces', PieceController::class)->names('piece');
});
