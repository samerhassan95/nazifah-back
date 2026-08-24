<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminAccountController;
use Modules\Admin\Http\Controllers\AdminAdController;
use Modules\Admin\Http\Controllers\AdminAddressController;
use Modules\Admin\Http\Controllers\AdminBanController;
use Modules\Admin\Http\Controllers\AdminBannerOfferController;
use Modules\Admin\Http\Controllers\AdminBranchController;
use Modules\Admin\Http\Controllers\AdminCategoryController;
use Modules\Admin\Http\Controllers\AdminChatsController;
use Modules\Admin\Http\Controllers\AdminClientController;
use Modules\Admin\Http\Controllers\AdminController;
use Modules\Admin\Http\Controllers\AdminDashboardController;
use Modules\Admin\Http\Controllers\AdminDriverController;
use Modules\Admin\Http\Controllers\AdminGeneralSettingController;
use Modules\Admin\Http\Controllers\AdminIconController;
use Modules\Admin\Http\Controllers\AdminInvoiceSettingController;
use Modules\Admin\Http\Controllers\AdminLaundryAdditionalServiceController;
use Modules\Admin\Http\Controllers\AdminLaundryBankAccountController;
use Modules\Admin\Http\Controllers\AdminLaundryBranchController;
use Modules\Admin\Http\Controllers\AdminLaundryCategoryController;
use Modules\Admin\Http\Controllers\AdminLaundryController;
use Modules\Admin\Http\Controllers\AdminLaundryDriverController;
use Modules\Admin\Http\Controllers\AdminLaundryOrderController;
use Modules\Admin\Http\Controllers\AdminLaundryPieceController;
use Modules\Admin\Http\Controllers\AdminLaundryServiceController;
use Modules\Admin\Http\Controllers\AdminLaundryWalletController;
use Modules\Admin\Http\Controllers\AdminNotificationController;
use Modules\Admin\Http\Controllers\AdminOrderController;
use Modules\Admin\Http\Controllers\AdminOwnerController;
use Modules\Admin\Http\Controllers\AdminPaymentController;
use Modules\Admin\Http\Controllers\AdminPaymentGatewayController;
use Modules\Admin\Http\Controllers\AdminPaymentMethodController;
use Modules\Admin\Http\Controllers\AdminPieceController;
use Modules\Admin\Http\Controllers\AdminPlatformSettingController;
use Modules\Admin\Http\Controllers\AdminRoleController;
use Modules\Admin\Http\Controllers\AdminServiceController;
use Modules\Admin\Http\Controllers\AdminSettingController;
use Modules\Admin\Http\Controllers\AdminUserController;
use Modules\Admin\Http\Controllers\AdminVendorController;
use Modules\Admin\Http\Controllers\AdminZoneController;
use Modules\Admin\Http\Controllers\Api\V1\AdminReportController;
use Modules\Admin\Http\Controllers\AuthController;
use Modules\Admin\Http\Controllers\BankController;
use Modules\Admin\Http\Controllers\CouponController;
use Modules\Admin\Http\Controllers\FinanceController;
use Modules\Admin\Http\Controllers\MarketingStatsController;
use Modules\Admin\Http\Controllers\SubscriptionController;

/*
|--------------------------------------------------------------------------
| Admin Authentication Routes (Public)
|--------------------------------------------------------------------------
*/
Route::prefix('v1/admin/auth')->middleware('throttle:5,1')->name('api.admin.auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('send-otp', [AuthController::class, 'sendOtp'])->name('send-otp');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
});

/*
|--------------------------------------------------------------------------
| Admin Protected Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:admin'])->prefix('v1/admin')->group(function () {

    // Auth Routes (Protected)
    Route::prefix('auth')->name('api.admin.auth.')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('change-password', [AuthController::class, 'changePassword'])->name('change-password');
    });

    // Admin Management
    Route::apiResource('admins', AdminController::class)->names('admin');

    // Unified Ban Management (for all user types)
    Route::prefix('ban')->group(function () {
        Route::post('/', [AdminBanController::class, 'ban'])->name('api.admin.ban');
        Route::post('/unban', [AdminBanController::class, 'unban'])->name('api.admin.unban');
        Route::get('/status', [AdminBanController::class, 'getBanStatus'])->name('api.admin.ban.status');
        Route::get('/banned-users', [AdminBanController::class, 'getBannedUsers'])->name('api.admin.banned-users');
    });

    // Chats – admin can send in any conversation or open new with target_type+target_id
    Route::prefix('chats')->group(function () {
        Route::get('/', [AdminChatsController::class, 'index'])->name('api.admin.chats.index');
        Route::post('/send', [AdminChatsController::class, 'sendMessage'])->name('api.admin.chats.send');
        Route::get('/{conversationId}', [AdminChatsController::class, 'show'])->name('api.admin.chats.show');
        Route::get('/{conversationId}/messages', [AdminChatsController::class, 'getMessages'])->name('api.admin.chats.messages');
        Route::post('/{conversationId}/send', [AdminChatsController::class, 'sendMessage'])->name('api.admin.chats.send.conversation');
    });

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('overview', [AdminDashboardController::class, 'overview']);
        Route::get('order-trends', [AdminDashboardController::class, 'orderTrends']);
        Route::get('monthly_revenue_growth', [AdminDashboardController::class, 'monthlyRevenueGrowth']);
        Route::get('top_areas', [AdminDashboardController::class, 'topAreas']);
        Route::get('top-vendors', [AdminDashboardController::class, 'topVendors']);
        Route::get('recent-activities', [AdminDashboardController::class, 'recentActivities']);
        Route::get('user-growth', [AdminDashboardController::class, 'userGrowth']);
        Route::get('notifications', [AdminDashboardController::class, 'notifications']);
    });

    // Marketing Management (Ads, Banners, Coupons, Stats)
    Route::prefix('marketing')->group(function () {
        // Marketing Dashboard
        Route::get('stats', [MarketingStatsController::class, 'index']);

        // Ad Management
        Route::prefix('ads')->group(function () {
            Route::get('/', [AdminAdController::class, 'index']);
            Route::post('/', [AdminAdController::class, 'store']);
            Route::get('statistics', [AdminAdController::class, 'statistics']);
            Route::get('{id}', [AdminAdController::class, 'show']);
            Route::put('{id}', [AdminAdController::class, 'update']);
            Route::delete('{id}', [AdminAdController::class, 'destroy']);
            Route::post('{id}/toggle-status', [AdminAdController::class, 'toggleStatus']);
        });

        // Banner Offer Management
        Route::prefix('banner-offers')->group(function () {
            Route::get('/', [AdminBannerOfferController::class, 'index']);
            Route::post('/', [AdminBannerOfferController::class, 'store']);
            Route::get('statistics', [AdminBannerOfferController::class, 'statistics']);
            Route::get('{id}', [AdminBannerOfferController::class, 'show']);
            Route::put('{id}', [AdminBannerOfferController::class, 'update']);
            Route::delete('{id}', [AdminBannerOfferController::class, 'destroy']);
            Route::post('{id}/toggle-status', [AdminBannerOfferController::class, 'toggleStatus']);
        });

        // Coupon & Discount Management (Unified)
        // Note: Using 'coupons' as the unified resource name for marketing discounts
        $couponRoutes = function () {
            Route::get('/', [CouponController::class, 'index']);
            Route::post('/', [CouponController::class, 'store']);
            Route::get('statistics', [CouponController::class, 'statistics']);
            Route::post('bulk-update-status', [CouponController::class, 'bulkUpdateStatus']);
            Route::get('{id}', [CouponController::class, 'show']);
            Route::put('{id}', [CouponController::class, 'update']);
            Route::delete('{id}', [CouponController::class, 'destroy']);
            Route::post('{id}/activate', [CouponController::class, 'activate']);
            Route::post('{id}/deactivate', [CouponController::class, 'deactivate']);
            Route::post('{id}/duplicate', [CouponController::class, 'duplicate']);
            Route::get('{id}/toggle-status', [CouponController::class, 'toggleStatus']);
        };

        Route::prefix('coupons')->group($couponRoutes);
    });

    // Client Management
    Route::prefix('clients')->group(function () {
        Route::get('/', [AdminClientController::class, 'index']);
        Route::post('/', [AdminClientController::class, 'store']);
        Route::get('statistics', [AdminClientController::class, 'statistics']);
        Route::get('clients_per_city', [AdminClientController::class, 'clientsPerCity']);
        Route::get('{id}', [AdminClientController::class, 'show']);
        Route::put('{id}', [AdminClientController::class, 'update']);
        Route::delete('{id}', [AdminClientController::class, 'destroy']);
        Route::post('{id}/toggle-status', [AdminClientController::class, 'toggleStatus']);
        Route::post('{id}/ban', [AdminClientController::class, 'ban']);
        Route::post('{id}/unban', [AdminClientController::class, 'unban']);
    });

    // Owner Management
    Route::prefix('owners')->group(function () {
        Route::get('/', [AdminOwnerController::class, 'index']);
        Route::post('/', [AdminOwnerController::class, 'store']);
        Route::get('statistics', [AdminOwnerController::class, 'statistics']);
        Route::get('{id}', [AdminOwnerController::class, 'show']);
        Route::put('{id}', [AdminOwnerController::class, 'update']);
        Route::delete('{id}', [AdminOwnerController::class, 'destroy']);
        Route::post('{id}/toggle-status', [AdminOwnerController::class, 'toggleStatus']);
    });

    // Driver Management
    Route::prefix('drivers')->group(function () {
        Route::get('/', [AdminDriverController::class, 'index']);
        Route::post('/', [AdminDriverController::class, 'store']);
        Route::get('statistics', [AdminDriverController::class, 'statistics']);
        Route::get('{id}', [AdminDriverController::class, 'show']);
        Route::put('{id}', [AdminDriverController::class, 'update']);
        Route::delete('{id}', [AdminDriverController::class, 'destroy']);
        Route::post('{id}/toggle-status', [AdminDriverController::class, 'toggleStatus']);
        Route::post('{id}/toggle-availability', [AdminDriverController::class, 'toggleAvailability']);
        Route::post('{id}/ban', [AdminDriverController::class, 'ban']);
        Route::post('{id}/unban', [AdminDriverController::class, 'unban']);
    });

    // Vendor Management
    Route::prefix('vendors')->group(function () {
        Route::get('/', [AdminVendorController::class, 'index']);
        Route::post('/', [AdminVendorController::class, 'store']);
        Route::get('statistics', [AdminVendorController::class, 'statistics']);
        Route::get('{id}', [AdminVendorController::class, 'show']);
        Route::put('{id}', [AdminVendorController::class, 'update']);
        Route::delete('{id}', [AdminVendorController::class, 'destroy']);
        Route::post('{id}/toggle-status', [AdminVendorController::class, 'toggleStatus']);
        Route::post('{id}/toggle-featured', [AdminVendorController::class, 'toggleFeatured']);
        Route::post('{id}/ban', [AdminVendorController::class, 'ban']);
        Route::post('{id}/unban', [AdminVendorController::class, 'unban']);
    });

    // Order Management
    Route::prefix('orders')->group(function () {
        Route::get('/', [AdminOrderController::class, 'index']);
        Route::get('statistics', [AdminOrderController::class, 'getStatistics']);
        Route::get('orders_per_city', [AdminOrderController::class, 'ordersPerCity']);
        Route::get('orders_status', [AdminOrderController::class, 'ordersStatus']);
        Route::get('{id}/details', [AdminOrderController::class, 'details']);
        Route::get('{id}/invoice', [AdminOrderController::class, 'invoice']);
        Route::get('{id}', [AdminOrderController::class, 'show']);
        Route::put('{id}/status', [AdminOrderController::class, 'updateStatus']);
        Route::put('{id}/assign-driver', [AdminOrderController::class, 'assignDriver']);
        Route::put('{id}/payment-status', [AdminOrderController::class, 'updatePaymentStatus']);
        Route::post('{id}/cancel', [AdminOrderController::class, 'cancel']);

        // Payment management — view transactions & capture / void / refund via gateway
        Route::get('{id}/payments', [AdminPaymentController::class, 'transactions']);
        Route::post('{id}/payments/capture', [AdminPaymentController::class, 'capture']);
        Route::post('{id}/payments/void', [AdminPaymentController::class, 'void']);
        Route::post('{id}/payments/refund', [AdminPaymentController::class, 'refund']);
    });

    // Category Management
    Route::prefix('categories')->group(function () {
        Route::get('/', [AdminCategoryController::class, 'index']);
        Route::post('/', [AdminCategoryController::class, 'store']);
        Route::get('statistics', [AdminCategoryController::class, 'statistics']);
        Route::get('{id}', [AdminCategoryController::class, 'show']);
        Route::put('{id}', [AdminCategoryController::class, 'update']);
        Route::delete('{id}', [AdminCategoryController::class, 'destroy']);
        Route::post('{id}/toggle-status', [AdminCategoryController::class, 'toggleStatus']);
    });

    // Service Management
    Route::prefix('services')->group(function () {
        Route::get('/', [AdminServiceController::class, 'index']);
        Route::post('/', [AdminServiceController::class, 'store']);
        Route::get('statistics', [AdminServiceController::class, 'statistics']);
        Route::get('{id}', [AdminServiceController::class, 'show']);
        Route::put('{id}', [AdminServiceController::class, 'update']);
        Route::delete('{id}', [AdminServiceController::class, 'destroy']);
        Route::post('{id}/toggle-status', [AdminServiceController::class, 'toggleStatus']);
        Route::post('{id}/toggle-availability', [AdminServiceController::class, 'toggleAvailability']);
    });

    // Zone Management
    Route::prefix('zones')->group(function () {
        Route::get('/', [AdminZoneController::class, 'index']);
        Route::post('/', [AdminZoneController::class, 'store']);
        Route::get('statistics', [AdminZoneController::class, 'statistics']);
        Route::get('{id}', [AdminZoneController::class, 'show']);
        Route::put('{id}', [AdminZoneController::class, 'update']);
        Route::delete('{id}', [AdminZoneController::class, 'destroy']);
        Route::post('{id}/toggle-status', [AdminZoneController::class, 'toggleStatus']);
    });

    // Banks Management (lookup used by vendor/branch bank accounts)
    Route::prefix('banks')->group(function () {
        Route::get('/', [BankController::class, 'index']);
        Route::post('/', [BankController::class, 'store']);
        Route::get('{id}', [BankController::class, 'show']);
        Route::put('{id}', [BankController::class, 'update']);
        Route::delete('{id}', [BankController::class, 'destroy']);
        Route::post('{id}/toggle-status', [BankController::class, 'toggleStatus']);
    });

    // Branch Management
    Route::prefix('branches')->group(function () {
        Route::get('/', [AdminBranchController::class, 'index']);
        Route::post('/', [AdminBranchController::class, 'store']);
        Route::get('statistics', [AdminBranchController::class, 'statistics']);
        Route::get('{id}', [AdminBranchController::class, 'show']);
        Route::put('{id}', [AdminBranchController::class, 'update']);
        Route::delete('{id}', [AdminBranchController::class, 'destroy']);
        Route::post('{id}/toggle-status', [AdminBranchController::class, 'toggleStatus']);

        // Branch Pieces Management
        Route::get('{id}/pieces', [AdminBranchController::class, 'getPieces']);
        Route::post('{id}/pieces', [AdminBranchController::class, 'addPieces']);
        Route::delete('{id}/pieces', [AdminBranchController::class, 'removePieces']);
        Route::put('{id}/pieces', [AdminBranchController::class, 'syncPieces']);
        Route::post('{branchId}/pieces/{pieceId}/toggle-status', [AdminBranchController::class, 'togglePieceStatus']);
    });

    // Notification Management
    // Note: index/show/destroy are intentionally not registered here — they are
    // shadowed by the identical GET/DELETE routes in Modules/Notification/routes/api.php
    // (Modules\Notification\Http\Controllers\Api\V1\NotificationController), which is
    // the controller that actually serves grouped/paginated admin notifications.
    Route::prefix('notifications')->group(function () {
        Route::post('send-bulk', [AdminNotificationController::class, 'sendBulkNotification']);
        Route::get('statistics', [AdminNotificationController::class, 'statistics']);
    });

    // ========================================================================
    // ADMIN PERSONAL NOTIFICATIONS (Handled by Notification Module)
    // ========================================================================
    // See Modules/Notification/routes/api.php for personal notification routes

    // Address Management
    Route::prefix('addresses')->group(function () {
        Route::get('/', [AdminAddressController::class, 'index']);
        Route::post('/', [AdminAddressController::class, 'store']);
        Route::get('statistics', [AdminAddressController::class, 'statistics']);
        Route::get('{id}', [AdminAddressController::class, 'show']);
        Route::put('{id}', [AdminAddressController::class, 'update']);
        Route::delete('{id}', [AdminAddressController::class, 'destroy']);
    });

    // Piece Management
    Route::prefix('pieces')->group(function () {
        Route::get('/', [AdminPieceController::class, 'index']);
        Route::post('/', [AdminPieceController::class, 'store']);
        Route::get('statistics', [AdminPieceController::class, 'statistics']);
        Route::get('{id}', [AdminPieceController::class, 'show']);
        Route::put('{id}', [AdminPieceController::class, 'update']);
        Route::delete('{id}', [AdminPieceController::class, 'destroy']);
        Route::post('{id}/toggle-status', [AdminPieceController::class, 'toggleStatus']);
    });

    // Icon Management
    Route::prefix('icons')->group(function () {
        Route::get('/', [AdminIconController::class, 'index']);
        Route::get('icons/all', [AdminIconController::class, 'all'])->name('admin.icons.all');
        Route::post('/', [AdminIconController::class, 'store']);
        Route::get('{id}', [AdminIconController::class, 'show']);
        Route::put('{id}', [AdminIconController::class, 'update']);
        Route::delete('{id}', [AdminIconController::class, 'destroy']);
    });

    // Settings Management
    Route::prefix('settings')->group(function () {
        Route::get('/', [AdminSettingController::class, 'index']);
        Route::post('/', [AdminSettingController::class, 'store']);
        // Routes by key must come before routes by id
        Route::get('key/{key}', [AdminSettingController::class, 'getByKey']);
        Route::put('key/{key}', [AdminSettingController::class, 'updateByKey']);
        Route::delete('key/{key}', [AdminSettingController::class, 'destroyByKey']);
        // Routes by id
        Route::get('{id}', [AdminSettingController::class, 'show']);
        Route::put('{id}', [AdminSettingController::class, 'update']);
        Route::delete('{id}', [AdminSettingController::class, 'destroy']);
    });

    // Platform Settings Management (New Enhanced Settings)
    Route::prefix('platform-settings')->group(function () {
        Route::get('/', [AdminPlatformSettingController::class, 'index']);
        Route::post('/', [AdminPlatformSettingController::class, 'store']);
        Route::post('bulk-update', [AdminPlatformSettingController::class, 'updateBulk']);
        Route::get('group/{group}', [AdminPlatformSettingController::class, 'getByGroup']);
        Route::get('{key}', [AdminPlatformSettingController::class, 'show']);
        Route::put('{key}', [AdminPlatformSettingController::class, 'update']);
        Route::delete('{key}', [AdminPlatformSettingController::class, 'destroy']);
    });

    // General Settings Management
    Route::prefix('general-settings')->group(function () {
        Route::get('/', [AdminGeneralSettingController::class, 'index']);
        Route::post('/', [AdminGeneralSettingController::class, 'update']);
    });

    // Invoice Settings Management
    Route::prefix('invoice-settings')->group(function () {
        Route::get('/', [AdminInvoiceSettingController::class, 'index']);
        Route::put('/', [AdminInvoiceSettingController::class, 'update']);
    });

    // Timezones
    Route::get('timezones', [AdminGeneralSettingController::class, 'timezones']);

    // Account Management (Current Admin)
    Route::prefix('account')->group(function () {
        Route::get('/', [AdminAccountController::class, 'show']);
        Route::post('/', [AdminAccountController::class, 'update']);
        Route::post('change-password', [AdminAccountController::class, 'changePassword']);
    });

    // Users Management (Admin Users)
    Route::prefix('users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::post('/', [AdminUserController::class, 'store']);
        Route::get('{id}', [AdminUserController::class, 'show']);
        Route::put('{id}', [AdminUserController::class, 'update']);
        Route::delete('{id}', [AdminUserController::class, 'destroy']);
    });

    // Payment Methods Management
    Route::prefix('payment-methods')->group(function () {
        Route::get('/', [AdminPaymentMethodController::class, 'index']);
        Route::post('sort', [AdminPaymentMethodController::class, 'updateSortOrder']);
        Route::put('{id}', [AdminPaymentMethodController::class, 'update']);
        Route::patch('{id}/status', [AdminPaymentMethodController::class, 'updateStatus']);
    });

    // Payment Gateways — choose the ACTIVE gateway for real transactions
    // (amazon_pay <-> moyasar). DB-backed; switches at runtime without a deploy.
    Route::prefix('payment-gateways')->group(function () {
        Route::get('/', [AdminPaymentGatewayController::class, 'index']);
        Route::put('active', [AdminPaymentGatewayController::class, 'setActive']);
        Route::post('active', [AdminPaymentGatewayController::class, 'setActive']);
    });

    // Roles & Permissions Management
    Route::prefix('roles')->group(function () {
        Route::get('/', [AdminRoleController::class, 'index']);
        Route::post('/', [AdminRoleController::class, 'store']);
        Route::get('permissions', [AdminRoleController::class, 'permissions']);
        Route::get('{id}', [AdminRoleController::class, 'show']);
        Route::put('{id}', [AdminRoleController::class, 'update']);
        Route::delete('{id}', [AdminRoleController::class, 'destroy']);
    });

    // Laundry Management Routes
    Route::prefix('laundries')->group(function () {
        // Laundry Data
        Route::get('laundry_data', [AdminLaundryController::class, 'laundryData']);

        // Branches
        Route::prefix('branches')->group(function () {
            Route::get('/', [AdminLaundryBranchController::class, 'index']);
            Route::post('/', [AdminLaundryBranchController::class, 'store']);
            Route::get('{id}', [AdminLaundryBranchController::class, 'show']);
            Route::put('{id}', [AdminLaundryBranchController::class, 'update']);
            Route::delete('{id}', [AdminLaundryBranchController::class, 'destroy']);
        });

        // Orders
        Route::get('orders', [AdminLaundryOrderController::class, 'index']);

        // Services
        Route::prefix('services')->group(function () {
            Route::get('/', [AdminLaundryServiceController::class, 'index']);
            Route::post('/', [AdminLaundryServiceController::class, 'store']);
            Route::get('{id}', [AdminLaundryServiceController::class, 'show']);
            Route::put('{id}', [AdminLaundryServiceController::class, 'update']);
            Route::delete('{id}', [AdminLaundryServiceController::class, 'destroy']);
        });

        // Drivers
        Route::prefix('drivers')->group(function () {
            Route::get('/', [AdminLaundryDriverController::class, 'index']);
            Route::post('/', [AdminLaundryDriverController::class, 'store']);
            Route::get('{id}', [AdminLaundryDriverController::class, 'show']);
            Route::put('{id}', [AdminLaundryDriverController::class, 'update']);
            Route::delete('{id}', [AdminLaundryDriverController::class, 'destroy']);
        });

        // Categories
        Route::prefix('categories')->group(function () {
            Route::get('/', [AdminLaundryCategoryController::class, 'index']);
            Route::post('/', [AdminLaundryCategoryController::class, 'store']);
            Route::get('{id}', [AdminLaundryCategoryController::class, 'show']);
            Route::put('{id}', [AdminLaundryCategoryController::class, 'update']);
            Route::delete('{id}', [AdminLaundryCategoryController::class, 'destroy']);
        });

        // Pieces
        Route::prefix('pieces')->group(function () {
            Route::get('/', [AdminLaundryPieceController::class, 'index']);
            Route::post('/', [AdminLaundryPieceController::class, 'store']);
            Route::get('{id}', [AdminLaundryPieceController::class, 'show']);
            Route::put('{id}', [AdminLaundryPieceController::class, 'update']);
            Route::delete('{id}', [AdminLaundryPieceController::class, 'destroy']);
        });

        // Additional Services
        Route::prefix('additional_services')->group(function () {
            Route::get('/', [AdminLaundryAdditionalServiceController::class, 'index']);
            Route::post('/', [AdminLaundryAdditionalServiceController::class, 'store']);
            Route::get('{id}', [AdminLaundryAdditionalServiceController::class, 'show']);
            Route::put('{id}', [AdminLaundryAdditionalServiceController::class, 'update']);
            Route::delete('{id}', [AdminLaundryAdditionalServiceController::class, 'destroy']);
        });

        // Wallet
        Route::prefix('wallet')->group(function () {
            Route::get('/', [AdminLaundryWalletController::class, 'index']);
            Route::post('is_accepted', [AdminLaundryWalletController::class, 'acceptWithdrawal']);
            Route::post('is_rejected', [AdminLaundryWalletController::class, 'rejectWithdrawal']);
        });

        // Bank Accounts
        Route::prefix('bankAccount')->group(function () {
            Route::get('/', [AdminLaundryBankAccountController::class, 'index']);
            Route::get('{id}', [AdminLaundryBankAccountController::class, 'show']);
            Route::delete('{id}', [AdminLaundryBankAccountController::class, 'destroy']);
        });

        Route::prefix('bank_account')->group(function () {
            Route::post('is_accepted', [AdminLaundryBankAccountController::class, 'acceptBankAccount']);
            Route::post('is_rejected', [AdminLaundryBankAccountController::class, 'rejectBankAccount']);
        });
    });

    // Finance Management Routes
    Route::prefix('finance')->group(function () {
        Route::get('overview', [FinanceController::class, 'overview']);
        Route::get('wallet', [FinanceController::class, 'wallet']);
        Route::get('bank_account', [FinanceController::class, 'bankAccount']);
        Route::post('{id}/approve', [FinanceController::class, 'approve']);
        Route::get('withdrawals', [FinanceController::class, 'withdrawals']);
        Route::post('{id}/reject', [FinanceController::class, 'reject']);
    });

    Route::prefix('subscriptions')->group(function () {

        Route::prefix('plans')->group(function () {
            Route::get('/', [SubscriptionController::class, 'plans'])->name('plans.index');
            Route::get('{id}', [SubscriptionController::class, 'showPlan'])->name('plans.show');
            Route::post('/', [SubscriptionController::class, 'createPlan'])->name('plans.store');
            Route::put('{id}', [SubscriptionController::class, 'updatePlan'])->name('plans.update');
            Route::delete('{id}', [SubscriptionController::class, 'deletePlan'])->name('plans.destroy');
        });

        // Subscribers
        Route::get('subscribers', [SubscriptionController::class, 'subscribers']);
        Route::get('{id}', [SubscriptionController::class, 'showSubscription']);
        Route::post('assign', [SubscriptionController::class, 'assignToVendor']);
        Route::put('{id}', [SubscriptionController::class, 'updateVendorSubscription']);
        Route::patch('{id}/cancel', [SubscriptionController::class, 'cancelVendorSubscription']);
        Route::post('run-expiry-check', [SubscriptionController::class, 'triggerExpiryCheck']);
    });

    // Reports & Statistics Management
    Route::prefix('reports')->name('api.admin.reports.')->group(function () {
        Route::get('/orders_report', [AdminReportController::class, 'ordersReport'])->name('orders_report');
        Route::get('/vendor_report', [AdminReportController::class, 'vendorReport'])->name('vendor_report');
        Route::get('/client_report', [AdminReportController::class, 'clientReport'])->name('client_report');
    });

});
