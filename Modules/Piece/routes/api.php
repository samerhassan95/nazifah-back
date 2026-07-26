<?php

use Illuminate\Support\Facades\Route;
use Modules\Piece\Http\Controllers\PieceController;

// ============================================================================
// ADMIN ROUTES - Piece Management
// ============================================================================
Route::prefix('v1/admin/pieces')->middleware(['auth:admin'])->group(function () {
    Route::get('/', [PieceController::class, 'index'])->name('admin.pieces.index');
    Route::post('/', [PieceController::class, 'store'])->name('admin.pieces.store');
    Route::get('/{id}', [PieceController::class, 'show'])->name('admin.pieces.show');
    Route::put('/{id}', [PieceController::class, 'update'])->name('admin.pieces.update');
    Route::delete('/{id}', [PieceController::class, 'destroy'])->name('admin.pieces.destroy');
});

// ============================================================================
// USER ROUTES - Get services by piece
// ============================================================================
Route::prefix('v1/user/pieces')->group(function () {
    Route::get('/{id}/services', [PieceController::class, 'getServices'])->name('user.pieces.services');
});
