<?php

namespace Modules\Admin\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Admin;
use Modules\Branch\Models\Branch;
use Modules\Client\Models\Client;
use Modules\Driver\Models\Driver;
use Modules\Notification\Models\Notification;
use Modules\Order\Models\Order;
use Modules\Owner\Models\Owner;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Service\Models\Service;
use Modules\Vendor\Models\Vendor;
use Modules\Zone\Models\Zone;

class AdminDashboardController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $period = (int) $request->input('period', 7);
        $periodStart = now()->subDays($period);
        $locale = app()->getLocale();

        // Single grouped query for order status counts, aligned with the real
        // OrderStatus enum. (The old hard-coded 'preparing' status never existed,
        // so preparing_orders was always 0.)
        $statusCounts = Order::select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        // Orders actively being processed: everything except not-yet-started,
        // finished, and cancelled.
        $inProgressOrders = (int) $statusCounts->reject(fn ($count, $status) => in_array($status, [
            OrderStatus::PENDING->value,
            OrderStatus::DELIVERED->value,
            OrderStatus::COMPLETED->value,
            OrderStatus::CANCELLED->value,
        ], true))->sum();

        $stats = [
            'users' => [
                'total_clients' => Client::count(),
                'total_owners' => Owner::count(),
                'total_drivers' => Driver::count(),
                'total_vendors' => Vendor::count(),
                'verified_clients' => Client::where('is_verified', true)->count(),
            ],
            'orders' => [
                'total_orders' => (int) $statusCounts->sum(),
                'pending_orders' => (int) ($statusCounts[OrderStatus::PENDING->value] ?? 0),
                'confirmed_orders' => (int) ($statusCounts[OrderStatus::CONFIRMED->value] ?? 0),
                'payment_confirmed_orders' => (int) ($statusCounts[OrderStatus::PAYMENT_CONFIRMED->value] ?? 0),
                'in_progress_orders' => $inProgressOrders,
                // Backward-compatible alias for the previously-broken key.
                'preparing_orders' => $inProgressOrders,
                'delivered_orders' => (int) ($statusCounts[OrderStatus::DELIVERED->value] ?? 0),
                'completed_orders' => (int) ($statusCounts[OrderStatus::COMPLETED->value] ?? 0),
                'cancelled_orders' => (int) ($statusCounts[OrderStatus::CANCELLED->value] ?? 0),
                'recent_orders' => Order::where('created_at', '>=', $periodStart)->count(),
                // Full per-status breakdown for accuracy.
                'by_status' => $statusCounts->map(fn ($c) => (int) $c),
            ],
            'revenue' => [
                // Captured (actually collected) money.
                'total_revenue' => (float) PaymentTransaction::where('status', 'completed')->sum('amount'),
                // Reserved (authorized hold, not yet captured) — the reserve→capture flow.
                'reserved_revenue' => (float) PaymentTransaction::where('status', 'authorized')
                    ->sum(DB::raw('COALESCE(authorized_amount, amount)')),
                'pending_revenue' => (float) PaymentTransaction::where('status', 'pending')->sum('amount'),
                'refunded_amount' => (float) PaymentTransaction::sum('refund_amount'),
                'period_revenue' => (float) PaymentTransaction::where('status', 'completed')
                    ->where('created_at', '>=', $periodStart)
                    ->sum('amount'),
                'average_order_value' => (float) PaymentTransaction::where('status', 'completed')->avg('amount'),
            ],
            'services' => [
                'total_services' => Service::count(),
                'active_services' => Service::count(),
            ],
            'vendors' => [
                'total_vendors' => Vendor::count(),
                'active_vendors' => Vendor::where('is_active', true)->count(),
            ],
            'zones' => $this->getZoneStatistics($period, $periodStart, $locale),
        ];

        return successResponse(
            $stats,
            'Dashboard overview retrieved successfully'
        );
    }

    /**
     * Get comprehensive statistics for all zones
     */
    private function getZoneStatistics(int $period, $periodStart, string $locale): array
    {
        $zones = Zone::where('is_active', true)->get();

        if ($zones->isEmpty()) {
            return [];
        }

        $zoneIds = $zones->pluck('id')->toArray();

        // --- Vendors per zone (unique vendors via branches) ---
        $vendorsPerZone = DB::table('branches')
            ->select('zone_id', DB::raw('COUNT(DISTINCT vendor_id) as vendors_count'))
            ->whereIn('zone_id', $zoneIds)
            ->whereNotNull('vendor_id')
            ->groupBy('zone_id')
            ->pluck('vendors_count', 'zone_id');

        // --- Active vendors per zone ---
        $activeVendorsPerZone = DB::table('branches')
            ->join('vendors', 'branches.vendor_id', '=', 'vendors.id')
            ->select('branches.zone_id', DB::raw('COUNT(DISTINCT branches.vendor_id) as vendors_count'))
            ->whereIn('branches.zone_id', $zoneIds)
            ->where('branches.is_active', true)
            ->where('vendors.is_active', true)
            ->where('vendors.is_banned', false)
            ->groupBy('branches.zone_id')
            ->pluck('vendors_count', 'zone_id');

        // --- Clients per zone (unique clients who have addresses in this zone) ---
        $clientsPerZone = DB::table('addresses')
            ->select('zone_id', DB::raw('COUNT(DISTINCT client_id) as clients_count'))
            ->whereIn('zone_id', $zoneIds)
            ->whereNotNull('client_id')
            ->groupBy('zone_id')
            ->pluck('clients_count', 'zone_id');

        // --- Total orders per zone (via delivery_address -> zone) ---
        $ordersPerZone = DB::table('orders')
            ->join('addresses', 'orders.delivery_address_id', '=', 'addresses.id')
            ->select('addresses.zone_id', DB::raw('COUNT(orders.id) as orders_count'))
            ->whereIn('addresses.zone_id', $zoneIds)
            ->groupBy('addresses.zone_id')
            ->pluck('orders_count', 'zone_id');

        // --- Period orders per zone ---
        $periodOrdersPerZone = DB::table('orders')
            ->join('addresses', 'orders.delivery_address_id', '=', 'addresses.id')
            ->select('addresses.zone_id', DB::raw('COUNT(orders.id) as orders_count'))
            ->whereIn('addresses.zone_id', $zoneIds)
            ->where('orders.created_at', '>=', $periodStart)
            ->groupBy('addresses.zone_id')
            ->pluck('orders_count', 'zone_id');

        // --- Completed orders per zone ---
        $completedOrdersPerZone = DB::table('orders')
            ->join('addresses', 'orders.delivery_address_id', '=', 'addresses.id')
            ->select('addresses.zone_id', DB::raw('COUNT(orders.id) as orders_count'))
            ->whereIn('addresses.zone_id', $zoneIds)
            ->where('orders.status', 'completed')
            ->groupBy('addresses.zone_id')
            ->pluck('orders_count', 'zone_id');

        // --- Cancelled orders per zone ---
        $cancelledOrdersPerZone = DB::table('orders')
            ->join('addresses', 'orders.delivery_address_id', '=', 'addresses.id')
            ->select('addresses.zone_id', DB::raw('COUNT(orders.id) as orders_count'))
            ->whereIn('addresses.zone_id', $zoneIds)
            ->where('orders.status', 'cancelled')
            ->groupBy('addresses.zone_id')
            ->pluck('orders_count', 'zone_id');

        // --- Revenue per zone (total) ---
        $revenuePerZone = DB::table('orders')
            ->join('addresses', 'orders.delivery_address_id', '=', 'addresses.id')
            ->select('addresses.zone_id', DB::raw('COALESCE(SUM(orders.final_amount), 0) as revenue'))
            ->whereIn('addresses.zone_id', $zoneIds)
            ->where('orders.status', '!=', 'cancelled')
            ->groupBy('addresses.zone_id')
            ->pluck('revenue', 'zone_id');

        // --- Revenue per zone (period) ---
        $periodRevenuePerZone = DB::table('orders')
            ->join('addresses', 'orders.delivery_address_id', '=', 'addresses.id')
            ->select('addresses.zone_id', DB::raw('COALESCE(SUM(orders.final_amount), 0) as revenue'))
            ->whereIn('addresses.zone_id', $zoneIds)
            ->where('orders.status', '!=', 'cancelled')
            ->where('orders.created_at', '>=', $periodStart)
            ->groupBy('addresses.zone_id')
            ->pluck('revenue', 'zone_id');

        // --- Branches count per zone ---
        $branchesPerZone = DB::table('branches')
            ->select('zone_id', DB::raw('COUNT(*) as branches_count'))
            ->whereIn('zone_id', $zoneIds)
            ->groupBy('zone_id')
            ->pluck('branches_count', 'zone_id');

        // --- Build the result ---
        $result = $zones->map(function ($zone) use (
            $locale,
            $vendorsPerZone,
            $activeVendorsPerZone,
            $clientsPerZone,
            $ordersPerZone,
            $periodOrdersPerZone,
            $completedOrdersPerZone,
            $cancelledOrdersPerZone,
            $revenuePerZone,
            $periodRevenuePerZone,
            $branchesPerZone
        ) {
            $zoneId = $zone->id;

            return [
                'id' => $zoneId,
                'code' => $zone->code,
                'name' => $zone->getTranslation('name', $locale),
                'vendors' => [
                    'total' => (int) ($vendorsPerZone[$zoneId] ?? 0),
                    'active' => (int) ($activeVendorsPerZone[$zoneId] ?? 0),
                ],
                'clients' => (int) ($clientsPerZone[$zoneId] ?? 0),
                'branches' => (int) ($branchesPerZone[$zoneId] ?? 0),
                'orders' => [
                    'total' => (int) ($ordersPerZone[$zoneId] ?? 0),
                    'period' => (int) ($periodOrdersPerZone[$zoneId] ?? 0),
                    'completed' => (int) ($completedOrdersPerZone[$zoneId] ?? 0),
                    'cancelled' => (int) ($cancelledOrdersPerZone[$zoneId] ?? 0),
                ],
                'revenue' => [
                    'total' => (float) ($revenuePerZone[$zoneId] ?? 0),
                    'period' => (float) ($periodRevenuePerZone[$zoneId] ?? 0),
                ],
            ];
        })->sortByDesc(function ($zone) {
            return $zone['orders']['total'];
        })->values()->toArray();

        return $result;
    }

    public function orderTrends(Request $request): JsonResponse
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $currentYear = now()->year;

        $trends = [];

        for ($i = 1; $i <= 12; $i++) {
            $monthStart = "{$currentYear}-".str_pad($i, 2, '0', STR_PAD_LEFT).'-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart)).' 23:59:59';

            $newOrders = Order::whereBetween('created_at', [$monthStart, $monthEnd])->count();
            $completedOrders = Order::whereBetween('created_at', [$monthStart, $monthEnd])
                ->where('status', 'completed')
                ->count();
            $cancelledOrders = Order::whereBetween('created_at', [$monthStart, $monthEnd])
                ->where('status', 'cancelled')
                ->count();

            $trends[] = [
                'month' => strtolower($months[$i - 1]),
                'new_orders' => $newOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
            ];
        }

        return successResponse(
            $trends,
            'Order trends retrieved successfully'
        );
    }

    public function monthlyRevenueGrowth(Request $request): JsonResponse
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $currentYear = now()->year;

        $revenue = [];

        for ($i = 1; $i <= 12; $i++) {
            $monthStart = "{$currentYear}-".str_pad($i, 2, '0', STR_PAD_LEFT).'-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart)).' 23:59:59';

            $revenueValue = Order::whereBetween('created_at', [$monthStart, $monthEnd])
                ->where('status', '!=', 'cancelled')
                ->sum('final_amount');

            $revenue[] = [
                'month' => $months[$i - 1],
                'revenue_value' => (float) $revenueValue,
            ];
        }

        return successResponse(
            $revenue,
            'Monthly revenue growth retrieved successfully'
        );
    }

    public function topAreas(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 4);

        // Get top areas by order count grouped by zone
        $topAreas = Order::select('delivery_address_id', DB::raw('COUNT(*) as order_count'))
            ->whereNotNull('delivery_address_id')
            ->groupBy('delivery_address_id')
            ->get()
            ->map(function ($order) {
                $address = \Modules\Address\Models\Address::with('zone')->find($order->delivery_address_id);

                return [
                    'zone_id' => $address?->zone_id,
                    'zone' => $address?->zone,
                    'order_count' => $order->order_count,
                ];
            })
            ->groupBy('zone_id')
            ->map(function ($items, $zoneId) {
                $zone = $items->first()['zone'];

                return [
                    'zone_id' => $zoneId,
                    'zone' => $zone,
                    'order_count' => $items->sum('order_count'),
                ];
            })
            ->sortByDesc('order_count')
            ->take($limit)
            ->values();

        $locale = app()->getLocale();

        $result = $topAreas->map(function ($area) use ($locale) {
            $zone = $area['zone'];

            return [
                'id' => $zone?->id,
                'code' => $zone?->code,
                'city' => $zone ? $zone->getTranslation('name', $locale) : 'Unknown',
                'order_count' => $area['order_count'],
            ];
        });

        return successResponse(
            $result,
            'Top areas retrieved successfully'
        );
    }

    public function topVendors(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);

        // Orders are linked to branches, and branches are linked to vendors
        $aggregates = DB::table('orders')
            ->join('branches', 'orders.branch_id', '=', 'branches.id')
            ->leftJoin('payment_transactions', function ($join) {
                $join->on('orders.id', '=', 'payment_transactions.order_id')
                    ->where('payment_transactions.status', 'completed');
            })
            ->select('branches.vendor_id', DB::raw('COUNT(DISTINCT orders.id) as order_count'))
            ->selectRaw('COALESCE(SUM(payment_transactions.amount), 0) as total_revenue')
            ->whereNotNull('branches.vendor_id')
            ->groupBy('branches.vendor_id')
            ->orderBy('total_revenue', 'desc')
            ->limit($limit)
            ->get();

        $vendorIds = $aggregates->pluck('vendor_id')->filter()->unique()->values()->all();

        $vendors = Vendor::with('branches')->whereIn('id', $vendorIds)->get()->keyBy('id');

        $locale = app()->getLocale();

        $result = $aggregates->map(function ($row) use ($vendors, $locale) {
            $vendor = $vendors->get($row->vendor_id);

            $vendorData = null;
            if ($vendor) {
                $uploadFilesService = app(\App\Services\UploadFilesService::class);
                // Get first active branch for location (vendors no longer have location fields)
                $firstBranch = $vendor->branches->where('is_active', true)->first();

                $vendorData = [
                    'id' => $vendor->id,
                    'name' => $vendor->getTranslation('name', $locale),
                    'logo' => $uploadFilesService->getFullUrl($vendor->logo),
                    'latitude' => $firstBranch ? (float) $firstBranch->latitude : null,
                    'longitude' => $firstBranch ? (float) $firstBranch->longitude : null,
                    'is_active' => (bool) $vendor->is_active,
                ];
            }

            return [
                'vendor' => $vendorData,
                'order_count' => (int) $row->order_count,
                'total_revenue' => (float) $row->total_revenue,
            ];
        });

        return successResponse(
            $result,
            'Top vendors retrieved successfully'
        );
    }

    public function recentActivities(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 20);

        $recentOrders = Order::with(['client', 'branch.vendor'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($order) {
                $clientName = $order->client->full_name ?? 'عميل';
                $vendor = $order->branch?->vendor;
                $vendorName = $vendor ? $vendor->getTranslation('name', app()->getLocale()) : 'مزود خدمة';

                $title = match ($order->status) {
                    'pending' => 'طلب جديد في انتظار التأكيد',
                    'confirmed' => 'تم تأكيد الطلب',
                    'picked_up' => 'تم استلام الطلب',
                    'delivered_to_branch' => 'الطلب في المغسلة',
                    'delivered' => 'تم توصيل الطلب',
                    'completed' => 'تم إكمال الطلب',
                    'cancelled' => 'تم إلغاء الطلب',
                    default => "طلب جديد #{$order->order_number}",
                };

                return [
                    'id' => $order->id,
                    'title' => $title,
                    'description' => "طلب #{$order->order_number} - {$clientName} من {$vendorName}",
                    'time' => $order->created_at->format('H:i'),
                    'date' => $order->created_at->format('Y-m-d'),
                    'type' => 'orders',
                    'is_read' => false,
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'status_label' => $order->status_label,
                        'final_amount' => (float) $order->final_amount,
                    ],
                ];
            });

        return successResponse(
            $recentOrders,
            'Recent activities retrieved successfully'
        );
    }

    public function userGrowth(Request $request): JsonResponse
    {
        $days = $request->input('days', 30);

        $growth = [
            'clients' => Client::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
                ->where('created_at', '>=', now()->subDays($days))
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get(),

            'vendors' => Vendor::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
                ->where('created_at', '>=', now()->subDays($days))
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get(),

            'drivers' => Driver::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
                ->where('created_at', '>=', now()->subDays($days))
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get(),
        ];

        return successResponse(
            $growth,
            'User growth data retrieved successfully'
        );
    }

    public function notifications(Request $request): JsonResponse
    {
        $admin = auth('admin')->user();

        if (! $admin || ! ($admin instanceof Admin)) {
            return errorResponse('Unauthorized', 401);
        }

        // Get unread counts by type
        $systemCount = Notification::where('user_type', 'admin')
            ->where('user_id', $admin->id)
            ->where('type', 'system')
            ->where('is_read', false)
            ->count();

        $financialCount = Notification::where('user_type', 'admin')
            ->where('user_id', $admin->id)
            ->where('type', 'financial')
            ->where('is_read', false)
            ->count();

        $ordersCount = Notification::where('user_type', 'admin')
            ->where('user_id', $admin->id)
            ->where('type', 'orders')
            ->where('is_read', false)
            ->count();

        // Get recent notifications
        $notifications = Notification::where('user_type', 'admin')
            ->where('user_id', $admin->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($notification) {
                $lang = app()->getLocale();
                $title = is_array($notification->title)
                    ? ($notification->title[$lang] ?? $notification->title['ar'] ?? $notification->title['en'] ?? '')
                    : $notification->title;
                $description = is_array($notification->message)
                    ? ($notification->message[$lang] ?? $notification->message['ar'] ?? $notification->message['en'] ?? '')
                    : $notification->message;

                // Format time in Arabic format (AM/PM)
                $time = $notification->created_at->format('g:i');
                $period = $notification->created_at->format('A') === 'AM' ? 'ص' : 'م';
                $formattedTime = $time.' '.$period;

                return array_merge([
                    'id' => $notification->id,
                    'title' => $title,
                    'description' => $description,
                    'time' => $formattedTime,
                    'type' => $notification->type,
                    'is_read' => $notification->is_read,
                ], $notification->orderMetaForApi());
            });

        return successResponse([
            'counts' => [
                'system' => $systemCount,
                'financial' => $financialCount,
                'orders' => $ordersCount,
            ],
            'notifications' => $notifications,
        ], 'Notifications retrieved successfully');
    }
}
