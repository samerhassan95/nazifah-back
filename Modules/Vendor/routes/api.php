<?php

use Illuminate\Support\Facades\Route;
use Modules\Vendor\Http\Controllers\Api\V1\AdditionalServicesController;
use Modules\Vendor\Http\Controllers\Api\V1\AuthController;
use Modules\Vendor\Http\Controllers\Api\V1\BankAccountController;
use Modules\Vendor\Http\Controllers\Api\V1\BranchesController;
use Modules\Vendor\Http\Controllers\Api\V1\CatalogToggleController;
use Modules\Vendor\Http\Controllers\Api\V1\CategoriesController;
use Modules\Vendor\Http\Controllers\Api\V1\ChatsController;
use Modules\Vendor\Http\Controllers\Api\V1\DashboardController;
use Modules\Vendor\Http\Controllers\Api\V1\DriversController;
use Modules\Vendor\Http\Controllers\Api\V1\HomeController;
use Modules\Vendor\Http\Controllers\Api\V1\IconController as VendorIconController;
use Modules\Vendor\Http\Controllers\Api\V1\LaundryDataController;
use Modules\Vendor\Http\Controllers\Api\V1\OrderController;
use Modules\Vendor\Http\Controllers\Api\V1\PiecesController;
use Modules\Vendor\Http\Controllers\Api\V1\ReportRevenuesController;
use Modules\Vendor\Http\Controllers\Api\V1\ServicesController;
use Modules\Vendor\Http\Controllers\Api\V1\SubscriptionController;
use Modules\Vendor\Http\Controllers\Api\V1\VendorEmployeeController;
use Modules\Vendor\Http\Controllers\Api\V1\VendorRoleController;
use Modules\Vendor\Http\Controllers\Api\V1\WalletController;
use Modules\Vendor\Http\Controllers\VendorController;
use Modules\Zone\Http\Controllers\Api\ZonesController;

/*
|--------------------------------------------------------------------------
| Vendor API Routes
|--------------------------------------------------------------------------
| All routes are prefixed with /api/v1/vendor
|--------------------------------------------------------------------------
*/

// ============================================================================
// VENDOR AUTHENTICATION ROUTES (Public) - Login/Register for all vendor users
// ============================================================================
Route::prefix('v1/vendor/auth')->middleware('throttle:5,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('vendor.auth.login');
    Route::post('/register', [AuthController::class, 'register'])->name('vendor.auth.register');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->name('vendor.auth.verify-otp');
    Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->name('vendor.auth.resend-otp');
});

// ============================================================================
// PROTECTED ROUTES (Require Authentication - VendorEmployee)
// ============================================================================
Route::middleware(['auth:vendor', 'banned'])->prefix('v1/vendor')->group(function () {
    Route::get('/icons/all', [VendorIconController::class, 'all'])->name('vendor.icons.all');
    // ========================================================================
    // VENDOR PROFILE (Edit vendor profile directly)
    // ========================================================================
    Route::get('/vendor-profile', [AuthController::class, 'getVendorProfile'])->name('vendor.vendor-profile.show');
    Route::put('/vendor-profile', [AuthController::class, 'updateProfile'])->name('vendor.vendor-profile.update');
    Route::post('/vendor-profile', [AuthController::class, 'updateProfile'])->name('vendor.vendor-profile.update-post');

    // ========================================================================
    // AUTH - Profile Management (VendorEmployee)
    // ========================================================================
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('vendor.auth.me');
        Route::put('/profile', [AuthController::class, 'updateEmployeeProfile'])->name('vendor.auth.update-profile');
        Route::post('/fcm-token', [AuthController::class, 'updateFcmToken'])->name('vendor.auth.update-fcm-token');
        Route::put('/lang', [AuthController::class, 'updateLang'])->name('vendor.auth.update-lang');
        Route::post('/logout', [AuthController::class, 'logout'])->name('vendor.auth.logout');
    });

    // ========================================================================
    // ROLES & PERMISSIONS (Dynamic employee access control)
    // ========================================================================
    Route::prefix('roles')->group(function () {
        Route::get('/permissions', [VendorRoleController::class, 'permissions'])->name('vendor.roles.permissions');
        Route::get('/', [VendorRoleController::class, 'index'])->name('vendor.roles.index');
        Route::post('/', [VendorRoleController::class, 'store'])->name('vendor.roles.store');
        Route::get('/{id}', [VendorRoleController::class, 'show'])->name('vendor.roles.show');
        Route::put('/{id}', [VendorRoleController::class, 'update'])->name('vendor.roles.update');
        Route::delete('/{id}', [VendorRoleController::class, 'destroy'])->name('vendor.roles.destroy');
    });

    // ========================================================================
    // EMPLOYEES MANAGEMENT (For owners/managers to manage employees)
    // ========================================================================
    Route::prefix('employees')->group(function () {
        Route::get('/', [VendorEmployeeController::class, 'index'])->name('vendor.employees.index');
        Route::post('/', [VendorEmployeeController::class, 'store'])->name('vendor.employees.store');
        Route::get('/{id}', [VendorEmployeeController::class, 'show'])->name('vendor.employees.show');
        Route::put('/{id}', [VendorEmployeeController::class, 'update'])->name('vendor.employees.update');
        Route::delete('/{id}', [VendorEmployeeController::class, 'destroy'])->name('vendor.employees.destroy');
        Route::post('/{id}/toggle-ban', [VendorEmployeeController::class, 'toggleBan'])->name('vendor.employees.toggle-ban');
    });

    // ========================================================================
    // HOME API
    // ========================================================================
    Route::prefix('home')->group(function () {
        Route::get('/user-data', [HomeController::class, 'getUserData'])->name('vendor.home.user-data');
        Route::get('/', [HomeController::class, 'getHomeDashboard'])->name('vendor.home.index');
        Route::get('/services', [HomeController::class, 'getServices'])->name('vendor.home.services');
        Route::get('/new-orders', [HomeController::class, 'getNewOrders'])->name('vendor.home.new-orders');
        Route::get('/order-details/{orderId}', [HomeController::class, 'getOrderDetails'])->name('vendor.home.order-details');
        Route::get('/drivers-available', [HomeController::class, 'getDriversAvailable'])->name('vendor.home.drivers-available');
        Route::post('/assign-driver', [HomeController::class, 'assignDriver'])->name('vendor.home.assign-driver');
        Route::get('/qr-code/{orderId}', [HomeController::class, 'getQRCode'])->name('vendor.home.qr-code');
        Route::get('/featured-drivers', [HomeController::class, 'getFeaturedDrivers'])->name('vendor.home.featured-drivers');
        Route::get('/reviews', [HomeController::class, 'getReviews'])->name('vendor.home.reviews');
    });

    // ========================================================================
    // DASHBOARD API
    // ========================================================================
    Route::prefix('dashboard')->group(function () {
        Route::get('/statistics', [DashboardController::class, 'statistics'])->name('vendor.dashboard.statistics');
    });

    // ========================================================================
    // ORDERS API
    // ========================================================================
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('vendor.orders.index');
        Route::post('/calculate', [OrderController::class, 'calculate'])->name('vendor.orders.calculate');
        Route::get('/{orderId}', [OrderController::class, 'show'])->name('vendor.orders.show');
        Route::get('/{orderId}/status-log', [OrderController::class, 'getStatusLog'])->name('vendor.orders.status-log');
        Route::put('/{orderId}/status', [OrderController::class, 'updateStatus'])->name('vendor.orders.update-status');
        Route::post('/accept_order', [OrderController::class, 'acceptOrder'])->name('vendor.orders.accept-order');
        Route::post('/{orderId}/accept', [OrderController::class, 'acceptOrder'])->name('vendor.orders.accept');
        Route::post('/{orderId}/reject', [OrderController::class, 'rejectOrder'])->name('vendor.orders.reject');
        Route::post('/{orderId}/review', [OrderController::class, 'reviewOrder'])->name('vendor.orders.review');
    });

    // ========================================================================
    // WALLET API
    // ========================================================================
    Route::prefix('wallet')->group(function () {
        Route::get('/', [WalletController::class, 'index'])->name('vendor.wallet.index');
        Route::post('/withdrawal-request', [WalletController::class, 'addWithdrawalRequest'])->name('vendor.wallet.withdrawal-request');
    });

    // ========================================================================
    // BANK ACCOUNT API
    // ========================================================================
    Route::prefix('bank-accounts')->group(function () {
        Route::get('/', [BankAccountController::class, 'index'])->name('vendor.bank-accounts.index');
        Route::get('/banks', [BankAccountController::class, 'banks'])->name('vendor.bank-accounts.banks');
        Route::get('/branch-requests', [BankAccountController::class, 'getBranchRequests'])->name('vendor.bank-accounts.branch-requests');
        Route::post('/branch-requests/{id}/approve', [BankAccountController::class, 'approveBranchRequest'])->name('vendor.bank-accounts.branch-requests.approve');
        Route::post('/branch-requests/{id}/reject', [BankAccountController::class, 'rejectBranchRequest'])->name('vendor.bank-accounts.branch-requests.reject');
        Route::post('/', [BankAccountController::class, 'store'])->name('vendor.bank-accounts.store');
        Route::put('/{id}', [BankAccountController::class, 'update'])->name('vendor.bank-accounts.update');
    });

    // ========================================================================
    // SUBSCRIPTION API
    // ========================================================================
    Route::prefix('subscriptions')->group(function () {
        Route::get('/plans', [SubscriptionController::class, 'plans'])->name('vendor.subscriptions.plans');
        Route::get('/', [SubscriptionController::class, 'index'])->name('vendor.subscriptions.index');
        Route::get('/active', [SubscriptionController::class, 'active'])->name('vendor.subscriptions.active');
        Route::get('/{id}', [SubscriptionController::class, 'show'])->name('vendor.subscriptions.show');
        Route::post('/subscribe', [SubscriptionController::class, 'subscribe'])->name('vendor.subscriptions.subscribe');
        Route::post('/{id}/cancel', [SubscriptionController::class, 'cancel'])->name('vendor.subscriptions.cancel');
    });

    // ========================================================================
    // DRIVERS API
    // ========================================================================
    Route::prefix('drivers')->group(function () {
        Route::get('/', [DriversController::class, 'index'])->name('vendor.drivers.index');
        Route::post('/', [DriversController::class, 'store'])->name('vendor.drivers.store');
        Route::get('/filter', [DriversController::class, 'filterDrivers'])->name('vendor.drivers.filter');
        Route::get('/revenues', [DriversController::class, 'getDriverRevenues'])->name('vendor.drivers.revenues');
        Route::get('/report-revenues', [DriversController::class, 'getReportRevenues'])->name('vendor.drivers.report-revenues');
        Route::get('/revenues-filter', [DriversController::class, 'getRevenuesFilter'])->name('vendor.drivers.revenues-filter');
        Route::get('/available', [DriversController::class, 'available'])->name('vendor.drivers.available');
        Route::get('/{driverId}', [DriversController::class, 'show'])->name('vendor.drivers.show');
        Route::get('/{driverId}/revenues', [DriversController::class, 'getSpecificDriverRevenues'])->name('vendor.drivers.specific-revenues');
        Route::get('/{driverId}/delivered', [DriversController::class, 'getDriverDelivered'])->name('vendor.drivers.delivered');
        Route::put('/{driverId}', [DriversController::class, 'update'])->name('vendor.drivers.update');
        Route::delete('/{driverId}', [DriversController::class, 'destroy'])->name('vendor.drivers.destroy');
        Route::post('/{driverId}/toggle-status', [DriversController::class, 'toggleStatus'])->name('vendor.drivers.toggle-status');
        Route::post('/{driverId}/assign-branch', [DriversController::class, 'assignToBranch'])->name('vendor.drivers.assign-branch');
    });

    // ========================================================================
    // SERVICES API (system catalog + vendor catalog)
    // ========================================================================
    Route::get('/available-services', [ServicesController::class, 'availableServices'])->name('vendor.available-services.index');

    Route::prefix('services')->group(function () {
        Route::get('/', [ServicesController::class, 'index'])->name('vendor.services.index');
        Route::post('/', [ServicesController::class, 'store'])->name('vendor.services.store');
        Route::get('/filter', [ServicesController::class, 'filter'])->name('vendor.services.filter');
        Route::post('/{serviceId}/toggle-active', [CatalogToggleController::class, 'toggleService'])->name('vendor.services.toggle-active');
        Route::delete('/{serviceId}', [ServicesController::class, 'destroy'])->name('vendor.services.destroy');
        Route::get('/{serviceId}', [ServicesController::class, 'show'])->name('vendor.services.show');
    });

    // ========================================================================
    // BRANCHES API
    // ========================================================================
    Route::prefix('branches')->group(function () {
        Route::get('/', [BranchesController::class, 'index'])->name('vendor.branches.index');
        Route::post('/', [BranchesController::class, 'store'])->name('vendor.branches.store');
        Route::get('/{branchId}', [BranchesController::class, 'show'])->name('vendor.branches.show');
        Route::put('/{branchId}', [BranchesController::class, 'update'])->name('vendor.branches.update');
        Route::delete('/{branchId}', [BranchesController::class, 'destroy'])->name('vendor.branches.destroy');
        Route::get('/{branchId}/working-hours', [BranchesController::class, 'getWorkingHours'])->name('vendor.branches.working-hours.show');
        Route::post('/{branchId}/working-hours', [BranchesController::class, 'saveWorkingHours'])->name('vendor.branches.working-hours.save');
        Route::put('/{branchId}/working-hours', [BranchesController::class, 'saveWorkingHours'])->name('vendor.branches.working-hours.update');
        Route::get('/{branchId}/services', [BranchesController::class, 'getServices'])->name('vendor.branches.services');
        Route::post('/{branchId}/services', [BranchesController::class, 'addService'])->name('vendor.branches.add-service');
        Route::post('/{branchId}/services/{serviceId}/toggle-active', [CatalogToggleController::class, 'toggleBranchService'])->name('vendor.branches.services.toggle-active');
        Route::delete('/{branchId}/services/{serviceId}', [BranchesController::class, 'removeService'])->name('vendor.branches.remove-service');
        Route::get('/{branchId}/drivers', [BranchesController::class, 'getDrivers'])->name('vendor.branches.drivers');
        Route::get('/{branchId}/pieces', [BranchesController::class, 'getPieces'])->name('vendor.branches.pieces');
        Route::post('/{branchId}/pieces/{pieceId}/toggle-active', [CatalogToggleController::class, 'toggleBranchPiece'])->name('vendor.branches.pieces.toggle-active');
        Route::get('/{branchId}/available-pieces', [PiecesController::class, 'availableForBranchById'])->name('vendor.branches.available-pieces');
        Route::get('/{branchId}/available-additional-services', [AdditionalServicesController::class, 'availableForBranchById'])->name('vendor.branches.available-additional-services');

        // Update branch-specific service pricing
        Route::put('/{branchId}/services/{serviceId}/pricing', [BranchesController::class, 'updateServicePricing'])->name('vendor.branches.update-service-pricing');

        // Sync operations
        Route::post('/{branchId}/sync-services', [BranchesController::class, 'syncServices'])->name('vendor.branches.sync-services');
        Route::post('/{branchId}/sync-pieces', [BranchesController::class, 'syncPieces'])->name('vendor.branches.sync-pieces');

        // Assign service additions to multiple pieces in a branch
        Route::post('/{branchId}/assign-service-additions', [PiecesController::class, 'assignServiceAdditionsToPieces'])->name('vendor.branches.assign-service-additions');

        // New routes for branch-level additional services management
        Route::post('/{branchId}/additional-services', [BranchesController::class, 'addAdditionalService'])->name('vendor.branches.add-additional-service');
        Route::post('/{branchId}/additional-services/{serviceAdditionId}/toggle-active', [CatalogToggleController::class, 'toggleBranchServiceAddition'])->name('vendor.branches.additional-services.toggle-active');
        Route::delete('/{branchId}/additional-services/{serviceAdditionId}', [BranchesController::class, 'removeAdditionalService'])->name('vendor.branches.remove-additional-service');
    });

    // ========================================================================
    // ADDITIONAL SERVICES API
    // ========================================================================
    Route::prefix('additional-services')->group(function () {
        Route::get('/', [AdditionalServicesController::class, 'index'])->name('vendor.additional-services.index');
        Route::post('/', [AdditionalServicesController::class, 'store'])->name('vendor.additional-services.store');
        Route::get('/filter', [AdditionalServicesController::class, 'filter'])->name('vendor.additional-services.filter');
        Route::post('/{serviceId}/toggle-active', [CatalogToggleController::class, 'toggleServiceAddition'])->name('vendor.additional-services.toggle-active');
        Route::get('/{serviceId}', [AdditionalServicesController::class, 'show'])->name('vendor.additional-services.show');
        Route::put('/{serviceId}', [AdditionalServicesController::class, 'update'])->name('vendor.additional-services.update');
        Route::delete('/{serviceId}', [AdditionalServicesController::class, 'destroy'])->name('vendor.additional-services.destroy');
    });

    // ========================================================================
    // PIECES API
    // ========================================================================
    Route::prefix('pieces')->group(function () {
        Route::get('/', [PiecesController::class, 'index'])->name('vendor.pieces.index');
        Route::post('/', [PiecesController::class, 'store'])->name('vendor.pieces.store');
        Route::get('/filter', [PiecesController::class, 'filter'])->name('vendor.pieces.filter');
        Route::post('/{pieceId}/toggle-active', [CatalogToggleController::class, 'togglePiece'])->name('vendor.pieces.toggle-active');
        Route::get('/{pieceId}', [PiecesController::class, 'show'])->name('vendor.pieces.show');
        Route::put('/{pieceId}', [PiecesController::class, 'update'])->name('vendor.pieces.update');
        Route::post('/{pieceId}', [PiecesController::class, 'update'])->name('vendor.pieces.update-post');
        Route::delete('/{pieceId}', [PiecesController::class, 'destroy'])->name('vendor.pieces.destroy');

        // Assign main services and/or additional services to piece (branch_id optional for additions only)
        Route::post('/{pieceId}/additional-services', [PiecesController::class, 'assignAdditionalService'])->name('vendor.pieces.assign-additional-service');
    });

    // ========================================================================
    // REPORT REVENUES API
    // ========================================================================
    Route::prefix('report-revenues')->group(function () {
        Route::get('/', [ReportRevenuesController::class, 'index'])->name('vendor.report-revenues.index');
        Route::get('/filter', [ReportRevenuesController::class, 'filter'])->name('vendor.report-revenues.filter');
    });

    // ========================================================================
    // CATEGORIES API (Read-only for vendors, managed by admin)
    // ========================================================================
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoriesController::class, 'index'])->name('vendor.categories.index');
    });

    // ========================================================================
    // ZONES API (Read-only - list all active zones)
    // ========================================================================
    Route::get('/zones', [ZonesController::class, 'index'])->name('vendor.zones.index');

    // ========================================================================
    // CHATS API
    // ========================================================================
    Route::prefix('chats')->group(function () {
        Route::get('/', [ChatsController::class, 'index'])->name('vendor.chats.index');
        Route::post('/send', [ChatsController::class, 'sendMessage'])->name('vendor.chats.send');
        Route::get('/{conversationId}/messages', [ChatsController::class, 'getMessages'])->name('vendor.chats.messages');
        Route::post('/{conversationId}/send', [ChatsController::class, 'sendMessage'])->name('vendor.chats.send.conversation');
        Route::get('/{conversationId}', [ChatsController::class, 'show'])->name('vendor.chats.show');
    });

    // ========================================================================
    // NOTIFICATIONS API (Handled by Notification Module)
    // ========================================================================
    // See Modules/Notification/routes/api.php for notification routes

    // ========================================================================
    // LAUNDRY DATA API
    // ========================================================================
    Route::get('/laundry-data', [LaundryDataController::class, 'getLaundryData'])->name('vendor.laundry-data');
});
