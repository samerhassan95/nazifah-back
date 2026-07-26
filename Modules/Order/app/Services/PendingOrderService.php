<?php

namespace Modules\Order\Services;

use App\Enums\OrderStatus;
use Modules\Discount\Models\Discount;
use Modules\Discount\Services\DiscountService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderItemAdditionalService;
use Modules\Order\Models\OrderStatusLog;
use Modules\Order\Models\PendingOrder;

class PendingOrderService
{
    public function __construct(
        private DiscountService $discountService,
    ) {}

    public function createOrderFromPending(PendingOrder $pendingOrder): Order
    {
        $orderData = $pendingOrder->order_data;
        if (empty($orderData['discount_id']) && $pendingOrder->discount_id) {
            $orderData['discount_id'] = $pendingOrder->discount_id;
        }
        $itemsData = $pendingOrder->items_data;

        $order = Order::create($orderData);

        foreach ($itemsData as $itemData) {
            $quantityToCreate = (int) $itemData['quantity'];
            $serviceRows = $itemData['services'] ?? [[
                'service_id' => $itemData['service_id'],
                'service_piece_price' => $itemData['service_piece_price'],
            ]];
            $additionalServicesTotal = (float) ($itemData['additional_services_total'] ?? 0);

            for ($i = 0; $i < $quantityToCreate; $i++) {
                $lineGroup = count($serviceRows) > 1 ? (string) \Illuminate\Support\Str::uuid() : null;

                foreach ($serviceRows as $serviceIndex => $serviceRow) {
                    $isPrimary = $serviceIndex === 0;
                    $servicePrice = (float) $serviceRow['service_piece_price'];
                    $rowUnitPrice = $isPrimary
                        ? ($servicePrice + $additionalServicesTotal)
                        : $servicePrice;

                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'piece_id' => $itemData['piece_id'],
                        'service_id' => $serviceRow['service_id'],
                        'line_group' => $lineGroup,
                        'piece_price' => 0,
                        'service_price' => $servicePrice,
                        'quantity' => 1,
                        'unit_price' => $rowUnitPrice,
                        'total_price' => $rowUnitPrice,
                        'notes' => $itemData['note'] ?? null,
                        'images' => $itemData['uploaded_image'] ?? null,
                    ]);

                    if ($isPrimary && ! empty($itemData['additional_services'])) {
                        foreach ($itemData['additional_services'] as $additionalService) {
                            OrderItemAdditionalService::create([
                                'order_item_id' => $orderItem->id,
                                'service_addition_id' => $additionalService['id'],
                                'price' => $additionalService['price'],
                                'quantity' => 1,
                            ]);
                        }
                    }
                }
            }
        }

        // Link transactions, create OrderPayments, link wallet transactions, and capture holds
        $transactions = \Modules\Payment\Models\PaymentTransaction::where(function ($q) use ($pendingOrder) {
            $q->where('response_data->pending_order_id', $pendingOrder->id)
              ->orWhere('response_data->pending_order_id', (string) $pendingOrder->id);
        })->get();

        foreach ($transactions as $index => $transaction) {
            $transaction->update(['order_id' => $order->id]);

            if ($transaction->gateway === 'wallet') {
                \Illuminate\Support\Facades\DB::table('wallet_transactions')
                    ->where('transaction_id', $transaction->transaction_id)
                    ->update(['order_id' => $order->id]);
            }

            $status = \Modules\Order\Models\OrderPayment::STATUS_PENDING;
            if (in_array($transaction->status, ['completed', 'authorized'], true)) {
                $status = \Modules\Order\Models\OrderPayment::STATUS_PAID;
            } elseif ($transaction->status === 'cancelled') {
                $status = \Modules\Order\Models\OrderPayment::STATUS_CANCELLED;
            }

            $isWalletLeg = $transaction->payment_method === \App\Enums\PaymentMethod::Nathefah_WALLET->value;

            \Modules\Order\Models\OrderPayment::create([
                'order_id' => $order->id,
                'payment_transaction_id' => $transaction->id,
                'payment_method' => $transaction->payment_method,
                'amount' => $transaction->amount,
                'status' => $status,
                'sequence' => $index,
                'is_surcharge' => false,
                // Carry the hold flag onto the leg so captureReservedWalletLeg() knows
                // whether funds were reserved up front (release the hold) or deferred
                // with no hold (re-check the balance and debit all-or-nothing).
                'meta' => $isWalletLeg ? [
                    'wallet_reserved' => (bool) ($transaction->response_data['wallet_reserved'] ?? true),
                    'wallet_hold_placed' => (bool) ($transaction->response_data['wallet_hold_placed'] ?? true),
                ] : null,
                'paid_at' => in_array($transaction->status, ['completed', 'authorized'], true) ? now() : null,
            ]);
        }

        try {
            app(\Modules\Order\Services\OrderPaymentService::class)->captureOrderWalletReservations($order->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Pending order wallet capture failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        $paymentService = app(\Modules\Order\Services\OrderPaymentService::class);
        // User requested that fully paid orders stay as 'pending' instead of moving to 'payment_confirmed'.
        // if ($paymentService->isFullyPaid($order)) {
        //     $statusService = app(\App\Services\OrderStatusService::class);
        //     if ($statusService->canTransition($order, \App\Enums\OrderStatus::PAYMENT_CONFIRMED)) {
        //         $statusService->transitionTo($order, \App\Enums\OrderStatus::PAYMENT_CONFIRMED, [
        //             'notes' => 'Auto: pending order materialized and fully paid',
        //             'changed_by' => $order->client_id,
        //         ]);
        //     }
        // }

        if ($pendingOrder->discount_id) {
            $discount = Discount::find($pendingOrder->discount_id);
            if ($discount) {
                $this->discountService->incrementUsage($discount);
            }
        }

        OrderStatusLog::create([
            'order_id' => $order->id,
            'status' => OrderStatus::PENDING->value,
            'notes' => 'Order created after payment',
            'changed_by' => $pendingOrder->client_id,
        ]);

        // Chat-conversation setup is NOT load-bearing for the order. It must never
        // abort order creation — a throw here would otherwise bubble up and roll back
        // the whole payment callback transaction (reverting the captured payment's
        // status update AND the order). Guard it like the notifications below.
        try {
            $chatService = app(\Modules\Chat\Services\ChatService::class);
            $chatService->ensureConversationForOrder(
                $order->id,
                $pendingOrder->client_id,
                $pendingOrder->vendor_id,
                null
            );
            $chatService->ensureConversationForOrder(
                $order->id,
                $pendingOrder->client_id,
                null,
                null
            );
        } catch (\Throwable $e) {
            try {
                \Illuminate\Support\Facades\Log::warning('Pending order conversation setup failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }

        $pendingOrder->update(['status' => 'completed']);

        app(\App\Services\OrderNotificationService::class)
            ->sendOrderCreatedNotificationsIfNeeded($order);

        return $order;
    }
}
