<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Vendor\Models\Vendor;

class AdminLaundryController extends Controller
{
    /**
     * Get laundry data
     * GET /laundries/laundry_data
     */
    public function laundryData(Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');
        $lang = app()->getLocale();

        if (! $vendorId) {
            return errorResponse('Vendor ID is required', null, 400);
        }

        $vendor = Vendor::with(['branches'])->find($vendorId);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        // Get branch IDs for this vendor
        $branchIds = $vendor->branches->pluck('id');

        // Get vendor attachments (assuming there's an attachments table or JSON field)
        $attachments = [];
        // You may need to adjust this based on your actual attachments structure
        if ($vendor->attachments) {
            $attachments = is_array($vendor->attachments) ? $vendor->attachments : json_decode($vendor->attachments, true) ?? [];
        }

        // Laundry info
        $laundryInfo = [
            'name' => $vendor->getTranslatedName($lang) ?: ($vendor->name ?? ''),
            'logo' => $vendor->logo ? (str_starts_with($vendor->logo, 'http') ? $vendor->logo : config('app.url').$vendor->logo) : null,
            'phone' => $vendor->phone,
            'email' => $vendor->email,
            'vat_number' => $vendor->vat_number,
            'attachments' => $attachments,
        ];

        // States - Calculate total revenue and orders through branches
        $totalRevenue = Order::whereIn('branch_id', $branchIds)
            ->whereHas('paymentTransactions', function ($q) {
                $q->where('status', 'completed');
            })
            ->sum('final_amount');

        // Calculate average rating from all branches
        $generalRating = Branch::where('vendor_id', $vendorId)
            ->whereNotNull('rating')
            ->avg('rating') ?? 0;

        $totalOrders = Order::whereIn('branch_id', $branchIds)->count();

        $states = [
            'total_revenue' => [
                'value' => (float) $totalRevenue,
            ],
            'general_rating' => [
                'value' => (float) $generalRating,
            ],
            'total_orders' => [
                'count' => $totalOrders,
            ],
        ];

        // Order status breakdown
        $orderStatusCounts = Order::whereIn('branch_id', $branchIds)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $totalCount = array_sum($orderStatusCounts);
        $statuses = [];

        $statusLabels = [
            'completed' => __('order.completed'),
            'delivered' => __('order.completed'), // grouped with completed
            'cancelled' => __('order.cancelled'),
            'delivered_to_branch' => __('order.delivered_to_branch'),
            'pending' => __('order.pending'),
            'confirmed' => __('order.confirmed'),
            'picked_up' => __('order.picked_up'),
        ];

        foreach ($orderStatusCounts as $status => $count) {
            $label = $statusLabels[$status] ?? $status;
            $percentage = $totalCount > 0 ? round(($count / $totalCount) * 100) : 0;

            // Group completed and delivered together
            if (in_array($status, ['completed', 'delivered'])) {
                $key = 'completed';
                if (! isset($statuses[$key])) {
                    $statuses[$key] = [
                        'label' => $statusLabels['completed'] ?? __('order.completed'),
                        'percentage' => 0,
                        'count' => 0,
                    ];
                }
                $statuses[$key]['count'] += $count;
            } elseif ($status === 'cancelled') {
                $statuses['cancelled'] = [
                    'label' => $statusLabels['cancelled'] ?? __('order.cancelled'),
                    'percentage' => $percentage,
                    'count' => $count,
                ];
            } elseif (in_array($status, ['delivered_to_branch', 'confirmed', 'picked_up'])) {
                $key = 'processing';
                if (! isset($statuses[$key])) {
                    $statuses[$key] = [
                        'label' => $statusLabels['delivered_to_branch'] ?? __('order.delivered_to_branch'),
                        'percentage' => 0,
                        'count' => 0,
                    ];
                }
                $statuses[$key]['count'] += $count;
            } elseif ($status === 'pending') {
                $statuses['pending'] = [
                    'label' => $statusLabels['pending'] ?? __('order.pending'),
                    'percentage' => $percentage,
                    'count' => $count,
                ];
            }
        }

        // Recalculate percentages
        foreach ($statuses as $key => &$status) {
            $status['percentage'] = $totalCount > 0 ? round(($status['count'] / $totalCount) * 100) : 0;
        }

        $orderStatus = [
            'total_count' => $totalCount,
            'statuses' => array_values($statuses),
        ];

        // Branch accounts status
        $branchAccountsStatus = [
            'active' => Branch::where('vendor_id', $vendorId)->where('is_active', true)->count(),
            'pending' => 0, // Adjust based on your business logic
            'rejected' => Branch::where('vendor_id', $vendorId)->where('is_active', false)->count(),
        ];

        // Branch orders chart (top 12 branches)
        $branchOrders = Branch::where('vendor_id', $vendorId)
            ->orderBy('created_at', 'desc')
            ->limit(12)
            ->get()
            ->map(function ($branch) use ($lang) {
                $ordersCount = Order::where('branch_id', $branch->id)->count();

                return [
                    'name' => $this->getTranslatableValue($branch, 'name', $lang),
                    'orders' => $ordersCount,
                ];
            });

        // Branch locations
        $branchLocations = Branch::where('vendor_id', $vendorId)
            ->get()
            ->map(function ($branch) use ($vendor, $lang) {
                return [
                    'id' => $branch->id,
                    'name' => $this->getTranslatableValue($branch, 'name', $lang),
                    'Email' => $vendor->email ?? null,
                    'Phone' => $branch->phone_number,
                    'lat' => (float) ($branch->latitude ?? 0),
                    'lng' => (float) ($branch->longitude ?? 0),
                    'address_details' => $this->getTranslatableValue($branch, 'location', $lang),
                ];
            });

        // Branch accounts status (detailed)
        $totalBranches = Branch::where('vendor_id', $vendorId)->count();
        $activeBranches = Branch::where('vendor_id', $vendorId)->where('is_active', true)->count();
        $inactiveBranches = Branch::where('vendor_id', $vendorId)->where('is_active', false)->count();

        $branchAccountsStatusDetailed = [
            'title' => __('branch.accounts_status'),
            'total_accounts' => $totalBranches,
            'data' => [
                [
                    'status_name' => __('branch.active'),
                    'count' => $activeBranches,
                    'percentage' => $totalBranches > 0 ? round(($activeBranches / $totalBranches) * 100) : 0,
                ],
                [
                    'status_name' => __('branch.pending_activation'),
                    'count' => 0, // Adjust based on your business logic
                    'percentage' => 0,
                ],
                [
                    'status_name' => __('branch.rejected'),
                    'count' => $inactiveBranches,
                    'percentage' => $totalBranches > 0 ? round(($inactiveBranches / $totalBranches) * 100) : 0,
                ],
            ],
        ];

        return successResponse([
            'laundry_info' => $laundryInfo,
            'states' => $states,
            'order_status' => $orderStatus,
            'branch_accounts_status' => $branchAccountsStatusDetailed,
            'branch_orders_chart' => $branchOrders,
            'branch_locations' => $branchLocations,
        ], 'Laundry data retrieved successfully');
    }

    private function getTranslatableValue(Branch $branch, string $attribute, string $lang): ?string
    {
        $translations = $branch->getTranslations($attribute);

        return $translations[$lang]
            ?? $translations['en']
            ?? $translations['ar']
            ?? (is_string($branch->{$attribute} ?? null) ? $branch->{$attribute} : null);
    }
}
