<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Services\OrderService;
use Modules\Order\Models\Order;
use Modules\Vendor\Models\Vendor;

class AdminLaundryOrderController extends Controller
{
    public function __construct(
        private OrderService $orderService
    ) {}

    /**
     * Get orders for a laundry
     * GET /laundries/orders
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');

        if (! $vendorId) {
            return errorResponse('Vendor ID is required', null, 400);
        }

        $vendor = Vendor::with(['branches'])->find($vendorId);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        // Get branch IDs for this vendor
        $branchIds = $vendor->branches->pluck('id');

        $filters = [
            'branch_ids' => $branchIds->toArray(),
            'status' => $request->status,
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $orders = $this->orderService->getAllPaginated(
            $filters,
            $request->input('per_page', 15)
        );

        // Calculate states through branches
        $totalOrders = Order::whereIn('branch_id', $branchIds)->count();
        $completedOrders = Order::whereIn('branch_id', $branchIds)
            ->whereIn('status', ['completed', 'delivered'])
            ->count();
        $underProcessing = Order::whereIn('branch_id', $branchIds)
            ->whereIn('status', ['pending', 'confirmed', 'picked_up', 'delivered_to_branch'])
            ->count();
        $deletedOrders = Order::whereIn('branch_id', $branchIds)
            ->where('status', 'cancelled')
            ->count();

        $states = [
            'Total_orders' => $totalOrders,
            'Completed_orders' => $completedOrders,
            'Under_processing' => $underProcessing,
            'Deleted_orders' => $deletedOrders,
        ];

        // Format orders
        $ordersData = collect($orders->items())->map(function ($order) {
            return [
                'id'           => $order->id,
                'Order_code'   => $order->order_number,
                'Client_name'  => $order->client ? ($order->client->getTranslation('full_name', 'ar') ?? $order->client->full_name ?? 'N/A') : 'N/A',
                'Driver_name'  => $order->driver ? ($order->driver->getTranslation('full_name', 'ar') ?? $order->driver->full_name ?? 'N/A') : 'N/A',
                'Pieces_count' => $order->items()->count(),
                'Branch'       => $order->branch?->getTranslation('name', 'ar') ?? $order->branch?->name ?? 'N/A',
                'Order_date'   => $order->created_at ? $order->created_at->format('d M Y') : null,
                'Order_status' => $this->mapOrderStatus($order->status),
            ];
        });

        return successResponse([
            'States' => $states,
            'Orders' => $ordersData,
        ], 'Orders retrieved successfully');
    }

    /**
     * Map order status to required format
     */
    private function mapOrderStatus($status): string
    {
        $statusMap = [
            'completed' => 'completed',
            'delivered' => 'completed',
            'cancelled' => 'deleted',
            'pending' => 'under_processing',
            'confirmed' => 'under_processing',
            'picked_up' => 'under_processing',
            'delivered_to_branch' => 'under_processing',
        ];

        return $statusMap[$status] ?? 'under_processing';
    }
}
