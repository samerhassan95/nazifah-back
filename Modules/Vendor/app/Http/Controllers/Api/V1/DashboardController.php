<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // Get branch IDs for this vendor
        $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id');

        $totalOrders = Order::whereIn('branch_id', $branchIds)->count();
        $pendingOrders = Order::whereIn('branch_id', $branchIds)
            ->whereIn('status', ['pending', 'confirmed'])
            ->count();
        $completedOrders = Order::whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->count();
        $cancelledOrders = Order::whereIn('branch_id', $branchIds)
            ->where('status', 'cancelled')
            ->count();

        $revenueLast30Days = Order::whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('total_amount');

        $revenueToday = Order::whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->whereDate('created_at', today())
            ->sum('total_amount');

        $ordersByStatus = Order::whereIn('branch_id', $branchIds)
            ->where('created_at', '>=', now()->subDays(7))
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        return successResponse([
            'orders' => [
                'total' => $totalOrders,
                'pending' => $pendingOrders,
                'completed' => $completedOrders,
                'cancelled' => $cancelledOrders,
            ],
            'revenue' => [
                'today' => (float) $revenueToday,
                'last_30_days' => (float) $revenueLast30Days,
            ],
            'orders_by_status' => $ordersByStatus,
        ], 'Dashboard statistics retrieved successfully');
    }
}
