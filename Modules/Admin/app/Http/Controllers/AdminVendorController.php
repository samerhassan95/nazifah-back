<?php

namespace Modules\Admin\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Admin\Http\Requests\StoreVendorRequest;
use Modules\Admin\Http\Requests\UpdateVendorRequest;
use Modules\Admin\Http\Resources\VendorResource;
use Modules\Admin\Services\VendorService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Vendor\Models\Vendor;

class AdminVendorController extends Controller
{
    public function __construct(
        private VendorService $vendorService,
        private UploadFilesService $uploadFilesService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'is_verified' => $request->is_verified,
            'is_active' => $request->is_active,
            'is_banned' => $request->is_banned,
            'search' => $request->search,
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $vendors = $this->vendorService->getAllPaginated(
            $filters,
            $request->input('per_page', 15)
        );

        return successResponse(
            VendorResource::collection($vendors),
            'Vendors retrieved successfully'
        );
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['is_verified'] = $validated['is_verified'] ?? true;
        $validated['is_active'] = $validated['is_active'] ?? true;

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $validated['logo'] = $this->uploadFilesService->uploadLogo($request->file('logo'), 'vendors');
        } elseif ($request->has('logo') && is_string($request->logo)) {
            $validated['logo'] = $request->logo;
        } else {
            unset($validated['logo']);
        }

        $vendor = $this->vendorService->create($validated);

        return successResponse(
            $vendor,
            'Vendor created successfully',
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $vendor = Vendor::with(['branches'])->find($id);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        // Get branch IDs for this vendor
        $branchIds = $vendor->branches->pluck('id');

        // Get orders statistics through branches
        $totalOrders = Order::whereIn('branch_id', $branchIds)->count();
        $completedOrders = Order::whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::COMPLETED->value)
            ->count();
        $cancelledOrders = Order::whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::CANCELLED->value)
            ->count();
        $underImplementationOrders = Order::whereIn('branch_id', $branchIds)
            ->whereIn('status', [
                OrderStatus::PENDING->value,
                OrderStatus::CONFIRMED->value,
                OrderStatus::PICKED_UP->value,
                OrderStatus::DELIVERED_TO_BRANCH->value,
                OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
                OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
                OrderStatus::ON_WAY_TO_DELIVERY->value,
            ])
            ->count();

        $vendorData = [
            'id' => $vendor->id,
            'name' => [
                'ar' => $vendor->getTranslation('name', 'ar'),
                'en' => $vendor->getTranslation('name', 'en'),
            ],
            'logo' => $this->uploadFilesService->getFullUrl($vendor->logo),
            'email' => $vendor->email,
            'official_number' => $vendor->official_number,
            'vat_number' => $vendor->vat_number ?? null,
            'phone' => $vendor->phone,
            'delivery_price_per_km' => $vendor->delivery_price_per_km ? (float) $vendor->delivery_price_per_km : 0,
            'is_active' => $vendor->is_active,
            'is_verified' => $vendor->is_verified,
            'is_banned' => $vendor->is_banned ?? false,
            'ban_reason' => $vendor->ban_reason,
            'banned_at' => $vendor->banned_at?->toDateTimeString(),
            'Orders' => [
                'Completed' => $completedOrders,
                'Total' => $totalOrders,
                'Cancelled' => $cancelledOrders,
                'Under_implementation' => $underImplementationOrders,
            ],
            'services' => $vendor->services,
            'created_at' => $vendor->created_at?->toDateTimeString(),
            'updated_at' => $vendor->updated_at?->toDateTimeString(),
        ];

        return successResponse(
            $vendorData,
            'Vendor retrieved successfully'
        );
    }

    public function update(UpdateVendorRequest $request, int $id): JsonResponse
    {
        $vendor = Vendor::find($id);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        $validated = $request->validated();

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $validated['logo'] = $this->uploadFilesService->uploadLogo($request->file('logo'), 'vendors', $vendor->logo);
        } elseif ($request->has('logo') && is_string($request->logo)) {
            $validated['logo'] = $request->logo;
        } else {
            unset($validated['logo']);
        }

        $vendor->update($validated);

        return successResponse(
            $vendor->fresh(),
            'Vendor updated successfully'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $vendor = Vendor::find($id);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        DB::beginTransaction();
        try {
            // Get branch IDs for this vendor
            $branchIds = DB::table('branches')->where('vendor_id', $vendor->id)->pluck('id');

            // Delete all orders related to this vendor's branches
            $orderIds = Order::whereIn('branch_id', $branchIds)->pluck('id');

            if ($orderIds->isNotEmpty()) {
                // Delete order items
                OrderItem::whereIn('order_id', $orderIds)->delete();

                // Delete order status logs
                OrderStatus->delete();

                // Delete payment transactions
                DB::table('payment_transactions')->whereIn('order_id', $orderIds)->delete();

                // Delete orders
                Order::whereIn('branch_id', $branchIds)->delete();
            }

            DB::table('pieces')->where('vendor_id', $vendor->id)->delete();

            if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'vendor_id')) {
                DB::table('branches')->where('vendor_id', $vendor->id)->delete();
            }

            // Delete vendor notifications
            DB::table('notifications')->where('user_type', 'vendor')
                ->where('user_id', $vendor->id)
                ->delete();

            // Finally delete the vendor
            $vendor->delete();

            DB::commit();

            return successResponse(
                null,
                'Vendor and all related data deleted successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return errorResponse('Failed to delete vendor: '.$e->getMessage(), 500);
        }
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $vendor = Vendor::find($id);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        $vendor->is_active = ! $vendor->is_active;
        $vendor->save();

        return successResponse(
            $vendor,
            'Vendor status updated successfully'
        );
    }

    /**
     * Ban a vendor
     * POST /vendors/{id}/ban
     */
    public function ban(Request $request, int $id): JsonResponse
    {
        $vendor = Vendor::find($id);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $vendor->update([
            'is_banned' => true,
            'ban_reason' => $request->input('reason'),
            'banned_at' => now(),
        ]);

        return successResponse(
            $vendor->fresh(),
            'Vendor banned successfully'
        );
    }

    /**
     * Unban a vendor
     * POST /vendors/{id}/unban
     */
    public function unban(int $id): JsonResponse
    {
        $vendor = Vendor::find($id);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        $vendor->update([
            'is_banned' => false,
            'ban_reason' => null,
            'banned_at' => null,
        ]);

        return successResponse(
            $vendor->fresh(),
            'Vendor unbanned successfully'
        );
    }

    public function statistics(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id',
        ]);

        $vendorId = $request->input('vendor_id');
        $locale = app()->getLocale();

        // ─── 1. Vendor Info ───────────────────────────────────────────────────────
        $vendor = DB::table('vendors')->where('id', $vendorId)->first();

        $vendorName = json_decode($vendor->name, true);
        $attachments = json_decode($vendor->attachments, true) ?? [];

        $vendorInfo = [
            'id' => $vendor->id,
            'name' => $vendorName[$locale] ?? $vendorName['en'] ?? $vendorName['ar'] ?? $vendor->name,
            'logo' => $vendor->logo,
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'vat_number' => $vendor->vat_number,
            'official_number' => $vendor->official_number,
            'delivery_price_per_km' => (float) $vendor->delivery_price_per_km,
            'wallet_balance' => (float) $vendor->wallet_balance,
            'attachments' => $attachments,
            'is_active' => (bool) $vendor->is_active,
            'is_verified' => (bool) $vendor->is_verified,
            'is_banned' => (bool) $vendor->is_banned,
            'ban_reason' => $vendor->ban_reason,
            'banned_at' => $vendor->banned_at,
            'created_at' => $vendor->created_at,
        ];

        // ─── 2. Branch IDs for this vendor ───────────────────────────────────────
        $branchIds = DB::table('branches')
            ->where('vendor_id', $vendorId)
            ->pluck('id');

        // ─── 3. Profits ───────────────────────────────────────────────────────────
        $totalProfits = DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->sum('final_amount');

        // ─── 4. Overall Rating ────────────────────────────────────────────────────
        $overallRating = DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('rating')
            ->avg('rating');

        // ─── 5. Total Orders ──────────────────────────────────────────────────────
        $totalOrders = DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->count();

        // ─── 6. Order Statuses ────────────────────────────────────────────────────
        $completedOrders = DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->count();

        $cancelledOrders = DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'cancelled')
            ->count();

        $delayedOrders = DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->whereNotNull('actual_delivery_time')
            ->whereNotNull('estimated_delivery_time')
            ->whereColumn('actual_delivery_time', '>', 'estimated_delivery_time')
            ->count();

        $underImplementationOrders = DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->whereNotIn('status', ['completed', 'cancelled', 'delivered'])
            ->count();

        // ─── 7. Orders per Branch ─────────────────────────────────────────────────
        $branchesOrders = DB::table('branches')
            ->leftJoin('orders', 'branches.id', '=', 'orders.branch_id')
            ->where('branches.vendor_id', $vendorId)
            ->select('branches.name', DB::raw('COUNT(orders.id) as count'))
            ->groupBy('branches.id', 'branches.name')
            ->get()
            ->map(function ($item) use ($locale) {
                $name = json_decode($item->name, true);

                return [
                    'name' => $name[$locale] ?? $name['en'] ?? $item->name,
                    'count' => (int) $item->count,
                ];
            });

        // ─── 8. Employee / Account Status ────────────────────────────────────────
        $employeeQuery = DB::table('vendor_employees')->where('vendor_id', $vendorId);

        $activeAccounts = (clone $employeeQuery)->where('is_active', true)->where('is_banned', false)->count();
        $pendingAccounts = (clone $employeeQuery)->where('is_verified', false)->count();
        $rejectedAccounts = (clone $employeeQuery)->where('is_banned', true)->count();

        // ─── 9. Branch Locations ─────────────────────────────────────────────────
        $branchesLocations = DB::table('branches')
            ->select('name', 'latitude', 'longitude')
            ->where('vendor_id', $vendorId)
            ->get()
            ->map(function ($item) use ($locale) {
                $name = json_decode($item->name, true);

                return [
                    'name' => $name[$locale] ?? $name['en'] ?? $item->name,
                    'latitude' => $item->latitude,
                    'longitude' => $item->longitude,
                ];
            });

        // ─── 10. Charts — Last 12 Months ─────────────────────────────────────────
        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $currentMonth = (int) date('n');
        $currentYear = (int) date('Y');

        $profitsChart = [];
        $ordersChart = [];
        $ratingChart = [];

        for ($i = 0; $i < 12; $i++) {
            $monthIndex = ($currentMonth - $i - 1 + 12) % 12;
            $monthStr = str_pad((string) ($monthIndex + 1), 2, '0', STR_PAD_LEFT);
            $year = ($monthIndex + 1 > $currentMonth) ? $currentYear - 1 : $currentYear;
            $monthName = $monthNames[$monthIndex];

            $profitsChart[$monthName] = (float) DB::table('orders')
                ->whereIn('branch_id', $branchIds)
                ->where('status', 'completed')
                ->whereMonth('created_at', $monthStr)
                ->whereYear('created_at', $year)
                ->sum('final_amount');

            $ordersChart[$monthName] = (int) DB::table('orders')
                ->whereIn('branch_id', $branchIds)
                ->whereMonth('created_at', $monthStr)
                ->whereYear('created_at', $year)
                ->count();

            $monthlyRating = DB::table('orders')
                ->whereIn('branch_id', $branchIds)
                ->whereNotNull('rating')
                ->whereMonth('created_at', $monthStr)
                ->whereYear('created_at', $year)
                ->avg('rating');

            $ratingChart[$monthName] = $monthlyRating ? round((float) $monthlyRating, 1) : 0;
        }

        // ─── 11. Build Response ───────────────────────────────────────────────────
        $stats = [
            'vendor_info' => $vendorInfo,

            'total_profits' => (float) $totalProfits,
            'overall_rating' => $overallRating ? round((float) $overallRating, 1) : 0,
            'total_orders' => $totalOrders,

            'profits_chart' => array_reverse($profitsChart),
            'orders_chart' => array_reverse($ordersChart),
            'rating_chart' => array_reverse($ratingChart),

            'order_statuses' => [
                'completed' => $completedOrders,
                'cancelled' => $cancelledOrders,
                'delayed' => $delayedOrders,
                'under_implementation' => $underImplementationOrders,
            ],

            'branches_orders' => $branchesOrders,

            'account_status' => [
                'active' => $activeAccounts,
                'pending' => $pendingAccounts,
                'rejected' => $rejectedAccounts,
            ],

            'branches_locations' => $branchesLocations,

            // Vendor-level flags (derived from already-fetched vendor row — no extra query)
            'is_active' => $vendorInfo['is_active'],
            'is_verified' => $vendorInfo['is_verified'],
            'is_banned' => $vendorInfo['is_banned'],
            'is_recent' => strtotime($vendor->created_at) >= strtotime('-7 days'),
        ];

        return successResponse($stats, 'Vendor statistics retrieved successfully');
    }
}
