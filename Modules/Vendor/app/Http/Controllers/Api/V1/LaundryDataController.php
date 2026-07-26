<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Vendor\Models\Vendor;

class LaundryDataController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get comprehensive laundry data for vendor dashboard
     */
    public function getLaundryData(Request $request): JsonResponse
    {
        $vendorId = $request->query('vendor_id');
        $lang = app()->getLocale();

        if (! $vendorId) {
            return errorResponse(__('vendor.vendor_id_required'), null, 400);
        }

        $vendor = Vendor::with(['branches'])->find($vendorId);

        if (! $vendor) {
            return errorResponse(__('vendor.vendor_not_found'), null, 404);
        }

        // Get laundry basic info
        $laundryInfo = $this->getLaundryInfo($vendor, $lang);

        // Get statistics
        $states = $this->getStates($vendorId);

        // Get order status breakdown
        $orderStatus = $this->getOrderStatus($vendorId);

        // Get branch accounts status
        $branchAccountsStatus = $this->getBranchAccountsStatus($vendorId);

        // Get branch orders chart data
        $branchOrdersChart = $this->getBranchOrdersChart($vendorId, $lang);

        // Get branch locations
        $branchLocations = $this->getBranchLocations($vendorId, $lang, $vendor);

        $data = [
            'laundry_info' => $laundryInfo,
            'states' => $states,
            'order_status' => $orderStatus,
            'branch_accounts_status' => $branchAccountsStatus,
            'branch_orders_chart' => $branchOrdersChart,
            'branch_locations' => $branchLocations,
        ];

        return successResponse($data, __('vendor.laundry_data_retrieved'));
    }

    /**
     * Get basic laundry information
     */
    private function getLaundryInfo(Vendor $vendor, string $lang): array
    {
        return [
            'name' => $vendor->getTranslatedName($lang) ?: (is_string($vendor->name) ? $vendor->name : ''),
            'logo' => $vendor->logo ? $this->uploadFilesService->getFullUrl($vendor->logo) : null,
            'phone' => $vendor->phone,
            'email' => $vendor->email,
            'vat_number' => $vendor->vat_number,
            'attachments' => [], // Could be expanded to include vendor documents
        ];
    }

    /**
     * Get vendor statistics
     */
    private function getStates(int $vendorId): array
    {
        // Calculate total revenue from completed orders
        $totalRevenue = Order::whereHas('branch', function ($query) use ($vendorId) {
            $query->where('vendor_id', $vendorId);
        })
            ->where('status', 'completed')
            ->sum('total_amount');

        // Calculate average rating
        $generalRating = Order::whereHas('branch', function ($query) use ($vendorId) {
            $query->where('vendor_id', $vendorId);
        })
            ->whereNotNull('rating')
            ->avg('rating') ?? 0;

        // Count total orders
        $totalOrders = Order::whereHas('branch', function ($query) use ($vendorId) {
            $query->where('vendor_id', $vendorId);
        })->count();

        return [
            'total_revenue' => [
                'value' => (float) $totalRevenue,
            ],
            'general_rating' => [
                'value' => round((float) $generalRating, 1),
            ],
            'total_orders' => [
                'count' => $totalOrders,
            ],
        ];
    }

    /**
     * Get order status breakdown
     */
    private function getOrderStatus(int $vendorId): array
    {
        $orders = Order::whereHas('branch', function ($query) use ($vendorId) {
            $query->where('vendor_id', $vendorId);
        })
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        $totalCount = $orders->sum('count');

        $statusLabels = [
            'pending' => __('order.pending'),
            'confirmed' => __('order.confirmed'),
            'delivered_to_branch' => __('order.delivered_to_branch'),
            'delivered' => __('order.delivered'),
            'completed' => __('order.completed'),
            'cancelled' => __('order.cancelled'),
            'overdue' => __('order.overdue'),
        ];

        $statuses = $orders->map(function ($order) use ($totalCount, $statusLabels) {
            $percentage = $totalCount > 0 ? round(($order->count / $totalCount) * 100) : 0;

            return [
                'label' => $statusLabels[$order->status] ?? $order->status,
                'percentage' => $percentage,
                'count' => $order->count,
            ];
        })->toArray();

        return [
            'total_count' => $totalCount,
            'statuses' => $statuses,
        ];
    }

    /**
     * Get branch accounts status
     */
    private function getBranchAccountsStatus(int $vendorId): array
    {
        $branches = Branch::where('vendor_id', $vendorId)->get();
        $totalAccounts = $branches->count();

        $activeCount = $branches->where('is_active', true)->count();
        $pendingCount = $branches->where('is_active', false)->count();
        $rejectedCount = 0; // Assuming no rejected status in current schema

        $data = [
            [
                'status_name' => __('branch.active'),
                'count' => $activeCount,
                'percentage' => $totalAccounts > 0 ? round(($activeCount / $totalAccounts) * 100) : 0,
            ],
            [
                'status_name' => __('branch.pending_activation'),
                'count' => $pendingCount,
                'percentage' => $totalAccounts > 0 ? round(($pendingCount / $totalAccounts) * 100) : 0,
            ],
            [
                'status_name' => __('branch.rejected'),
                'count' => $rejectedCount,
                'percentage' => 0,
            ],
        ];

        return [
            'title' => __('branch.accounts_status'),
            'total_accounts' => $totalAccounts,
            'data' => $data,
        ];
    }

    /**
     * Get branch orders chart data
     */
    private function getBranchOrdersChart(int $vendorId, string $lang): array
    {
        $branches = Branch::where('vendor_id', $vendorId)
            ->withCount('orders')
            ->get();

        return $branches->map(function ($branch) use ($lang) {
            return [
                'name' => $this->getTranslatableValue($branch, 'name', $lang),
                'orders' => $branch->orders_count,
            ];
        })->toArray();
    }

    /**
     * Get branch locations
     */
    private function getBranchLocations(int $vendorId, string $lang, Vendor $vendor): array
    {
        $branches = Branch::where('vendor_id', $vendorId)->get();

        return $branches->map(function ($branch) use ($lang, $vendor) {
            return [
                'id' => $branch->id,
                'name' => $this->getTranslatableValue($branch, 'name', $lang),
                'Email' => $vendor->email ?? null,
                'Phone' => $branch->phone_number,
                'lat' => (float) $branch->latitude,
                'lng' => (float) $branch->longitude,
                'address_details' => $this->getTranslatableValue($branch, 'location', $lang) ?? '',
            ];
        })->toArray();
    }

    /**
     * Read a translatable attribute using the requested locale with fallbacks.
     */
    private function getTranslatableValue(Model $model, string $attribute, string $lang): ?string
    {
        if (! method_exists($model, 'getTranslations')) {
            return is_string($model->{$attribute} ?? null) ? $model->{$attribute} : null;
        }

        $translations = $model->getTranslations($attribute);

        return $translations[$lang]
            ?? $translations['en']
            ?? $translations['ar']
            ?? (is_string($model->{$attribute} ?? null) ? $model->{$attribute} : null);
    }
}
