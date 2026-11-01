<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

    /**
     * Combined vendor dashboard overview: order counters, today's activity feed,
     * top branches by order count, monthly order-status trend, monthly revenue
     * growth, and a current order-status breakdown — everything the vendor
     * dashboard home screen needs in a single call.
     */
    public function overview(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id');
        $locale = app()->getLocale();

        $statusCounts = Order::whereIn('branch_id', $branchIds)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $currentOrders = (int) collect(OrderStatus::vendorCurrentStatusValues())
            ->sum(fn ($status) => $statusCounts[$status] ?? 0);

        $stats = [
            'orders' => [
                'total_orders' => (int) $statusCounts->sum(),
                'today_orders' => Order::whereIn('branch_id', $branchIds)
                    ->whereDate('created_at', today())
                    ->count(),
                'current_orders' => $currentOrders,
            ],
            'today_activities' => $this->todayActivities($branchIds),
            'top_branches' => $this->topBranches($branchIds, $locale),
            'order_status_trend' => $this->orderStatusTrend($branchIds),
            'monthly_revenue_growth' => $this->monthlyRevenueGrowth($branchIds),
            'order_statuses' => $this->orderStatusesBreakdown($statusCounts),
        ];

        return successResponse(
            $stats,
            'Dashboard overview retrieved successfully'
        );
    }

    /**
     * Today's order-creation activity feed for this vendor's branches.
     */
    private function todayActivities(Collection $branchIds, int $limit = 20): Collection
    {
        return Order::whereIn('branch_id', $branchIds)
            ->whereDate('created_at', today())
            ->with('client')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($order) {
                $clientName = $order->client->full_name ?? 'عميل';

                return [
                    'id' => $order->id,
                    'title' => 'إنشاء طلب جديد',
                    'description' => "تم إنشاء طلب جديد #{$order->order_number} بواسطة العميل {$clientName}",
                    'time' => $order->created_at->format('h:i A'),
                    'date' => $order->created_at->format('Y-m-d'),
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'status_label' => $order->status_label,
                    ],
                ];
            });
    }

    /**
     * Top branches (of this vendor) by order count, each tagged with a display
     * color from a fixed palette — branches have no stored color of their own.
     */
    private function topBranches(Collection $branchIds, string $locale, int $limit = 4): Collection
    {
        $palette = ['#3ccbf5', '#2cb2a5', '#f5a623', '#e74c3c', '#9b59b6', '#34495e'];

        $counts = Order::whereIn('branch_id', $branchIds)
            ->select('branch_id', DB::raw('COUNT(*) as order_count'))
            ->groupBy('branch_id')
            ->orderByDesc('order_count')
            ->limit($limit)
            ->get();

        $branches = Branch::whereIn('id', $counts->pluck('branch_id'))->get()->keyBy('id');

        return $counts->values()->map(function ($row, $index) use ($branches, $palette, $locale) {
            $branch = $branches->get($row->branch_id);

            return [
                'branch_id' => $row->branch_id,
                'name' => $branch?->getTranslation('name', $locale),
                'order_count' => (int) $row->order_count,
                'color' => $palette[$index % count($palette)],
            ];
        });
    }

    /**
     * Monthly new/completed/cancelled order counts for the current year, scoped
     * to this vendor's branches.
     */
    private function orderStatusTrend(Collection $branchIds): array
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $currentYear = now()->year;

        $trends = [];

        for ($i = 1; $i <= 12; $i++) {
            $monthStart = "{$currentYear}-".str_pad($i, 2, '0', STR_PAD_LEFT).'-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart)).' 23:59:59';

            $query = Order::whereIn('branch_id', $branchIds)->whereBetween('created_at', [$monthStart, $monthEnd]);

            $trends[] = [
                'month' => strtolower($months[$i - 1]),
                'new_orders' => (clone $query)->count(),
                'completed_orders' => (clone $query)->whereIn('status', ['completed', 'delivered'])->count(),
                'cancelled_orders' => (clone $query)->where('status', 'cancelled')->count(),
            ];
        }

        return $trends;
    }

    /**
     * Monthly revenue (final_amount, excluding cancelled orders) for the current
     * year, scoped to this vendor's branches.
     */
    private function monthlyRevenueGrowth(Collection $branchIds): array
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $currentYear = now()->year;

        $revenue = [];

        for ($i = 1; $i <= 12; $i++) {
            $monthStart = "{$currentYear}-".str_pad($i, 2, '0', STR_PAD_LEFT).'-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart)).' 23:59:59';

            $revenueValue = Order::whereIn('branch_id', $branchIds)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->where('status', '!=', 'cancelled')
                ->sum('final_amount');

            $revenue[] = [
                'month' => $months[$i - 1],
                'revenue_value' => (float) $revenueValue,
            ];
        }

        return $revenue;
    }

    /**
     * Current order-status breakdown (counts + percentages) for the donut chart.
     */
    private function orderStatusesBreakdown(Collection $statusCounts): array
    {
        $total = (int) $statusCounts->sum();

        $completed = (int) ($statusCounts[OrderStatus::COMPLETED->value] ?? 0)
            + (int) ($statusCounts[OrderStatus::DELIVERED->value] ?? 0);
        $cancelled = (int) ($statusCounts[OrderStatus::CANCELLED->value] ?? 0);
        $pending = (int) ($statusCounts[OrderStatus::PENDING->value] ?? 0);
        $current = max(0, $total - $completed - $cancelled - $pending);

        $percentage = fn (int $count) => $total > 0 ? round($count / $total * 100, 1) : 0.0;

        return [
            'total' => $total,
            'completed' => ['count' => $completed, 'percentage' => $percentage($completed)],
            'current' => ['count' => $current, 'percentage' => $percentage($current)],
            'cancelled' => ['count' => $cancelled, 'percentage' => $percentage($cancelled)],
            'pending' => ['count' => $pending, 'percentage' => $percentage($pending)],
        ];
    }
}
