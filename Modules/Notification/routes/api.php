<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\Api\V1\MarketingNotificationController;
use Modules\Notification\Http\Controllers\Api\V1\NotificationController as V1NotificationController;

/*
|--------------------------------------------------------------------------
| Admin Notification Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:admin'])->prefix('v1/admin')->group(function () {

    // Notifications grouped by type
    Route::prefix('notifications')->group(function () {
        Route::get('/', [V1NotificationController::class, 'index'])->name('admin.notifications.index');
        Route::get('/unread-count', [V1NotificationController::class, 'unreadCount'])->name('admin.notifications.unread-count');
        Route::get('/{id}', [V1NotificationController::class, 'show'])->name('admin.notifications.show');
        Route::post('/{id}/read', [V1NotificationController::class, 'markAsRead'])->name('admin.notifications.mark-read');
        Route::post('/read-all', [V1NotificationController::class, 'markAllAsRead'])->name('admin.notifications.mark-all-read');
        Route::delete('/{id}', [V1NotificationController::class, 'destroy'])->name('admin.notifications.destroy');
        Route::delete('/', [V1NotificationController::class, 'destroyAll'])->name('admin.notifications.destroy-all');
    });

    // Marketing Notifications
    Route::prefix('marketing')->group(function () {
        Route::get('/notifications/stats', [MarketingNotificationController::class, 'stats'])->name('admin.marketing.notifications.stats');

        // Get today's notifications
        Route::get('/notifications', [MarketingNotificationController::class, 'index'])->name('admin.marketing.notifications.today');

        // Get all marketing notifications
        Route::get('/notifications/all', [MarketingNotificationController::class, 'all'])->name('admin.marketing.notifications.all');

        // Create marketing notification
        Route::post('/notification_management', [MarketingNotificationController::class, 'store'])->name('admin.marketing.notifications.store');

        // Delete marketing notification
        Route::delete('/notifications/{id}', [MarketingNotificationController::class, 'destroy'])->name('admin.marketing.notifications.destroy');

        // Send & Resend
        Route::post('/notifications/{id}/send', [MarketingNotificationController::class, 'send'])->name('admin.marketing.notifications.send');
        Route::post('/notifications/{id}/resend', [MarketingNotificationController::class, 'resend'])->name('admin.marketing.notifications.resend');
    });
});

/*
|--------------------------------------------------------------------------
| Vendor Notification Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:vendor'])->prefix('v1/vendor')->group(function () {

    // Notifications grouped by type
    Route::prefix('notifications')->group(function () {
        Route::get('/', [V1NotificationController::class, 'index'])->name('vendor.notifications.index');
        Route::get('/unread-count', [V1NotificationController::class, 'unreadCount'])->name('vendor.notifications.unread-count');
        Route::get('/{id}', [V1NotificationController::class, 'show'])->name('vendor.notifications.show');
        Route::post('/{id}/read', [V1NotificationController::class, 'markAsRead'])->name('vendor.notifications.mark-read');
        Route::post('/read-all', [V1NotificationController::class, 'markAllAsRead'])->name('vendor.notifications.mark-all-read');
        Route::delete('/{id}', [V1NotificationController::class, 'destroy'])->name('vendor.notifications.destroy');
        Route::delete('/', [V1NotificationController::class, 'destroyAll'])->name('vendor.notifications.destroy-all');
    });
});

/*
|--------------------------------------------------------------------------
| Driver Notification Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:driver'])->prefix('v1/driver')->group(function () {

    // Notifications grouped by type
    Route::prefix('notifications')->group(function () {
        Route::get('/', [V1NotificationController::class, 'index'])->name('driver.notifications.index');
        Route::get('/unread-count', [V1NotificationController::class, 'unreadCount'])->name('driver.notifications.unread-count');
        Route::get('/{id}', [V1NotificationController::class, 'show'])->name('driver.notifications.show');
        Route::post('/{id}/read', [V1NotificationController::class, 'markAsRead'])->name('driver.notifications.mark-read');
        Route::post('/read-all', [V1NotificationController::class, 'markAllAsRead'])->name('driver.notifications.mark-all-read');
        Route::delete('/{id}', [V1NotificationController::class, 'destroy'])->name('driver.notifications.destroy');
        Route::delete('/', [V1NotificationController::class, 'destroyAll'])->name('driver.notifications.destroy-all');
    });
});

/*
|--------------------------------------------------------------------------
| Client/User Notification Routes
|--------------------------------------------------------------------------
| User notifications: /api/v1/user/notifications (routes/api/v1/user.php)
*/
