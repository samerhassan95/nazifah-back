<?php

namespace Modules\Admin\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\UploadFilesService;
use App\Services\OrderStatusService;
use App\Services\OrderPieceHandoffService;
use App\Support\OrderStatusLogPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Models\Icon;
use Modules\Admin\Http\Resources\OrderResource;
use Modules\Admin\Services\OrderService;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;

class AdminOrderController extends Controller
{
    public function __construct(
        private OrderService $orderService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->status,
            'payment_status' => $request->payment_status,
            'client_id' => $request->client_id,
            'vendor_id' => $request->vendor_id,
            'driver_id' => $request->driver_id,
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
            'search' => $request->search,
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $orders = $this->orderService->getAllPaginated(
            $filters,
            $request->input('per_page', 15)
        );

        return successResponse(
            OrderResource::collection($orders),
            __('admin.orders_retrieved')
        );
    }

    public function show(int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make(__('admin.order_not_found'), null, 404);
        }

        return successResponse(new OrderResource($order), __('admin.order_retrieved'));
    }

    public function invoice(int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make(__('admin.order_not_found'), null, 404);
        }

        try {
            $invoiceService = app(\Modules\Invoice\Services\InvoiceService::class);
            $invoice = $invoiceService->createOrFetchForOrder($order);
            $shareUrl = $invoiceService->shareUrl($invoice);
            $pdfUrl = $invoiceService->pdfUrl($invoice);

            return successResponse([
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'share_url'      => $shareUrl,
                'pdf_url'        => $pdfUrl,
                'issued_at'      => optional($invoice->issued_at)->toIso8601String(),
                'status'         => $invoice->status,
                'zatca_status'   => $invoice->zatca_status,
                'total_amount'   => (float) $invoice->total_amount,
            ], __('admin.order_invoice_retrieved'));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Invoice creation failed', [
                'order_id' => $id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return ErrorResponse::make(__('admin.order_invoice_creation_failed', ['error' => $e->getMessage()]), null, 500);
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make(__('admin.order_not_found'), null, 404);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:pending,confirmed,preparing,ready,picked_up,delivered,cancelled',
            'notes' => 'nullable|string',
        ]);

        $order->status = $validated['status'];
        $order->save();

        // Log status change
        $order->statusLogs()->create([
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return successResponse(
            $order->fresh()->load(['statusLogs']),
            __('admin.order_status_updated')
        );
    }

    public function assignDriver(Request $request, int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make(__('admin.order_not_found'), null, 404);
        }

        if ($order->status === OrderStatus::CANCELLED->value) {
            return ErrorResponse::make(__('admin.order_cannot_assign_driver_cancelled'), null, 400);
        }

        $validated = $request->validate([
            'driver_id' => 'required|exists:drivers,id',
        ]);

        $driver = Driver::find($validated['driver_id']);

        if (! $driver || ! $driver->is_available) {
            return ErrorResponse::make(__('admin.order_driver_not_available'), null, 400);
        }

        // Route through the state machine (same as the vendor-side assignment endpoint)
        // so the order status actually advances past 'pending', pickup_driver_id /
        // delivery_driver_id get populated, and the DriverAssigned event fires. The old
        // raw `$order->driver_id = ...; $order->save();` left status untouched, which
        // silently dropped the order out of the vendor's "current" list and the driver's
        // own assignment list whenever it was assigned before being confirmed.
        $currentStatus = OrderStatus::from($order->status);
        $onDeliveryLeg = in_array($currentStatus, OrderStatus::vendorDeliveryDriverAssignableStatuses(), true)
            || in_array($currentStatus, [OrderStatus::PICKED_UP, OrderStatus::DELIVERED], true);

        $statusService = app(OrderStatusService::class);
        $changedBy = optional(auth()->user())->id;

        try {
            if ($onDeliveryLeg && $order->needsDeliveryDriver()) {
                $statusService->assignDeliveryDriver($order, $driver, $changedBy, 'admin');
            } elseif ($order->needsPickupDriver()) {
                $statusService->assignPickupDriver($order, $driver, $changedBy, 'admin');
            } elseif ($order->needsDeliveryDriver()) {
                $statusService->assignDeliveryDriver($order, $driver, $changedBy, 'admin');
            } else {
                return ErrorResponse::make(__('admin.order_no_driver_needed'), null, 400);
            }
        } catch (\Throwable $e) {
            return ErrorResponse::make($e->getMessage() ?: __('admin.order_driver_assignment_failed'), null, 400);
        }

        return successResponse(
            $order->fresh()->load(['driver', 'pickupDriver', 'deliveryDriver']),
            __('admin.order_driver_assigned')
        );
    }

    public function updatePaymentStatus(Request $request, int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make(__('admin.order_not_found'), null, 404);
        }

        // Check if payment_status is actually a payment method
        $paymentStatusInput = $request->input('payment_status');
        $paymentMethodInput = $request->input('payment_method');

        $isPaymentMethod = $paymentStatusInput && in_array($paymentStatusInput, PaymentMethod::values());

        // Allow payment_status to accept both payment status values and payment method values
        $paymentStatusValues = 'pending,authorized,completed,failed,cancelled,refunded,partially_refunded';
        $allPaymentMethodValues = implode(',', PaymentMethod::values());
        $combinedValues = $paymentStatusValues.','.$allPaymentMethodValues;

        $validated = $request->validate([
            'payment_status' => 'nullable|string|in:'.$combinedValues,
            'payment_method' => 'nullable|string|in:'.$allPaymentMethodValues,
        ]);

        // Get the actual values
        $paymentStatus = $validated['payment_status'] ?? null;
        $paymentMethod = $validated['payment_method'] ?? null;

        // If payment_status is actually a payment method, treat it as such
        if ($paymentStatus && in_array($paymentStatus, PaymentMethod::values()) && ! $paymentMethod) {
            $paymentMethod = $paymentStatus;
            $paymentStatus = null;
        }

        // If only payment_method is provided, set status based on method
        if ($paymentMethod && ! $paymentStatus) {
            // For cash_on_delivery, status is pending until delivery
            // For other methods, assume completed if not specified
            $paymentStatus = ($paymentMethod === PaymentMethod::CASH_ON_DELIVERY->value) ? 'pending' : 'completed';
        }

        // Update order payment_method if provided
        if ($paymentMethod) {
            $order->update(['payment_method' => $paymentMethod]);
        }

        // Update or create payment transaction
        $paymentTransaction = $order->paymentTransactions()->latest()->first();

        if ($paymentTransaction && $paymentStatus) {
            $paymentTransaction->update(['status' => $paymentStatus]);
            if ($paymentMethod) {
                $paymentTransaction->update(['payment_method' => $paymentMethod]);
            }
        } elseif ($paymentStatus) {
            $order->paymentTransactions()->create([
                'gateway' => $paymentMethod ?? 'manual',
                'transaction_id' => 'MANUAL-'.$order->id.'-'.time(),
                'amount' => $order->final_amount,
                'currency' => 'SAR',
                'status' => $paymentStatus,
                'payment_method' => $paymentMethod ?? $order->payment_method ?? 'cash_on_delivery',
            ]);
        }

        return successResponse(
            $order->fresh()->load('paymentTransactions'),
            __('admin.order_payment_status_updated')
        );
    }

    public function cancel(int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make(__('admin.order_not_found'), null, 404);
        }

        if ($order->status === OrderStatus::CANCELLED->value) {
            return successResponse($order->fresh(), __('admin.order_cancelled'));
        }

        $context = [
            'reason' => 'Cancelled by admin',
            'notes' => 'Cancelled by admin',
            'changed_by' => optional(auth()->user())->id,
            'actor_type' => 'admin',
            'actor_id' => optional(auth()->user())->id,
        ];

        // Route through the state machine so the cancellation listeners fire — most
        // importantly HandleOrderCancellationRefund, which voids any AUTHORIZATION hold
        // or refunds a captured payment. The old raw status write skipped that and left
        // the customer's funds orphaned at the gateway.
        $statusService = app(OrderStatusService::class);

        try {
            $statusService->transitionTo($order, OrderStatus::CANCELLED, $context);
        } catch (InvalidStatusTransitionException $e) {
            // Admin override: the order is in a state the transition table does not allow
            // cancelling from, but admin cancellation is still permitted. Force the status
            // and dispatch the same event so the void/refund listeners still run.
            $oldStatus = OrderStatus::from($order->status);
            $order->update([
                'status' => OrderStatus::CANCELLED->value,
                'cancelled_reason' => $context['reason'],
                'cancelled_at' => now(),
            ]);
            $order->statusLogs()->create([
                'status' => OrderStatus::CANCELLED->value,
                'notes' => $context['notes'],
                'changed_by' => $context['changed_by'],
            ]);
            event(new OrderStatusChanged($order, $oldStatus, OrderStatus::CANCELLED, $context));
        }

        return successResponse(
            $order->fresh(),
            __('admin.order_cancelled')
        );
    }

    public function statistics(Request $request): JsonResponse
    {
        $query = Order::query();

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Get paid orders through payment transactions
        $paidOrdersQuery = (clone $query)->whereHas('paymentTransactions', function ($q) {
            $q->where('status', 'completed');
        });

        $stats = [
            'total_orders' => (clone $query)->count(),
            'pending_orders' => (clone $query)->where('status', 'pending')->count(),
            'confirmed_orders' => (clone $query)->where('status', 'confirmed')->count(),
            'preparing_orders' => (clone $query)->where('status', 'delivered_to_branch')->count(),
            'delivered_orders' => (clone $query)->where('status', 'delivered')->count(),
            'cancelled_orders' => (clone $query)->where('status', 'cancelled')->count(),
            'total_revenue' => $paidOrdersQuery->sum('final_amount'),
            'average_order_value' => $paidOrdersQuery->avg('final_amount'),
            'recent_orders' => (clone $query)->where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return successResponse(
            $stats,
            __('admin.order_statistics_retrieved')
        );
    }

    /**
     * Get order statistics (simplified version)
     * GET /orders/statistics
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $query = Order::query();

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $totalOrders = (clone $query)->count();
        $completedOrders = (clone $query)->whereIn('status', ['completed', 'delivered'])->count();
        $pendingOrders = (clone $query)->where('status', 'pending')->count();
        $cancelledOrders = (clone $query)->where('status', 'cancelled')->count();

        return successResponse([
            'Total_orders' => $totalOrders,
            'Completed_orders' => $completedOrders,
            'Pending_orders' => $pendingOrders,
            'cancelled_orders' => $cancelledOrders,
        ], __('admin.order_statistics_retrieved'));
    }

    /**
     * Get orders per city/area
     * GET /orders/orders_per_city
     */
    public function ordersPerCity(Request $request): JsonResponse
    {
        $query = Order::query();

        if ($request->has('from_date')) {
            $query->whereDate('orders.created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('orders.created_at', '<=', $request->to_date);
        }

        // Get orders grouped by zone/city
        $ordersPerCity = $query
            ->join('addresses', 'orders.delivery_address_id', '=', 'addresses.id')
            ->join('zones', 'addresses.zone_id', '=', 'zones.id')
            ->selectRaw('zones.code as Area_id, COUNT(orders.id) as Value')
            ->groupBy('zones.code')
            ->orderByDesc('Value')
            ->limit(4)
            ->get();

        $totalOrders = $ordersPerCity->sum('Value');

        $result = $ordersPerCity->map(function ($item) use ($totalOrders) {
            return [
                'Area_id' => $item->Area_id,
                'Value' => (int) $item->Value,
                'Percentage' => $totalOrders > 0 ? round(($item->Value / $totalOrders) * 100, 2) : 0,
            ];
        });

        return successResponse($result, __('admin.orders_per_city_retrieved'));
    }

    /**
     * Get order detailed view for admin
     * GET /orders/{id}/details
     * GET /orders/order_details?order_id={id}
     */
    public function details(Request $request, ?int $id = null): JsonResponse
    {
        $orderId = $id ?? $request->input('order_id');

        if (! $orderId) {
            return ErrorResponse::make(__('admin.order_id_required'), null, 400);
        }

        $order = Order::with([
            'client.addresses',
            'driver',
            'pickupDriver',
            'deliveryDriver',
            'branch.vendor',
            'items.piece',
            'items.service',
            'items.additions.serviceAddition',
            'pickupAddress',
            'deliveryAddress',
            'latestPayment',
            'statusLogs',
        ])->find($orderId);

        if (! $order) {
            return ErrorResponse::make(__('admin.order_not_found'), null, 404);
        }

        // Separate accepted and rejected items (multi-service piece = one line)
        $acceptedItems = collect();
        $rejectedItems = collect();
        $branchId = (int) ($order->branch_id ?? 0);
        $lang = app()->getLocale();
        $uploadFilesService = app(UploadFilesService::class);

        foreach (\Modules\Order\Support\OrderItemGrouper::buckets($order->items) as $groupItems) {
            $primary = $groupItems->first();
            $pieceName = $primary->piece
                ? \App\Support\OrderItemDisplayNames::pieceName($primary->piece, $branchId, $lang)
                : ($primary->piece?->name ?? '');

            $byStatus = [
                'accepted' => collect(),
                'rejected' => collect(),
                'modified' => collect(),
            ];
            foreach ($groupItems as $item) {
                $status = $item->vendor_status ?? 'accepted';
                if ($status === 'rejected') {
                    $byStatus['rejected']->push($item);
                } elseif ($status === 'modified') {
                    $byStatus['modified']->push($item);
                } else {
                    $byStatus['accepted']->push($item);
                }
            }

            foreach (['accepted', 'modified', 'rejected'] as $statusKey) {
                $statusItems = $byStatus[$statusKey];
                if ($statusItems->isEmpty()) {
                    continue;
                }

                $serviceNames = [];
                $totalPrice = 0.0;
                $additionalServices = [];
                foreach ($statusItems as $item) {
                    if ($item->service) {
                        $serviceNames[] = \App\Support\OrderItemDisplayNames::serviceName($item->service, $branchId, $lang)
                            ?: ($item->service->name ?? '');
                    }
                    if ($statusKey !== 'rejected') {
                        $totalPrice += (float) $item->total_price;
                    }
                    foreach ($item->additionalServicesPivot ?? [] as $addition) {
                        $name = $addition->serviceAddition?->getTranslation('name', $lang)
                            ?? $addition->serviceAddition?->name;
                        if ($name) {
                            $additionalServices[] = $name;
                        }
                    }
                }

                $serviceNames = array_values(array_unique(array_filter($serviceNames)));
                $additionalServices = array_values(array_unique(array_filter($additionalServices)));
                $joinedServices = implode('، ', $serviceNames);
                $quantity = (int) $primary->quantity;

                // The piece's generic type icon (e.g. a "dress" icon shared by every dress) —
                // distinct from the client's own photo of this specific item, if they
                // uploaded one. Previously both were collapsed into the same value, so the
                // client's actual photo never made it into the response once the piece had
                // a generic icon (which is effectively always).
                $pieceIcon = $primary->piece?->iconRelation;
                if (! $pieceIcon && $primary->piece?->icon_id) {
                    $pieceIcon = Icon::withTrashed()->find($primary->piece->icon_id);
                }

                $pieceLogo = $uploadFilesService->getFullUrl(
                    $pieceIcon?->full_path ?: $pieceIcon?->path
                ) ?? '';
                $clientImage = $uploadFilesService->getFullUrl($primary->images) ?: $pieceLogo;

                $itemData = [
                    'icon' => $pieceLogo,
                    'logo' => $pieceLogo,
                    'image' => $clientImage,
                    'piece_image' => $clientImage,
                    'Piece_logo' => $pieceLogo,
                    'piece_logo' => $pieceLogo,
                    'Piece_count' => $quantity,
                    'Piece_name' => $pieceName,
                    'Total_without_tax' => round($totalPrice, 2),
                    'Services' => collect($serviceNames)->map(fn ($name) => [
                        'Piece_amount' => $quantity,
                        'service_name' => $name,
                    ])->values()->all(),
                    'Item_details' => [
                        'Piece_name' => $pieceName,
                        'Price' => round($totalPrice, 2),
                        'Services' => [[
                            'Main_services' => $serviceNames,
                            'Additional_services' => $additionalServices,
                        ]],
                        'Comment' => $primary->notes ?? '',
                        'Image' => $clientImage,
                    ],
                    'name_operation' => $joinedServices,
                ];

                if ($statusKey === 'rejected') {
                    $rejectedItems->push($itemData);
                } else {
                    $acceptedItems->push($itemData);
                }
            }
        }

        // Determine payment status - convert to "done" or "pending"
        $paymentStatus = $order->latestPayment?->status ?? 'pending';
        $paymentStatus = in_array($paymentStatus, ['completed', 'authorized']) ? 'done' : 'pending';

        // Determine progress based on order status
        $progressData = $this->getOrderProgress($order->status);

        $actualStatus = $order->status ?? 'pending';
        $statusLabel = OrderStatus::tryFrom($actualStatus)?->localizedLabel(
            $order->payment_method,
            $actualStatus === OrderStatus::COMPLETED->value && ! $order->client_delivery_handoff_at,
            (bool) $order->delivery_at_vendor,
            false,
        ) ?? ($order->status_label ?? $actualStatus);

        // Use stored order totals so all order APIs return same amounts
        $subtotal = (float) $order->total_amount;
        $deliveryFee = (float) $order->delivery_fee;
        $discount = (float) $order->discount_amount;
        $tax = (float) $order->tax_amount;
        $finalTotal = (float) $order->final_amount;

        $pickupLocation = $order->pickupAddress ? [
            'lat' => $order->pickupAddress->latitude !== null ? (float) $order->pickupAddress->latitude : null,
            'lang' => $order->pickupAddress->longitude !== null ? (float) $order->pickupAddress->longitude : null,
            'lng' => $order->pickupAddress->longitude !== null ? (float) $order->pickupAddress->longitude : null,
            'address' => $order->pickupAddress->address_text ?? $order->pickupAddress->street_name ?? null,
        ] : null;

        $deliveryLocation = $order->deliveryAddress ? [
            'lat' => $order->deliveryAddress->latitude !== null ? (float) $order->deliveryAddress->latitude : null,
            'lang' => $order->deliveryAddress->longitude !== null ? (float) $order->deliveryAddress->longitude : null,
            'lng' => $order->deliveryAddress->longitude !== null ? (float) $order->deliveryAddress->longitude : null,
            'address' => $order->deliveryAddress->address_text ?? $order->deliveryAddress->street_name ?? null,
        ] : null;

        $paymentBreakdown = method_exists($order, 'paymentBreakdownForApi')
            ? $order->paymentBreakdownForApi()
            : [];

        $orderInvoice = [
            'pieces' => $acceptedItems->map(function ($item) {
                $clientImage = $item['piece_image'] ?? $item['image'] ?? $item['Item_details']['Image'] ?? '';
                $pieceIcon = $item['Piece_logo'] ?? $item['piece_logo'] ?? $item['icon'] ?? '';

                return [
                    'piece_count' => $item['Piece_count'],
                    'piece_name' => $item['Piece_name'],
                    'piece_image' => $clientImage,
                    'piece_logo' => $pieceIcon,
                    'image' => $clientImage,
                    'icon' => $pieceIcon,
                    'logo' => $pieceIcon,
                    'service' => $item['name_operation'] ?: ($item['Services'][0]['service_name'] ?? ''),
                    'additional_services' => $item['Item_details']['Services'][0]['Additional_services'] ?? [],
                    'price' => $item['Item_details']['Price'],
                ];
            })->values()->toArray(),
            'deleviery_price' => $deliveryFee,
            'delivery_price' => $deliveryFee,
            'total_price' => $subtotal,
            'total_tax' => $tax,
            'total_price_after_tax' => $finalTotal,
            'discount_amount' => $discount,
            'final_amount' => $finalTotal,
        ];

        $clientAddressObj = $order->deliveryAddress 
            ?? $order->pickupAddress 
            ?? $order->client?->addresses?->first();

        $clientResolvedAddress = $clientAddressObj?->street_name ?? $clientAddressObj?->address_text ?? null;
        $laundryResolvedAddress = $order->branch?->address_text ?? $order->branch?->name ?? null;

        $orderInfo = [
            'branch_id' => $order->branch_id,
            'status' => $actualStatus,
            'status_label' => $statusLabel,
            'order_status' => $actualStatus,
            'order_status_label' => $statusLabel,
            'order_code' => $order->order_number ?? '',
            'order_number' => $order->order_number ?? '',
            'order_price' => $subtotal,
            'total_price' => $subtotal,
            'total_price_after_tax' => $finalTotal,
            'final_amount' => $finalTotal,
            'client_location' => $clientResolvedAddress ?? '',
            'client_address' => $clientResolvedAddress ?? '',
            'laundry_name' => $order->branch?->vendor?->name ?? '',
            'laundry_address' => $laundryResolvedAddress ?? '',
            'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
            'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
        ];

        // Active driver selection based on current order status
        $activeDriver = null;
        if (in_array($actualStatus, ['driver_pickup_accepted', 'on_way_to_pickup', 'picked_up'], true)) {
            $activeDriver = $order->pickupDriver ?? $order->driver;
        } elseif (in_array($actualStatus, ['driver_delivery_accepted', 'on_way_to_delivery', 'waiting_client_receipt', 'delivered'], true)) {
            $activeDriver = $order->deliveryDriver ?? $order->driver;
        } else {
            $activeDriver = $order->driver ?? $order->deliveryDriver ?? $order->pickupDriver;
        }

        $formatDriver = function ($driver) use ($lang) {
            if (! $driver) {
                return null;
            }

            return [
                'id' => $driver->id,
                'name' => method_exists($driver, 'getTranslation') ? ($driver->getTranslation('full_name', $lang) ?? $driver->full_name) : $driver->full_name,
                'full_name' => method_exists($driver, 'getTranslation') ? ($driver->getTranslation('full_name', $lang) ?? $driver->full_name) : $driver->full_name,
                'phone' => $driver->phone,
                'avatar' => $driver->image,
                'image' => $driver->image,
                'rating' => (float) ($driver->rating ?? 4.5),
                'latitude' => $driver->latitude !== null ? (float) $driver->latitude : null,
                'longitude' => $driver->longitude !== null ? (float) $driver->longitude : null,
                'lat' => $driver->latitude !== null ? (float) $driver->latitude : null,
                'lng' => $driver->longitude !== null ? (float) $driver->longitude : null,
            ];
        };

        $driverData = $formatDriver($activeDriver);
        // Distinct from $driverData (which picks whichever leg is "currently active" for
        // the tracking map): these always reflect each leg's own actual assignment, so the
        // admin UI can show the pickup and delivery driver cards independently even when
        // they're two different people.
        $pickupDriverData = $formatDriver($order->pickupDriver);
        $deliveryDriverData = $formatDriver($order->deliveryDriver);

        $clientName = $order->client ? (method_exists($order->client, 'getTranslation') ? ($order->client->getTranslation('full_name', $lang) ?? $order->client->full_name) : $order->client->full_name) : '';

        $clientData = $order->client ? [
            'id' => $order->client->id,
            'name' => $clientName,
            'full_name' => $clientName,
            'phone' => $order->client->phone,
            'avatar' => $order->client->avatar ?? $order->client->image ?? null,
            'image' => $order->client->avatar ?? $order->client->image ?? null,
            'rating' => (float) ($order->client->rating ?? 4.5),
            'address' => $clientAddressObj?->street_name ?? $clientAddressObj?->address_text ?? null,
            'latitude' => $clientAddressObj?->latitude !== null ? (float) $clientAddressObj->latitude : null,
            'longitude' => $clientAddressObj?->longitude !== null ? (float) $clientAddressObj->longitude : null,
            'lat' => $clientAddressObj?->latitude !== null ? (float) $clientAddressObj->latitude : null,
            'lng' => $clientAddressObj?->longitude !== null ? (float) $clientAddressObj->longitude : null,
        ] : null;

        $statusProgressMap = [
            'pending' => 10,
            'confirmed' => 25,
            'driver_pickup_assigned' => 30,
            'driver_pickup_accepted' => 35,
            'on_way_to_pickup' => 45,
            'picked_up' => 50,
            'delivered_to_branch' => 60,
            'preparing' => 70,
            'ready' => 80,
            'driver_delivery_assigned' => 85,
            'driver_delivery_accepted' => 88,
            'on_way_to_delivery' => 92,
            'waiting_client_receipt' => 95,
            'delivered' => 100,
            'completed' => 100,
            'cancelled' => 0,
            'rejected' => 0,
        ];
        $progressPercentage = $statusProgressMap[$actualStatus] ?? 50;

        // Which leg the driver is on decides where the map and the ETA should point:
        // pickup leg -> pickup address, drop-off leg -> the laundry branch, delivery leg -> delivery address.
        $trackingPhase = match (true) {
            in_array($actualStatus, ['driver_pickup_assigned', 'driver_pickup_accepted', 'on_way_to_pickup'], true) => 'pickup',
            $actualStatus === 'picked_up' => 'to_branch',
            in_array($actualStatus, ['driver_delivery_assigned', 'driver_delivery_accepted', 'on_way_to_delivery', 'waiting_client_receipt'], true) => 'delivery',
            default => null,
        };

        $trackingTarget = null;
        if ($trackingPhase === 'pickup' || $trackingPhase === 'delivery') {
            $targetAddress = $trackingPhase === 'pickup'
                ? ($order->pickupAddress ?? $clientAddressObj)
                : ($order->deliveryAddress ?? $clientAddressObj);
            if ($targetAddress && $targetAddress->latitude !== null && $targetAddress->longitude !== null) {
                $trackingTarget = [
                    'type' => $trackingPhase === 'pickup' ? 'pickup_address' : 'delivery_address',
                    'lat' => (float) $targetAddress->latitude,
                    'lng' => (float) $targetAddress->longitude,
                    'address' => $targetAddress->street_name ?? $targetAddress->address_text ?? null,
                ];
            }
        } elseif ($trackingPhase === 'to_branch' && $order->branch && $order->branch->latitude !== null && $order->branch->longitude !== null) {
            $trackingTarget = [
                'type' => 'branch',
                'lat' => (float) $order->branch->latitude,
                'lng' => (float) $order->branch->longitude,
                'address' => $order->branch->name ?? null,
            ];
        }

        // Outside an active driver leg keep the previous behaviour (measure toward the client).
        $etaTarget = $trackingTarget ?? (($clientAddressObj && $clientAddressObj->latitude && $clientAddressObj->longitude)
            ? ['lat' => (float) $clientAddressObj->latitude, 'lng' => (float) $clientAddressObj->longitude]
            : null);

        $estimatedMinutes = 15;
        $distanceRemainingKm = null;
        if ($activeDriver && $activeDriver->latitude && $activeDriver->longitude && $etaTarget) {
            $distKm = $this->calculateDistance((float) $activeDriver->latitude, (float) $activeDriver->longitude, $etaTarget['lat'], $etaTarget['lng']);
            $distanceRemainingKm = round($distKm, 2);
            $estimatedMinutes = max(5, min(60, (int) round($distKm * 2 + 5)));
        }
        $estimatedArrivalText = $lang === 'ar' ? "سيصل خلال {$estimatedMinutes} دقيقة" : "Will arrive in {$estimatedMinutes} minutes";

        $paymentPayload = [
            'payment_way' => $order->payment_method ?? 'cash_on_delivery',
            'payment_method' => $order->payment_method ?? 'cash_on_delivery',
            'payment_status' => $paymentStatus,
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($paymentStatus),
            'payment_breakdown' => $paymentBreakdown,
        ];

        $statusLogs = $order->statusLogs()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($log) use ($order, $lang) {
                $at = $log->created_at?->copy()->timezone('Asia/Riyadh');
                $title = OrderStatusLogPresenter::title($log->status, $order->payment_method, (bool) $order->delivery_at_vendor);
                $subTitle = OrderStatusLogPresenter::localizeNote($log->notes);

                return [
                    'id' => $log->id,
                    'status' => $log->status,
                    'status_label' => $title,
                    'title' => $title,
                    'sub_title' => $subTitle,
                    'notes' => $subTitle,
                    'status_date' => $at?->format('Y-m-d'),
                    'status_time' => $at?->format('H:i:s'),
                    'date_formatted' => $at ? ($lang === 'ar' ? $at->locale('ar')->translatedFormat('j F') : $at->format('j M')) : '',
                    'time_formatted' => $at ? ($lang === 'ar' ? $at->locale('ar')->translatedFormat('g:i A') : $at->format('g:i A')) : '',
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            })->values()->toArray();

        $latestLog = end($statusLogs) ?: null;

        // Dynamic Title and Subtitle matching Figma specifications for every order status
        $deliveryTitle = match ($actualStatus) {
            'pending' => ($lang === 'ar' ? 'طلب جديد قيد الانتظار' : 'New Order Pending'),
            'confirmed', 'branch_review', 'waiting_payment', 'payment_confirmed', 'awaiting_remaining_payment' => ($lang === 'ar' ? 'تم تأكيد الطلب' : 'Order Confirmed'),
            'driver_pickup_assigned', 'driver_delivery_assigned' => ($lang === 'ar' ? 'تم تعيين السائق وبانتظار تأكيده' : 'Driver Assigned and Awaiting Confirmation'),
            'driver_pickup_accepted', 'driver_delivery_accepted' => ($lang === 'ar' ? 'تم قبول الطلب من السائق' : 'Order Accepted by Driver'),
            'on_way_to_pickup' => ($lang === 'ar' ? 'السائق في طريقه لاستلام الطلب' : 'Driver on the way for pickup'),
            'on_way_to_delivery' => ($lang === 'ar' ? 'السائق في طريقه لتسليم الطلب' : 'Driver on the way for delivery'),
            'picked_up' => ($lang === 'ar' ? 'تم استلام الطلب من العميل' : 'Order Picked Up from Client'),
            'delivered_to_branch' => ($lang === 'ar' ? 'تم تسليم الطلب إلى المغسلة' : 'Delivered to Laundry Branch'),
            'preparing' => ($lang === 'ar' ? 'الطلب قيد التجهيز والغسيل' : 'Order is Being Prepared and Washed'),
            'ready' => ($lang === 'ar' ? 'الطلب جاهز للتوصيل' : 'Order Ready for Delivery'),
            'waiting_client_receipt' => ($lang === 'ar' ? 'سلم السائق الطلب' : 'Driver Delivered the Order'),
            'delivered', 'completed' => ($lang === 'ar' ? 'تم تسليم الطلب بنجاح' : 'Order Delivered Successfully'),
            'client_postponed_pickup' => ($lang === 'ar' ? 'أجل العميل موعد الاستلام' : 'Client Postponed Pickup'),
            'client_postponed_delivery' => ($lang === 'ar' ? 'أجل العميل موعد التسليم' : 'Client Postponed Delivery'),
            'cancelled' => ($lang === 'ar' ? 'تم إلغاء الطلب' : 'Order Cancelled'),
            default => ($latestLog['title'] ?? $statusLabel),
        };

        $deliverySubtitle = match ($actualStatus) {
            'pending' => ($lang === 'ar' ? 'تم استلام الطلب وجاري مراجعته وتأكيده.' : 'Order received and awaiting review and confirmation.'),
            'confirmed', 'branch_review', 'waiting_payment', 'payment_confirmed', 'awaiting_remaining_payment' => ($lang === 'ar' ? 'تم تأكيد الطلب من العميل وبانتظار تعيين السائق.' : 'Order confirmed by client and awaiting driver assignment.'),
            'driver_pickup_assigned', 'driver_delivery_assigned' => ($lang === 'ar' ? 'تم تعيين سائق للطلب وبانتظار تأكيده لاستلام المهمة والبدء في التوصيل.' : 'Driver assigned and awaiting confirmation to start the mission.'),
            'driver_pickup_accepted', 'driver_delivery_accepted' => ($lang === 'ar' ? 'قبل السائق الطلب وهو بصدد الانطلاق للموقع.' : 'Driver accepted and preparing to head to location.'),
            'on_way_to_pickup' => ($lang === 'ar' ? 'السائق يقترب من موقع العميل لاستلام الطلب وبانتظار تأكيد العميل جاهزيته.' : 'Driver approaching client location for pickup and awaiting readiness confirmation.'),
            'on_way_to_delivery' => ($lang === 'ar' ? 'السائق يقترب من موقع العميل لتسليم الطلب وبانتظار استلام العميل.' : 'Driver approaching client location for delivery.'),
            'picked_up' => ($lang === 'ar' ? 'تم استلام الشحنة بنجاح وجاري نقلها إلى المغسلة.' : 'Order picked up successfully and heading to laundry.'),
            'delivered_to_branch' => ($lang === 'ar' ? 'تم تسليم الملابس إلى المغسلة وبدء مرحلة الفرز والمعالجة.' : 'Clothes delivered to laundry and sorting stage started.'),
            'preparing' => ($lang === 'ar' ? 'المغسلة تعمل حالياً على غسيل وكي وتجهيز الملابس.' : 'Laundry is currently washing, ironing and preparing clothes.'),
            'ready' => ($lang === 'ar' ? 'تم الانتهاء من تجهيز الطلب بالكامل وهو جاهز للتسليم أو التوصيل.' : 'Order processing complete and ready for pickup/delivery.'),
            'waiting_client_receipt' => ($lang === 'ar' ? 'سلم السائق الطلب للعميل بنجاح، بأنتظار تأكيد استلام الطلب من العميل لإتمام عملية التسليم.' : "Driver delivered the order to the client successfully, awaiting the client's confirmation of receipt to complete the delivery."),
            'delivered', 'completed' => ($lang === 'ar' ? 'تم تسليم الطلب للعميل واكتمال عملية التوصيل بنجاح.' : 'Order delivered successfully to client.'),
            'client_postponed_pickup' => ($lang === 'ar' ? 'طلب العميل تأجيل موعد استلام الملابس وسيتم المتابعة لاحقاً.' : 'Client requested to postpone pickup time.'),
            'client_postponed_delivery' => ($lang === 'ar' ? 'طلب العميل تأجيل موعد تسليم الملابس وسيتم إعادة الجدولة.' : 'Client requested to postpone delivery time.'),
            'cancelled' => ($lang === 'ar' ? 'تم إلغاء هذا الطلب ولا توجد إجراءات إضافية مطلوبة.' : 'This order has been cancelled.'),
            default => ($latestLog['sub_title'] ?? $estimatedArrivalText),
        };

        // 'picked_up' and 'driver_delivery_accepted' each silently cover two very
        // different real moments (driver still heading over vs. one side already
        // declared handoff and is waiting on the other's confirmation) - the status
        // column alone can't tell them apart, but the two-step handoff service already
        // tracks exactly this via its own timestamps, so surface it here too.
        $handoffStage = app(OrderPieceHandoffService::class)->determineCurrentHandoffStage($order);
        if ($handoffStage === OrderPieceHandoffService::TYPE_DRIVER_TO_LAUNDRY) {
            $deliveryTitle = $lang === 'ar' ? 'تسليم الطلب للمغسلة' : 'Delivered to Laundry — Awaiting Confirmation';
            $deliverySubtitle = $lang === 'ar'
                ? 'سلّم السائق الطلب للمغسلة وبانتظار تأكيد المغسلة الاستلام.'
                : "The driver handed the order to the laundry and is awaiting the laundry's confirmation of receipt.";
        } elseif ($handoffStage === OrderPieceHandoffService::TYPE_LAUNDRY_TO_DRIVER) {
            $deliveryTitle = $lang === 'ar' ? 'تسليم الطلب لسائق التوصيل' : 'Handed to Delivery Driver — Awaiting Confirmation';
            $deliverySubtitle = $lang === 'ar'
                ? 'سلّمت المغسلة الطلب لسائق التوصيل وبانتظار تأكيد السائق الاستلام.'
                : "The laundry handed the order to the delivery driver and is awaiting the driver's confirmation of receipt.";
        }

        $deliveryStatusPayload = [
            'status' => $actualStatus,
            'title' => $deliveryTitle,
            'subtitle' => $deliverySubtitle,
            'delivery_status_title' => $deliveryTitle,
            'delivery_status_subtitle' => $deliverySubtitle,
            'estimated_arrival_minutes' => $estimatedMinutes,
            'estimated_arrival_text' => $estimatedArrivalText,
        ];

        $trackOrder = [
            'progress_percentage' => $progressPercentage,
            'estimated_arrival_minutes' => $estimatedMinutes,
            'estimated_arrival_text' => $estimatedArrivalText,
            'phase' => $trackingPhase,
            'is_live' => $trackingPhase !== null && $driverData !== null && $driverData['lat'] !== null && $driverData['lng'] !== null,
            'destination' => $trackingTarget,
            'distance_remaining_km' => $distanceRemainingKm,
            'route' => ($driverData && $driverData['lat'] !== null && $driverData['lng'] !== null && $trackingTarget) ? [
                'origin' => ['lat' => $driverData['lat'], 'lng' => $driverData['lng']],
                'destination' => ['lat' => $trackingTarget['lat'], 'lng' => $trackingTarget['lng']],
            ] : null,
            'driver' => $driverData,
            'client' => $clientData,
            'driver_location' => $driverData ? [
                'lat' => $driverData['lat'],
                'lng' => $driverData['lng'],
            ] : null,
            'client_location' => [
                'lat' => $clientAddressObj?->latitude !== null ? (float) $clientAddressObj->latitude : null,
                'lng' => $clientAddressObj?->longitude !== null ? (float) $clientAddressObj->longitude : null,
                'address' => $clientAddressObj?->street_name ?? $clientAddressObj?->address_text ?? $deliveryLocation['address'] ?? null,
            ],
            'laundry_location' => [
                'lat' => $order->branch?->latitude !== null ? (float) $order->branch->latitude : null,
                'lng' => $order->branch?->longitude !== null ? (float) $order->branch->longitude : null,
                'address' => $order->branch?->address_text ?? $order->branch?->name ?? null,
            ],
            'pickup_location' => $pickupLocation,
            'delivery_location' => $deliveryLocation,
            'status_logs' => $statusLogs,
            'status_history' => $statusLogs,
            'delivery_status' => $deliveryStatusPayload,
        ];

        $response = [
            'laundry_name' => $order->branch?->vendor?->name ?? '',
            'laundry_address' => $laundryResolvedAddress ?? '',
            'driver_name' => $driverData['name'] ?? $order->driver?->full_name ?? '',
            'client_name' => $clientName,
            'status' => $actualStatus,
            'status_label' => $statusLabel,
            'driver' => $driverData,
            'pickup_driver' => $pickupDriverData,
            'delivery_driver' => $deliveryDriverData,
            'client' => $clientData,
            'track_order' => $trackOrder,
            'estimated_arrival_text' => $estimatedArrivalText,
            'estimated_arrival_minutes' => $estimatedMinutes,
            'progress_percentage' => $progressPercentage,
            'order_invoice' => $orderInvoice,
            'order_info' => $orderInfo,
            'order_details' => $acceptedItems->values()->toArray(),
            'rejected_details' => $rejectedItems->values()->toArray(),
            'progress' => $progressData,
            'status_logs' => $statusLogs,
            'status_history' => $statusLogs,
            'delivery_status' => $deliveryStatusPayload,
            'delivery_status_title' => $deliveryTitle,
            'delivery_status_subtitle' => $deliverySubtitle,
            'payment' => $paymentPayload,
            'pickup_methods' => [
                'pick_up' => $order->pickup_at_vendor ? 'vendor' : 'client',
                'delivery' => $order->delivery_at_vendor ? 'vendor' : 'client',
            ],
            'pickup_address' => $pickupLocation,
            'delivery_address' => $deliveryLocation,
            'payment_breakdown' => $paymentBreakdown,
        ];

        return successResponse($response, __('admin.order_details_retrieved'));
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Get order progress based on status
     */
    private function getOrderProgress(string $status): array
    {
        // Handle rejected/cancelled orders
        if (in_array($status, ['cancelled', 'rejected'])) {
            return [
                ['Value' => 0, 'Description' => 'Rejected'],
            ];
        }

        // Standard progress for active orders
        return [
            ['Value' => 0, 'Description' => 'not_approved_yet'],
            ['Value' => 25, 'Description' => 'order_confimed'],
            ['Value' => 50, 'Description' => 'order_at_branch'],
            ['Value' => 75, 'Description' => 'ready_for_delievery'],
            ['Value' => 100, 'Description' => 'order_completed'],
        ];
    }

    /**
     * Alias method for order_details endpoint
     * GET /orders/order_details?order_id={id}
     */
    public function orderDetails(Request $request): JsonResponse
    {
        return $this->details($request);
    }

    /**
     * Get orders status over time (for the chart "تطور حالة الطلبات")
     * GET /orders/orders_status
     */
    public function ordersStatus(Request $request): JsonResponse
    {
        $filterType = $request->input('filter_type', 'monthly');
        if (! in_array($filterType, ['monthly', 'yearly', 'daily'], true)) {
            $filterType = 'monthly';
        }

        $arabicMonths = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];

        if ($filterType === 'daily') {
            $startDate = $request->filled('from_date') ? \Carbon\Carbon::parse($request->from_date) : now()->startOfMonth();
            $endDate = $request->filled('to_date') ? \Carbon\Carbon::parse($request->to_date) : now()->endOfMonth();

            $query = Order::query()->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

            if ($request->filled('vendor_id')) {
                $vendorId = $request->input('vendor_id');
                $query->whereHas('branch', fn ($q) => $q->where('vendor_id', $vendorId));
            }

            $rawResults = $query
                ->selectRaw('
                    DATE(created_at) as date_str,
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status IN ("completed", "delivered") THEN 1 ELSE 0 END) as completed_orders,
                    SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as canceled_orders,
                    SUM(CASE WHEN status != "cancelled" THEN 1 ELSE 0 END) as new_orders
                ')
                ->groupBy('date_str')
                ->get()
                ->keyBy('date_str');

            $data = collect();
            $curr = $startDate->copy();
            while ($curr->lte($endDate)) {
                $dateKey = $curr->format('Y-m-d');
                $item = $rawResults->get($dateKey);

                $data->push([
                    'period'           => $dateKey,
                    'month'            => $curr->format('j M'),
                    'day'              => $curr->format('j'),
                    'total_orders'     => $item ? (int) $item->total_orders : 0,
                    'new_orders'       => $item ? (int) $item->new_orders : 0,
                    'completed_orders' => $item ? (int) $item->completed_orders : 0,
                    'canceled_orders'  => $item ? (int) $item->canceled_orders : 0,
                    'cancelled_orders' => $item ? (int) $item->canceled_orders : 0,
                ]);
                $curr->addDay();
            }
        } elseif ($filterType === 'yearly') {
            $query = Order::query();

            if ($request->filled('vendor_id')) {
                $vendorId = $request->input('vendor_id');
                $query->whereHas('branch', fn ($q) => $q->where('vendor_id', $vendorId));
            }

            $data = $query
                ->selectRaw('
                    YEAR(created_at) as year,
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status IN ("completed", "delivered") THEN 1 ELSE 0 END) as completed_orders,
                    SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as canceled_orders,
                    SUM(CASE WHEN status != "cancelled" THEN 1 ELSE 0 END) as new_orders
                ')
                ->groupBy('year')
                ->orderBy('year')
                ->get()
                ->map(function ($item) {
                    return [
                        'period'           => (string) $item->year,
                        'month'            => (string) $item->year,
                        'year'             => (int) $item->year,
                        'total_orders'     => (int) $item->total_orders,
                        'new_orders'       => (int) $item->new_orders,
                        'completed_orders' => (int) $item->completed_orders,
                        'canceled_orders'  => (int) $item->canceled_orders,
                        'cancelled_orders' => (int) $item->canceled_orders,
                    ];
                });
        } else {
            // Monthly
            $year = now()->year;
            if ($request->filled('from_date')) {
                $year = (int) date('Y', strtotime($request->from_date));
            } elseif ($request->filled('to_date')) {
                $year = (int) date('Y', strtotime($request->to_date));
            }

            $query = Order::query()->whereYear('created_at', $year);

            if ($request->filled('vendor_id')) {
                $vendorId = $request->input('vendor_id');
                $query->whereHas('branch', fn ($q) => $q->where('vendor_id', $vendorId));
            }

            $rawResults = $query
                ->selectRaw('
                    MONTH(created_at) as month_num,
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status IN ("completed", "delivered") THEN 1 ELSE 0 END) as completed_orders,
                    SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as canceled_orders,
                    SUM(CASE WHEN status != "cancelled" THEN 1 ELSE 0 END) as new_orders
                ')
                ->groupBy('month_num')
                ->get()
                ->keyBy('month_num');

            $data = collect();
            for ($m = 1; $m <= 12; $m++) {
                $item = $rawResults->get($m);
                $arMonth = $arabicMonths[$m];
                $period = sprintf('%d-%02d', $year, $m);

                $data->push([
                    'period'           => $period,
                    'month'            => $arMonth,
                    'month_ar'         => $arMonth,
                    'month_num'        => $m,
                    'year'             => $year,
                    'total_orders'     => $item ? (int) $item->total_orders : 0,
                    'new_orders'       => $item ? (int) $item->new_orders : 0,
                    'completed_orders' => $item ? (int) $item->completed_orders : 0,
                    'canceled_orders'  => $item ? (int) $item->canceled_orders : 0,
                    'cancelled_orders' => $item ? (int) $item->canceled_orders : 0,
                ]);
            }
        }

        return successResponse([
            'filter_type'    => $filterType,
            'data'           => $data,
            'monthly_status' => $data,
        ], __('admin.orders_status_retrieved'));
    }
}
