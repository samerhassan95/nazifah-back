<?php

namespace Modules\Driver\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get driver home data (user data)
     */
    public function getUserData(Request $request): JsonResponse
    {
        $driver = $request->user();
        $driver->load(['branch.vendor']);

        $lang = app()->getLocale();

        // Get vendor data if exists (via branch)
        $vendorData = null;
        if ($driver->branch && $driver->branch->vendor) {
            $vendor = $driver->branch->vendor;
            $vendorData = [
                'id' => $vendor->id,
                'name' => $vendor->getTranslatedName($lang),
                'logo' => $this->uploadFilesService->getFullUrl($vendor->logo),
                'phone' => $vendor->phone,
                'email' => $vendor->email,
            ];
        }

        // Get primary branch data if exists
        $branchData = null;
        if ($driver->branch) {
            $branchData = [
                'id' => $driver->branch->id,
                'name' => method_exists($driver->branch, 'getTranslation')
                    ? $driver->branch->getTranslation('name', $lang)
                    : $driver->branch->name,
                'phone_number' => $driver->branch->phone_number,
                'latitude' => $driver->branch->latitude ? (float) $driver->branch->latitude : null,
                'longitude' => $driver->branch->longitude ? (float) $driver->branch->longitude : null,
                'location' => method_exists($driver->branch, 'getTranslation')
                    ? $driver->branch->getTranslation('location', $lang)
                    : ($driver->branch->location ?? null),
            ];
        }

        // Get all assigned branches (now just one)
        $assignedBranches = [];
        if ($driver->branch) {
            $assignedBranches[] = [
                'id' => $driver->branch->id,
                'name' => method_exists($driver->branch, 'getTranslation')
                    ? $driver->branch->getTranslation('name', $lang)
                    : $driver->branch->name,
                'is_active' => (bool) $driver->branch->is_active,
            ];
        }

        return successResponse([
            'id' => $driver->id,
            'vendor_id' => $driver->branch?->vendor_id,
            'branch_id' => $driver->branch_id,
            'full_name_local' => $driver->full_name,
            'full_name' => method_exists($driver, 'getTranslation')
                ? $driver->getTranslations('full_name')
                : $driver->full_name,

            'email' => $driver->email,
            'phone' => $driver->phone,
            'image' => $this->uploadFilesService->getFullUrl($driver->image),
            'rating' => (float) ($driver->rating ?? 0),
            'total_orders' => (int) ($driver->total_orders ?? 0),
            'is_available' => (bool) $driver->is_available,
            'location' => [
                'latitude' => $driver->latitude ? (float) $driver->latitude : null,
                'longitude' => $driver->longitude ? (float) $driver->longitude : null,
                'address' => null,
            ],
            'vendor' => $vendorData,
            'branch' => $branchData,
            'assigned_branch' => count($assignedBranches) > 0 ? $assignedBranches[0] : null,
            'created_at' => $driver->created_at->toISOString(),
            'updated_at' => $driver->updated_at->toISOString(),
        ], 'Driver data retrieved successfully');
    }

    /**
     * Get driver dashboard statistics
     */
    public function getDashboard(Request $request): JsonResponse
    {
        $driver = $request->user();

        // Get today's orders
        $todayOrders = \Modules\Order\Models\Order::query()
            ->driverToday($driver->id, $driver->branch_id)
            ->count();

        // Get completed orders today
        $completedToday = \Modules\Order\Models\Order::query()
            ->forDriverAtBranch($driver->id, $driver->branch_id)
            ->whereDate('created_at', today())
            ->whereIn('status', ['delivered', 'completed'])
            ->count();

        // Get total earnings today (this driver's delivery fee portion per order)
        $earningsToday = \Modules\Order\Models\Order::query()
            ->forDriverAtBranch($driver->id, $driver->branch_id)
            ->whereDate('created_at', today())
            ->whereIn('status', ['delivered', 'completed'])
            ->get()
            ->sum(fn ($order) => $order->getDeliveryFeeForDriver($driver->id));

        // Get pending orders (in progress — same definition as home card current_orders)
        $pendingOrders = \Modules\Order\Models\Order::query()
            ->forDriverAtBranch($driver->id, $driver->branch_id)
            ->driverCurrent($driver->id)
            ->count();

        // Get total earnings this month (this driver's delivery fee portion per order)
        $earningsMonth = \Modules\Order\Models\Order::query()
            ->forDriverAtBranch($driver->id, $driver->branch_id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->whereIn('status', ['delivered', 'completed'])
            ->get()
            ->sum(fn ($order) => $order->getDeliveryFeeForDriver($driver->id));

        return successResponse([
            'today_orders' => $todayOrders,
            'completed_today' => $completedToday,
            'earnings_today' => (float) $earningsToday,
            'pending_orders' => $pendingOrders,
            'earnings_month' => (float) $earningsMonth,
            'total_orders' => (int) ($driver->total_orders ?? 0),
            'rating' => (float) ($driver->rating ?? 0),
            'is_available' => (bool) $driver->is_available,
        ], 'Dashboard data retrieved successfully');
    }

    /**
     * Get driver card/summary data
     */
    public function getCard(Request $request): JsonResponse
    {
        $driver = $request->user();
        $lang = app()->getLocale();

        // Get total new orders assigned to this driver that need acceptance
        $totalNewOrders = \Modules\Order\Models\Order::query()
            ->forDriverAtBranch($driver->id, $driver->branch_id)
            ->driverNew($driver->id)
            ->count();

        // Get KPIs
        $totalOrders = (int) ($driver->total_orders ?? 0);

        $currentOrders = \Modules\Order\Models\Order::query()
            ->forDriverAtBranch($driver->id, $driver->branch_id)
            ->driverCurrent($driver->id)
            ->count();

        $todayOrders = \Modules\Order\Models\Order::query()
            ->driverToday($driver->id, $driver->branch_id)
            ->count();

        // Get map tracking data
        $addressName = null;
        $currentLocationGps = [
            'lat' => $driver->latitude ? (float) $driver->latitude : null,
            'long' => $driver->longitude ? (float) $driver->longitude : null,
        ];

        if (false) {
            $addressName = null;
        }

        // Get monthly profits (this driver's delivery fee portion from completed orders this month)
        $monthlyProfits = \Modules\Order\Models\Order::query()
            ->forDriverAtBranch($driver->id, $driver->branch_id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->whereIn('status', ['delivered', 'completed'])
            ->get()
            ->sum(fn ($order) => $order->getDeliveryFeeForDriver($driver->id));

        // Get total reviews count
        $totalReviews = \Modules\Order\Models\Order::query()
            ->forDriverAtBranch($driver->id, $driver->branch_id)
            ->whereNotNull('rating')
            ->count();

        // Get recent reviews
        $reviews = \Modules\Order\Models\Order::query()
            ->forDriverAtBranch($driver->id, $driver->branch_id)
            ->with(['client'])
            ->whereNotNull('rating')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($order) {
                return [
                    'user_name' => $order->client?->full_name ?? 'Unknown',
                    'user_image' => $this->uploadFilesService->getFullUrl($order->client?->image ?? null),
                    'rating' => (int) $order->rating,
                    'comment' => $order->review,
                    'date' => $order->updated_at?->format('Y-m-d H:i:s'),
                ];
            })->toArray();

        return successResponse([
            'total_new_orders' => (string) $totalNewOrders,
            'kpis' => [
                'total_orders' => (int) $totalOrders,
                'current_orders' => (int) $currentOrders,
                'today_orders' => (int) $todayOrders,
                'monthly_profits' => (float) $monthlyProfits,
            ],
            'map_tracking' => [
                'address_name' => $addressName,
                'current_location_gps' => $currentLocationGps,
            ],
            'total_reviews' => (int) $totalReviews,
            'reviews' => $reviews,
        ], 'Driver card retrieved successfully');
    }

    /**
     * Get new orders that need to be accepted by the driver
     * Returns orders in status driver_pending_acceptance assigned to this driver
     */
    public function getNewOrders(Request $request): JsonResponse
    {
        $driver = $request->user();
        $lang = app()->getLocale();

        $newOrders = \Modules\Order\Models\Order::query()
            ->forDriverAtBranch($driver->id, $driver->branch_id)
            ->driverNew($driver->id)
            ->with(['vendor', 'branch', 'client', 'pickupAddress', 'deliveryAddress', 'items.piece', 'items.service'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) use ($lang, $driver) {
                $serviceName = null;
                $subTitle = null;
                $piecesCount = 0;

                if ($order->items && $order->items->count() > 0) {
                    $firstItem = $order->items->first();
                    if ($firstItem && $firstItem->piece) {
                        $serviceName = method_exists($firstItem->piece, 'getTranslation')
                            ? $firstItem->piece->getTranslation('name', $lang)
                            : $firstItem->piece->name;
                    }
                    $piecesCount = $order->items->sum('quantity');
                    if ($firstItem && $firstItem->service) {
                        $subTitle = method_exists($firstItem->service, 'getTranslation')
                            ? $firstItem->service->getTranslation('service_name', $lang)
                            : $firstItem->service->service_name;
                    }
                }

                $customerLocation = null;
                $customerLatitude = null;
                $customerLongitude = null;

                if ($order->deliveryAddress) {
                    $customerLocation = $order->deliveryAddress->address_text
                        ?? $order->deliveryAddress->street_name
                        ?? $order->deliveryAddress->district
                        ?? $order->deliveryAddress->city
                        ?? null;
                    $customerLatitude = $order->deliveryAddress->latitude;
                    $customerLongitude = $order->deliveryAddress->longitude;
                } elseif ($order->pickupAddress && ! $order->pickup_at_vendor) {
                    $customerLocation = $order->pickupAddress->address_text
                        ?? $order->pickupAddress->street_name
                        ?? $order->pickupAddress->district
                        ?? $order->pickupAddress->city
                        ?? null;
                    $customerLatitude = $order->pickupAddress->latitude;
                    $customerLongitude = $order->pickupAddress->longitude;
                }

                $distance = null;
                if ($customerLatitude && $customerLongitude && $driver->latitude && $driver->longitude) {
                    $distance = $this->calculateDistance(
                        $driver->latitude,
                        $driver->longitude,
                        $customerLatitude,
                        $customerLongitude
                    );
                }

                $taskType = $this->resolveDriverTaskType($order, $driver, $lang);

                $firstItemImage = null;
                if ($order->items && $order->items->count() > 0) {
                    $itemWithImage = $order->items->first(fn ($item) => ! empty($item->images));
                    if ($itemWithImage) {
                        $firstItemImage = $this->uploadFilesService->getFullUrl($itemWithImage->images);
                    }
                }

                return array_merge([
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'status_label' => $order->status_label,
                    'service_name' => $serviceName,
                    'sub_title' => $subTitle,
                    'customer_name' => $order->client?->full_name ?? 'Unknown',
                    'customer_location' => $customerLocation,
                    'distance' => $distance,
                    'pieces_count' => $piecesCount,
                    'first_item_image' => $firstItemImage,
                    'delivery_fee' => (float) $order->getDeliveryFeeForDriver($driver->id),
                    'final_amount' => (float) ($order->final_amount ?? 0),
                    'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
                    'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
                    'needs_acceptance' => true,
                ], $taskType, $order->clientVisitResponseFields());
            })->values()->toArray();

        return successResponse($newOrders, 'New orders retrieved successfully');
    }

    /**
     * Get driver reviews/ratings
     */
    public function getReviews(Request $request): JsonResponse
    {
        $driver = $request->user();

        $reviews = \Modules\Order\Models\Order::forDriver($driver->id)
            ->with(['client'])
            ->whereNotNull('rating')
            ->orderBy('updated_at', 'desc')
            ->paginate($request->query('per_page', 15));

        $reviewsData = $reviews->getCollection()->map(function ($order) {
            return [
                'id' => $order->id,
                'user_name' => $order->client?->full_name ?? 'Unknown',
                'user_img' => $this->uploadFilesService->getFullUrl($order->client?->image) ?? null,
                'rating' => (int) $order->rating,
                'comment' => $order->review ?? null,
                'date' => $order->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->toArray();

        return successResponse([
            'reviews' => $reviewsData,
            'average_rating' => (float) ($driver->rating ?? 0),
            'total_reviews' => $reviews->total(),
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'from' => $reviews->firstItem(),
                'to' => $reviews->lastItem(),
            ],
        ], 'Reviews retrieved successfully');
    }

    /**
     * Get new order details
     */
    public function getOrderNewDetails(Request $request): JsonResponse
    {
        $orderId = $request->query('order_id');
        $driver = $request->user();

        if (! $orderId) {
            return errorResponse('Order ID is required', null, 400);
        }

        $lang = app()->getLocale();

        $order = \Modules\Order\Models\Order::forDriver($driver->id)
            ->with(['client', 'pickupAddress', 'deliveryAddress', 'items.piece'])
            ->whereIn('status', [
                OrderStatus::DRIVER_PICKUP_ASSIGNED->value,
                OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
                OrderStatus::DRIVER_PICKUP_ACCEPTED->value,
                OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
            ])
            ->find($orderId);

        if (! $order) {
            return notFoundResponse('Order not found or not assigned to you');
        }

        // Get order address
        $orderAddress = null;
        if ($order->pickupAddress && ! $order->pickup_at_vendor) {
            $orderAddress = $order->pickupAddress->street_name ?? null;
        } elseif ($order->deliveryAddress && ! $order->delivery_at_vendor) {
            $orderAddress = $order->deliveryAddress->street_name ?? null;
        }

        // Get customer address
        $customerAddress = null;
        if ($order->deliveryAddress) {
            $customerAddress = $order->deliveryAddress->street_name ?? null;
        }

        $taskType = $this->resolveDriverTaskType($order, $driver, $lang);

        // Get pieces details
        $piecesDetails = $order->items->map(function ($item) use ($lang) {
            return [
                'piece_id' => $item->piece_id,
                'piece_name' => $item->piece && method_exists($item->piece, 'getTranslation')
                    ? $item->piece->getTranslation('name', $lang)
                    : ($item->piece?->name ?? 'Unknown'),
                'quantity' => (int) $item->quantity,
            ];
        })->toArray();

        return successResponse([
            'order_info' => array_merge([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'order_address' => $orderAddress,
                'customer_id' => $order->client_id,
                'customer_name' => $order->client?->full_name ?? 'Unknown',
                'customer_address' => $customerAddress,
                'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
                'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
                'payment_method' => $order->payment_method ?? 'cash_on_delivery',
            ], $taskType, $order->clientVisitResponseFields()),
            'pieces_details' => $piecesDetails,
        ], 'Order details retrieved successfully');
    }

    /**
     * Get map tracking data for active deliveries
     */
    public function getMapTracking(Request $request): JsonResponse
    {
        $driver = $request->user();

        // Get active orders (orders currently being delivered / picked up)
        $activeOrders = \Modules\Order\Models\Order::forDriver($driver->id)
            ->with(['vendor', 'branch', 'pickupAddress', 'deliveryAddress', 'client'])
            ->whereIn('status', [
                OrderStatus::DRIVER_PICKUP_ACCEPTED->value,
                OrderStatus::PAYMENT_CONFIRMED->value,
                OrderStatus::ON_WAY_TO_PICKUP->value,
                OrderStatus::PICKED_UP->value,
                OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
                OrderStatus::ON_WAY_TO_DELIVERY->value,
                OrderStatus::WAITING_CLIENT_RECEIPT->value,
            ])
            ->orderBy('pickup_time', 'asc')
            ->get()
            ->map(function ($order) use ($driver) {
                $lang = app()->getLocale();
                $taskType = $this->resolveDriverTaskType($order, $driver, $lang);

                return array_merge([
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'status_label' => $order->status_label,
                    'client' => $order->client ? [
                        'id' => $order->client->id,
                        'name' => $order->client->full_name,
                        'phone' => $order->client->phone,
                    ] : null,
                    'pickup_location' => $order->pickup_at_vendor && $order->branch
                        ? $order->branch->toApiMapPoint($lang)
                        : ($order->pickupAddress ? [
                            'type' => 'address',
                            'name' => $order->pickupAddress->street_name,
                            'latitude' => (float) $order->pickupAddress->latitude,
                            'longitude' => (float) $order->pickupAddress->longitude,
                        ] : null),
                    'delivery_location' => $order->delivery_at_vendor && $order->branch
                        ? $order->branch->toApiMapPoint($lang)
                        : ($order->deliveryAddress ? [
                            'type' => 'address',
                            'name' => $order->deliveryAddress->street_name,
                            'latitude' => (float) $order->deliveryAddress->latitude,
                            'longitude' => (float) $order->deliveryAddress->longitude,
                        ] : null),
                    'pickup_time' => $order->pickup_time ? $order->pickup_time->toISOString() : null,
                    'estimated_delivery_time' => $order->estimated_delivery_time ? $order->estimated_delivery_time->toISOString() : null,
                ], $taskType, $order->clientVisitResponseFields());
            })->toArray();

        return successResponse([
            'active_orders' => $activeOrders,
            'driver_location' => [
                'latitude' => $driver->latitude ? (float) $driver->latitude : null,
                'longitude' => $driver->longitude ? (float) $driver->longitude : null,
            ],
            'count' => count($activeOrders),
        ], 'Map tracking data retrieved successfully');
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     * Returns distance in kilometers
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;

        return round($distance, 2); // Round to 2 decimal places
    }

    /**
     * Determine driver task type (pickup or delivery) based on the current order status phase.
     */
    private function resolveDriverTaskType($order, $driver, string $lang): array
    {
        $deliveryPhaseStatuses = [
            'driver_delivery_assigned', 'driver_delivery_accepted',
            'on_way_to_delivery', 'waiting_client_receipt', 'delivered',
        ];
        $pickupPhaseStatuses = [
            'driver_pickup_assigned', 'driver_pickup_accepted',
            'waiting_payment', 'payment_confirmed',
            'on_way_to_pickup', 'picked_up', 'delivered_to_branch',
        ];

        if (in_array($order->status, $deliveryPhaseStatuses)) {
            $type = 'delivery';
        } elseif (in_array($order->status, $pickupPhaseStatuses)) {
            $type = 'pickup';
        } else {
            $isPickup = (int) ($order->pickup_driver_id ?? 0) === (int) $driver->id;
            $type = $isPickup ? 'pickup' : 'delivery';
        }

        return [
            'driver_task_type' => $type,
            'driver_task_type_label' => $type === 'pickup'
                ? ($lang === 'ar' ? 'استلام' : 'Pickup')
                : ($lang === 'ar' ? 'تسليم' : 'Delivery'),
        ];
    }
}
