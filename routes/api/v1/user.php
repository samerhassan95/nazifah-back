<?php

use App\Http\Controllers\Api\V1\User\FirebaseTestController;
// User API Controllers
use App\Http\Controllers\Api\V1\User\HomeController;
use Illuminate\Support\Facades\Route;
use Modules\Address\Http\Controllers\Api\V1\User\LocationController;
use Modules\Category\Http\Controllers\Api\V1\User\DepartmentController;
use Modules\Chat\Http\Controllers\Api\V1\User\ChatController;
use Modules\Client\Http\Controllers\Api\V1\AuthController;
use Modules\Notification\Http\Controllers\Api\V1\User\NotificationController;
use Modules\Order\Http\Controllers\Api\V1\User\OrderController;
use Modules\Order\Http\Controllers\Api\V1\User\OrderTrackingController;
use Modules\Payment\Http\Controllers\Api\V1\User\PaymentMethodController;
use Modules\Payment\Http\Controllers\PaymentController;
use Modules\Payment\Http\Controllers\Api\V1\User\WalletController;
use Modules\Zone\Http\Controllers\Api\ZonesController;

/*
|--------------------------------------------------------------------------
| User API Routes - V1
|--------------------------------------------------------------------------
| All routes are prefixed with /api/v1/user
|--------------------------------------------------------------------------
*/

// ============================================================================
// AUTHENTICATION ROUTES (Public)
// ============================================================================
Route::prefix('auth')->group(function () {
    // Send OTP (unified login/register)
    Route::post('/send-otp', [AuthController::class, 'sendOtp'])
        ->name('user.auth.send-otp');

    // Verify OTP
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])
        ->name('user.auth.verify-otp');

    // Resend OTP
    Route::post('/resend-otp', [AuthController::class, 'resendOtp'])
        ->name('user.auth.resend-otp');

    // Login with Fingerprint
    Route::post('/fingerprint', [AuthController::class, 'loginWithFingerprint'])
        ->name('user.auth.fingerprint');
});

// ========================================================================
// PUBLIC ROUTES (No Authentication Required)
// ========================================================================
Route::prefix('payment-methods')->group(function () {
    // Get all payment methods
    Route::get('/', [PaymentMethodController::class, 'index'])
        ->name('user.payment-methods.index');

});

// ========================================================================
// ZONES API (Public - list all active zones)
// ========================================================================
Route::get('/zones', [ZonesController::class, 'index'])->name('user.zones.index');

// ========================================================================
// HOME API (Public)
// ========================================================================
Route::prefix('home')->group(function () {
    Route::get('/slider', [HomeController::class, 'getSlider'])
        ->name('user.home.slider');

    // Get departments
    Route::get('/departments', [HomeController::class, 'getDepartments'])
        ->name('user.home.departments');

    // Get best/featured laundries
    Route::get('/best-laundries', [HomeController::class, 'getBestLaundries'])
        ->name('user.home.best-laundries');

    // Get advertisements
    Route::get('/ads', [HomeController::class, 'getAds'])
        ->name('user.home.ads');
});

// ========================================================================
// LAUNDRIES API (Public) - laundries = branches
// ========================================================================
Route::prefix('laundries')->group(function () {
    // Get all laundries (branches) in a zone
    Route::get('/all', [\Modules\Client\Http\Controllers\Api\ClientBranchController::class, 'index'])
        ->name('user.laundries.index');

    // Get laundry (branch) details
    Route::get('/{branch_id}', [\Modules\Client\Http\Controllers\Api\ClientBranchController::class, 'show'])
        ->name('user.laundries.show');

    // Get all services for a laundry (branch)
    Route::get('/{branch_id}/services', [\Modules\Client\Http\Controllers\Api\ClientBranchController::class, 'getServices'])
        ->name('user.laundries.services');

    // Get all pieces for a service in a laundry (branch)
    Route::get('/{branch_id}/services/{service_id}/pieces', [\Modules\Client\Http\Controllers\Api\ClientBranchController::class, 'getServicePieces'])
        ->name('user.laundries.service-pieces');

    // Get all additional services (additions) for a piece
    Route::get('/{branch_id}/pieces/{piece_id}/additions', [\Modules\Client\Http\Controllers\Api\ClientBranchController::class, 'getPieceAdditions'])
        ->name('user.laundries.piece-additions');

    Route::get('/{branch_id}/pieces/{piece_id}', [\Modules\Client\Http\Controllers\Api\ClientBranchController::class, 'getPieceDetails'])
        ->name('user.pieces.show');
    Route::get('/{branch_id}/services/{service_id}', [\Modules\Client\Http\Controllers\Api\ClientBranchController::class, 'getServiceDetails'])
        ->name('user.services.show');
});

// ========================================================================
// SERVICES API (Public)
// ========================================================================
Route::prefix('services')->group(function () {
    // Get all available services in client's zone
    Route::get('/available', [\Modules\Client\Http\Controllers\Api\ClientServiceController::class, 'getAvailableServices'])
        ->name('user.services.available');
});

// ========================================================================
// DEPARTMENT API
// ========================================================================
Route::prefix('departments')->group(function () {
    // Get all departments
    Route::get('/', [DepartmentController::class, 'index'])
        ->name('user.departments.index');

    // Get services in department
    Route::get('/{department_id}/services', [DepartmentController::class, 'getServices'])
        ->name('user.departments.services');

    // Get service item details by vendor and service
    Route::get('/vendors/{vendor_id}/services/{service_id}/items', [DepartmentController::class, 'getServiceItems'])
        ->name('user.departments.service-items');
});

// ========================================================================
// LOCATION API (Public - Zone Validation)
// ========================================================================
Route::post('/locations/validate-zone', [LocationController::class, 'validateZone'])
    ->name('user.locations.validate-zone');

// ============================================================================
// PROTECTED ROUTES (Require Authentication)
// ============================================================================
Route::middleware(['auth:client', 'banned'])->group(function () {

    // ========================================================================
    // AUTH - Profile Management
    // ========================================================================
    Route::prefix('auth')->group(function () {
        // Create/Complete Profile
        Route::post('/create-profile', [AuthController::class, 'createProfile'])
            ->name('user.auth.create-profile');

        // Logout
        Route::post('/logout', [AuthController::class, 'logout'])
            ->name('user.auth.logout');

        // Delete Account
        Route::delete('/delete-account', [AuthController::class, 'deleteAccount'])
            ->name('user.auth.delete-account');

        // Register Fingerprint
        Route::post('/register-fingerprint', [AuthController::class, 'registerFingerprint'])
            ->name('user.auth.register-fingerprint');

        Route::post('/remove-fingerprint', [AuthController::class, 'removeFingerprint'])
            ->name('user.auth.remove-fingerprint');
    });

    // ========================================================================
    // HOME API (Protected)
    // ========================================================================
    Route::prefix('home')->group(function () {
        // Get user data (requires auth)
        Route::get('/user-data', [HomeController::class, 'getUserData'])
            ->name('user.home.user-data');
    });

    // ========================================================================
    // WALLET API
    // ========================================================================
    Route::prefix('wallet')->group(function () {
        // Get wallet details (balance, transactions, cards)
        Route::get('/', [WalletController::class, 'index'])
            ->name('user.wallet.index');

        // Add credit card
        Route::post('/cards', [WalletController::class, 'addCard'])
            ->name('user.wallet.add-card');

        // Delete credit card
        Route::delete('/cards/{card_id}', [WalletController::class, 'deleteCard'])
            ->name('user.wallet.delete-card');

        // Add deposit to wallet
        Route::post('/deposit', [WalletController::class, 'addDeposit'])
            ->name('user.wallet.deposit');

        // Add direct deposit to wallet (no payment gateway)
        Route::post('/deposit/direct', [WalletController::class, 'addDirectDeposit'])
            ->name('user.wallet.deposit.direct');

        // Verify deposit
        Route::get('/deposit/{transactionId}/verify', [WalletController::class, 'verifyDeposit'])
            ->name('user.wallet.deposit.verify');

        // Get transaction history
        Route::get('/transactions', [WalletController::class, 'getTransactions'])
            ->name('user.wallet.transactions');
    });

    // ========================================================================
    // LOCATION API
    // ========================================================================
    Route::prefix('locations')->group(function () {
        // Get all user locations/addresses
        Route::get('/', [LocationController::class, 'index'])
            ->name('user.locations.index');

        // Get single address
        Route::get('/{address_id}', [LocationController::class, 'show'])
            ->name('user.locations.show');

        // Add new location
        Route::post('/', [LocationController::class, 'store'])
            ->name('user.locations.store');

        // Update location
        Route::put('/{address_id}', [LocationController::class, 'update'])
            ->name('user.locations.update');

        // Delete location
        Route::delete('/{address_id}', [LocationController::class, 'destroy'])
            ->name('user.locations.destroy');

        // Set default location
        Route::post('/{address_id}/set-default', [LocationController::class, 'setDefault'])
            ->name('user.locations.set-default');
    });

    // ========================================================================
    // ORDER CREATION API
    // ========================================================================
    Route::prefix('orders')->group(function () {
        // Get orders on the way (on_way_to_pickup or on_way_to_delivery)
        Route::get('/on-the-way/{order_id}', [OrderController::class, 'getOrdersOnTheWay'])
            ->name('user.orders.on-the-way');

        // Client confirms actual handoff (gave/received clothes)
        Route::post('/{order_id}/confirm-handoff', [OrderController::class, 'confirmHandoff'])
            ->name('user.orders.confirm-handoff');

        // Confirm readiness or postpone pickup/delivery (reason + new time)
        Route::post('/{order_id}/visit-response', [OrderController::class, 'respondToDriverVisit'])
            ->name('user.orders.visit-response');

        // Calculate order summary (without creating order)
        Route::post('/calculate', [OrderController::class, 'calculate'])
            ->name('user.orders.calculate');

        // Get orders pending client action (approval or payment)
        Route::get('/pending-approval/{order_id}', [OrderController::class, 'getPendingApproval'])
            ->name('user.orders.pending-action');

        // Get all orders (with status filter)
        Route::get('/', [OrderController::class, 'index'])
            ->name('user.orders.index');

        // Create new order
        Route::post('/', [OrderController::class, 'store'])
            ->name('user.orders.store');

        // Get order details
        Route::get('/{order_id}', [OrderController::class, 'show'])
            ->name('user.orders.show');

        // Send reminder notification to laundry
        Route::post('/{order_id}/send-reminder', [OrderController::class, 'sendReminderNotification'])
            ->name('user.orders.send-reminder');

        // Complete payment after vendor approval
        Route::post('/{order_id}/complete-payment', [OrderController::class, 'completePayment'])
            ->name('user.orders.complete-payment');

        // Get payment status
        Route::get('/{order_id}/payment-status', [OrderController::class, 'getPaymentStatus'])
            ->name('user.orders.payment-status');

        // Confirm a gateway payment by its transaction_id and get the resulting order.
        // Pull-based recovery for when the gateway redirect/webhook never reaches us
        // (e.g. Moyasar hosted-invoice page that does not redirect back). Verifies the
        // payment server-side, materializes the pending order, returns order + status.
        Route::match(['get', 'post'], '/payment/confirm/{transactionId}', [PaymentController::class, 'confirmByTransaction'])
            ->where('transactionId', '.*')
            ->name('user.orders.payment-confirm');

        // Get available discounts
        Route::get('/get-valid/discounts', [OrderController::class, 'getDiscounts'])
            ->name('user.orders.discounts');

        // Validate coupon code
        Route::post('/validate-coupon', [OrderController::class, 'validateCoupon'])
            ->name('user.orders.validate-coupon');

        // Rate order
        Route::post('/{order_id}/rate', [OrderController::class, 'rateOrder'])
            ->name('user.orders.rate');

        // Update receipt/modification status
        Route::put('/{order_id}/receipt-status', [OrderController::class, 'updateReceiptStatus'])
            ->name('user.orders.update-receipt-status');

        // Delete order (soft delete/cancel)
        Route::delete('/{order_id}', [OrderController::class, 'destroy'])
            ->name('user.orders.destroy');
    });

    // ========================================================================
    // ORDER TRACKING API
    // ========================================================================
    Route::prefix('orders/{order_id}')->group(function () {
        // Get order tracking
        Route::get('/tracking', [OrderTrackingController::class, 'getTracking'])
            ->name('user.orders.tracking');

        // Scan QR code to confirm delivery
        Route::post('/qr-code', [OrderTrackingController::class, 'scanQRCode'])
            ->name('user.orders.scan-qr-code');

        // Cancel order
        Route::post('/cancel', [OrderTrackingController::class, 'cancelOrder'])
            ->name('user.orders.cancel');

        // Update order — also pays any price increase (surcharge) in the same request
        // via surcharge_payment (the former POST /additional-payments is merged here).
        Route::put('/update', [OrderTrackingController::class, 'updateOrder'])
            ->name('user.orders.update');

        // Get delivery route
        Route::get('/delivery-route', [OrderTrackingController::class, 'getDeliveryRoute'])
            ->name('user.orders.delivery-route');

        // Get estimated time
        Route::get('/estimated-time', [OrderTrackingController::class, 'getEstimatedTime'])
            ->name('user.orders.estimated-time');

        // Confirm delivery (by QR code scan)
        Route::post('/confirm-delivery', [OrderTrackingController::class, 'confirmDelivery'])
            ->name('user.orders.confirm-delivery');

        // Get status log
        Route::get('/status-log', [OrderTrackingController::class, 'getStatusLog'])
            ->name('user.orders.status-log');
    });

    // ========================================================================
    // NOTIFICATIONS API
    // ========================================================================
    Route::prefix('notifications')->group(function () {
        // Get all notifications
        Route::get('/', [NotificationController::class, 'index'])
            ->name('user.notifications.index');

        // Mark notification as read
        Route::post('/{notification_id}/read', [NotificationController::class, 'markAsRead'])
            ->name('user.notifications.mark-read');

        // Mark all as read
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead'])
            ->name('user.notifications.mark-all-read');

    });

    // ========================================================================
    // FIREBASE / FCM TOKEN API
    // ========================================================================
    // Update FCM token
    Route::post('/fcm-token', [FirebaseTestController::class, 'updateFcmToken'])
        ->name('user.fcm-token.update');

    // Get all FCM tokens
    Route::get('/fcm-tokens', [FirebaseTestController::class, 'getFcmTokens'])
        ->name('user.fcm-tokens.index');

    // Delete FCM token by ID
    Route::delete('/fcm-token/{tokenId}', [FirebaseTestController::class, 'deleteFcmToken'])
        ->name('user.fcm-token.delete');

    // Delete FCM token by device ID
    Route::delete('/fcm-token/device/{deviceId}', [FirebaseTestController::class, 'deleteFcmTokenByDevice'])
        ->name('user.fcm-token.delete-by-device');

    // ========================================================================
    // CHAT API (Needs Meeting Discussion)
    // ========================================================================
    Route::prefix('chats')->group(function () {
        // Get all conversations
        Route::get('/', [ChatController::class, 'getConversations'])
            ->name('user.chats.index');

        // Get conversation messages
        Route::get('/{conversation_id}/messages', [ChatController::class, 'getMessages'])
            ->name('user.chats.messages');

        // Send message (conversation_id → existing chat; order_id → find/create order chat; neither → support chat)
        Route::post('/send', [ChatController::class, 'sendMessage'])
            ->name('user.chats.send');
    });
});
