<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminIconController;
use Modules\Driver\Http\Controllers\Api\V1\AuthController;
use Modules\Driver\Http\Controllers\Api\V1\ChatsController;
use Modules\Driver\Http\Controllers\Api\V1\HomeController;
use Modules\Driver\Http\Controllers\Api\V1\LocationController;
use Modules\Driver\Http\Controllers\Api\V1\OrderController;
use Modules\Driver\Http\Controllers\Api\V1\RevenuesController;
use Modules\Driver\Http\Controllers\Api\V1\ReviewsController;
use Modules\Driver\Http\Controllers\Api\V1\StatusController;
use Modules\Zone\Http\Controllers\Api\ZonesController;

/*
|--------------------------------------------------------------------------
| Driver API Routes
|--------------------------------------------------------------------------
| All routes are prefixed with /api/v1/driver
|--------------------------------------------------------------------------
*/

// ============================================================================
// AUTHENTICATION ROUTES (Public)
// ============================================================================
Route::prefix('v1/driver/auth')->middleware('throttle:5,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('driver.auth.login');
    Route::post('/register', [AuthController::class, 'register'])->name('driver.auth.register');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->name('driver.auth.verify-otp');
    Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->name('driver.auth.resend-otp');
    Route::post('/fingerprint', [AuthController::class, 'loginWithFingerprint'])->name('driver.auth.fingerprint');
});

// ============================================================================
// PROTECTED ROUTES (Require Authentication)
// ============================================================================
Route::middleware('auth:driver')->prefix('v1/driver')->group(function () {

    // ========================================================================
    // ICONS API
    // ========================================================================
    Route::get('/icons/all', [AdminIconController::class, 'all'])->name('driver.icons.all');

    // ========================================================================
    // ZONES API (Read-only - list all active zones)
    // ========================================================================
    Route::get('/zones', [ZonesController::class, 'index'])->name('driver.zones.index');

    // ========================================================================
    // AUTH - Profile Management
    // ========================================================================
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('driver.auth.me');
        Route::put('/create-profile', [AuthController::class, 'createProfile'])->name('driver.auth.create-profile');
        Route::post('/fcm-token', [AuthController::class, 'updateFcmToken'])->name('driver.auth.update-fcm-token');
        Route::put('/lang', [AuthController::class, 'updateLang'])->name('driver.auth.update-lang');
        Route::post('/logout', [AuthController::class, 'logout'])->name('driver.auth.logout');
        Route::post('/register-fingerprint', [AuthController::class, 'registerFingerprint'])->name('driver.auth.register-fingerprint');
        Route::post('/remove-fingerprint', [AuthController::class, 'removeFingerprint'])->name('driver.auth.remove-fingerprint');
    });

    // ========================================================================
    // HOME API
    // ========================================================================
    Route::prefix('home')->group(function () {
        Route::get('/user-data', [HomeController::class, 'getUserData'])->name('driver.home.user-data');
        Route::get('/dashboard', [HomeController::class, 'getDashboard'])->name('driver.home.dashboard');
        Route::get('/card', [HomeController::class, 'getCard'])->name('driver.home.card');
        Route::get('/new-orders', [HomeController::class, 'getNewOrders'])->name('driver.home.new-orders');
        Route::get('/order-new-details', [HomeController::class, 'getOrderNewDetails'])->name('driver.home.order-new-details');
        Route::get('/reviews', [HomeController::class, 'getReviews'])->name('driver.home.reviews');
        Route::get('/map-tracking', [HomeController::class, 'getMapTracking'])->name('driver.home.map-tracking');
    });

    // ========================================================================
    // STATUS API
    // ========================================================================
    Route::prefix('profile')->group(function () {
        Route::get('/', [StatusController::class, 'show'])->name('driver.status.show');
        Route::put('/availability', [StatusController::class, 'updateAvailability'])->name('driver.status.update-availability');
    });

    // ========================================================================
    // LOCATION API
    // ========================================================================
    Route::prefix('location')->group(function () {
        Route::get('/', [LocationController::class, 'show'])->name('driver.location.show');
        Route::put('/', [LocationController::class, 'update'])->name('driver.location.update');
    });

    // ========================================================================
    // ORDERS API
    // ========================================================================
    Route::prefix('orders')->group(function () {
        Route::get('/available', [OrderController::class, 'available'])->name('driver.orders.available');
        Route::get('/', [OrderController::class, 'index'])->name('driver.orders.index');
        Route::get('/{orderId}', [OrderController::class, 'show'])->name('driver.orders.show');
        Route::get('/{orderId}/status-log', [OrderController::class, 'getStatusLog'])->name('driver.orders.status-log');
        Route::post('/{orderId}/accept', [OrderController::class, 'accept'])->name('driver.orders.accept');
        Route::post('/{orderId}/reject', [OrderController::class, 'reject'])->name('driver.orders.reject');
        Route::put('/{orderId}/status', [OrderController::class, 'updateStatus'])->name('driver.orders.update-status');
        Route::post('/{orderId}/notify-client-on-the-way', [OrderController::class, 'notifyClientOnTheWay'])
            ->name('driver.orders.notify-client-on-the-way');
        Route::get('/{orderId}/tracking', [OrderController::class, 'tracking'])->name('driver.orders.tracking');
        Route::post('/{orderId}/pickup-complete', [OrderController::class, 'pickupComplete'])->name('driver.orders.pickup-complete');
        Route::post('/{orderId}/confirm-qr', [OrderController::class, 'confirmQR'])->name('driver.orders.confirm-qr');
    });

    // ========================================================================
    // REVENUES API
    // ========================================================================
    Route::prefix('revenues')->group(function () {
        Route::get('/', [RevenuesController::class, 'index'])->name('driver.revenues.index');
        Route::get('/history', [RevenuesController::class, 'history'])->name('driver.revenues.history');
    });

    // ========================================================================
    // NOTIFICATIONS API (Handled by Notification Module)
    // ========================================================================
    // See Modules/Notification/routes/api.php for notification routes

    // ========================================================================
    // REVIEWS API
    // ========================================================================
    Route::prefix('reviews')->group(function () {
        Route::get('/', [ReviewsController::class, 'index'])->name('driver.reviews.index');
        Route::get('/statistics', [ReviewsController::class, 'statistics'])->name('driver.reviews.statistics');
    });

    // ========================================================================
    // CHATS API (open chat for order or support; order_id can be null)
    // ========================================================================
    Route::prefix('chats')->group(function () {
        Route::get('/', [ChatsController::class, 'index'])->name('driver.chats.index');
        Route::post('/send', [ChatsController::class, 'sendMessage'])->name('driver.chats.send');
        Route::get('/{conversationId}/messages', [ChatsController::class, 'getMessages'])->name('driver.chats.messages');
        Route::post('/{conversationId}/send', [ChatsController::class, 'sendMessage'])->name('driver.chats.send.conversation');
        Route::get('/{conversationId}', [ChatsController::class, 'show'])->name('driver.chats.show');
    });
});
