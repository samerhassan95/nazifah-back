<?php

use Illuminate\Support\Facades\Route;
use Modules\Service\Http\Controllers\ServiceController;

// ============================================================================
// ADMIN ROUTES - Service Management
// ============================================================================
Route::prefix('v1/admin/services')->middleware(['auth:admin'])->group(function () {
    Route::get('/', [ServiceController::class, 'index'])->name('admin.services.index');
    Route::post('/', [ServiceController::class, 'store'])->name('admin.services.store');
    Route::get('/{id}', [ServiceController::class, 'show'])->name('admin.services.show');
    Route::put('/{id}', [ServiceController::class, 'update'])->name('admin.services.update');
    Route::delete('/{id}', [ServiceController::class, 'destroy'])->name('admin.services.destroy');
});

// ============================================================================
// USER ROUTES - Get branches by service
// ============================================================================
Route::prefix('v1/user/services')->group(function () {
    Route::get('/{id}/branches', [ServiceController::class, 'getBranches'])->name('user.services.branches');
    Route::get('/{serviceId}/branches/{branchId}/pieces', [ServiceController::class, 'getPiecesByServiceAndBranch'])->name('user.services.branches.pieces');
});
