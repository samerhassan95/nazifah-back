<?php

namespace Modules\Admin\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Resources\OrderResource;
use Modules\Admin\Services\OrderService;
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
            'Orders retrieved successfully'
        );
    }

    public function show(int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make('Order not found', null, 404);
        }

        return successResponse(new OrderResource($order), 'Order retrieved successfully');
    }

    public function invoice(int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make('Order not found', null, 404);
        }

        $invoice = $order->invoice()->first();
        if (! $invoice) {
            return ErrorResponse::make('Invoice not found', null, 404);
        }

        $invoiceService = app(\Modules\Invoice\Services\InvoiceService::class);
        $shareUrl = $invoiceService->shareUrl($invoice);

        return successResponse([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'share_url' => $shareUrl,
            'issued_at' => optional($invoice->issued_at)->toIso8601String(),
            'status' => $invoice->status,
            'zatca_status' => $invoice->zatca_status,
            'total_amount' => (float) $invoice->total_amount,
        ], 'Invoice retrieved successfully');
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make('Order not found', null, 404);
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
            'Order status updated successfully'
        );
    }

    public function assignDriver(Request $request, int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make('Order not found', null, 404);
        }

        $validated = $request->validate([
            'driver_id' => 'required|exists:drivers,id',
        ]);

        $order->driver_id = $validated['driver_id'];
        $order->save();

        return successResponse(
            $order->fresh()->load(['driver']),
            'Driver assigned successfully'
        );
    }

    public function updatePaymentStatus(Request $request, int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make('Order not found', null, 404);
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
            'Payment status updated successfully'
        );
    }

    public function cancel(int $id): JsonResponse
    {
        $order = $this->orderService->find($id);

        if (! $order) {
            return ErrorResponse::make('Order not found', null, 404);
        }

        if ($order->status === OrderStatus::CANCELLED->value) {
            return successResponse($order->fresh(), 'Order cancelled successfully');
        }

        $context = [
            'reason' => 'Cancelled by admin',
            'notes' => 'Cancelled by admin',
            'changed_by' => optional(auth()->user())->id,
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
            'Order cancelled successfully'
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
            'Order statistics retrieved successfully'
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
        ], 'Order statistics retrieved successfully');
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

        return successResponse($result, 'Orders per city retrieved successfully');
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
            return ErrorResponse::make('Order ID is required', null, 400);
        }

        $order = Order::with([
            'client',
            'driver',
            'branch.vendor',
            'items.piece',
            'items.service',
            'items.additions.serviceAddition',
            'pickupAddress',
            'deliveryAddress',
            'latestPayment',
        ])->find($orderId);

        if (! $order) {
            return ErrorResponse::make('Order not found', null, 404);
        }

        // Separate accepted and rejected items (multi-service piece = one line)
        $acceptedItems = collect();
        $rejectedItems = collect();
        $branchId = (int) ($order->branch_id ?? 0);
        $lang = app()->getLocale();

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
                    foreach ($item->additions ?? [] as $addition) {
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

                $itemData = [
                    'icon' => $primary->piece?->icon->path ?? '',
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
                        'Image' => $primary->image ?? '',
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

        // Use stored order totals so all order APIs return same amounts
        $subtotal = (float) $order->total_amount;
        $deliveryFee = (float) $order->delivery_fee;
        $discount = (float) $order->discount_amount;
        $tax = (float) $order->tax_amount;
        $finalTotal = (float) $order->final_amount;

        return successResponse([
            'laundry_name' => $order->branch?->vendor?->name ?? '',
            'driver_name' => $order->driver?->full_name ?? '',
            'client_name' => $order->client?->full_name ?? '',
            'order_invoice' => [
                'pieces' => $acceptedItems->map(function ($item) {
                    return [
                        'piece_count' => $item['Piece_count'],
                        'piece_name' => $item['Piece_name'],
                        'service' => $item['name_operation'] ?: ($item['Services'][0]['service_name'] ?? ''),
                        'additional_services' => $item['Item_details']['Services'][0]['Additional_services'] ?? [],
                        'price' => $item['Item_details']['Price'],
                    ];
                })->values()->toArray(),
                'deleviery_price' => $deliveryFee,
                'total_price' => $subtotal,
                'total_tax' => $tax,
                'total_price_after_tax' => $finalTotal,
            ],
            'order_info' => [
                'branch_id' => $order->branch_id,
                'status' => $order->status,
                'status_label' => $order->status_label,
                'order_code' => $order->order_number ?? '',
                'order_price' => $subtotal,
                'total_price_after_tax' => $finalTotal,
                'client_location' => $order->deliveryAddress?->street_name ?? '',
                'laundry_name' => $order->branch?->vendor?->name ?? '',
            ],
            'order_details' => $acceptedItems->values()->toArray(),
            'rejected_details' => $rejectedItems->values()->toArray(),
            'progress' => $progressData,
            'payment' => [
                'payment_way' => $order->payment_method ?? 'cash_on_delivery',
                'payment_status' => $paymentStatus,
                'payment_status_label' => \App\Support\PaymentStatusPresenter::label($paymentStatus),
            ],
            'pickup_methods' => [
                'pick_up' => $order->pickup_at_vendor ? 'vendor' : 'client',
                'delievry' => $order->delivery_at_vendor ? 'vendor' : 'client',
            ],
            'track_order' => [
                'client_location' => [
                    'lat' => $order->deliveryAddress?->latitude ?? null,
                    'lang' => $order->deliveryAddress?->longitude ?? null,
                ],
                'laundry_location' => [
                    'lat' => $order->branch?->latitude ?? null,
                    'lang' => $order->branch?->longitude ?? null,
                ],
                'delievry_location' => [
                    'lat' => $order->pickupAddress?->latitude ?? null,
                    'lang' => $order->pickupAddress?->longitude ?? null,
                ],
            ],
        ], 'Order details retrieved successfully');
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
     * Get orders status over time
     * GET /orders/orders_status
     */
    public function ordersStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filter_type' => 'required|in:monthly,yearly',
        ]);

        $filterType = $validated['filter_type'];
        $query = Order::query();

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($filterType === 'monthly') {
            $data = $query
                ->selectRaw('
                    DATE_FORMAT(created_at, "%Y-%m") as period,
                    MONTHNAME(created_at) as month,
                    YEAR(created_at) as year,
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as canceled_orders,
                    SUM(CASE WHEN status != "cancelled" THEN 1 ELSE 0 END) as new_orders
                ')
                ->groupBy('period', 'month', 'year')
                ->orderBy('period')
                ->get()
                ->map(function ($item) {
                    return [
                        'month' => $item->month.' '.$item->year,
                        'new_orders' => (int) $item->new_orders,
                        'canceled_orders' => (int) $item->canceled_orders,
                    ];
                });
        } else {
            // Yearly
            $data = $query
                ->selectRaw('
                    YEAR(created_at) as year,
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as canceled_orders,
                    SUM(CASE WHEN status != "cancelled" THEN 1 ELSE 0 END) as new_orders
                ')
                ->groupBy('year')
                ->orderBy('year')
                ->get()
                ->map(function ($item) {
                    return [
                        'month' => (string) $item->year,
                        'new_orders' => (int) $item->new_orders,
                        'canceled_orders' => (int) $item->canceled_orders,
                    ];
                });
        }

        return successResponse([
            'filter_type' => $filterType,
            'data' => $data,
        ], 'Orders status retrieved successfully');
    }
}
