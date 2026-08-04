<?php

namespace Modules\Driver\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Support\OrderStatusLogPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Chat\Services\ChatService;
use Modules\Driver\Http\Requests\ConfirmQRRequest;
use Modules\Driver\Http\Requests\RejectOrderRequest;
use Modules\Driver\Models\Driver;
use Modules\Notification\Models\Notification;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDriverTrip;
use Modules\Vendor\Models\VendorEmployee;

class OrderController extends Controller
{
    /**
     * Calculate distance between two coordinates using Haversine formula
     * Returns distance in kilometers
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Get available orders for driver
     */
    public function available(Request $request): JsonResponse
    {
        $driver = $request->user();

        if (! $driver->is_available) {
            return forbiddenResponse('Driver must be online and available to view orders');
        }

        $orders = Order::whereIn('status', [OrderStatus::DELIVERED_TO_BRANCH->value, OrderStatus::COMPLETED->value])
            ->whereNull('delivery_driver_id')
            ->where('branch_id', $driver->branch_id)
            ->with(['vendor', 'client'])
            ->orderBy('updated_at', 'desc')
            ->paginate($request->query('limit', 15));

        return successResponse($orders, 'Available orders retrieved successfully');
    }

    /**
     * Get driver's assigned orders
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $request->user();
        $status = $request->query('status'); // current, completed, cancelled, delivered
        $lang = app()->getLocale();

        $query = Order::forDriverAtBranch($driver->id, $driver->branch_id)
            ->with(['vendor', 'client', 'items.piece', 'branch', 'pickupAddress', 'deliveryAddress'])
            ->orderBy('updated_at', 'desc');

        // Filter by status parameter
        // "current" = driver accepted (pickup or delivery) through in-progress handoffs.
        // Excludes:
        //   - *_ASSIGNED (driver not yet accepted → shown in "new orders" API)
        //   - waiting_payment (client not yet paid)
        // Includes driver_pickup_accepted so accepted pickups stay visible.
        if ($status) {
            switch ($status) {
                case 'current':
                    $query->driverCurrent($driver->id);
                    break;
                case 'completed':
                    // Only orders where this driver has fully finished his task(s):
                    // - Pickup done: he delivered to branch (delivered_to_branch+). If he is also delivery driver, include only when order is delivered.
                    // - Delivery done: he delivered to client (delivered only).
                    // Exclude orders still "on the way" (on_way_to_delivery, etc.) — those stay in "current".
                    $query->where(function ($q) use ($driver) {
                        $q->where(function ($q2) use ($driver) {
                            // Pickup task completed: I was pickup driver and order reached branch
                            $q2->where('pickup_driver_id', $driver->id)
                                ->whereIn('status', [
                                    OrderStatus::DELIVERED_TO_BRANCH->value,
                                    OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
                                    OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
                                    OrderStatus::ON_WAY_TO_DELIVERY->value,
                                    OrderStatus::WAITING_CLIENT_RECEIPT->value,
                                    OrderStatus::DELIVERED->value,
                                    OrderStatus::COMPLETED->value,
                                ])
                                ->where(function ($q3) use ($driver) {
                                    // If I am also the delivery driver, count pickup as "completed" only when order is delivered (no in-progress delivery)
                                    $q3->where('delivery_driver_id', '!=', $driver->id)
                                        ->orWhereNull('delivery_driver_id')
                                        ->orWhereIn('status', [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value]);
                                });
                        })
                            ->orWhere(function ($q2) use ($driver) {
                                // Delivery task completed: I was delivery driver and order is delivered
                                $q2->where('delivery_driver_id', $driver->id)
                                    ->whereIn('status', [
                                        OrderStatus::DELIVERED->value,
                                        OrderStatus::COMPLETED->value,
                                    ]);
                            });
                    });
                    $query->with('statusLogs');
                    break;
                case 'cancelled':
                    $query->where('status', OrderStatus::CANCELLED->value);
                    break;
                case 'delivered':
                    $query->where('status', OrderStatus::DELIVERED->value);
                    break;
                default:
                    $query->driverCurrent($driver->id);
                    break;
            }
        } else {
            $query->driverCurrent($driver->id);
        }

        $ordersCollection = $query->get();

        // When status=completed, return trips from order_driver_trips table (or build from orders if empty)
        if ($status === 'completed') {
            $trips = $this->buildCompletedTripsFromTable($driver, $lang);
            if (empty($trips)) {
                $trips = $this->buildCompletedTripsFromOrders($ordersCollection, $driver, $lang);
            }

            return successResponse([
                'orders' => $trips,
            ], 'Orders retrieved successfully');
        }

        $orders = $ordersCollection->map(function ($order) use ($lang, $driver) {
            return $this->mapOrderToDriverListItem($order, $driver, $lang);
        })->toArray();

        return successResponse(['orders' => $orders], 'Orders retrieved successfully');
    }

    /**
     * Statuses that mean the order's delivery is still in progress (not finished yet).
     */
    private function activeDeliveryStatuses(): array
    {
        return [
            OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
            OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
            OrderStatus::ON_WAY_TO_DELIVERY->value,
            OrderStatus::WAITING_CLIENT_RECEIPT->value,
        ];
    }

    /**
     * Build list of completed trips from order_driver_trips table (source of truth for reports).
     */
    protected function buildCompletedTripsFromTable($driver, string $lang): array
    {
        $trips = OrderDriverTrip::where('driver_id', $driver->id)
            ->where('status', OrderDriverTrip::STATUS_COMPLETED)
            ->with(['order' => function ($q) {
                $q->with(['vendor', 'client', 'items.piece', 'branch', 'pickupAddress', 'deliveryAddress']);
            }])
            ->orderBy('completed_at', 'desc')
            ->get();

        $activeDelivery = $this->activeDeliveryStatuses();

        return $trips->map(function ($trip) use ($driver, $lang, $activeDelivery) {
            $order = $trip->order;
            if (! $order) {
                return null;
            }

            // Pickup trip: if this driver is also the delivery driver and delivery is still active, skip
            if ($trip->trip_type === OrderDriverTrip::TYPE_PICKUP
                && (int) $order->delivery_driver_id === (int) $driver->id
                && in_array($order->status, $activeDelivery, true)) {
                return null;
            }

            // Delivery trip: order must be delivered/completed
            if ($trip->trip_type === OrderDriverTrip::TYPE_DELIVERY
                && ! in_array($order->status, [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value], true)) {
                return null;
            }

            $base = $this->mapOrderToDriverListItem($order, $driver, $lang);
            $base['delivery_fee'] = (float) ($trip->delivery_fee ?? $order->getDeliveryFeeForDriver($driver->id));

            return array_merge($base, [
                'trip_type' => $trip->trip_type,
                'trip_type_label' => $trip->trip_type === OrderDriverTrip::TYPE_PICKUP
                    ? ($lang === 'ar' ? 'رحلة استلام' : 'Pickup trip')
                    : ($lang === 'ar' ? 'رحلة توصيل' : 'Delivery trip'),
                'completed_at' => $trip->completed_at?->format('c'),
            ]);
        })->filter()->values()->toArray();
    }

    /**
     * Build list of completed trips from orders (fallback when order_driver_trips not yet populated).
     */
    protected function buildCompletedTripsFromOrders($orders, $driver, string $lang): array
    {
        $trips = [];
        $activeDelivery = $this->activeDeliveryStatuses();

        foreach ($orders as $order) {
            $base = $this->mapOrderToDriverListItem($order, $driver, $lang);

            // Pickup trip: I was pickup driver and order reached delivered_to_branch or later
            if ((int) $order->pickup_driver_id === (int) $driver->id) {
                // If I am also the delivery driver and delivery is still active, skip (it belongs in "current")
                $deliveryStillActive = (int) $order->delivery_driver_id === (int) $driver->id
                    && in_array($order->status, $activeDelivery, true);

                if (! $deliveryStillActive) {
                    $pickupCompletedAt = $this->getFirstStatusLogAt($order, OrderStatus::DELIVERED_TO_BRANCH->value);
                    $trips[] = array_merge($base, [
                        'trip_type' => 'pickup',
                        'trip_type_label' => $lang === 'ar' ? 'رحلة استلام' : 'Pickup trip',
                        'completed_at' => $pickupCompletedAt?->format('c'),
                    ]);
                }
            }

            // Delivery trip: I was delivery driver and order is delivered/completed
            if ((int) $order->delivery_driver_id === (int) $driver->id
                && in_array($order->status, [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value], true)) {
                $deliveryCompletedAt = $this->getFirstStatusLogAt($order, OrderStatus::DELIVERED->value);
                $trips[] = array_merge($base, [
                    'trip_type' => 'delivery',
                    'trip_type_label' => $lang === 'ar' ? 'رحلة توصيل' : 'Delivery trip',
                    'completed_at' => $deliveryCompletedAt?->format('c'),
                ]);
            }
        }

        // Sort by completed_at desc (most recent first)
        usort($trips, function ($a, $b) {
            $at = $a['completed_at'] ?? '';
            $bt = $b['completed_at'] ?? '';

            return strcmp($bt, $at);
        });

        return $trips;
    }

    /**
     * Get created_at of first status log entry for the given status.
     */
    protected function getFirstStatusLogAt($order, string $status): ?\Carbon\Carbon
    {
        if (! $order->relationLoaded('statusLogs')) {
            $order->load('statusLogs');
        }
        $log = $order->statusLogs->firstWhere('status', $status);

        return $log ? $log->created_at : null;
    }

    /**
     * Map order to driver list item (order or trip row).
     */
    /**
     * Determine driver task type (pickup or delivery) based on the current order status phase.
     * Status-based so it works correctly even when the same driver is both pickup and delivery.
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

    protected function mapOrderToDriverListItem($order, $driver, string $lang): array
    {
        $deliverableItems = \Modules\Order\Support\OrderItemGrouper::withoutRejected($order->items ?? collect());

        $orderTitle = null;
        if ($deliverableItems->count() > 0) {
            $firstItem = $deliverableItems->first();
            if ($firstItem && $firstItem->piece) {
                $orderTitle = \App\Support\OrderItemDisplayNames::pieceName(
                    $firstItem->piece,
                    (int) ($order->branch_id ?? 0),
                    $lang
                );
            }
        }

        $vendorName = null;
        if ($order->vendor) {
            $vendorName = method_exists($order->vendor, 'getTranslatedName')
                ? $order->vendor->getTranslatedName($lang)
                : $order->vendor->name;
        }

        $piecesCount = \Modules\Order\Support\OrderItemGrouper::totalPiecesCount($deliverableItems);
        $distance = null;
        if ($driver->latitude && $driver->longitude) {
            $targetLat = null;
            $targetLong = null;
            if (in_array($order->status, ['delivered_to_branch', 'completed', 'confirmed', 'picked_up'])) {
                if (! $order->pickup_at_vendor && $order->pickupAddress) {
                    $targetLat = $order->pickupAddress->latitude;
                    $targetLong = $order->pickupAddress->longitude;
                } elseif ($order->branch) {
                    $targetLat = $order->branch->latitude;
                    $targetLong = $order->branch->longitude;
                }
            } elseif (in_array($order->status, ['on_way_to_delivery', 'waiting_client_receipt'])) {
                if (! $order->delivery_at_vendor && $order->deliveryAddress) {
                    $targetLat = $order->deliveryAddress->latitude;
                    $targetLong = $order->deliveryAddress->longitude;
                } elseif ($order->branch) {
                    $targetLat = $order->branch->latitude;
                    $targetLong = $order->branch->longitude;
                }
            }
            if ($targetLat && $targetLong) {
                $distance = $this->calculateDistance(
                    (float) $driver->latitude,
                    (float) $driver->longitude,
                    (float) $targetLat,
                    (float) $targetLong
                );
                $distance = round($distance, 2);
            }
        }

        // First item image (prefer non-rejected lines)
        $uploadService = app(\App\Services\UploadFilesService::class);
        $firstItemImage = null;
        if ($deliverableItems->count() > 0) {
            $itemWithImage = $deliverableItems->first(fn ($item) => ! empty($item->images));
            if ($itemWithImage) {
                $firstItemImage = $uploadService->getFullUrl($itemWithImage->images);
            }
        }

        // Branch location
        $branchLocation = $order->branch ? $order->branch->getApiLocation($lang) : null;

        $taskType = $this->resolveDriverTaskType($order, $driver, $lang);
        $handoffService = app(\App\Services\VendorOrderHandoffService::class);

        return array_merge([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_title' => $orderTitle,
            'order_status' => $order->status,
            'status_label' => $order->status_label,
            'vendor_name' => $vendorName,
            'customer_name' => $order->client?->full_name ?? 'Unknown',
            'distance' => $distance,
            'pieces_count' => $piecesCount,
            'delivery_fee' => (float) $order->getDeliveryFeeForDriver($driver->id),
            'first_item_image' => $firstItemImage,
            'branch_location' => $branchLocation,
            'rating' => $order->rating !== null ? (int) $order->rating : null,
            'review' => $order->review,
        ], $taskType, $order->clientVisitResponseFields(), $handoffService->vendorConfirmFlags($order), $handoffService->driverDeliveryActionFlags($order, (int) $driver->id));
    }

    /**
     * Accept order assignment
     */
    public function accept(Request $request, $orderId): JsonResponse
    {
        $driver = $request->user();

        if (! $driver->is_available) {
            return forbiddenResponse(__('driver.must_be_available_to_accept'));
        }

        $order = Order::where('id', $orderId)
            ->forDriver($driver->id)
            ->whereIn('status', [
                OrderStatus::DRIVER_PICKUP_ASSIGNED->value,
                OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
            ])
            ->first();

        if (! $order) {
            return notFoundResponse(__('driver.order_not_available_or_not_assigned'));
        }

        $statusService = app(\App\Services\OrderStatusService::class);

        try {
            $statusService->handleDriverResponse($order, $driver, 'accept');
        } catch (\Throwable $e) {
            return errorResponse($e->getMessage(), null, 400);
        }

        return successResponse($order->fresh(['vendor', 'client', 'items']), __('driver.order_accepted_successfully'));
    }

    /**
     * Update order status (driver-allowed transitions)
     */
    public function updateStatus(Request $request, $orderId): JsonResponse
    {
        $driver = $request->user();

        $validator = Validator::make($request->all(), [
            'status' => ['required', 'in:on_way_to_pickup,picked_up,delivered_to_branch,on_way_to_delivery,waiting_client_receipt'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $order = Order::where('id', $orderId)
            ->forDriver($driver->id)
            ->first();

        if (! $order) {
            return notFoundResponse('Order not found or not assigned to you');
        }

        $newStatus = OrderStatus::from($request->status);
        $statusService = app(\App\Services\OrderStatusService::class);

        try {
            $statusService->transitionTo($order, $newStatus, [
                'notes' => $request->notes,
                'changed_by' => $driver->id,
            ]);
        } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
            return errorResponse($e->userMessage(), null, 400);
        } catch (\LogicException $e) {
            return errorResponse($e->getMessage(), null, 400);
        }

        if ($newStatus === OrderStatus::DELIVERED) {
            $driver->update(['is_available' => true]);
        }

        $order = $order->fresh(['vendor', 'client', 'items', 'statusLogs']);

        return successResponse($order, __('order.order_status_updated'));
    }

    /**
     * Notify client that the driver is on the way (pickup or delivery to client).
     * Sends push + in-app notification only — does not change order status.
     * Pickup leg (collect from client): enables client requires_visit_response.
     * Delivery leg (from laundry to client): does not change requires_visit_response.
     */
    public function notifyClientOnTheWay(Request $request, $orderId): JsonResponse
    {
        $driver = $request->user();

        $validator = Validator::make($request->all(), [
            'visit_type' => ['nullable', 'string', 'in:pickup,delivery'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $order = Order::where('id', $orderId)
            ->forDriver($driver->id)
            ->with('client')
            ->first();

        if (! $order) {
            return notFoundResponse('Order not found or not assigned to you');
        }

        $notifyService = app(\App\Services\ClientDriverOnTheWayNotificationService::class);

        $isPickupDriver = (int) $order->pickup_driver_id === (int) $driver->id;
        $isDeliveryDriver = (int) $order->delivery_driver_id === (int) $driver->id;
        $preferredLeg = $request->filled('visit_type') ? strtolower((string) $request->input('visit_type')) : null;

        $targetLeg = $preferredLeg;
        if ($targetLeg === null) {
            if ($isDeliveryDriver && ! (bool) $order->delivery_at_vendor) {
                $targetLeg = 'delivery';
            } elseif ($isPickupDriver && ! (bool) $order->pickup_at_vendor) {
                $targetLeg = 'pickup';
            }
        }

        if ($targetLeg === null) {
            return errorResponse(__('order.driver_on_the_way_not_allowed', [
                'status' => OrderStatus::tryFrom($order->status)?->localizedLabel($order->payment_method) ?? $order->status,
            ]), ['order_status' => $order->status], 400);
        }

        $leg = $notifyService->resolveNotifyLegForDriver($order, (int) $driver->id, $targetLeg);

        if ($leg === null) {
            return errorResponse(
                __('order.driver_on_the_way_not_allowed', [
                    'status' => OrderStatus::tryFrom($order->status)?->localizedLabel($order->payment_method) ?? $order->status,
                ]),
                ['order_status' => $order->status, 'visit_type' => $targetLeg],
                400
            );
        }

        $sent = $notifyService->send($order, $leg);

        if (! $sent) {
            return errorResponse(__('order.driver_on_the_way_send_failed'), ['order_status' => $order->status], 400);
        }

        // Pickup from client → laundry: prompt client visit-response. Delivery from laundry → client: do not touch it (QR / on_way_to_delivery handles that).
        if ($leg === 'pickup') {
            app(\App\Services\ClientOrderVisitService::class)->enablePickupVisitResponseAfterDriverNotify($order);
            $order->refresh();
        }

        $visitMeta = $notifyService->visitTypeMeta($leg, $order);

        return successResponse(array_merge([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'status_label' => OrderStatus::tryFrom($order->status)?->localizedLabel($order->payment_method) ?? $order->status,
            'notification_sent' => true,
            'visit_type' => $leg,
            'visit_type_label' => $visitMeta['visit_type_label'],
        ], $order->clientVisitResponseFields()), __('order.driver_on_the_way_sent'));
    }

    /**
     * Get order details with progress tracking
     */
    public function show(Request $request, $orderId): JsonResponse
    {
        $driver = $request->user();
        $lang = app()->getLocale();

        $order = Order::where('id', $orderId)
            ->forDriver($driver->id)
            ->with([
                'vendor',
                'client',
                'latestPayment',
                'items.piece',
                'items.service',
                'items.additionalServicesPivot.serviceAddition',
                'branch',
                'pickupAddress',
                'deliveryAddress',
            ])
            ->first();

        if (! $order) {
            return notFoundResponse('Order not found');
        }

        // Calculate progress based on status
        $progressPercentage = match ($order->status) {
            'pending' => 0,
            'branch_review' => 5,
            'confirmed' => 10,
            'waiting_payment' => 15,
            'payment_confirmed' => 20,
            'driver_pickup_assigned' => 25,
            'driver_pickup_accepted' => 30,
            'on_way_to_pickup' => 35,
            'picked_up' => 45,
            'delivered_to_branch' => 55,
            'driver_delivery_assigned' => 80,
            'driver_delivery_accepted' => 83,
            'on_way_to_delivery' => 88,
            'waiting_client_receipt' => 90,
            'delivered' => 95,
            'completed' => 100,
            default => 0,
        };

        // Get progress titles based on percentage
        $titleProgress = '';
        $subTitleProgress = '';

        if ($progressPercentage == 25) {
            $titleProgress = $lang == 'ar' ? 'تم تأكيد الطلب' : 'Order Confirmed';
            $subTitleProgress = $lang == 'ar' ? 'في انتظار السائق' : 'Waiting for driver';
        } elseif ($progressPercentage == 35) {
            $titleProgress = $lang == 'ar' ? 'في الطريق للاستلام' : 'On Way to Pickup';
            $subTitleProgress = $lang == 'ar' ? 'السائق في الطريق للاستلام من العميل' : 'Driver is on the way to pickup from customer';
        } elseif ($progressPercentage == 45) {
            $titleProgress = $lang == 'ar' ? 'تم استلام الطلب من العميل' : 'Order Picked Up From Client';
            $subTitleProgress = $lang == 'ar' ? 'في الطريق إلى المغسلة' : 'On the way to laundry';
        } elseif ($progressPercentage == 50) {
            $titleProgress = $lang == 'ar' ? 'تم استلام الطلب' : 'Order Picked Up';
            $subTitleProgress = $lang == 'ar' ? 'في الطريق إلى المغسلة' : 'On the way to laundry';
        } elseif ($progressPercentage == 70 || $progressPercentage == 88) {
            $titleProgress = $lang == 'ar' ? 'في الطريق للتسليم' : 'On Way to Delivery';
            $subTitleProgress = $lang == 'ar' ? 'السائق في الطريق للتسليم' : 'Driver is on the way to deliver';
        } elseif ($progressPercentage == 90) {
            $titleProgress = $lang == 'ar' ? 'تم الوصول لموقع التسليم في انتظار استلام العميل' : 'At delivery location, waiting for client';
            $subTitleProgress = $lang == 'ar' ? 'في انتظار استلام العميل للطلب' : 'Waiting for client to receive';
        } elseif ($progressPercentage == 75) {
            $titleProgress = $lang == 'ar' ? 'جاهز للتسليم' : 'Ready for Delivery';
            $subTitleProgress = $lang == 'ar' ? 'في الطريق إلى العميل' : 'On the way to customer';
        } elseif ($progressPercentage == 100) {
            $titleProgress = $lang == 'ar' ? 'تم التسليم' : 'Delivered';
            $subTitleProgress = $lang == 'ar' ? 'تم تسليم الطلب بنجاح' : 'Order delivered successfully';
        }

        // Get order title from first non-rejected item
        $orderTitle = null;
        $titleItems = \Modules\Order\Support\OrderItemGrouper::withoutRejected($order->items ?? collect());
        if ($titleItems->count() > 0) {
            $firstItem = $titleItems->first();
            if ($firstItem && $firstItem->piece) {
                $orderTitle = \App\Support\OrderItemDisplayNames::pieceName(
                    $firstItem->piece,
                    (int) ($order->branch_id ?? 0),
                    $lang
                );
            }
        }

        // Get client address - try multiple address fields
        $clientAddress = null;
        if ($order->deliveryAddress) {
            $clientAddress = $order->deliveryAddress->address_text
                ?? $order->deliveryAddress->street_name
                ?? $order->deliveryAddress->national_address
                ?? null;
        } elseif ($order->pickupAddress) {
            $clientAddress = $order->pickupAddress->address_text
                ?? $order->pickupAddress->street_name
                ?? $order->pickupAddress->national_address
                ?? null;
        }

        // Build response based on progress
        $response = [
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,

            'order_status' => $order->status,
            'status_label' => $order->status_label,

            'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
            'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
            'payment_method' => $order->payment_method ?? 'cash_on_delivery',
            'payment_status' => $order->payment_status ?? 'pending',
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->payment_status ?? 'pending'),
        ];

        // Add branch location info (always available)
        if ($order->branch) {
            $response['branch_info'] = $order->branch->toApiOrderBranchFlat($lang, [
                'phone' => $order->branch->phone_number ?? null,
            ]);
        }

        // Pickup address object
        $pickupAddress = $order->pickupAddress ? [
            'id' => $order->pickupAddress->id,
            'address_text' => $order->pickupAddress->address_text ?? $order->pickupAddress->street_name,
            'street_name' => $order->pickupAddress->street_name,
            'building_number' => $order->pickupAddress->building_number ?? null,
            ...$order->pickupAddress->getApiFloorAttributes(),
            'apartment_number' => $order->pickupAddress->apartment ?? null,
            'city' => $order->pickupAddress->city ?? null,
            'district' => $order->pickupAddress->district ?? null,
            'national_address' => $order->pickupAddress->national_address,
            'latitude' => (float) $order->pickupAddress->latitude,
            'longitude' => (float) $order->pickupAddress->longitude,
        ] : null;

        // Delivery address object
        $deliveryAddress = $order->deliveryAddress ? [
            'id' => $order->deliveryAddress->id,
            'address_text' => $order->deliveryAddress->address_text ?? $order->deliveryAddress->street_name,
            'street_name' => $order->deliveryAddress->street_name,
            'building_number' => $order->deliveryAddress->building_number ?? null,
            ...$order->deliveryAddress->getApiFloorAttributes(),
            'apartment_number' => $order->deliveryAddress->apartment ?? null,
            'city' => $order->deliveryAddress->city ?? null,
            'district' => $order->deliveryAddress->district ?? null,
            'national_address' => $order->deliveryAddress->national_address,
            'latitude' => (float) $order->deliveryAddress->latitude,
            'longitude' => (float) $order->deliveryAddress->longitude,
        ] : null;

        $response['pickup_address'] = $pickupAddress;
        $response['delivery_address'] = $deliveryAddress;

        // Driver task type based on current order status phase
        $taskType = $this->resolveDriverTaskType($order, $driver, $lang);
        $response['driver_task_type'] = $taskType['driver_task_type'];
        $response['driver_task_type_label'] = $taskType['driver_task_type_label'];

        // Client info (always) with image and client default address text
        $uploadService = app(\App\Services\UploadFilesService::class);
        $clientName = 'Unknown';
        $clientDefaultAddressText = null;
        if ($order->client) {
            $clientName = is_array($order->client->full_name ?? null)
                ? ($order->client->full_name[$lang] ?? $order->client->full_name['en'] ?? 'Unknown')
                : $order->client->full_name;
            $clientDefaultAddressText = $order->client->getDefaultAddressText();
        }
        $response['client_info'] = $order->client
            ? $order->client->toApiClientInfo(
                $lang,
                $order->client->image ? $uploadService->getFullUrl($order->client->image) : null,
                [
                    'name' => $clientName,
                    'address' => $clientAddress,
                    'phone' => $order->client->phone ?? null,
                ]
            )
            : [
                'id' => $order->client_id,
                'name' => $clientName,
                'address' => $clientAddress,
                'phone' => $order->client?->phone ?? null,
                'image' => null,
                'default_address_text' => $clientDefaultAddressText,
                'national_address' => null,
            ];

        // Add client address GPS at pickup stage (where to go for pickup)
        if ($progressPercentage == 25) {
            $response['client_address_gps'] = [
                'lat' => $order->pickupAddress ? (float) $order->pickupAddress->latitude : null,
                'long' => $order->pickupAddress ? (float) $order->pickupAddress->longitude : null,
            ];
        } elseif ($progressPercentage == 50) {
            $response['vendor_order_gps'] = [
                'lat' => $order->branch ? (float) $order->branch->latitude : null,
                'long' => $order->branch ? (float) $order->branch->longitude : null,
            ];
        } elseif ($progressPercentage >= 75) {
            $response['order_title'] = $orderTitle;
        }

        // Common order info for all stages
        $response['order_info'] = [
            'order_number' => $order->order_number,
            'order_status' => $order->status,
            'status_label' => $order->status_label,

            'client_address' => $clientAddress,
        ];

        // Add total_price and payment for 75% and above
        if ($progressPercentage >= 75) {
            $response['order_info']['total_price'] = (float) $order->final_amount;
            $response['order_info']['qr_code'] = $order->qr_code;
            $response['order_info']['payment_method'] = $order->payment_method ?? 'cash_on_delivery';
            $response['order_info']['payment_status'] = $order->payment_status ?? 'pending';
            $response['order_info']['payment_status_label'] = \App\Support\PaymentStatusPresenter::label($order->payment_status ?? 'pending');
        }

        // Items info — exclude laundry-rejected pieces/services from driver delivery view
        $branchId = (int) ($order->branch_id ?? 0);
        $uploadService = app(\App\Services\UploadFilesService::class);
        $deliverableItems = \Modules\Order\Support\OrderItemGrouper::withoutRejected($order->items ?? collect());
        $response['items_info'] = collect(\Modules\Order\Support\OrderItemGrouper::toApiLines(
            $deliverableItems,
            $branchId,
            $lang,
            fn ($item) => $item->images ? $uploadService->getFullUrl($item->images) : null,
            splitByVendorStatus: false
        ))->map(function (array $g) {
            $services = $g['services'] ?? [];
            $serviceNames = collect($services)->pluck('name')->filter()->values()->all();
            $additionalServices = collect($g['additional_services'] ?? [])
                ->filter(fn ($a) => ($a['vendor_status'] ?? $a['status'] ?? 'accepted') !== 'rejected')
                ->values()
                ->all();

            return [
                'item_id' => $g['id'],
                'item_ids' => $g['ids'] ?? [$g['id']],
                'piece_id' => $g['piece']['id'] ?? null,
                'piece_name' => $g['piece']['name'] ?? 'Unknown',
                'service_id' => $services[0]['id'] ?? null,
                'service_name' => $serviceNames !== []
                    ? implode('، ', $serviceNames)
                    : ($g['service']['name'] ?? 'Unknown'),
                'services' => $services,
                'quantity' => (int) ($g['quantity'] ?? 1),
                'unit_price' => (float) ($g['unit_price'] ?? 0),
                'total_price' => (float) ($g['total_price'] ?? 0),
                'status' => $g['status'] ?? null,
                'note' => $g['note'] ?? null,
                'image' => $g['image'] ?? null,
                'additional_services' => $additionalServices,
            ];
        })->values()->toArray();

        $response['pieces_count'] = \Modules\Order\Support\OrderItemGrouper::totalPiecesCount($deliverableItems);

        $response['delivery_fee'] = (float) $order->getDeliveryFeeForDriver($driver->id);

        if ($order->payment_method === 'cash_on_delivery') {
            $response['payment_collection'] = [
                'payment_method' => $order->payment_method,
                'payment_label' => $lang === 'ar' ? 'الدفع عند التسليم' : 'Cash on Delivery',
                'subtotal' => (float) $order->total_amount,
                'discount' => (float) $order->discount_amount,
                'tax' => (float) $order->tax_amount,
                'delivery_fee' => (float) $order->getDeliveryFeeForDriver($driver->id),
                'final_amount' => (float) $order->final_amount,
                'collect_message' => $lang === 'ar'
                    ? 'يرجى تحصيل مبلغ '.number_format((float) $order->final_amount, 2).' ريال من العميل'
                    : 'Please collect '.number_format((float) $order->final_amount, 2).' SAR from the customer',
            ];
        }

        // Add review for this specific order if it exists
        if ($order->rating) {
            $uploadService = app(\App\Services\UploadFilesService::class);
            $response['order_review'] = [
                'rating' => (int) $order->rating,
                'comment' => $order->review ?? null,
                'client_name' => $order->client?->full_name ?? 'Unknown',
                'client_image' => $order->client?->image ? $uploadService->getFullUrl($order->client->image) : null,
                'reviewed_at' => $order->updated_at?->format('Y-m-d H:i:s'),
            ];
        }

        // Add reviews for 100% completion
        if ($progressPercentage == 100) {
            $totalReviews = Order::forDriver($driver->id)
                ->whereNotNull('rating')
                ->count();

            $reviews = Order::with(['client'])
                ->forDriver($driver->id)
                ->whereNotNull('rating')
                ->orderBy('updated_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($reviewOrder) {
                    $uploadService = app(\App\Services\UploadFilesService::class);

                    return [
                        'user_name' => $reviewOrder->client?->full_name ?? 'Unknown',
                        'user_image' => $uploadService->getFullUrl($reviewOrder->client?->image) ?? null,
                        'rating' => (int) $reviewOrder->rating,
                        'comment' => $reviewOrder->review ?? null,
                        'date' => $reviewOrder->updated_at?->format('Y-m-d H:i:s'),
                    ];
                })->toArray();

            $response['total_reviews'] = (string) $totalReviews;
            $response['reviews'] = $reviews;
        }

        $response['client_chat'] = $this->getChatForOrder($order->id, $order->client_id, null, $driver->id);
        $response['vendor_chat'] = $this->getVendorChatForOrder($order, $driver->id);
        $response['rating'] = $order->rating !== null ? (int) $order->rating : null;
        $response['review'] = $order->review;
        $response = array_merge(
            $response,
            $order->clientVisitResponseFields(),
            app(\App\Services\VendorOrderHandoffService::class)->vendorConfirmFlags($order),
            app(\App\Services\VendorOrderHandoffService::class)->driverDeliveryActionFlags($order, (int) $driver->id)
        );

        return successResponse($response, 'Order details retrieved successfully');
    }

    /**
     * Return chat info for this order (conversation_id and order_id). Only returns existing chats, doesn't create.
     */
    private function getChatForOrder(int $orderId, ?int $clientId = null, ?int $vendorId = null, ?int $driverId = null): array
    {
        $chatService = app(ChatService::class);
        $conversation = $chatService->getConversationForOrder($orderId, $clientId, $vendorId, $driverId);

        return [
            'conversation_id' => $conversation?->id,
            'order_id' => $conversation?->order_id ?? $orderId,
        ];
    }

    /**
     * Return vendor chat info (vendor ↔ driver) for this order.
     * Resolves the vendor_id from the order's branch.
     * Returns null ids if no vendor is found or chat doesn't exist.
     */
    private function getVendorChatForOrder(Order $order, int $driverId): array
    {
        $vendorId = $order->branch?->vendor_id;

        if (! $vendorId) {
            return [
                'conversation_id' => null,
                'order_id' => $order->id,
            ];
        }

        $chatService = app(ChatService::class);
        $conversation = $chatService->getConversationForOrder(
            $order->id,
            (int) $order->client_id,
            (int) $vendorId,
            $driverId
        );

        return [
            'conversation_id' => $conversation?->id,
            'order_id' => $conversation?->order_id ?? $order->id,
        ];
    }

    /**
     * Get/Update order tracking progress
     */
    public function tracking(Request $request, $orderId): JsonResponse
    {
        $driver = $request->user();

        $order = Order::where('id', $orderId)
            ->forDriver($driver->id)
            ->with(['vendor', 'branch', 'client', 'pickupAddress', 'deliveryAddress'])
            ->first();

        if (! $order) {
            return notFoundResponse('Order not found');
        }

        // Calculate progress based on current status (read-only)
        $progress = match ($order->status) {
            'pending' => 0,
            'branch_review' => 5,
            'confirmed' => 10,
            'waiting_payment' => 15,
            'payment_confirmed' => 20,
            'driver_pickup_assigned' => 25,
            'driver_pickup_accepted' => 30,
            'on_way_to_pickup' => 35,
            'picked_up' => 45,
            'delivered_to_branch' => 55,
            'driver_delivery_assigned' => 80,
            'driver_delivery_accepted' => 83,
            'on_way_to_delivery' => 88,
            'waiting_client_receipt' => 90,
            'delivered' => 95,
            'completed' => 100,
            default => 0,
        };

        $lang = app()->getLocale();
        $taskType = $this->resolveDriverTaskType($order, $driver, $lang);

        return successResponse(array_merge([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'status_label' => $order->status_label,

            'progress' => $progress,
            'client' => $order->client ? [
                'id' => $order->client->id,
                'name' => $order->client->full_name,
                'phone' => $order->client->phone,
                'image' => $order->client->image ? url($order->client->image) : null,
            ] : null,
            'vendor' => $order->vendor ? [
                'id' => $order->vendor->id,
                'name' => $order->vendor->getTranslatedName($lang),
                'logo' => url($order->vendor->logo ?? ''),
            ] : null,
            'branch' => $order->branch
                ? $order->branch->toApiOrderBranchFlat($lang)
                : null,
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
            'driver_location' => [
                'latitude' => $driver->latitude ? (float) $driver->latitude : null,
                'longitude' => $driver->longitude ? (float) $driver->longitude : null,
            ],
            'pickup_time' => $order->pickup_time ? $order->pickup_time->toISOString() : null,
            'estimated_delivery_time' => $order->estimated_delivery_time ? $order->estimated_delivery_time->toISOString() : null,
            'actual_delivery_time' => $order->actual_delivery_time ? $order->actual_delivery_time->toISOString() : null,
        ], $taskType, $order->clientVisitResponseFields()), 'Order tracking retrieved successfully');
    }

    /**
     * Reject order assignment
     */
    public function reject(RejectOrderRequest $request, $orderId): JsonResponse
    {
        $driver = $request->user();

        $order = Order::where('id', $orderId)
            ->forDriver($driver->id)
            ->whereIn('status', [
                OrderStatus::DRIVER_PICKUP_ASSIGNED->value,
                OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
            ])
            ->first();

        if (! $order) {
            return notFoundResponse('Order not found or cannot be rejected');
        }

        $statusService = app(\App\Services\OrderStatusService::class);

        try {
            $statusService->handleDriverResponse($order, $driver, 'reject', $request->reason);
        } catch (\Throwable $e) {
            return errorResponse($e->getMessage(), null, 400);
        }

        $this->notifyVendorDriverRejected($order, $driver);

        return successResponse($this->orderApiPayload($order->fresh(), [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->fresh()->status,
            'status_label' => OrderStatus::tryFrom($order->fresh()->status)?->localizedLabel($order->payment_method) ?? $order->fresh()->status,
            'message' => 'Order rejected successfully',
        ], (int) $driver->id), 'Order rejected successfully');
    }

    /**
     * Notify vendor (branch employees) that driver rejected the order – لتعيين سائق آخر
     */
    private function notifyVendorDriverRejected(Order $order, Driver $driver): void
    {
        $notifications = app(\App\Services\OrderNotificationService::class);

        $notifications->sendToVendorAndAdmins(
            $order,
            'رفض السائق الطلب – يرجى تعيين سائق آخر',
            'Driver rejected order – please assign another driver',
            "السائق رفض طلب رقم {$order->order_number}. يرجى تعيين سائق آخر.",
            "Driver rejected order #{$order->order_number}. Please assign another driver.",
            'order_driver_rejected',
            ['driver_id' => (string) $driver->id]
        );

        $notifications->sendToClient(
            $order,
            'تحديث على طلبك',
            'Order Update',
            "تعذر على السائق إكمال طلبك #{$order->order_number}. جاري تعيين سائق آخر.",
            "The assigned driver could not complete your order #{$order->order_number}. We are assigning another driver.",
            'driver_rejected',
        );
    }

    /**
     * Mark pickup as complete — transitions based on order and stage:
     * - Pickup driver (on_way_to_pickup) → picked_up
     * - Delivery driver (driver_delivery_accepted after laundry handoff) → on_way_to_delivery
     */
    public function pickupComplete(Request $request, $orderId): JsonResponse
    {
        $driver = $request->user();

        $order = Order::where('id', $orderId)
            ->forDriver($driver->id)
            ->with(['vendor', 'branch', 'client', 'items'])
            ->first();

        if (! $order) {
            return notFoundResponse('Order not found or not assigned to you');
        }

        $statusService = app(\App\Services\OrderStatusService::class);
        $currentStatus = OrderStatus::tryFrom($order->status);
        $isDeliveryDriver = (int) $order->delivery_driver_id === (int) $driver->id;

        // Delivery driver: arrived at client — wait for client handoff confirmation (never auto-complete).
        if ($isDeliveryDriver && $currentStatus === OrderStatus::ON_WAY_TO_DELIVERY) {
            try {
                $statusService->transitionTo($order, OrderStatus::WAITING_CLIENT_RECEIPT, [
                    'notes' => 'Driver arrived at delivery location, waiting for client handoff confirmation',
                    'changed_by' => $driver->id,
                ]);
            } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
                return errorResponse($e->userMessage(), null, 400);
            }

            return successResponse($this->orderApiPayload($order->fresh(), [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => OrderStatus::WAITING_CLIENT_RECEIPT->value,
                'status_label' => OrderStatus::WAITING_CLIENT_RECEIPT->localizedLabel(),
                'payment_status' => $order->fresh()->payment_status ?? 'pending',
                'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->fresh()->payment_status ?? 'pending'),
            ], (int) $driver->id), app()->getLocale() === 'ar'
                ? 'تم الوصول لموقع التسليم — في انتظار تأكيد العميل للاستلام'
                : 'At delivery location — waiting for client to confirm receipt');
        }

        // If already past pickup / delivered, just return success
        if (in_array($currentStatus, [
            OrderStatus::PICKED_UP,
            OrderStatus::DELIVERED_TO_BRANCH,
            OrderStatus::ON_WAY_TO_DELIVERY,
            OrderStatus::WAITING_CLIENT_RECEIPT,
            OrderStatus::DELIVERED,
            OrderStatus::COMPLETED,
        ])) {
            return successResponse($this->orderApiPayload($order, [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'status_label' => $currentStatus?->localizedLabel($order->payment_method) ?? $order->status,
            ], (int) $driver->id), 'Pickup already completed');
        }

        // Delivery driver: after laundry handoff → on_way_to_delivery (driver-owned step)
        if ($isDeliveryDriver && $currentStatus === OrderStatus::DRIVER_DELIVERY_ACCEPTED) {
            if ($order->vendor_handed_to_delivery_at === null) {
                return errorResponse(__('order.driver_on_way_requires_laundry_handoff'), null, 400);
            }

            try {
                $statusService->transitionTo($order, OrderStatus::ON_WAY_TO_DELIVERY, [
                    'notes' => 'Driver started delivery trip to client',
                    'changed_by' => $driver->id,
                ]);
            } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
                return errorResponse($e->userMessage(), null, 400);
            } catch (\LogicException $e) {
                return errorResponse($e->getMessage(), null, 400);
            }

            return successResponse($this->orderApiPayload($order->fresh(), [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->fresh()->status,
                'status_label' => OrderStatus::tryFrom($order->fresh()->status)?->localizedLabel($order->payment_method) ?? $order->fresh()->status,
            ], (int) $driver->id), app()->getLocale() === 'ar' ? 'أنت في الطريق لتوصيل الطلب للعميل' : 'You are on the way to deliver the order to the client');
        }

        // Pickup driver: on_way_to_pickup → picked_up
        try {
            $statusService->transitionTo($order, OrderStatus::PICKED_UP, [
                'notes' => 'Order picked up from client by driver',
                'changed_by' => $driver->id,
            ]);
        } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
            return errorResponse($e->userMessage(), null, 400);
        }

        return successResponse($this->orderApiPayload($order->fresh(), [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->fresh()->status,
            'status_label' => OrderStatus::tryFrom($order->fresh()->status)?->localizedLabel($order->payment_method) ?? $order->fresh()->status,
        ], (int) $driver->id), app()->getLocale() === 'ar' ? 'تم استلام الطلب من العميل' : 'Order picked up from client');
    }

    /**
     * Confirm with QR code.
     *
     * For vendor-owned handoff legs, QR enables the corresponding vendor confirm flag
     * without changing order status. Final delivery is still confirmed by the client.
     */
    public function confirmQR(ConfirmQRRequest $request, $orderId): JsonResponse
    {
        $driver = $request->user();
        $lang = app()->getLocale();

        $order = Order::where('id', $orderId)
            ->forDriver($driver->id)
            ->with(['vendor', 'branch', 'client', 'items'])
            ->first();

        if (! $order) {
            return notFoundResponse('Order not found or not assigned to you');
        }

        // QR is optional: when omitted/empty, confirm without scanning.
        // When provided, it must match the order QR.
        $providedQR = trim((string) $request->input('qr_code', ''));
        if ($providedQR !== '') {
            $orderQR = trim($order->qr_code ?? '');

            if ($orderQR === '' || strcasecmp($orderQR, $providedQR) !== 0) {
                return errorResponse($lang === 'ar' ? 'رمز QR غير صالح.' : 'Invalid QR code.', null, 400);
            }
        }

        // Determine the driver's role for this order
        $isPickupDriver = (int) $order->pickup_driver_id === (int) $driver->id;
        $isDeliveryDriver = (int) $order->delivery_driver_id === (int) $driver->id;
        $handoffService = app(\App\Services\VendorOrderHandoffService::class);

        if ($isPickupDriver && $order->status === OrderStatus::PICKED_UP->value) {
            $order = $handoffService->enablePickupFromDriverConfirm($order);

            return successResponse($this->orderApiPayload($order, [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'status_label' => OrderStatus::tryFrom($order->status)?->localizedLabel($order->payment_method) ?? $order->status,
                'driver_role' => 'pickup',
            ], (int) $driver->id), __('order.vendor_handoff_driver_qr_enabled_pickup'));
        }

        if ($isDeliveryDriver && $order->status === OrderStatus::DRIVER_DELIVERY_ACCEPTED->value) {
            if ($order->vendor_handed_to_delivery_at !== null) {
                return successResponse($this->orderApiPayload($order, [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'status_label' => OrderStatus::tryFrom($order->status)?->localizedLabel($order->payment_method) ?? $order->status,
                    'driver_role' => 'delivery',
                ], (int) $driver->id), app()->getLocale() === 'ar'
                    ? 'تم استلام الطلب من المغسلة مسبقًا — يمكنك وضع نفسك في الطريق لهذا الطلب'
                    : 'Order already handed over by laundry — you can mark this order as on the way');
            }

            $order = $handoffService->enableHandoverToDeliveryConfirm($order);

            return successResponse($this->orderApiPayload($order, [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'status_label' => OrderStatus::tryFrom($order->status)?->localizedLabel($order->payment_method) ?? $order->status,
                'driver_role' => 'delivery',
            ], (int) $driver->id), __('order.vendor_handoff_driver_qr_enabled_delivery'));
        }

        // Delivery driver never transitions to delivered via confirm-qr — only client confirm-delivery does
        $currentLabel = OrderStatus::tryFrom($order->status)?->localizedLabel($order->payment_method) ?? $order->status;

        if ($isPickupDriver) {
            return errorResponse(
                $lang === 'ar'
                    ? "أنت سائق الاستلام لهذا الطلب. يمكنك تأكيد QR عندما تكون حالة الطلب 'تم الاستلام' (picked_up). الحالة الحالية: {$currentLabel}."
                    : "You are the pickup driver for this order. QR confirmation is available when order status is 'Picked Up'. Current status: {$currentLabel}.",
                null,
                400
            );
        }

        if ($isDeliveryDriver) {
            return errorResponse(
                $lang === 'ar'
                    ? "أنت سائق التوصيل. يمكنك تأكيد QR فقط عند استلام الطلب من المغسلة (قبل سائق التوصيل). تسليم الطلب للعميل يؤكده العميل فقط. الحالة الحالية: {$currentLabel}."
                    : "You are the delivery driver. QR confirmation is only for receiving from laundry (driver_delivery_accepted). Delivery to client is confirmed by the client only. Current status: {$currentLabel}.",
                null,
                400
            );
        }

        return errorResponse(
            $lang === 'ar'
                ? 'لا يمكن تأكيد QR — لم يتم تعيينك كسائق استلام أو توصيل لهذا الطلب.'
                : 'Cannot confirm QR — you are not assigned as pickup or delivery driver for this order.',
            null,
            400
        );
    }

    /**
     * Get order status log
     */
    public function getStatusLog(Request $request, $orderId): JsonResponse
    {
        $driver = $request->user();

        $order = Order::where('id', $orderId)
            ->forDriver($driver->id)
            ->with(['statusLogs'])
            ->first();

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $statusLog = $order->statusLogs
            ->sortBy('created_at')
            ->values()
            ->map(fn ($log) => OrderStatusLogPresenter::forDriver($log, $order))
            ->toArray();

        return successResponse(['status_log' => $statusLog], __('order.status_log_retrieved'));
    }

    private function orderApiPayload(Order $order, array $payload, ?int $viewerDriverId = null): array
    {
        $handoffService = app(\App\Services\VendorOrderHandoffService::class);

        return array_merge(
            $payload,
            $order->couponResponseFields(),
            $order->clientVisitResponseFields(),
            $handoffService->vendorConfirmFlags($order),
            $handoffService->driverDeliveryActionFlags($order, $viewerDriverId)
        );
    }
}
