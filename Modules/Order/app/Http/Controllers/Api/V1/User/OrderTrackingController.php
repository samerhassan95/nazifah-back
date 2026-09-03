<?php

namespace Modules\Order\Http\Controllers\Api\V1\User;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\OrderCatalogAvailabilityService;
use App\Services\UploadFilesService;
use App\Support\OrderStatusLogPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Address\Models\Address;
use Modules\Admin\Models\AdminSetting;
use Modules\Chat\Services\ChatService;
use Modules\Discount\Services\DiscountService;
use Modules\Order\Exceptions\InsufficientWalletBalanceException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderStatusLog;
use Modules\Order\Services\OrderPaymentService;
use Modules\Order\Support\OrderItemGrouper;
use Modules\Order\Support\OrderItemsNormalizer;
use Modules\Order\Support\PendingApprovalItemCategorizer;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentService;
use Modules\Piece\Models\Piece;

class OrderTrackingController extends Controller
{
    protected $uploadFilesService;

    protected $discountService;

    protected $catalogAvailabilityService;

    protected $paymentService;

    protected $orderPaymentService;

    public function __construct(
        UploadFilesService $uploadFilesService,
        DiscountService $discountService,
        OrderCatalogAvailabilityService $catalogAvailabilityService,
        PaymentService $paymentService,
        OrderPaymentService $orderPaymentService
    ) {
        $this->uploadFilesService = $uploadFilesService;
        $this->discountService = $discountService;
        $this->catalogAvailabilityService = $catalogAvailabilityService;
        $this->paymentService = $paymentService;
        $this->orderPaymentService = $orderPaymentService;
    }

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
     * Get order tracking information
     */
    public function getTracking(Request $request, int $order_id): JsonResponse
    {
        $user = $request->user();

        // Get language from Accept-Language header
        $accept = $request->header('Accept-Language', 'en');
        $lang = Str::contains(strtolower($accept), 'ar') ? 'ar' : 'en';

        $order = Order::with([
            'vendor',
            'branch.vendor',
            'latestPayment',
            'client.addresses',
            'statusLogs',
            'pickupAddress',
            'deliveryAddress',
            // Soft-deleted lines (post-edit replacements) are not part of the live order.
            'items' => fn ($q) => $q->with([
                'piece.iconRelation',
                'service.iconRelation',
                'additionalServicesPivot.serviceAddition.iconRelation',
            ]),
            'driver',
            'pickupDriver',
            'deliveryDriver',
            'discount',
            'driverRejections' => fn ($q) => $q->orderBy('rejected_at', 'asc'),
            'driverRejections.driver',
        ])
            ->where('client_id', $user->id)
            ->find($order_id);

        // Set locale for translations
        $originalLocale = app()->getLocale();
        app()->setLocale($lang);

        if (! $order) {
            // Get translated message before restoring locale
            $message = trans('order.order_not_found', [], $lang);
            app()->setLocale($originalLocale);

            return notFoundResponse($message);
        }

        $statusHistory = $order->statusLogs()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($log) use ($order) {
                return [
                    'status' => $log->status,
                    'status_label' => OrderStatus::fromString($log->status)?->localizedLabel($order->payment_method, false, (bool) $order->delivery_at_vendor) ?? $log->status,
                    'notes' => $log->notes,
                    'date' => $log->created_at->toISOString(),
                ];
            });

        // Get client default address (use loaded addresses if available)
        $clientDefaultAddress = null;
        if ($order->client && $order->client->relationLoaded('addresses')) {
            $clientDefaultAddress = $order->client->addresses->where('is_default', true)->first();
        } elseif ($order->client) {
            $clientDefaultAddress = $order->client->defaultAddress();
        }

        // Get vendor name and address text
        $vendorName = $order->vendor ? $order->vendor->getTranslatedName($lang) : '';
        $vendorAddressText = null;
        if ($order->vendor) {
            if (method_exists($order->vendor, 'getTranslation') && $order->vendor->location) {
                $vendorAddressText = $order->vendor->getTranslation('location', $lang);
            } elseif ($order->vendor->location) {
                if (is_array($order->vendor->location)) {
                    $vendorAddressText = $order->vendor->location[$lang] ?? $order->vendor->location['en'] ?? $order->vendor->location['ar'] ?? null;
                } else {
                    $vendorAddressText = $order->vendor->location;
                }
            }
        }

        // Prepare driver data
        $formatDriver = function ($driver) use ($lang) {
            if (! $driver) {
                return null;
            }

            return [
                'id' => $driver->id,
                'name' => method_exists($driver, 'getTranslation')
                    ? $driver->getTranslation('full_name', $lang)
                    : $driver->full_name,
                'phone' => $driver->phone,
                'image' => $driver->image,
                'latitude' => (float) $driver->latitude,
                'longitude' => (float) $driver->longitude,
                'rating' => (float) $driver->rating,
            ];
        };

        $activeDriver = null;
        $orderStatus = OrderStatus::fromString($order->status);

        if ($orderStatus === OrderStatus::DELIVERED_TO_BRANCH) {
            // Pickup leg is done (order already at the branch) and no delivery
            // driver has started yet — nobody is actively working the order right
            // now, so there's no current driver.
            $activeDriver = null;
        } elseif (in_array($orderStatus, [
            OrderStatus::DRIVER_PICKUP_ASSIGNED,
            OrderStatus::DRIVER_PICKUP_ACCEPTED,
            OrderStatus::ON_WAY_TO_PICKUP,
            OrderStatus::PICKED_UP,
        ])) {
            $activeDriver = $order->pickupDriver;
        } elseif (in_array($orderStatus, [
            OrderStatus::DRIVER_DELIVERY_ASSIGNED,
            OrderStatus::DRIVER_DELIVERY_ACCEPTED,
            OrderStatus::ON_WAY_TO_DELIVERY,
            OrderStatus::WAITING_CLIENT_RECEIPT,
            OrderStatus::DELIVERED,
        ])) {
            $activeDriver = $order->deliveryDriver;
        } else {
            $activeDriver = $order->driver;
        }

        $handoffService = app(\App\Services\VendorOrderHandoffService::class);

        $driverRejections = $order->driverRejections->map(function ($rejection) use ($formatDriver) {
            return [
                'trip_type' => $rejection->trip_type,
                'driver' => $formatDriver($rejection->driver),
                'reason' => $rejection->reason,
                'rejected_at' => $rejection->rejected_at?->toISOString(),
            ];
        })->values();

        // Only surface the flag while the order is sitting at the status a rejection
        // reverts it to (confirmed for pickup, delivered_to_branch for delivery) — once
        // the order moves past that, the rejection is resolved and no longer relevant.
        $hadDriverRejection = in_array($order->status, [
            OrderStatus::CONFIRMED->value,
            OrderStatus::DELIVERED_TO_BRANCH->value,
        ], true) && $driverRejections->isNotEmpty();

        // Client give/receive handoff at the branch (walk-in drop-off and pickup), e.g.
        // pickup_at_vendor + delivery_at_vendor both true. Reuses ClientOrderHandoffService
        // for the type/label/receive-side logic, but on this endpoint the "drop off at the
        // laundry" step is only surfaced at status=confirmed — payment_confirmed is not
        // treated as a trigger here (unlike the shared confirm-handoff/on-the-way endpoints).
        $clientHandoffService = app(\App\Services\ClientOrderHandoffService::class);
        $handoffContext = $clientHandoffService->resolveHandoffContext($order);
        if ($handoffContext && $handoffContext['handoff_type'] === 'give_to_laundry' && $order->status !== OrderStatus::CONFIRMED->value) {
            $handoffContext = null;
        }

        // "completed" means the vendor is done, not that the client has the order in
        // hand yet (branch self-pickup not collected, or home delivery not yet handed
        // over) — client_delivery_handoff_at is only ever set once they actually confirm
        // receipt, so its absence here means they're still waiting either way.
        $awaitingClientReceipt = $order->status === OrderStatus::COMPLETED->value && ! $order->client_delivery_handoff_at;

        $branchId = (int) ($order->branch_id ?? 0);
        $imageUrlResolver = fn ($path) => $path ? $this->uploadFilesService->getFullUrl($path) : null;
        $categorized = PendingApprovalItemCategorizer::categorize($order, $lang, $imageUrlResolver);

        // Whole items actually rejected (their order_item rows have
        // vendor_status=rejected) — these are removed from the normal `items` list
        // below and shown only here. Checked against actual order_item vendor_status
        // (not the categorizer's merged `ids`, which can span multiple items once
        // mergeDuplicateRejectedItems collapses same-piece-name entries together).
        // Items with only a rejected *addition* are handled separately below
        // ($partiallyRejectedItems) — they stay in the normal `items` list at their
        // correct accepted price, and are added into rejected_items too so the
        // "Rejected Requests" section still surfaces what got declined.
        $rejectedOrderItemIds = $order->items
            ->filter(fn ($item) => ($item->vendor_status ?? 'accepted') === 'rejected')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rejectedItems = collect($categorized['rejected'])
            ->filter(function (array $item) use ($rejectedOrderItemIds) {
                $ids = collect($item['ids'] ?? [])->map(fn ($id) => (int) $id);

                return $ids->intersect($rejectedOrderItemIds)->isNotEmpty();
            })
            ->map(function (array $item) use ($order, $branchId, $lang) {
                // The categorizer's rejected entries carry only piece_name (a plain
                // string), never the piece's own id/icon — the client's "Rejected
                // Requests" section renders these with a generic placeholder icon
                // instead of the actual piece icon shown everywhere else. Look the
                // piece back up from the first matching order_item.
                $firstId = collect($item['ids'] ?? [])->first();
                $sourceItem = $firstId ? $order->items->firstWhere('id', $firstId) : null;

                $item['piece'] = [
                    'id' => $sourceItem?->piece_id,
                    'name' => $sourceItem?->piece
                        ? \App\Support\OrderItemDisplayNames::pieceName($sourceItem->piece, $branchId, $lang)
                        : ($item['piece_name'] ?? 'Unknown'),
                    'icon' => \App\Support\OrderItemDisplayNames::pieceIconUrl($sourceItem?->piece),
                ];

                return $item;
            })
            ->values();

        // Rejected additional services, read straight off each order_item's own
        // pivot rows rather than the categorizer's rejected-item list — that list
        // merges same-piece-name entries together (mergeDuplicateRejectedItems),
        // which loses the per-order_item id association needed to attribute a
        // rejected addition back to the specific accepted item it belongs to.
        $rejectedServicesByItemId = $order->items
            ->flatMap(function ($item) use ($branchId, $lang) {
                if (! $item->relationLoaded('additionalServicesPivot')) {
                    return collect();
                }

                return collect($item->additionalServicesPivot)
                    ->filter(fn ($pivot) => ($pivot->vendor_status ?? 'accepted') === 'rejected' && $pivot->serviceAddition)
                    ->map(function ($pivot) use ($item, $branchId, $lang) {
                        $qty = (int) ($pivot->quantity ?? 1);
                        $unitPrice = \App\Support\OrderItemDisplayNames::storedAdditionalServiceUnitPrice($pivot);

                        return [
                            'order_item_id' => (int) $item->id,
                            'id' => $pivot->serviceAddition->id,
                            'name' => \App\Support\OrderItemDisplayNames::additionalServiceName($pivot->serviceAddition, $branchId, $lang),
                            'price' => $unitPrice,
                            'quantity' => $qty,
                            'total' => round($unitPrice * $qty, 2),
                        ];
                    });
            })
            ->groupBy('order_item_id');

        // An otherwise-accepted item can still have one or more of its additional
        // services rejected. Those don't belong in $rejectedItems above (the item
        // itself is not rejected — it still shows normally in `items` with
        // `rejected_services` noting what was declined), but the client's
        // "Rejected Requests" section is expected to surface every rejection, not
        // just whole-item ones, so add one compact entry per affected item here —
        // piece + just the declined addition(s) + their price, not the item's full
        // price (that stays in the normal `items` line, unaffected).
        $partiallyRejectedItems = $order->items
            ->filter(fn ($item) => ! in_array((int) $item->id, $rejectedOrderItemIds, true))
            ->filter(fn ($item) => $rejectedServicesByItemId->has((int) $item->id))
            ->map(function ($item) use ($rejectedServicesByItemId, $branchId, $lang, $imageUrlResolver) {
                $rejectedServices = $rejectedServicesByItemId->get((int) $item->id, collect());
                $pieceName = $item->piece
                    ? \App\Support\OrderItemDisplayNames::pieceName($item->piece, $branchId, $lang)
                    : 'Unknown';

                return [
                    'id' => (int) $item->id,
                    'ids' => [(int) $item->id],
                    'piece' => [
                        'id' => $item->piece_id,
                        'name' => $pieceName,
                        'icon' => \App\Support\OrderItemDisplayNames::pieceIconUrl($item->piece),
                    ],
                    'piece_name' => $pieceName,
                    'service_name' => $rejectedServices->pluck('name')->filter()->implode('، '),
                    'services' => $rejectedServices->map(fn (array $row) => [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'price' => $row['price'],
                    ])->values()->all(),
                    'quantity' => (int) $item->quantity,
                    'unit_price' => 0.0,
                    'total_price' => round($rejectedServices->sum('total'), 2),
                    'vendor_notes' => null,
                    'note' => $item->notes ?? null,
                    'description' => $item->notes ?? null,
                    'image' => $imageUrlResolver($item->images),
                    'additional_services' => [],
                    'status' => 'rejected',
                ];
            })
            ->values();

        $mappedItems = collect(OrderItemGrouper::toApiLines(
            $order->items,
            $branchId,
            $lang,
            fn ($item) => $item->images ? $this->uploadFilesService->getFullUrl($item->images) : null
        ))
            ->map(function (array $line) use ($rejectedServicesByItemId) {
                $line['rejected_services'] = collect($line['ids'] ?? [])
                    ->flatMap(fn ($id) => $rejectedServicesByItemId->get($id, collect()))
                    ->map(fn (array $row) => [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'price' => $row['price'],
                    ])
                    ->values()
                    ->all();

                return $line;
            })
            ->reject(fn (array $line) => ($line['status'] ?? 'accepted') === 'rejected')
            ->concat($rejectedItems->map(function (array $item) {
                return [
                    'id' => $item['id'] ?? null,
                    'ids' => $item['ids'] ?? [],
                    'line_group' => null,
                    'piece' => [
                        'id' => $item['piece']['id'] ?? null,
                        'name' => $item['piece_name'] ?? 'Unknown',
                        'icon' => $item['piece']['icon'] ?? null,
                    ],
                    'service' => ($item['services'][0] ?? null),
                    'services' => $item['services'] ?? [],
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'total_price' => (float) ($item['total_price'] ?? 0),
                    'additional_services_total' => 0.0,
                    'additional_services' => [],
                    'rejected_services' => [],
                    'status' => 'rejected',
                    'note' => $item['note'] ?? null,
                    'image' => $item['image'] ?? null,
                ];
            }))
            ->values();

        // Everything the "Rejected Requests" section should list: whole rejected
        // items plus otherwise-accepted items that had one or more additions
        // declined (those stay in `items` above at their correct, accepted price;
        // this is just the summary of what got turned down and for how much).
        $allRejectedItems = $rejectedItems->concat($partiallyRejectedItems)->values();

        return successResponse(array_merge([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'current_status' => $order->status,
            'status_label' => OrderStatus::fromString($order->status)?->localizedLabel($order->payment_method, $awaitingClientReceipt, (bool) $order->delivery_at_vendor) ?? $order->status,
            'progress_percentage' => $this->getProgressPercentage($order->status),
            'laundry' => $order->vendor ? [
                'id' => $order->vendor->id,
                'name' => $vendorName,
                'phone' => $order->vendor->phone,
                'logo' => $this->uploadFilesService->getFullUrl($order->vendor->logo),
                'location' => [
                    'address_text' => $vendorAddressText,
                    'latitude' => $order->branch ? (float) $order->branch->latitude : 0,
                    'longitude' => $order->branch ? (float) $order->branch->longitude : 0,
                ],

            ] : null,
            'branch' => $order->branch
                ? $order->branch->toApiOrderBranchFlat($lang, [
                    'phone' => $order->branch->phone_number,
                    'delivery_price_per_km' => (float) (
                        $order->branch->vendor?->delivery_price_per_km
                        ?? $order->vendor?->delivery_price_per_km
                        ?? 0
                    ),
                ])
                : null,
            'client_address' => $clientDefaultAddress ? [
                'id' => $clientDefaultAddress->id,
                'title' => $clientDefaultAddress->title,
                'address_text' => $clientDefaultAddress->street_name ?? $clientDefaultAddress->address_text,
                'national_address' => $clientDefaultAddress->national_address,
                'building_number' => $clientDefaultAddress->building_number,
                'street_number' => $clientDefaultAddress->street_number,
                ...$clientDefaultAddress->getApiFloorAttributes(),
                'apartment' => $clientDefaultAddress->apartment,
                'latitude' => (float) $clientDefaultAddress->latitude,
                'longitude' => (float) $clientDefaultAddress->longitude,
                'notes' => $clientDefaultAddress->notes,
                'is_default' => (bool) $clientDefaultAddress->is_default,
            ] : null,
            'items' => $mappedItems,
            'rejected_items' => $allRejectedItems,
            'rejected_count' => $allRejectedItems->count(),
            'pickup_address' => ($order->pickup_at_vendor || ! $order->pickup_address_id) ? null : ($order->pickupAddress ? [
                'id' => $order->pickupAddress->id,
                'title' => $order->pickupAddress->title,
                'address_text' => $order->pickupAddress->street_name ?? $order->pickupAddress->address_text,
                'national_address' => $order->pickupAddress->national_address,
                'building_number' => $order->pickupAddress->building_number,
                'street_number' => $order->pickupAddress->street_number,
                ...$order->pickupAddress->getApiFloorAttributes(),
                'apartment' => $order->pickupAddress->apartment,
                'latitude' => (float) $order->pickupAddress->latitude,
                'longitude' => (float) $order->pickupAddress->longitude,
                'notes' => $order->pickupAddress->notes,
                'is_default' => (bool) $order->pickupAddress->is_default,
            ] : null),
            'delivery_address' => ($order->delivery_at_vendor || ! $order->delivery_address_id) ? null : ($order->deliveryAddress ? [
                'id' => $order->deliveryAddress->id,
                'title' => $order->deliveryAddress->title,
                'address_text' => $order->deliveryAddress->street_name ?? $order->deliveryAddress->address_text,
                'national_address' => $order->deliveryAddress->national_address,
                'building_number' => $order->deliveryAddress->building_number,
                'street_number' => $order->deliveryAddress->street_number,
                ...$order->deliveryAddress->getApiFloorAttributes(),
                'apartment' => $order->deliveryAddress->apartment,
                'latitude' => (float) $order->deliveryAddress->latitude,
                'longitude' => (float) $order->deliveryAddress->longitude,
                'notes' => $order->deliveryAddress->notes,
                'is_default' => (bool) $order->deliveryAddress->is_default,
            ] : null),
            'total_amount' => (float) $order->total_amount,
            'discount_amount' => (float) $order->discount_amount,
            ...$order->couponResponseFields($lang),
            'tax_amount' => (float) $order->tax_amount,
            'delivery_fee' => (float) $order->delivery_fee,
            ...$this->freeDeliveryFields($order),
            'final_amount' => (float) $order->final_amount,
            'distance' => $order->distance !== null ? (float) $order->distance : 0,
            ...$order->paymentFieldsForApi(),
            'payment_breakdown' => $order->paymentBreakdownForApi(),
            'payment_status' => $order->payment_status ?? 'pending',
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->payment_status ?? 'pending'),
            'notes' => $order->notes,
            'pickup_at_vendor' => $order->pickup_at_vendor,
            'delivery_at_vendor' => $order->delivery_at_vendor,
            'driver_id' => $order->driver_id,
            'pickup_driver_id' => $order->pickup_driver_id,
            'delivery_driver_id' => $order->delivery_driver_id,
            'delivery_data' => [
                'current_driver' => $formatDriver($activeDriver),
                'pickup_driver' => $formatDriver($order->pickupDriver),
                'delivery_driver' => $formatDriver($order->deliveryDriver),
            ],
            'had_driver_rejection' => $hadDriverRejection,
            'driver_rejections' => $driverRejections,
            'requires_handoff_confirmation' => $handoffContext !== null,
            'handoff' => $handoffContext ? [
                'type' => $handoffContext['handoff_type'],
                'direction' => $handoffContext['direction'],
                'confirm_label' => $handoffContext['confirm_label'],
                'endpoint' => '/api/v1/user/orders/'.$order->id.'/confirm-handoff',
                'confirm_action' => 'confirm',
            ] : null,
            'pickup_time' => $order->pickup_time?->toISOString(),
            'estimated_delivery_time' => $order->estimated_delivery_time?->toISOString(),
            'actual_delivery_time' => $order->actual_delivery_time?->toISOString(),
            'qr_code' => $order->qr_code,
            'rating' => $order->rating !== null ? (int) $order->rating : null,
            'review' => $order->review,
            'created_at' => $order->created_at->toISOString(),

            // vendor_chat: client ↔ vendor (driver_id must be null) - return existing or create
            'vendor_chat' => $this->getChatForOrder(
                $order->id,
                $user->id,
                $order->vendor?->id,
                null, // driver_id must be null for vendor chat
                ensure: true
            ),
            // delivery_chat: client ↔ driver (vendor_id must be null) - return existing only
            'delivery_chat' => $this->getChatForOrder(
                $order->id,
                $user->id,
                null, // vendor_id must be null for delivery chat
                $order->delivery_driver_id ?? $order->driver_id,
                ensure: false // Only return if driver is assigned and chat exists
            ),
            // chat: general support chat (both vendor_id and driver_id null) - return existing or create
            'chat' => $this->getChatForOrder(
                $order->id,
                $user->id,
                null,
                null,
                ensure: true
            ),
        ],
            $order->clientVisitResponseFields(),
            $handoffService->vendorConfirmFlags($order),
            $handoffService->driverDeliveryActionFlags($order)
        ), __('order.order_tracking_retrieved'));

        // Restore original locale
        app()->setLocale($originalLocale);
    }

    /**
     * Return chat info for an order (conversation_id and order_id). Handles order_id null (support chats).
     */
    private function getChatForOrder(
        int $orderId,
        ?int $clientId = null,
        ?int $vendorId = null,
        ?int $driverId = null,
        bool $ensure = false
    ): array {
        $chatService = app(ChatService::class);
        $conversation = null;
        if ($ensure) {
            if ($clientId === null) {
                return [
                    'conversation_id' => null,
                    'order_id' => $orderId,
                ];
            }
            $conversation = $chatService->ensureConversationForOrder($orderId, $clientId, $vendorId, $driverId);
        } else {
            $conversation = $chatService->getConversationForOrder($orderId, $clientId, $vendorId, $driverId);
        }

        return [
            'conversation_id' => $conversation?->id,
            'order_id' => $conversation?->order_id ?? $orderId,
        ];
    }

    /**
     * Scan QR code to confirm delivery
     */
    public function scanQRCode(Request $request, int $order_id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'qr_code' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();

        $order = Order::where('client_id', $user->id)
            ->where('id', $order_id)
            ->where('qr_code', $request->qr_code)
            ->first();

        if (! $order) {
            return errorResponse(__('order.invalid_qr_code'), null, 400);
        }

        if (! in_array($order->status, [OrderStatus::ON_WAY_TO_DELIVERY->value, OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value])) {
            return errorResponse(__('order.order_not_ready_for_delivery'), null, 400);
        }

        $statusService = app(\App\Services\OrderStatusService::class);

        // Home delivery closes straight to completed below — the delivered hop here
        // is just a transient stepping stone in that case, not a real notify-worthy
        // event on its own.
        $willAutoCompleteAfterDelivered = ! (bool) $order->delivery_at_vendor
            && ! in_array($order->status, [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value], true);

        try {
            // Update to delivered if not already delivered
            if (! in_array($order->status, [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value], true)) {
                $statusService->transitionTo($order, OrderStatus::DELIVERED, [
                    'notes' => 'Delivery confirmed by client with QR code scan',
                    'changed_by' => $user->id,
                    'actor_type' => 'client',
                    'actor_id' => $user->id,
                    'skip_notifications' => $willAutoCompleteAfterDelivered,
                ]);
            }

            // Home delivery: client receiving it is the final step — no separate
            // party needs to confirm afterward, so close the order out directly
            // instead of leaving it at delivered pending a later approval call.
            if (
                ! (bool) $order->delivery_at_vendor
                && $order->status !== OrderStatus::COMPLETED->value
                && $statusService->canTransition($order, OrderStatus::COMPLETED)
            ) {
                $statusService->transitionTo($order, OrderStatus::COMPLETED, [
                    'notes' => 'Delivery confirmed by client with QR code scan',
                    'changed_by' => $user->id,
                    'actor_type' => 'client',
                    'actor_id' => $user->id,
                ]);
            }
        } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
            return errorResponse($e->userMessage(), null, 400);
        }

        if (! $order->client_delivery_handoff_at) {
            $order->update(['client_delivery_handoff_at' => now()]);
        }

        // Keep COD unpaid until order is fully completed.
        $isCashOnDelivery = ($order->payment_method ?? '') === PaymentMethod::CASH_ON_DELIVERY->value;
        if (! $isCashOnDelivery) {
            $this->markOrderPaymentPaid($order);
        }

        return successResponse(array_merge([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->fresh()->status,
            'status_label' => OrderStatus::fromString($order->fresh()->status)?->localizedLabel($order->payment_method) ?? $order->fresh()->status,
            'delivered_at' => now()->toISOString(),
            'payment_method' => $order->payment_method,
            'payment_status' => $order->fresh()->payment_status ?? 'pending',
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->fresh()->payment_status ?? 'pending'),
        ], $order->fresh()->clientVisitResponseFields()), __('order.delivery_confirmed'));
    }

    /**
     * Create or update payment transaction to completed so payment_status is paid.
     */
    private function markOrderPaymentPaid(Order $order): void
    {
        $tx = $order->paymentTransactions()->latest()->first();
        if ($tx) {
            $tx->update([
                'status' => 'completed',
                'paid_at' => $tx->paid_at ?? now(),
            ]);
        } else {
            $order->paymentTransactions()->create([
                'gateway' => 'cash_on_delivery',
                'transaction_id' => 'COD-'.$order->id.'-'.time(),
                'amount' => $order->final_amount,
                'currency' => 'SAR',
                'status' => 'completed',
                'payment_method' => $order->payment_method ?? 'cash_on_delivery',
                'paid_at' => now(),
            ]);
        }
    }

    /**
     * Cancel order
     */
    public function cancelOrder(Request $request, int $order_id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();
        $accept = $request->header('Accept-Language', 'en');
        $lang = Str::contains(strtolower($accept), 'ar') ? 'ar' : 'en';

        // Set locale for translations
        $originalLocale = app()->getLocale();
        app()->setLocale($lang);

        $order = Order::where('client_id', $user->id)->find($order_id);

        if (! $order) {
            // Get translated message while locale is still set
            $message = __('order.order_not_found');
            app()->setLocale($originalLocale);

            return notFoundResponse($message);
        }

        $statusService = app(\App\Services\OrderStatusService::class);

        if (! $statusService->canTransition($order, OrderStatus::CANCELLED)) {
            $message = __('order.order_cannot_cancel_status');
            app()->setLocale($originalLocale);

            return ErrorResponse::make($message, null, 400);
        }

        $statusService->transitionTo($order, OrderStatus::CANCELLED, [
            'notes' => $request->reason,
            'reason' => $request->reason,
            'changed_by' => $user->id,
            'actor_type' => 'client',
            'actor_id' => $user->id,
        ]);

        app()->setLocale($originalLocale);

        return successResponse([
            'order_id' => $order->id,
            'status' => OrderStatus::CANCELLED->value,
            'cancelled_at' => $order->cancelled_at->toISOString(),
        ], __('order.order_cancelled_success'));
    }

    /**
     * Update order
     */
    public function updateOrder(Request $request, int $order_id): JsonResponse
    {
        $user = $request->user();
        $lang = app()->getLocale();

            $order = Order::with(['items', 'vendor', 'branch', 'pickupAddress', 'deliveryAddress', 'discount'])
            ->where('client_id', $user->id)
            ->find($order_id);

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        if (! \App\Enums\OrderStatus::isClientEditable($order->status)) {
            return errorResponse(__('order.order_can_only_update_pending'), 400);
        }

        $request->merge([
            'items' => OrderItemsNormalizer::normalize($request->input('items', [])),
        ]);

        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1'],
            'items.*.piece_id' => ['required', 'exists:pieces,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.service_id' => ['required', 'exists:services,id'],
            'items.*.additional_service_ids' => ['nullable', 'array'],
            'items.*.additional_service_ids.*' => ['integer', 'exists:service_additions,id'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.image' => ['nullable', 'sometimes'],
            'notes' => ['nullable', 'string', 'max:500'],
            'pickup_at_vendor' => ['nullable', 'boolean'],
            'delivery_at_vendor' => ['nullable', 'boolean'],
            'pickup_address_id' => ['nullable', 'exists:addresses,id'],
            'delivery_address_id' => ['nullable', 'exists:addresses,id'],
            // payment_methods: string ("visa") or array (["nazefah_wallet", "visa"])
            'payment_methods' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $methods = $this->orderPaymentService->normalizePaymentMethodsInput($value);
                    if ($methods === null) {
                        $fail(__('validation.required', ['attribute' => $attribute]));

                        return;
                    }
                    if (! $this->orderPaymentService->paymentMethodsAreAllowed($methods, $this->orderPaymentService->allowedSurchargeMethods())) {
                        $fail(__('validation.in', ['attribute' => $attribute]));
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        // Store old final amount for wallet calculation.
        // While the order sits in BRANCH_REVIEW, final_amount is a PROVISIONAL value the
        // vendor's review already wrote in (e.g. a rejected item's price was subtracted)
        // — nothing has actually been refunded/charged for that yet; the client is meant
        // to settle it via the dedicated "approve vendor review" flow first. If they edit
        // the order directly instead (allowed — BRANCH_REVIEW is client-editable), anchor
        // the delta on original_final_amount (what was actually paid) rather than the
        // provisional final_amount, so this edit reconciles the pending vendor change in
        // the same step instead of silently losing it (over/under-charging the client).
        $resolvesPendingVendorReview = $order->status === OrderStatus::BRANCH_REVIEW->value && $order->original_final_amount !== null;
        $oldFinalAmount = $resolvesPendingVendorReview
            ? (float) $order->original_final_amount
            : (float) $order->final_amount;

        // Up-front payment evidence: gateway/wallet transactions OR settled OrderPayment legs.
        // (Some wallet/COD paths mark OrderPayment paid without a PaymentTransaction row.)
        $paymentTransaction = PaymentTransaction::where('order_id', $order->id)
            ->whereIn('status', ['completed', 'authorized'])
            ->latest()
            ->first();
        $isPaid = $paymentTransaction !== null;
        $paidLegsTotal = $this->orderPaymentService->paidTotal($order);
        $hasSettledCoverage = $isPaid || $paidLegsTotal > 0.005;
        $isCashOnDelivery = $order->isCashOnDelivery();

        // Get current wallet balance (spendable after active holds)
        $walletBalance = app(OrderPaymentService::class)->availableWalletBalance((int) $user->id);

        DB::beginTransaction();
        try {
            // Lock the order row (and its primary payment transaction) so a concurrent
            // edit can't race the delta calculation against a stale total. No-op on
            // SQLite; real row-level serialization on MySQL/Postgres.
            Order::whereKey($order->id)->lockForUpdate()->first();
            if ($paymentTransaction) {
                PaymentTransaction::whereKey($paymentTransaction->id)->lockForUpdate()->first();
            }

            // Use request values if provided, otherwise keep existing order values
            $pickupAtVendor = $request->has('pickup_at_vendor')
                ? $request->boolean('pickup_at_vendor')
                : (bool) $order->pickup_at_vendor;
            $deliveryAtVendor = $request->has('delivery_at_vendor')
                ? $request->boolean('delivery_at_vendor')
                : (bool) $order->delivery_at_vendor;

            // Resolve pickup address
            $pickupAddress = null;
            if (! $pickupAtVendor) {
                if ($request->has('pickup_address_id') && $request->pickup_address_id) {
                    $pickupAddress = Address::where('id', $request->pickup_address_id)
                        ->where('client_id', $user->id)
                        ->first();
                    if (! $pickupAddress) {
                        return errorResponse(__('order.invalid_pickup_address'), 400);
                    }
                } elseif ($order->pickup_address_id) {
                    $pickupAddress = $order->pickupAddress;
                } else {
                    $pickupAddress = $user->defaultAddress();
                    if (! $pickupAddress) {
                        return errorResponse(__('order.no_pickup_address'), 400);
                    }
                }
            }

            // Resolve delivery address
            $deliveryAddress = null;
            if (! $deliveryAtVendor) {
                if ($request->has('delivery_address_id') && $request->delivery_address_id) {
                    $deliveryAddress = Address::where('id', $request->delivery_address_id)
                        ->where('client_id', $user->id)
                        ->first();
                    if (! $deliveryAddress) {
                        return errorResponse(__('order.invalid_delivery_address'), 400);
                    }
                } elseif ($order->delivery_address_id) {
                    $deliveryAddress = $order->deliveryAddress;
                } else {
                    $deliveryAddress = $user->defaultAddress();
                    if (! $deliveryAddress) {
                        return errorResponse(__('order.no_delivery_address'), 400);
                    }
                }
            }

            // Get branch for delivery calculations
            $branch = $order->branch;

            if (! $branch) {
                return errorResponse(__('order.branch_not_found'), 400);
            }

            $branch->loadMissing('vendor');
            $vendor = $branch->vendor;
            if (! $vendor) {
                DB::rollBack();

                return errorResponse(__('order.vendor_not_found'), 404);
            }

            $vendorId = (int) $vendor->id;
            $existingLineKeys = $order->items
                ->mapWithKeys(fn ($item) => [((int) $item->piece_id).':'.((int) $item->service_id) => true])
                ->all();

            $branchZoneId = $branch->zone_id;
            if ($branchZoneId !== null) {
                if ($pickupAddress && ! $pickupAtVendor) {
                    if ($pickupAddress->zone_id === null) {
                        return errorResponse(__('order.address_must_be_in_branch_zone'), 400);
                    }
                    if ((int) $pickupAddress->zone_id !== (int) $branchZoneId) {
                        return errorResponse(__('order.pickup_address_not_in_branch_zone'), 400);
                    }
                }
                if ($deliveryAddress && ! $deliveryAtVendor) {
                    if ($deliveryAddress->zone_id === null) {
                        return errorResponse(__('order.address_must_be_in_branch_zone'), 400);
                    }
                    if ((int) $deliveryAddress->zone_id !== (int) $branchZoneId) {
                        return errorResponse(__('order.delivery_address_not_in_branch_zone'), 400);
                    }
                }
            }

            // Handle items update: recalculate all amounts using branch-based pricing (same as create order)
            $itemsData = [];
            $totalAmount = 0;
            $discountAmount = 0;
            $deliveryDiscountAmount = 0;
            $appliedDiscount = null;
            $pieces = null;
            $storeBranchId = (int) $order->branch_id;
            $discountItemsBreakdown = [];

            if ($request->has('items') && is_array($request->items) && count($request->items) > 0) {
                if ($request->has('coupon_code') && $request->coupon_code) {
                    $result = $this->discountService->validateAndCalculateDiscount(
                        $request->coupon_code,
                        $request->items,
                        $user->id,
                        $vendorId,
                        $lang,
                        (int) $order->branch_id,
                        0.0,
                        null,
                        ! ($pickupAtVendor && $deliveryAtVendor)
                    );

                    if (! $result['success']) {
                        DB::rollBack();

                        return errorResponse($result['message'], $result['code'], $result['errors'] ?? null);
                    }

                    $totalAmount = $result['data']['order_amount'];
                    $discountAmount = $result['data']['discount_amount'];
                    $deliveryDiscountAmount = (float) ($result['data']['delivery_discount_amount'] ?? 0);
                    $appliedDiscount = $result['data']['discount'];
                    $pieces = $result['data']['pieces'];
                    $discountItemsBreakdown = $result['data']['items_breakdown'] ?? [];
                } else {
                    $pieceIds = collect($request->items)->pluck('piece_id')->unique()->map(fn ($id) => (int) $id);
                    $pieces = Piece::withTrashed()
                        ->with(['vendor', 'services', 'additionalServices' => function ($query) use ($order) {
                            $query->where(function ($q) use ($order) {
                                $q->where('service_addition_piece.branch_id', $order->branch_id)
                                    ->orWhereNull('service_addition_piece.branch_id');
                            });
                        }])
                        ->whereIn('id', $pieceIds)
                        ->get();

                    if ($pieces->count() !== $pieceIds->count()) {
                        DB::rollBack();

                        return errorResponse(__('order.items_not_available'), 400);
                    }

                    $vendorIds = $pieces->pluck('vendor_id')->unique();
                    if ($vendorIds->count() > 1 || (int) $vendorIds->first() !== $vendorId) {
                        DB::rollBack();

                        return errorResponse(__('order.items_vendor_not_match'), 400);
                    }
                }

                $branchPieceIds = $branch->activePieces()->pluck('pieces.id')->toArray();
                $branchServiceIds = $branch->activeServices()->pluck('services.id')->toArray();

                foreach ($request->items as $index => $item) {
                    $pieceId = (int) $item['piece_id'];
                    $mainServiceIds = OrderItemsNormalizer::mainServiceIds($item);
                    if ($mainServiceIds === []) {
                        DB::rollBack();

                        return errorResponse(trans('order.piece_not_found'), 400);
                    }

                    $piece = $pieces->firstWhere('id', $pieceId);
                    if (! $piece) {
                        DB::rollBack();

                        return errorResponse(trans('order.piece_not_found'), 400);
                    }

                    $servicesRows = [];
                    $servicesTotal = 0.0;

                    foreach ($mainServiceIds as $serviceId) {
                        $lineKey = $pieceId.':'.$serviceId;
                        $lineExistedOnOrder = isset($existingLineKeys[$lineKey]);

                        $availabilityError = $this->catalogAvailabilityService->validateOrderLineForOrderUpdate(
                            $storeBranchId,
                            $pieceId,
                            $serviceId,
                            $item['additional_service_ids'] ?? [],
                            $lang,
                            $lineExistedOnOrder
                        );
                        if ($availabilityError !== null) {
                            DB::rollBack();

                            return errorResponse($availabilityError, 400);
                        }

                        if (! $lineExistedOnOrder && ! in_array($pieceId, $branchPieceIds, true)) {
                            $pieceName = \App\Support\OrderItemDisplayNames::pieceName($piece, $storeBranchId, $lang);
                            DB::rollBack();

                            return errorResponse(trans('order.piece_not_available_at_branch', ['piece_name' => $pieceName]), 400);
                        }

                        $service = $piece->services->firstWhere('id', $serviceId);
                        if (! $service) {
                            $pieceName = \App\Support\OrderItemDisplayNames::pieceName($piece, $storeBranchId, $lang);
                            DB::rollBack();

                            return errorResponse(__('order.service_not_available', ['piece_name' => $pieceName]), 400);
                        }
                        if (! $lineExistedOnOrder && ! in_array($serviceId, $branchServiceIds, true)) {
                            $serviceName = \App\Support\OrderItemDisplayNames::serviceName($service, $storeBranchId, $lang);
                            DB::rollBack();

                            return errorResponse(trans('order.service_not_available_at_branch', ['service_name' => $serviceName]), 400);
                        }

                        $servicePrice = (float) $service->getPriceForPieceAtBranch($piece->id, $storeBranchId);
                        $servicesTotal += $servicePrice;
                        $servicesRows[] = [
                            'service_id' => $serviceId,
                            'service_piece_price' => $servicePrice,
                            'price' => $servicePrice,
                        ];
                    }

                    $piecePrice = 0.0;
                    $additionalServicesTotal = 0;
                    $additionalServicesData = [];

                    if (! empty($item['additional_service_ids'])) {
                        $uniqueAdditionalServiceIds = array_unique($item['additional_service_ids']);
                        $availableAdditions = $piece->getAdditionalServicesForBranch($storeBranchId);
                        foreach ($uniqueAdditionalServiceIds as $additionalServiceId) {
                            $additionModel = $availableAdditions->firstWhere('id', $additionalServiceId);
                            if (! $additionModel) {
                                $additionForError = \Modules\Service\Models\ServiceAddition::find($additionalServiceId);
                                $additionName = $additionForError
                                    ? \App\Support\OrderItemDisplayNames::additionalServiceName($additionForError, $storeBranchId, $lang)
                                    : "ID: {$additionalServiceId}";
                                DB::rollBack();

                                return errorResponse(__('order.additional_service_not_available_at_branch', ['service_name' => $additionName]), 400);
                            }
                            $additionalPrice = $additionModel->getPriceForPieceAtBranch($piece->id, $storeBranchId);
                            $additionalServicesTotal += $additionalPrice;
                            $additionalServicesData[] = \App\Support\OrderItemDisplayNames::additionalServiceLine(
                                $additionModel,
                                $storeBranchId,
                                $lang,
                                (float) $additionalPrice
                            );
                        }
                    }

                    $unitPrice = $piecePrice + $servicesTotal + $additionalServicesTotal;
                    $itemTotal = $unitPrice * $item['quantity'];

                    if (! $appliedDiscount) {
                        $totalAmount += $itemTotal;
                    }

                    $imageFile = $item['image'] ?? null;
                    if (! ($imageFile instanceof \Illuminate\Http\UploadedFile)) {
                        $imageFile = $request->file("items.{$index}.image");
                    }

                    $uploadedImage = null;
                    if ($imageFile instanceof \Illuminate\Http\UploadedFile) {
                        $uploadedImage = $this->uploadFilesService->uploadImage($imageFile, 'orders/items');
                    }

                    $existingItem = $order->items->first(function ($i) use ($pieceId, $servicesRows) {
                        return (int) $i->piece_id === $pieceId && (int) $i->service_id === (int) $servicesRows[0]['service_id'];
                    });

                    $itemNote = $item['note'] ?? $item['notes'] ?? $existingItem?->notes;
                    $itemImage = $uploadedImage ?? $item['images'] ?? $existingItem?->images;

                    $itemsData[] = [
                        'piece_id' => $item['piece_id'],
                        'piece_price' => $piecePrice,
                        'service_id' => $servicesRows[0]['service_id'],
                        'service_price' => $servicesRows[0]['service_piece_price'],
                        'services' => $servicesRows,
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPrice,
                        'total_price' => $itemTotal,
                        'additional_services' => $additionalServicesData,
                        'additional_services_total' => (float) $additionalServicesTotal,
                        'note' => $itemNote,
                        'notes' => $itemNote,
                        'images' => $itemImage,
                    ];
                }

                // Keep order coupon only when rules still pass on the new item totals.
                if (! $appliedDiscount && ! ($request->filled('coupon_code'))) {
                    if ($order->discount) {
                        $soft = $this->discountService->applyIfEligible(
                            $order->discount,
                            (float) $totalAmount,
                            (int) $user->id,
                            $vendorId,
                            true
                        );
                        if ($soft['applied']) {
                            $appliedDiscount = $soft['discount'];
                            $discountAmount = (float) $soft['discount_amount'];
                        }
                    } elseif ((float) $order->discount_amount > 0) {
                        $discountAmount = min((float) $order->discount_amount, (float) $totalAmount);
                    }
                }
            }

            // Calculate delivery fee using branch coordinates:
            // - pickup_at_vendor=false & delivery_at_vendor=false → charge pickup + delivery fees
            // - pickup_at_vendor=false & delivery_at_vendor=true  → charge pickup fee only
            // - pickup_at_vendor=true  & delivery_at_vendor=false → charge delivery fee only
            // - pickup_at_vendor=true  & delivery_at_vendor=true  → no delivery charge
            $deliveryFee = 0;
            $totalDistance = 0;
            $pickupFee = 0;
            $deliveryFeeAmount = 0;

            if (! $pickupAtVendor || ! $deliveryAtVendor) {
                if (! $branch->latitude || ! $branch->longitude) {
                    return errorResponse(__('order.vendor_location_not_available'), 400);
                }

                $deliveryPricePerKm = $vendor->delivery_price_per_km
                    ?? AdminSetting::getValue('delivery_price_per_km', 5);

                // Calculate pickup fee (customer address → branch) if pickup is via delivery
                if (! $pickupAtVendor && $pickupAddress) {
                    if (! $pickupAddress->latitude || ! $pickupAddress->longitude) {
                        return errorResponse(__('order.pickup_address_location_not_available'), 400);
                    }
                    $pickupDistance = $this->calculateDistance(
                        (float) $pickupAddress->latitude,
                        (float) $pickupAddress->longitude,
                        (float) $branch->latitude,
                        (float) $branch->longitude
                    );
                    $totalDistance += $pickupDistance;
                    $pickupFee = $pickupDistance * $deliveryPricePerKm;
                }

                // Calculate delivery fee (branch → customer address) if delivery is via delivery
                if (! $deliveryAtVendor && $deliveryAddress) {
                    if (! $deliveryAddress->latitude || ! $deliveryAddress->longitude) {
                        return errorResponse(__('order.delivery_address_location_not_available'), 400);
                    }
                    $deliveryDistance = $this->calculateDistance(
                        (float) $branch->latitude,
                        (float) $branch->longitude,
                        (float) $deliveryAddress->latitude,
                        (float) $deliveryAddress->longitude
                    );
                    $totalDistance += $deliveryDistance;
                    $deliveryFeeAmount = $deliveryDistance * $deliveryPricePerKm;
                }

                // Sum applicable fees
                $deliveryFee = $pickupFee + $deliveryFeeAmount;
            }

            $discountCity = $deliveryAddress?->city ?? $pickupAddress?->city;
            if ($appliedDiscount && $discountItemsBreakdown !== []) {
                $rechecked = $this->discountService->evaluateKnownOrderDiscount(
                    $appliedDiscount,
                    $discountItemsBreakdown,
                    (float) $totalAmount,
                    (int) $user->id,
                    (int) $vendorId,
                    $storeBranchId,
                    (float) $deliveryFee,
                    $discountCity,
                    false,
                    $lang,
                    ! ($pickupAtVendor && $deliveryAtVendor)
                );
                $discountAmount = (float) $rechecked['discount_amount'];
                $deliveryDiscountAmount = (float) ($rechecked['delivery_discount_amount'] ?? 0);
            } elseif (! $request->filled('coupon_code')) {
                $automatic = $this->discountService->findBestAutomaticOrderDiscount(
                    $request->items ?? [],
                    (int) $user->id,
                    (int) $vendorId,
                    $lang,
                    $storeBranchId,
                    (float) $deliveryFee,
                    $discountCity
                );
                if ($automatic['applied']) {
                    $appliedDiscount = $automatic['discount'];
                    $discountAmount = (float) $automatic['discount_amount'];
                    $deliveryDiscountAmount = (float) ($automatic['delivery_discount_amount'] ?? 0);
                }
            }

            $pricingTotals = Order::calculatePricingTotals($totalAmount, $discountAmount, $deliveryFee, $deliveryDiscountAmount);
            $taxAmount = $pricingTotals['tax_amount'];
            $finalAmount = $pricingTotals['final_amount'];

            // Delta between old and new final amount, computed with bc math — never
            // float arithmetic on money. $deltaCmp: -1 decrease, 0 unchanged, 1 increase.
            $oldFinal = number_format($oldFinalAmount, 2, '.', '');
            $newFinal = number_format($finalAmount, 2, '.', '');
            $delta = bcsub($newFinal, $oldFinal, 2);
            $deltaCmp = bccomp($delta, '0', 2);

            // A gateway increase is paid IN THIS request via surcharge_payment: wallet
            // legs settle now, gateway legs return payment link(s). The edit is STAGED
            // and applied only after the surcharge is fully paid (webhook or wallet-only).
            $gatewayPayments = [];
            $deferApplication = false;
            $modificationIntent = null;

            // Handle price differences:
            // - Non-COD INCREASES always require settling the delta first (stage items;
            //   apply only after wallet/gateway surcharge is paid). Never silently commit.
            // - COD INCREASES extend the COD leg (cash collected at delivery).
            // - DECREASES refund when there is settled coverage.
            if ($deltaCmp !== 0) {
                if ($isPaid && $paymentTransaction) {
                    // Audit every payment-relevant edit (amount, fort_id, status).
                    \Illuminate\Support\Facades\Log::info('order.update.payment_delta', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'old_final' => $oldFinal,
                        'new_final' => $newFinal,
                        'delta' => $delta,
                        'primary_status' => $paymentTransaction->status,
                        'primary_fort_id' => $paymentTransaction->fort_id,
                        'payment_method' => $paymentTransaction->payment_method,
                    ]);
                }

                if ($deltaCmp < 0) {
                    if ($hasSettledCoverage) {
                        // DECREASE — refund each paid leg via its original payment method.
                        $refundAmount = bcsub($oldFinal, $newFinal, 2);

                        $this->orderPaymentService->refundDecrease(
                            $order,
                            (float) $refundAmount,
                            'Refund for order update'
                        );
                        // Authorized gateway holds: HandlePaymentCapture captures the lower
                        // effective amount at confirmation and voids the surplus.
                    }
                } elseif ($deltaCmp > 0) {
                    // INCREASE — customer may settle the delta with any surcharge method
                    // (visa↔cash, wallet, etc.). COD with no methods keeps collect-at-delivery.
                    $existingMethod = $paymentTransaction?->payment_method
                        ?? $order->payment_method
                        ?? PaymentMethod::Nathefah_WALLET->value;
                    $updatePayment = $this->resolveUpdatePaymentInput($request);

                    if ($isCashOnDelivery && $updatePayment === null) {
                        $this->orderPaymentService->recordCodLeg($order, (float) $delta, [
                            'is_surcharge' => true,
                            'meta' => ['reason' => 'order_update'],
                        ]);
                    } elseif ($updatePayment !== null) {
                        $alloc = $this->orderPaymentService->allocateSurcharge(
                            $updatePayment['payment_methods'],
                            (float) $delta,
                            $walletBalance
                        );

                        if ($alloc['error']) {
                            DB::rollBack();
                            if ($alloc['error'] === 'order.insufficient_wallet_balance_short') {
                                return jsonResponse(false, 402, __($alloc['error']), [
                                    'payment_required' => true,
                                    'amount_due' => (float) $delta,
                                    'available_methods' => $this->orderPaymentService->allowedSurchargeMethods(),
                                ]);
                            }

                            return errorResponse(__($alloc['error'], $alloc['params'] ?? []), 422);
                        }
                        $legs = $alloc['legs'];

                        $stagedPricing = [
                            'status' => $this->postEditStatus($order)->value,
                            'total_amount' => $totalAmount,
                            'discount_amount' => $discountAmount,
                            'tax_amount' => $taxAmount,
                            'delivery_fee' => (float) $pricingTotals['delivery_fee'],
                            'final_amount' => $finalAmount,
                            'distance' => $totalDistance,
                            'pickup_at_vendor' => $pickupAtVendor,
                            'delivery_at_vendor' => $deliveryAtVendor,
                            'pickup_address_id' => ! $pickupAtVendor && $pickupAddress ? $pickupAddress->id : null,
                            'delivery_address_id' => ! $deliveryAtVendor && $deliveryAddress ? $deliveryAddress->id : null,
                            'notes' => $request->has('notes') ? $request->notes : $order->notes,
                            'items' => $itemsData,
                            'coupon_discount_id' => $appliedDiscount?->id,
                        ];

                        $modificationIntent = $this->orderPaymentService->createModificationIntent(
                            $order,
                            $delta,
                            $totalAmount,
                            $finalAmount,
                            $stagedPricing
                        );

                        try {
                            $settle = $this->orderPaymentService->settleSplitLegs($order, $legs, $user, [
                                'is_surcharge' => true,
                                'original_method' => $existingMethod,
                                'modification_intent_id' => $modificationIntent->id,
                                'meta' => ['reason' => 'order_update'],
                            ]);
                        } catch (InsufficientWalletBalanceException $e) {
                            DB::rollBack();

                            return jsonResponse(false, 402, __('order.insufficient_wallet_balance_short'), [
                                'payment_required' => true,
                                'amount_due' => $e->amountDue,
                                'wallet_balance' => $e->available,
                                'available_methods' => $this->orderPaymentService->allowedSurchargeMethods(),
                            ]);
                        } catch (\RuntimeException $e) {
                            DB::rollBack();

                            return errorResponse(__('order.payment_init_failed'), 400);
                        }

                        $gatewayPayments = $settle['gateway_payments'];
                        $order->mergePaymentMethods($updatePayment['payment_methods']);
                        $deferApplication = ! empty($gatewayPayments);

                        if (! $deferApplication) {
                            $paidLegTx = \Modules\Order\Models\OrderPayment::where('modification_intent_id', $modificationIntent->id)
                                ->where('status', \Modules\Order\Models\OrderPayment::STATUS_PAID)
                                ->whereNotNull('payment_transaction_id')
                                ->first();

                            $surchargeTx = $paidLegTx
                                ? PaymentTransaction::find($paidLegTx->payment_transaction_id)
                                : null;
                            $this->orderPaymentService->applyModificationIntentIfFullyPaid(
                                $modificationIntent->fresh(),
                                $surchargeTx
                            );
                        }
                    } else {
                        // Non-COD increase without payment_methods — never apply the edit.
                        DB::rollBack();

                        return jsonResponse(false, 422, __('order.update_requires_surcharge'), [
                            'amount_due' => (float) $delta,
                            'available_methods' => $this->orderPaymentService->allowedSurchargeMethods(),
                        ]);
                    }
                }
            }

            $updateData = [
                'status' => $this->postEditStatus($order)->value,
                'pickup_at_vendor' => $pickupAtVendor,
                'delivery_at_vendor' => $deliveryAtVendor,
                'pickup_address_id' => ! $pickupAtVendor && $pickupAddress ? $pickupAddress->id : null,
                'delivery_address_id' => ! $deliveryAtVendor && $deliveryAddress ? $deliveryAddress->id : null,
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'discount_id' => $appliedDiscount?->id,
                'tax_amount' => $taxAmount,
                            'delivery_fee' => (float) $pricingTotals['delivery_fee'],
                'final_amount' => $finalAmount,
                'distance' => $totalDistance,
            ];

            if ($request->has('notes')) {
                $updateData['notes'] = $request->notes;
            }

            // This edit's new item list supersedes whatever the vendor's still-pending
            // review had proposed — clear the pending-review markers so a later edit
            // doesn't re-anchor on the now-stale original_final_amount snapshot.
            if ($resolvesPendingVendorReview) {
                $updateData['vendor_reviewed'] = false;
                $updateData['client_approved'] = false;
                $updateData['original_final_amount'] = null;
            }

            // A wallet-only surcharge settles in-request: applyModificationIntent has
            // already committed the staged items + pricing (and discount usage). The
            // else branch below must NOT run for it, or replaceOrderItems fires twice
            // and duplicates the order items and their service additions.
            $intentAppliedImmediately = $modificationIntent !== null
                && ! $deferApplication
                && $modificationIntent->fresh()->status === \Modules\Order\Models\OrderModificationIntent::STATUS_RESOLVED;

            if ($deferApplication) {
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'status' => $order->status,
                    'notes' => 'Order update staged (awaiting surcharge payment)',
                    'changed_by' => $user->id,
                ]);
            } elseif ($intentAppliedImmediately) {
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'status' => $order->status,
                    'notes' => 'Order updated',
                    'changed_by' => $user->id,
                ]);
            } else {
                if (! empty($itemsData)) {
                    $this->orderPaymentService->replaceOrderItems($order, $itemsData);
                }

                if ($appliedDiscount && $request->has('coupon_code')) {
                    $this->discountService->incrementUsage($appliedDiscount);
                }

                $order->update($updateData);

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'status' => $order->status,
                    'notes' => 'Order updated',
                    'changed_by' => $user->id,
                ]);
            }

            DB::commit();

            if ($modificationIntent !== null || ! empty($gatewayPayments)) {
                $payment = $this->orderPaymentService->buildSurchargePaymentResponse(
                    $order,
                    $modificationIntent?->id,
                    $gatewayPayments,
                    $modificationIntent ? (float) $modificationIntent->delta_amount : null,
                    $deferApplication
                );
            } else {
                $payment = null;
            }

            $order->refresh();
            $order->load([
                'items.piece',
                'items.piece.iconRelation',
                'items.service',
                'items.additionalServicesPivot.serviceAddition',
                'pickupAddress',
                'deliveryAddress',
                'vendor',
                'branch.vendor',
            ]);

            // Full order response (same shape as create order)
            $branchData = $order->branch
                ? $order->branch->toApiOrderBranch($lang, [
                    'delivery_price_per_km' => (float) (
                        $order->branch->vendor?->delivery_price_per_km
                        ?? $order->vendor?->delivery_price_per_km
                        ?? 0
                    ),
                ])
                : null;

            $branchId = (int) ($order->branch_id ?? 0);
            $itemsResponse = OrderItemGrouper::toApiLines(
                $order->items,
                $branchId,
                $lang,
                fn ($item) => $item->images ? $this->uploadFilesService->getFullUrl($item->images) : null
            );

            return successResponse([
                'order' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'status_label' => OrderStatus::fromString($order->status)?->localizedLabel($order->payment_method) ?? $order->status,
                    'total_amount' => (float) $order->total_amount,
                    'discount_amount' => (float) $order->discount_amount,
                    ...$order->couponResponseFields(app()->getLocale()),
                    'tax_amount' => (float) $order->tax_amount,
                    'delivery_fee' => (float) $order->delivery_fee,
                    'final_amount' => (float) $order->final_amount,
                    ...$order->paymentFieldsForApi(),
                    'payment_status' => $order->payment_status ?? 'pending',
                    'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->payment_status ?? 'pending'),
                    'qr_code' => $order->qr_code,
                    'distance' => $order->distance !== null ? (float) $order->distance : 0,
                    'total_distance_km' => $order->distance !== null ? (float) $order->distance : 0,
                    'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
                    'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
                    'driver_id' => $order->driver_id,
                    'pickup_driver_id' => $order->pickup_driver_id,
                    'delivery_driver_id' => $order->delivery_driver_id,
                    'pickup_time' => $order->pickup_time,
                    'estimated_delivery_time' => $order->estimated_delivery_time,
                    'pickup_address' => ($order->pickup_at_vendor || ! $order->pickup_address_id) ? null : ($order->pickupAddress ? [
                        'id' => $order->pickupAddress->id,
                        'address' => $order->pickupAddress->address_text ?? $order->pickupAddress->street_name,
                        'street_name' => $order->pickupAddress->street_name,
                        'building_number' => $order->pickupAddress->building_number,
                        ...$order->pickupAddress->getApiFloorAttributes(),
                        'apartment' => $order->pickupAddress->apartment,
                        'city' => $order->pickupAddress->city,
                        'district' => $order->pickupAddress->district,
                        'latitude' => $order->pickupAddress->latitude ? (float) $order->pickupAddress->latitude : null,
                        'longitude' => $order->pickupAddress->longitude ? (float) $order->pickupAddress->longitude : null,
                    ] : null),
                    'delivery_address' => ($order->delivery_at_vendor || ! $order->delivery_address_id) ? null : ($order->deliveryAddress ? [
                        'id' => $order->deliveryAddress->id,
                        'address' => $order->deliveryAddress->address_text ?? $order->deliveryAddress->street_name,
                        'street_name' => $order->deliveryAddress->street_name,
                        'building_number' => $order->deliveryAddress->building_number,
                        ...$order->deliveryAddress->getApiFloorAttributes(),
                        'apartment' => $order->deliveryAddress->apartment,
                        'city' => $order->deliveryAddress->city,
                        'district' => $order->deliveryAddress->district,
                        'latitude' => $order->deliveryAddress->latitude ? (float) $order->deliveryAddress->latitude : null,
                        'longitude' => $order->deliveryAddress->longitude ? (float) $order->deliveryAddress->longitude : null,
                    ] : null),
                    'branch' => $branchData,
                    'vendor' => $order->vendor ? [
                        'id' => $order->vendor->id,
                        'name' => $order->vendor->getTranslatedName($lang),
                        'logo' => $this->uploadFilesService->getFullUrl($order->vendor->logo),
                    ] : null,
                    'items' => $itemsResponse,
                    'notes' => $order->notes,
                    'attachments' => $order->attachments ? collect($order->attachments)->map(function ($attachment) {
                        return [
                            'url' => $this->uploadFilesService->getFullUrl($attachment['url'] ?? ''),
                            'type' => $attachment['type'] ?? 'file',
                            'name' => $attachment['name'] ?? '',
                        ];
                    })->toArray() : [],
                    ...$order->clientVisitResponseFields(),
                ],
                // Non-null only when a gateway increase was paid in this request:
                // contains the payment link(s)/params for the difference (gateway legs).
                'payment' => $payment,
            ], __('order.order_updated_successfully'));

        } catch (\Exception $e) {
            DB::rollBack();

            return serverErrorResponse(__('order.failed_to_update_order').': '.$e->getMessage());
        }
    }

    /**
     * Status to apply after a client edit: always back to pending for a full
     * vendor re-review. Any driver already assigned (driver_id/pickup_driver_id/
     * delivery_driver_id) is left untouched — Order::scopeVendorCurrent() keeps
     * such pending-but-assigned orders visible in the vendor's "current" list
     * even though pending is otherwise excluded from vendorCurrentStatusValues().
     */
    private function postEditStatus(Order $order): OrderStatus
    {
        return OrderStatus::PENDING;
    }

    /**
     * When delivery is free (waived by a discount), also surface what it would have
     * cost so the client UI can show that amount struck through. The order only
     * persists the net (post-discount) delivery_fee, so the pre-discount amount is
     * recomputed the same way checkout computed it (distance × per-km rate) — this
     * only differs from what was actually charged if the vendor's per-km rate has
     * since changed.
     *
     * @return array{is_free_delivery?: true, original_delivery_fee?: float}
     */
    private function freeDeliveryFields(Order $order): array
    {
        if ((float) $order->delivery_fee !== 0.0) {
            return [];
        }

        $fields = ['is_free_delivery' => true];

        $distance = (float) ($order->distance ?? 0);
        if ($distance <= 0) {
            return $fields;
        }

        $deliveryPricePerKm = (float) (
            $order->branch?->vendor?->delivery_price_per_km
            ?? $order->vendor?->delivery_price_per_km
            ?? AdminSetting::getValue('delivery_price_per_km', 5)
        );

        $originalDeliveryFee = round($distance * $deliveryPricePerKm, 2);
        if ($originalDeliveryFee > 0) {
            $fields['original_delivery_fee'] = $originalDeliveryFee;
        }

        return $fields;
    }

    /**
     * @deprecated Use OrderItemsNormalizer::normalize() — kept for BC if referenced elsewhere.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeUpdateOrderItems(array $items): array
    {
        return OrderItemsNormalizer::normalize($items);
    }

    /**
     * Resolve payment_methods for a price increase (string or array).
     *
     * @return array{payment_methods: list<string>}|null
     */
    private function resolveUpdatePaymentInput(Request $request): ?array
    {
        $methods = $this->orderPaymentService->normalizePaymentMethodsInput($request->input('payment_methods'));

        return $methods !== null ? ['payment_methods' => $methods] : null;
    }

    /**
     * Get delivery route
     */
    public function getDeliveryRoute(Request $request, int $order_id): JsonResponse
    {
        $user = $request->user();

        $order = Order::with(['vendor', 'branch', 'deliveryAddress'])
            ->where('client_id', $user->id)
            ->find($order_id);

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        // Check if delivery address exists
        if (! $order->deliveryAddress) {
            return errorResponse(__('order.no_delivery_address'), 400);
        }

        // Get driver location (placeholder - should come from driver tracking)
        // Use branch coordinates since vendors no longer have location fields
        $driverLocation = [
            'latitude' => $order->branch ? (float) $order->branch->latitude : 0,
            'longitude' => $order->branch ? (float) $order->branch->longitude : 0,
        ];

        return successResponse([
            'route' => [
                'order_id' => $order->id,
                'driver_location' => $driverLocation,
                'destination' => [
                    'latitude' => (float) ($order->deliveryAddress->latitude ?? 0),
                    'longitude' => (float) ($order->deliveryAddress->longitude ?? 0),
                ],
                'laundry_location' => [
                    'latitude' => $order->branch ? (float) $order->branch->latitude : 0,
                    'longitude' => $order->branch ? (float) $order->branch->longitude : 0,
                ],
            ],
        ], __('order.delivery_route_retrieved'));
    }

    /**
     * Get estimated time
     */
    public function getEstimatedTime(Request $request, int $order_id): JsonResponse
    {
        $user = $request->user();

        $order = Order::where('client_id', $user->id)->find($order_id);

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        // Calculate estimated time based on status
        $estimatedMinutes = match ($order->status) {
            OrderStatus::PENDING->value => 120,
            OrderStatus::BRANCH_REVIEW->value => 120,
            OrderStatus::CONFIRMED->value => 90,
            OrderStatus::PICKED_UP->value => 180,
            OrderStatus::DELIVERED_TO_BRANCH->value => 60,
            OrderStatus::ON_WAY_TO_DELIVERY->value => 30,
            OrderStatus::WAITING_CLIENT_RECEIPT->value => 15,
            default => 0,
        };

        return successResponse([
            'estimated_time' => [
                'order_id' => $order->id,
                'current_status' => $order->status,
                'estimated_minutes' => $estimatedMinutes,
                'estimated_delivery' => now()->addMinutes($estimatedMinutes)->toISOString(),
            ],
        ], __('order.estimated_time_retrieved'));
    }

    /**
     * Confirm delivery (by QR code scan)
     * Client can accept or reject the receipt
     */
    public function confirmDelivery(Request $request, int $order_id): JsonResponse
    {

        $user = $request->user();

        $order = Order::where('client_id', $user->id)
            ->where('id', $order_id)
            ->first();

        if (! $order) {
            return errorResponse(__('order.order_not_found'), 400);
        }

        $handoffService = app(\App\Services\ClientOrderHandoffService::class);

        if (! $handoffService->canConfirmHandoff($order)) {
            return errorResponse(__('order.handoff_not_available'), 400);
        }

        try {
            $order = $handoffService->confirmHandoff($order, (int) $user->id);
        } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
            return errorResponse($e->userMessage(), 400);
        } catch (\LogicException) {
            return errorResponse(__('order.handoff_not_available'), 400);
        }

        $order = $order->fresh();

        return successResponse(array_merge([
            'order_id' => $order->id,
            'status' => $order->status,
            'status_text' => OrderStatus::fromString($order->status)?->localizedLabel($order->payment_method) ?? $order->status,
            'delivered_at' => $order->actual_delivery_time?->toISOString() ?? now()->toISOString(),
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status ?? 'pending',
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->payment_status ?? 'pending'),
            'requires_handoff_confirmation' => $handoffService->canConfirmHandoff($order),
        ], $order->clientVisitResponseFields()), __('order.handoff_success_receive_from_driver'));
    }

    /**
     * Get status log
     */
    public function getStatusLog(Request $request, int $order_id): JsonResponse
    {
        $user = $request->user();
        $accept = $request->header('Accept-Language', 'en');
        $lang = Str::contains(strtolower($accept), 'ar') ? 'ar' : 'en';

        $originalLocale = app()->getLocale();
        app()->setLocale($lang);

        $order = Order::with(['client', 'statusLogs'])->where('client_id', $user->id)->find($order_id);

        if (! $order) {
            $message = trans('order.order_not_found', [], $lang);
            app()->setLocale($originalLocale);

            return notFoundResponse($message);
        }

        $levels = $order->statusLogs
            ->sortBy('created_at')
            ->values()
            ->map(fn ($log) => OrderStatusLogPresenter::forVendor($log, $order))
            ->values()
            ->all();

        $message = __('order.status_log_retrieved');
        app()->setLocale($originalLocale);

        return successResponse(array_merge([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'levels' => $levels,
        ], $order->clientVisitResponseFields()), $message);
    }

    /**
     * Get progress percentage based on order status
     */
    private function getProgressPercentage(string $status): int
    {
        return match ($status) {
            OrderStatus::PENDING->value => 0,
            OrderStatus::BRANCH_REVIEW->value => 0,
            OrderStatus::CONFIRMED->value => 25,
            OrderStatus::PICKED_UP->value => 50,
            OrderStatus::DELIVERED_TO_BRANCH->value => 75,
            OrderStatus::ON_WAY_TO_DELIVERY->value => 90,
            OrderStatus::WAITING_CLIENT_RECEIPT->value => 95,
            OrderStatus::DELIVERED->value => 100,
            OrderStatus::COMPLETED->value => 100,
            OrderStatus::CANCELLED->value => 0,
            default => 0,
        };
    }
}
