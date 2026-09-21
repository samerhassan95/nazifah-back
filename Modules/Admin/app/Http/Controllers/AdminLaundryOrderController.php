<?php

namespace Modules\Admin\Http\Controllers;

use App\Enums\OrderStatus;
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

        $isAr = app()->getLocale() === 'ar';
        $orderItems = collect($orders->items());
        $orderItems->each->loadMissing(['pickupAddress', 'deliveryAddress']);

        // Format orders with the actual lifecycle status instead of coarse summary buckets.
        $ordersData = $orderItems->map(function ($order) use ($isAr) {
            $normalizedStatus = $order->status ?? 'pending';

            // Two independent handoff modes shown side by side on the order card:
            // how the laundry receives the items, and how the client gets them back.
            $pickupSelf = (bool) $order->pickup_at_vendor;
            $deliverySelf = (bool) $order->delivery_at_vendor;
            $addressModel = $order->deliveryAddress ?? $order->pickupAddress;
            $statusLabel = OrderStatus::fromString($normalizedStatus)?->localizedLabel(
                $order->payment_method,
                $normalizedStatus === OrderStatus::COMPLETED->value && ! $order->client_delivery_handoff_at,
                (bool) $order->delivery_at_vendor,
                false,
            ) ?? $normalizedStatus;

            return [
                'id'           => $order->id,
                'Order_code'   => $order->order_number,
                'Client_name'  => $order->client ? ($order->client->getTranslation('full_name', 'ar') ?? $order->client->full_name ?? 'N/A') : 'N/A',
                'Driver_name'  => $order->driver ? ($order->driver->getTranslation('full_name', 'ar') ?? $order->driver->full_name ?? 'N/A') : 'N/A',
                'Pieces_count' => $order->items()->count(),
                'Branch'       => $order->branch?->getTranslation('name', 'ar') ?? $order->branch?->name ?? 'N/A',
                'Order_value'  => (float) $order->final_amount,
                'order_value'  => (float) $order->final_amount,
                'Order_date'   => $order->created_at ? $order->created_at->format('d M Y') : null,
                'Order_status' => $normalizedStatus,
                'order_status' => $normalizedStatus,
                'status_label' => $statusLabel,
                'Distance'          => (float) ($order->distance ?? 0),
                'Pickup_distance'   => (float) ($order->pickup_distance ?? 0),
                'Delivery_distance' => (float) ($order->delivery_distance ?? 0),
                'Pickup_at_vendor'   => $pickupSelf,
                'Delivery_at_vendor' => $deliverySelf,
                'Pickup_method' => [
                    'type'  => $pickupSelf ? 'self' : 'driver',
                    'label' => $pickupSelf
                        ? ($isAr ? 'توصيل ذاتي إلى المغسلة' : 'Self drop-off at the laundry')
                        : ($isAr ? 'استلام من المنزل' : 'Pickup from home'),
                ],
                'Delivery_method' => [
                    'type'  => $deliverySelf ? 'self' : 'driver',
                    'label' => $deliverySelf
                        ? ($isAr ? 'استلام ذاتي من المغسلة' : 'Self pickup from the laundry')
                        : ($isAr ? 'توصيل إلى المنزل' : 'Delivery to home'),
                ],
                'Address' => $addressModel?->street_name ?? $addressModel?->address_text ?? null,
            ];
        });

        return successResponse([
            'States' => $states,
            'Orders' => $ordersData,
            // successResponse() moves this into the standard top-level `meta` block.
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
                'total' => $orders->total(),
            ],
        ], 'Orders retrieved successfully');
    }

}
