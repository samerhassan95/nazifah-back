<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidStatusTransitionException;
use Modules\Order\Models\Order;

/**
 * Client confirms actual handoff: gave or received clothes (driver or laundry).
 */
class ClientOrderHandoffService
{
    public function __construct(
        protected OrderStatusService $statusService
    ) {}

    public function canConfirmHandoff(Order $order): bool
    {
        return $this->resolveHandoffContext($order) !== null;
    }

    /**
     * Client must still pick up at the branch (vendor ready, handoff not confirmed).
     */
    public function isPendingBranchPickupReceipt(Order $order): bool
    {
        return (bool) $order->delivery_at_vendor
            && ! $order->client_delivery_handoff_at
            && in_array($order->status, [OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::COMPLETED->value], true);
    }

    /**
     * Repair legacy branch orders where the vendor used delivered for "ready for pickup".
     */
    public function repairInconsistentBranchPickupStatus(Order $order): Order
    {
        if (
            (bool) $order->delivery_at_vendor
            && ! $order->client_delivery_handoff_at
            && $order->status === OrderStatus::DELIVERED->value
            && $this->statusService->canTransition($order, OrderStatus::COMPLETED)
        ) {
            return $this->statusService->transitionTo($order, OrderStatus::COMPLETED, [
                'notes' => __('order.handoff_log_repair_branch_completed'),
                'changed_by' => null,
            ]);
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    public function availableActions(Order $order): array
    {
        $context = $this->resolveHandoffContext($order);
        if (! $context) {
            return [];
        }

        return [
            'confirm_handoff' => true,
            'handoff_type' => $context['handoff_type'],
            'direction' => $context['direction'],
            'confirm_label' => $context['confirm_label'],
            'endpoint' => '/api/v1/user/orders/'.$order->id.'/confirm-handoff',
            'confirm_action' => 'confirm',
        ];
    }

    public function confirmHandoff(Order $order, int $clientId): Order
    {
        $this->assertOrderOwner($order, $clientId);
        $context = $this->resolveHandoffContext($order);

        if (! $context) {
            throw new \LogicException(__('order.handoff_error_not_awaiting_confirmation'));
        }

        return match ($context['handoff_type']) {
            'give_to_driver' => $this->confirmGiveToDriver($order, $clientId),
            'receive_from_driver' => $this->confirmReceiveFromDriver($order, $clientId),
            'give_to_laundry' => $this->confirmGiveToLaundry($order, $clientId),
            'receive_from_laundry' => $this->confirmReceiveFromLaundry($order, $clientId),
            default => throw new \LogicException(__('order.handoff_error_unknown_type')),
        };
    }

    /**
     * @return array{handoff_type: string, direction: string, confirm_label: string}|null
     */
    public function resolveHandoffContext(Order $order): ?array
    {
        $status = $order->status;
        $pickupAtVendor = (bool) $order->pickup_at_vendor;
        $deliveryAtVendor = (bool) $order->delivery_at_vendor;

        // Home pickup: driver marks picked_up first, then client confirms they handed clothes over.
        if (
            ! $pickupAtVendor
            && ! $order->client_pickup_handoff_at
            && $status === OrderStatus::PICKED_UP->value
        ) {
            return [
                'handoff_type' => 'give_to_driver',
                'direction' => 'give',
                'confirm_label' => __('order.handoff_confirm_give_to_driver'),
            ];
        }

        if (
            $pickupAtVendor
            && ! $order->client_pickup_handoff_at
            && in_array($status, [
                OrderStatus::CONFIRMED->value,
                OrderStatus::PAYMENT_CONFIRMED->value,
            ], true)
        ) {
            return [
                'handoff_type' => 'give_to_laundry',
                'direction' => 'give',
                'confirm_label' => __('order.handoff_confirm_give_to_laundry'),
            ];
        }

        if (
            ! $deliveryAtVendor
            && ! $order->client_delivery_handoff_at
            && in_array($status, [
                OrderStatus::WAITING_CLIENT_RECEIPT->value,
                OrderStatus::DELIVERED->value,
            ], true)
        ) {
            return [
                'handoff_type' => 'receive_from_driver',
                'direction' => 'receive',
                'confirm_label' => __('order.handoff_confirm_receive_from_driver'),
            ];
        }

        if (
            $deliveryAtVendor
            && ! $order->client_delivery_handoff_at
            && in_array($status, [OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::COMPLETED->value], true)
        ) {
            return [
                'handoff_type' => 'receive_from_laundry',
                'direction' => 'receive',
                'confirm_label' => __('order.handoff_confirm_receive_from_laundry'),
            ];
        }

        return null;
    }

    protected function confirmGiveToDriver(Order $order, int $clientId): Order
    {
        if ($order->status !== OrderStatus::PICKED_UP->value) {
            throw new \LogicException(__('order.handoff_error_not_awaiting_confirmation'));
        }

        // Driver already moved the order to picked_up; client only confirms the handoff.
        $order->update(['client_pickup_handoff_at' => now()]);

        return $order->fresh();
    }

    protected function confirmGiveToLaundry(Order $order, int $clientId): Order
    {
        $order->update(['client_pickup_handoff_at' => now()]);

        return $order->fresh();
    }

    protected function confirmReceiveFromDriver(Order $order, int $clientId): Order
    {
        if (! in_array($order->status, [
            OrderStatus::WAITING_CLIENT_RECEIPT->value,
            OrderStatus::DELIVERED->value,
            OrderStatus::COMPLETED->value,
        ], true)) {
            throw new \LogicException(__('order.handoff_error_not_awaiting_delivery_handoff'));
        }

        // Home delivery: unlike branch pickup, there's no separate party who still
        // needs to confirm afterward — the client receiving it from the driver is
        // the final step, so this closes the order out directly instead of leaving
        // it at delivered pending a later approval call.
        if ($order->status !== OrderStatus::COMPLETED->value) {
            if ($this->statusService->canTransition($order, OrderStatus::COMPLETED)) {
                $this->statusService->transitionTo($order, OrderStatus::COMPLETED, [
                    'notes' => __('order.handoff_log_receive_from_driver_delivered'),
                    'changed_by' => $clientId,
                ]);
                $this->markCashOnDeliveryPaidIfNeeded($order);
            } else {
                throw new InvalidStatusTransitionException(
                    OrderStatus::from($order->status),
                    OrderStatus::COMPLETED,
                    $order
                );
            }
        }

        $order->update(['client_delivery_handoff_at' => now()]);

        return $order->fresh();
    }

    protected function confirmReceiveFromLaundry(Order $order, int $clientId): Order
    {
        if (! in_array($order->status, [OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::COMPLETED->value], true)) {
            throw new \LogicException(__('order.handoff_error_not_ready_laundry_pickup'));
        }

        if ($this->statusService->canTransition($order, OrderStatus::DELIVERED)) {
            $this->statusService->transitionTo($order, OrderStatus::DELIVERED, [
                'notes' => __('order.handoff_log_receive_from_laundry'),
                'changed_by' => $clientId,
            ]);
            $this->markCashOnDeliveryPaidIfNeeded($order);
        } else {
            throw new InvalidStatusTransitionException(
                OrderStatus::from($order->status),
                OrderStatus::DELIVERED,
                $order
            );
        }

        $order->update(['client_delivery_handoff_at' => now()]);

        return $order->fresh();
    }

    protected function markCashOnDeliveryPaidIfNeeded(Order $order): void
    {
        if (($order->payment_method ?? '') !== PaymentMethod::CASH_ON_DELIVERY->value || $order->isPaid()) {
            return;
        }

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

    protected function assertOrderOwner(Order $order, int $clientId): void
    {
        if ((int) $order->client_id !== $clientId) {
            throw new \InvalidArgumentException(__('order.handoff_error_order_not_owned'));
        }
    }
}
