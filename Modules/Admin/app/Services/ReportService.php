<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Default cache expiration time in seconds (1 hour)
     */
    protected const DEFAULT_CACHE_SECONDS = 3600;

    /**
     * Format a localized name from JSON or raw string
     */
    protected function formatName(?string $nameParam, ?string $locale = null): ?string
    {
        if (! $nameParam) {
            return null;
        }

        $locale = $locale ?? app()->getLocale();
        $decoded = json_decode($nameParam, true);

        if (is_array($decoded)) {
            return $decoded[$locale] ?? $decoded['en'] ?? $decoded['ar'] ?? $nameParam;
        }

        return $nameParam;
    }

    /**
     * Get detailed order statistics for the Orders dashboard screen
     */
    public function getOrderStatistics(): array
    {
        try {
            $totalOrders = DB::table('orders')->count();

            // Basic Rates
            $completedOrdersCount = DB::table('orders')->where('status', 'completed')->count();
            $cancelledOrdersCount = DB::table('orders')->where('status', 'cancelled')->count();

            $lateOrdersCount = DB::table('orders')
                ->where('status', 'completed')
                ->whereNotNull('actual_delivery_time')
                ->whereNotNull('estimated_delivery_time')
                ->whereColumn('actual_delivery_time', '>', 'estimated_delivery_time')
                ->count();

            $cancelledBeforeAccept = DB::table('orders')
                ->where('status', 'cancelled')
                ->whereNull('driver_id')
                ->count();

            $acceptanceRate = $totalOrders > 0 ? round(($completedOrdersCount / $totalOrders) * 100, 2) : 0;
            $rejectionRate = $totalOrders > 0 ? round(($cancelledOrdersCount / $totalOrders) * 100, 2) : 0;
            $lateRate = $totalOrders > 0 ? round(($lateOrdersCount / $totalOrders) * 100, 2) : 0;
            $cancelBeforeAcceptRate = $totalOrders > 0 ? round(($cancelledBeforeAccept / $totalOrders) * 100, 2) : 0;

            // Average Order Time (Creation to Completion in minutes)
            $averageOrderTime = DB::table('orders')
                ->where('status', 'completed')
                ->whereNotNull('actual_delivery_time')
                ->select(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, created_at, actual_delivery_time)) as avg_time'))
                ->value('avg_time');

            // Order Funnel (Journey)
            $funnel = [
                ['label' => 'Created', 'value' => $totalOrders, 'percentage' => 100],
                ['label' => 'Accepted', 'value' => DB::table('orders')->whereNotIn('status', ['pending', 'cancelled'])->count(), 'percentage' => $totalOrders > 0 ? round((DB::table('orders')->whereNotIn('status', ['pending', 'cancelled'])->count() / $totalOrders) * 100) : 0],
                ['label' => 'Picked Up', 'value' => DB::table('orders')->whereIn('status', ['picked_up', 'delivered_to_branch', 'delivered', 'completed'])->count(), 'percentage' => $totalOrders > 0 ? round((DB::table('orders')->whereIn('status', ['picked_up', 'delivered_to_branch', 'delivered', 'completed'])->count() / $totalOrders) * 100) : 0],
                ['label' => 'Processing', 'value' => DB::table('orders')->whereIn('status', ['delivered_to_branch', 'delivered', 'completed'])->count(), 'percentage' => $totalOrders > 0 ? round((DB::table('orders')->whereIn('status', ['delivered_to_branch', 'delivered', 'completed'])->count() / $totalOrders) * 100) : 0],
                ['label' => 'Delivered', 'value' => $completedOrdersCount, 'percentage' => $totalOrders > 0 ? round(($completedOrdersCount / $totalOrders) * 100) : 0],
            ];

            // Order Status Evolution (Last 12 months for trends)
            $months = collect(range(0, 11))->map(function ($i) {
                return now()->subMonths($i)->format('Y-m');
            })->reverse();

            $evolutionData = DB::table('orders')
                ->select(
                    DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                    'status',
                    DB::raw('count(*) as count')
                )
                ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
                ->groupBy('month', 'status')
                ->get()
                ->groupBy('month');

            $statusEvolution = $months->map(function ($month) use ($evolutionData) {
                $monthData = $evolutionData->get($month, collect());

                return [
                    'month' => $month,
                    'completed' => $monthData->where('status', 'completed')->first()->count ?? 0,
                    'cancelled' => $monthData->where('status', 'cancelled')->first()->count ?? 0,
                    'pending' => $monthData->where('status', 'pending')->first()->count ?? 0,
                    'other' => $monthData->whereNotIn('status', ['completed', 'cancelled', 'pending'])->sum('count'),
                ];
            })->values();

            // Average Order Time Trend (Weekly for last 7 days)
            $orderTimeTrend = collect(range(0, 6))->map(function ($i) {
                $date = now()->subDays($i)->format('Y-m-d');
                $avgTime = DB::table('orders')
                    ->where('status', 'completed')
                    ->whereDate('created_at', $date)
                    ->whereNotNull('actual_delivery_time')
                    ->select(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, created_at, actual_delivery_time)) as avg_time'))
                    ->value('avg_time');

                return [
                    'day' => now()->subDays($i)->format('l'),
                    'avg_minutes' => round($avgTime ?? 0),
                ];
            })->reverse()->values();

            return [
                'metrics' => [
                    'acceptance_rate' => $acceptanceRate,
                    'rejection_rate' => $rejectionRate,
                    'late_orders_rate' => $lateRate,
                    'cancelled_before_acceptance' => $cancelBeforeAcceptRate,
                    'total_orders' => $totalOrders,
                ],
                'funnel' => $funnel,
                'status_evolution' => $statusEvolution,
                'average_order_time' => round($averageOrderTime ?? 0),
                'order_time_trend' => $orderTimeTrend,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get detailed vendor/laundry statistics for the Laundries dashboard screen
     */
    public function getVendorStatistics(): array
    {
        try {
            $totalOrders = DB::table('orders')->count();
            $completedOrdersCount = DB::table('orders')->where('status', 'completed')->count();
            $cancelledOrdersCount = DB::table('orders')->where('status', 'cancelled')->count();

            // Rates
            $acceptanceRate = $totalOrders > 0 ? round(($completedOrdersCount / $totalOrders) * 100, 2) : 0;
            $rejectionRate = $totalOrders > 0 ? round(($cancelledOrdersCount / $totalOrders) * 100, 2) : 0;

            // Average Execution Time (Vendor Review to Completion in hours)
            $avgExecutionTime = DB::table('orders')
                ->where('status', 'completed')
                ->whereNotNull('vendor_reviewed_at')
                ->whereNotNull('actual_delivery_time')
                ->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, vendor_reviewed_at, actual_delivery_time)) as avg_time'))
                ->value('avg_time');

            // Orders per vendor (bar chart data)
            $ordersPerVendor = DB::table('vendors')
                ->leftJoin('branches', 'vendors.id', '=', 'branches.vendor_id')
                ->leftJoin('orders', 'branches.id', '=', 'orders.branch_id')
                ->select('vendors.name', DB::raw('count(orders.id) as order_count'))
                ->groupBy('vendors.id', 'vendors.name')
                ->orderBy('order_count', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'name' => $this->formatName($item->name),
                        'order_count' => $item->order_count,
                    ];
                });

            // Top vendors by revenue (pie chart data)
            $topVendorsByRevenue = DB::table('vendors')
                ->leftJoin('branches', 'vendors.id', '=', 'branches.vendor_id')
                ->leftJoin('orders', 'branches.id', '=', 'orders.branch_id')
                ->select('vendors.name', DB::raw('sum(orders.total_amount) as total_revenue'))
                ->where('orders.status', 'completed')
                ->groupBy('vendors.id', 'vendors.name')
                ->orderBy('total_revenue', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'name' => $this->formatName($item->name),
                        'total_revenue' => $item->total_revenue,
                    ];
                });

            // Top regions by number of laundries
            $topRegions = DB::table('branches')
                ->leftJoin('zones', 'branches.zone_id', '=', 'zones.id')
                ->select('zones.name', DB::raw('count(branches.id) as branch_count'))
                ->whereNotNull('zones.name')
                ->groupBy('zones.id', 'zones.name')
                ->orderBy('branch_count', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'name' => $this->formatName($item->name),
                        'branch_count' => $item->branch_count,
                    ];
                });

            return [
                'metrics' => [
                    'received_orders' => $totalOrders,
                    'rejection_rate' => $rejectionRate,
                    'acceptance_rate' => $acceptanceRate,
                    'average_execution_time' => round($avgExecutionTime ?? 0, 1),
                ],
                'orders_per_laundry' => $ordersPerVendor,
                'top_laundries_by_revenue' => $topVendorsByRevenue,
                'top_regions' => $topRegions,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get branch statistics
     */
    public function getBranchStatistics(): array
    {
        try {
            $branchStats = DB::table('branches')
                ->leftJoin('vendors', 'branches.vendor_id', '=', 'vendors.id')
                ->leftJoin('orders', 'branches.id', '=', 'orders.branch_id')
                ->select(
                    'branches.id',
                    'branches.name',
                    'branches.location',
                    'vendors.name as vendor_name',
                    DB::raw('count(orders.id) as total_orders'),
                    DB::raw('sum(orders.total_amount) as total_revenue'),
                    DB::raw('count(case when orders.status = "completed" then 1 end) as completed_orders')
                )
                ->groupBy('branches.id', 'branches.name', 'branches.location', 'vendors.name')
                ->get()
                ->map(function ($branch) {
                    return [
                        'id' => $branch->id,
                        'name' => $this->formatName($branch->name),
                        'location' => $branch->location,
                        'vendor_name' => $this->formatName($branch->vendor_name),
                        'total_orders' => $branch->total_orders,
                        'total_revenue' => $branch->total_revenue,
                        'completed_orders' => $branch->completed_orders,
                    ];
                })
                ->toArray();

            return [
                'branches' => $branchStats,
                'summary' => [
                    'total_branches' => count($branchStats),
                    'total_revenue' => array_sum(array_column($branchStats, 'total_revenue')),
                    'total_orders' => array_sum(array_column($branchStats, 'total_orders')),
                    'active_branches' => count(array_filter($branchStats, function ($branch) {
                        return $branch->total_orders > 0;
                    })),
                ],
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get detailed client statistics for the Clients dashboard screen
     */
    public function getClientStatistics(): array
    {
        try {
            $totalClients = DB::table('clients')->count();

            $activeClients = DB::table('clients')
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('orders')
                        ->whereColumn('orders.client_id', 'clients.id')
                        ->where('created_at', '>=', now()->subDays(30));
                })
                ->count();

            $newClients = DB::table('clients')
                ->where('created_at', '>=', now()->subDays(30))
                ->count();

            $totalOrders = DB::table('orders')->count();
            $averageOrdersPerClient = $totalClients > 0 ? round($totalOrders / $totalClients, 1) : 0;

            // Client Growth (Last 12 months)
            $months = collect(range(0, 11))->map(function ($i) {
                return now()->subMonths($i)->format('Y-m');
            })->reverse();

            $growthData = DB::table('clients')
                ->select(
                    DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                    DB::raw('count(*) as count')
                )
                ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
                ->groupBy('month')
                ->pluck('count', 'month');

            $clientGrowth = $months->map(function ($month) use ($growthData) {
                return [
                    'month' => $month,
                    'count' => $growthData->get($month, 0),
                ];
            })->values();

            // Client Distribution by Activity (Bar Chart - grouped by orders count)
            $distributionData = DB::table('clients')
                ->leftJoin('orders', 'clients.id', '=', 'orders.client_id')
                ->select('clients.id', DB::raw('count(orders.id) as orders_count'))
                ->groupBy('clients.id')
                ->get();

            $distribution = [
                '0 orders' => $distributionData->where('orders_count', 0)->count(),
                '1 order' => $distributionData->where('orders_count', 1)->count(),
                '2 orders' => $distributionData->where('orders_count', 2)->count(),
                '3 orders' => $distributionData->where('orders_count', 3)->count(),
                '4 orders' => $distributionData->where('orders_count', 4)->count(),
                '5+ orders' => $distributionData->where('orders_count', '>=', 5)->count(),
            ];

            // Receiving and Delivery Method (Donut Chart)
            // Using pickup_at_vendor and delivery_at_vendor flags
            $byDriver = DB::table('orders')
                ->where('pickup_at_vendor', false)
                ->where('delivery_at_vendor', false)
                ->count();

            $selfService = DB::table('orders')
                ->where('pickup_at_vendor', true)
                ->where('delivery_at_vendor', true)
                ->count();

            $mixed = $totalOrders - ($byDriver + $selfService);

            $deliveryMethods = [
                ['label' => 'By Driver', 'count' => $byDriver, 'percentage' => $totalOrders > 0 ? round(($byDriver / $totalOrders) * 100) : 0],
                ['label' => 'Self Service', 'count' => $selfService, 'percentage' => $totalOrders > 0 ? round(($selfService / $totalOrders) * 100) : 0],
                ['label' => 'Mixed', 'count' => $mixed, 'percentage' => $totalOrders > 0 ? round(($mixed / $totalOrders) * 100) : 0],
            ];

            return [
                'metrics' => [
                    'total_clients' => $totalClients,
                    'active_clients' => $activeClients,
                    'new_clients' => $newClients,
                    'average_orders_per_client' => $averageOrdersPerClient,
                ],
                'client_growth' => $clientGrowth,
                'client_distribution' => $distribution,
                'receiving_delivery_method' => $deliveryMethods,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get comprehensive dashboard statistics
     */
    public function getDashboardStatistics(): array
    {
        $cacheKey = 'admin:dashboard:statistics';

        return Cache::remember($cacheKey, self::DEFAULT_CACHE_SECONDS, function () {
            try {
                $orderStats = $this->getOrderStatistics();
                $vendorStats = $this->getVendorStatistics();
                $branchStats = $this->getBranchStatistics();
                $clientStats = $this->getClientStatistics();

                // Get recent activity (last 7 days)
                $recentOrders = DB::table('orders')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count();

                $recentRevenue = DB::table('orders')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->where('status', 'completed')
                    ->sum('total_amount');

                return [
                    'orders' => $orderStats,
                    'vendors' => $vendorStats,
                    'branches' => $branchStats,
                    'clients' => $clientStats,
                    'recent_activity' => [
                        'last_7_days_orders' => $recentOrders,
                        'last_7_days_revenue' => $recentRevenue,
                    ],
                    'generated_at' => now()->toDateTimeString(),
                ];
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    /**
     * Get revenue statistics
     *
     * @param  string  $period  'daily', 'weekly', 'monthly'
     */
    public function getRevenueStatistics(string $period = 'daily'): array
    {
        $cacheKey = "admin:revenue:statistics:{$period}";

        return Cache::remember($cacheKey, self::DEFAULT_CACHE_SECONDS, function () use ($period) {
            try {
                $dateFormat = match ($period) {
                    'daily' => '%Y-%m-%d',
                    'weekly' => '%Y-%u',
                    'monthly' => '%Y-%m',
                    default => '%Y-%m-%d',
                };

                $revenueData = DB::table('orders')
                    ->where('status', 'completed')
                    ->select(
                        DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as period"),
                        DB::raw('sum(total_amount) as revenue'),
                        DB::raw('count(*) as order_count')
                    )
                    ->where('created_at', '>=', match ($period) {
                        'daily' => now()->subDays(30),
                        'weekly' => now()->subWeeks(12),
                        'monthly' => now()->subMonths(12),
                        default => now()->subDays(30),
                    })
                    ->groupBy('period')
                    ->orderBy('period', 'desc')
                    ->get()
                    ->toArray();

                return [
                    'period' => $period,
                    'revenue_data' => $revenueData,
                    'total_revenue' => array_sum(array_column($revenueData, 'revenue')),
                    'total_orders' => array_sum(array_column($revenueData, 'order_count')),
                    'avg_revenue_per_period' => count($revenueData) > 0 ?
                        array_sum(array_column($revenueData, 'revenue')) / count($revenueData) : 0,
                    'generated_at' => now()->toDateTimeString(),
                ];
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    /**
     * Clear statistics cache
     */
    public function clearCache(): void
    {
        Cache::forget('admin:dashboard:statistics');
        Cache::forget('admin:revenue:statistics:daily');
        Cache::forget('admin:revenue:statistics:weekly');
        Cache::forget('admin:revenue:statistics:monthly');
    }
}
