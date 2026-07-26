<?php

use Illuminate\Support\Facades\Route;
use Modules\Branch\Http\Controllers\BranchController;

// ============================================================================
// ADMIN ROUTES - Branch Management
// ============================================================================
Route::prefix('v1/admin/branches')->middleware(['auth:admin'])->group(function () {
    Route::get('/', [BranchController::class, 'index'])->name('admin.branches.index');
    Route::post('/', [BranchController::class, 'store'])->name('admin.branches.store');
    Route::get('/{id}', [BranchController::class, 'show'])->name('admin.branches.show');
    Route::put('/{id}', [BranchController::class, 'update'])->name('admin.branches.update');
    Route::delete('/{id}', [BranchController::class, 'destroy'])->name('admin.branches.destroy');
});

// ============================================================================
// USER ROUTES - Get pieces by branch
// ============================================================================
Route::prefix('v1/user/branches')->group(function () {
    Route::get('/{id}/pieces', [BranchController::class, 'getPieces'])->name('user.branches.pieces');
    Route::get('/{branch_id}/pieces/{piece_id}/services', [BranchController::class, 'getPieceServices'])->name('user.branches.pieces.services');
});
