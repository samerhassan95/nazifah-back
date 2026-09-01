<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidStatusTransitionException;
use Modules\Order\Models\Order;

/**
 * Vendor/laundry handoff: confirm client drop-off at branch, or mark order ready for client pickup.
 */
class VendorOrderHandoffService
{
    public function __construct(
        protected OrderStatusService $statusService
    ) {}

    public function canConfirmPickupReceived(Order $order): bool
    {
        return $this->resolvePickupReceiptContext($order) !== null;
    }

    public function canRequestClientDelivery(Order $order): bool
    {
        return $this->resolveClientDeliveryContext($order) !== null;
    }

    public function canConfirmClientPickupReceived(Order $order): bool
    {
        return $this->resolveClientDeliveryHandoffContext($order) !== null;
    }

    public function canConfirmPickupFromDriver(Order $order): bool
    {
        return $this->resolvePickupFromDriverContext($order) !== null;
    }

    public function canConfirmHandoverToDelivery(Order $order): bool
    {
        return $this->resolveHandoverToDeliveryContext($order) !== null;
    }

    /**
     * @return array{can_confirm_pickup_from_driver: bool, can_confirm_handover_to_delivery: bool}
     */
    public function vendorConfirmFlags(Order $order): array
    {
        return [
            'can_confirm_pickup_from_driver' => (bool) $order->can_confirm_pickup_from_driver,
            'can_confirm_handover_to_delivery' => (bool) $order->can_confirm_handover_to_delivery,
        ];
    }

    /**
     * Whether the delivery driver may mark this order as on_way_to_delivery.
     */
    public function canMarkOnTheWay(Order $order, ?int $driverId = null): bool
    {
        if ($order->status !== OrderStatus::DRIVER_DELIVERY_ACCEPTED->value) {
            return false;
        }

        if ($order->vendor_handed_to_delivery_at === null) {
            return false;
        }

        if (! $order->needsDeliveryDriver() || ! $order->delivery_driver_id) {
            return false;
        }

        if ($driverId !== null && (int) $order->delivery_driver_id !== (int) $driverId) {
            return false;
        }

        return true;
    }

    /**
     * @return array{can_mark_on_the_way: bool, vendor_handed_to_delivery_at: string|null}
     */
    public function driverDeliveryActionFlags(Order $order, ?int $driverId = null): array
    {
        return [
            'can_mark_on_the_way' => $this->canMarkOnTheWay($order, $driverId),
            'vendor_handed_to_delivery_at' => $order->vendor_handed_to_delivery_at?->toISOString(),
        ];
    }

    public function enablePickupFromDriverConfirm(Order $order): Order
    {
        $order->update([
            'can_confirm_pickup_from_driver' => true,
            'can_confirm_handover_to_delivery' => false,
        ]);

        return $order->fresh();
    }

    public function enableHandoverToDeliveryConfirm(Order $order): Order
    {
        if ($order->vendor_handed_to_delivery_at !== null) {
            return $order->fresh();
        }

        $order->update([
            'can_confirm_pickup_from_driver' => false,
            'can_confirm_handover_to_delivery' => true,
        ]);

        return $order->fresh();
    }

    public function clearVendorConfirmFlags(Order $order): Order
    {
        $order->update([
            'can_confirm_pickup_from_driver' => false,
            'can_confirm_handover_to_delivery' => false,
        ]);

        return $order->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function availableActions(Order $order): array
    {
        $actions = [];

        if ($context = $this->resolvePickupReceiptContext($order)) {
            $actions[] = array_merge($context, [
                'action' => 'confirm_pickup_received',
                'method' => 'PUT',
                'body' => ['status' => OrderStatus::DELIVERED_TO_BRANCH->value],
            ]);
            $actions[] = array_merge($context, [
                'action' => 'reject_pickup_received',
                'method' => 'PUT',
                'body' => $context['reject_body'] ?? ['status' => OrderStatus::CANCELLED->value],
                'confirm_label' => $context['reject_label'] ?? __('order.vendor_handoff_reject_pickup_received'),
            ]);
        }

        if ($context = $this->resolvePickupFromDriverContext($order)) {
            $actions[] = array_merge($context, [
                'action' => 'confirm_pickup_from_driver',
                'method' => 'POST',
            ]);
        }

        if ($context = $this->resolveHandoverToDeliveryContext($order)) {
            $actions[] = array_merge($context, [
                'action' => 'confirm_handover_to_delivery',
                'method' => 'POST',
            ]);
        }

        if ($context = $this->resolveClientDeliveryContext($order)) {
            $actions[] = array_merge($context, [
                'action' => $context['handoff_type'],
                'method' => $context['method'] ?? 'PUT',
            ]);
        }

        if ($context = $this->resolveClientDeliveryHandoffContext($order)) {
            $actions[] = array_merge($context, [
                'action' => 'confirm_client_pickup_received',
                'method' => 'PUT',
            ]);
        }

        return $actions;
    }

    public function confirmPickupReceived(Order $order, int $changedBy, ?string $notes = null): Order
    {
        if (! $this->canConfirmPickupReceived($order)) {
            throw new \LogicException(__('order.vendor_handoff_error_not_awaiting_pickup_receipt'));
        }

        if (! $this->statusService->canTransition($order, OrderStatus::DELIVERED_TO_BRANCH)) {
            throw new InvalidStatusTransitionException(
                OrderStatus::from($order->status),
                OrderStatus::DELIVERED_TO_BRANCH,
                $order
            );
        }

        $this->statusService->transitionTo($order, OrderStatus::DELIVERED_TO_BRANCH, [
            'notes' => $notes ?? __('order.vendor_handoff_log_pickup_received'),
            'changed_by' => $changedBy,
            'actor_type' => 'vendor',
            'actor_id' => $changedBy,
        ]);

        $order->update(['vendor_pickup_received_at' => now()]);

        return $order->fresh();
    }

    public function requestClientDelivery(Order $order, int $changedBy, ?string $notes = null): Order
    {
        $context = $this->resolveClientDeliveryContext($order);

        if (! $context || ($context['handoff_type'] ?? '') !== 'request_client_delivery') {
            throw new \LogicException(__('order.vendor_handoff_error_not_ready_client_delivery'));
        }

        if (! $this->statusService->canTransition($order, OrderStatus::WAITING_CLIENT_RECEIPT)) {
            throw new InvalidStatusTransitionException(
                OrderStatus::from($order->status),
                OrderStatus::WAITING_CLIENT_RECEIPT,
                $order
            );
        }

        $this->statusService->transitionTo($order, OrderStatus::WAITING_CLIENT_RECEIPT, [
            'notes' => $notes ?? __('order.vendor_handoff_log_ready_for_pickup'),
            'changed_by' => $changedBy,
            'actor_type' => 'vendor',
            'actor_id' => $changedBy,
        ]);

        $order->update(['vendor_delivery_ready_at' => now()]);

        return $order->fresh();
    }

    public function confirmClientPickupReceived(Order $order, int $changedBy, ?string $notes = null): Order
    {
        $context = $this->resolveClientDeliveryHandoffContext($order);

        if (! $context) {
            throw new \LogicException(__('order.vendor_handoff_error_not_awaiting_client_pickup'));
        }

        $target = OrderStatus::from($context['target_status']);

        if (! $this->statusService->canTransition($order, $target)) {
            throw new InvalidStatusTransitionException(
                OrderStatus::from($order->status),
                $target,
                $order
            );
        }

        $this->statusService->transitionTo($order, $target, [
            'notes' => $notes ?? __('order.vendor_handoff_log_client_pickup_received'),
            'changed_by' => $changedBy,
            'actor_type' => 'vendor',
            'actor_id' => $changedBy,
        ]);

        $order->update(['vendor_client_delivery_handoff_at' => now()]);
        $this->markCashOnDeliveryPaidIfNeeded($order->fresh());

        return $order->fresh();
    }

    public function confirmPickupFromDriver(Order $order, int $changedBy, ?string $notes = null): Order
    {
        if (! $this->canConfirmPickupFromDriver($order)) {
            throw new \LogicException(__('order.vendor_handoff_error_not_awaiting_driver_pickup_handoff'));
        }

        if (! $this->statusService->canTransition($order, OrderStatus::DELIVERED_TO_BRANCH)) {
            throw new InvalidStatusTransitionException(
                OrderStatus::from($order->status),
                OrderStatus::DELIVERED_TO_BRANCH,
                $order
            );
        }

        $this->statusService->transitionTo($order, OrderStatus::DELIVERED_TO_BRANCH, [
            'notes' => $notes ?? __('order.vendor_handoff_log_pickup_from_driver_confirmed'),
            'changed_by' => $changedBy,
            'actor_type' => 'vendor',
            'actor_id' => $changedBy,
        ]);

        $order->refresh();

        return $this->clearVendorConfirmFlags($order);
    }

    public function confirmHandoverToDelivery(Order $order, int $changedBy, ?string $notes = null): Order
    {
        if (! $this->canConfirmHandoverToDelivery($order)) {
            throw new \LogicException(__('order.vendor_handoff_error_not_awaiting_delivery_driver_handoff'));
        }

        // Laundry confirms bags handed to delivery driver — status stays driver_delivery_accepted.
        // Driver later marks on_way_to_delivery for this specific order.
        $order->update([
            'vendor_handed_to_delivery_at' => now(),
            'can_confirm_pickup_from_driver' => false,
            'can_confirm_handover_to_delivery' => false,
        ]);

        \Modules\Order\Models\OrderStatusLog::create([
            'order_id' => $order->id,
            'status' => $order->status,
            'notes' => $notes ?? __('order.vendor_handoff_log_handover_to_delivery_confirmed'),
            'changed_by' => $changedBy,
        ]);

        return $order->fresh();
    }

    /**
     * @return array{handoff_type: string, target_status: string, confirm_label: string, endpoint: string}|null
     */
    public function resolvePickupReceiptContext(Order $order): ?array
    {
        if (! (bool) $order->pickup_at_vendor) {
            return null;
        }

        if ($order->status === OrderStatus::DELIVERED_TO_BRANCH->value) {
            return null;
        }

        if (! $order->client_pickup_handoff_at) {
            return null;
        }

        if (! $this->statusService->canTransition($order, OrderStatus::DELIVERED_TO_BRANCH)) {
            return null;
        }

        if (! in_array($order->status, [
            OrderStatus::CONFIRMED->value,
            OrderStatus::PAYMENT_CONFIRMED->value,
            OrderStatus::WAITING_PAYMENT->value,
        ], true)) {
            return null;
        }

        return [
            'handoff_type' => 'confirm_pickup_received',
            'target_status' => OrderStatus::DELIVERED_TO_BRANCH->value,
            'confirm_label' => __('order.vendor_handoff_confirm_pickup_received'),
            'reject_label' => __('order.vendor_handoff_reject_pickup_received'),
            'endpoint' => '/api/v1/vendor/orders/'.$order->id.'/status',
            'reject_endpoint' => '/api/v1/vendor/orders/'.$order->id.'/status',
            'reject_body' => ['status' => OrderStatus::CANCELLED->value],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveClientDeliveryContext(Order $order): ?array
    {
        if ((bool) $order->delivery_at_vendor) {
            if (in_array($order->status, [OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::COMPLETED->value], true) || $order->client_delivery_handoff_at) {
                return null;
            }

            if (! $this->statusService->canTransition($order, OrderStatus::WAITING_CLIENT_RECEIPT)) {
                return null;
            }

            if ($order->status !== OrderStatus::DELIVERED_TO_BRANCH->value) {
                return null;
            }

            return [
                'handoff_type' => 'request_client_delivery',
                'target_status' => OrderStatus::WAITING_CLIENT_RECEIPT->value,
                'confirm_label' => __('order.vendor_handoff_request_client_delivery'),
                'endpoint' => '/api/v1/vendor/orders/'.$order->id.'/status',
                'method' => 'PUT',
                'body' => ['status' => OrderStatus::WAITING_CLIENT_RECEIPT->value],
            ];
        }

        if (! $order->needsDeliveryDriver()) {
            return null;
        }

        if ($order->delivery_driver_id) {
            return null;
        }

        if (! in_array($order->status, [
            OrderStatus::DELIVERED_TO_BRANCH->value,
            OrderStatus::CLIENT_POSTPONED_DELIVERY->value,
        ], true)) {
            return null;
        }

        return [
            'handoff_type' => 'assign_delivery_driver',
            'confirm_label' => __('order.vendor_handoff_assign_delivery_driver'),
            'endpoint' => '/api/v1/vendor/home/assign-driver',
            'method' => 'POST',
            'note' => __('order.vendor_handoff_assign_delivery_driver_note'),
        ];
    }

    /**
     * Vendor confirms receiving the order from the pickup driver at the branch.
     *
     * @return array<string, mixed>|null
     */
    public function resolvePickupFromDriverContext(Order $order): ?array
    {
        if (! (bool) $order->can_confirm_pickup_from_driver) {
            return null;
        }

        if (! $order->needsPickupDriver()) {
            return null;
        }

        if (! $order->pickup_driver_id) {
            return null;
        }

        if ($order->status !== OrderStatus::PICKED_UP->value) {
            return null;
        }

        if (! $this->statusService->canTransition($order, OrderStatus::DELIVERED_TO_BRANCH)) {
            return null;
        }

        return [
            'handoff_type' => 'confirm_pickup_from_driver',
            'target_status' => OrderStatus::DELIVERED_TO_BRANCH->value,
            'confirm_label' => __('order.vendor_handoff_confirm_pickup_from_driver'),
            'endpoint' => '/api/v1/vendor/orders/'.$order->id.'/confirm-handoff',
        ];
    }

    /**
     * Vendor confirms handing the order to the delivery driver.
     *
     * @return array<string, mixed>|null
     */
    public function resolveHandoverToDeliveryContext(Order $order): ?array
    {
        if (! (bool) $order->can_confirm_handover_to_delivery) {
            return null;
        }

        if (! $order->needsDeliveryDriver()) {
            return null;
        }

        if (! $order->delivery_driver_id) {
            return null;
        }

        if ($order->status !== OrderStatus::DRIVER_DELIVERY_ACCEPTED->value) {
            return null;
        }

        // Already handed to driver — nothing for vendor to confirm.
        if ($order->vendor_handed_to_delivery_at !== null) {
            return null;
        }

        return [
            'handoff_type' => 'confirm_handover_to_delivery',
            'target_status' => OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
            'confirm_label' => __('order.vendor_handoff_confirm_handover_to_delivery'),
            'endpoint' => '/api/v1/vendor/orders/'.$order->id.'/confirm-handoff',
        ];
    }

    /**
     * Vendor confirms the client physically picked up the order at the branch.
     *
     * Two points in the flow this can fire:
     * - Client hasn't confirmed themselves yet (still waiting_client_receipt/completed):
     *   vendor confirms on the client's behalf -> delivered.
     * - Client already confirmed receive_from_laundry themselves (already delivered):
     *   vendor sends their own closing confirmation ("تم الاستلام") -> completed.
     *   This is the required second step — a walk-in order does not reach completed
     *   from the client's confirmation alone.
     *
     * @return array<string, mixed>|null
     */
    public function resolveClientDeliveryHandoffContext(Order $order): ?array
    {
        if (! (bool) $order->delivery_at_vendor) {
            return null;
        }

        if ($order->vendor_client_delivery_handoff_at) {
            return null;
        }

        if ($order->status === OrderStatus::DELIVERED->value) {
            if (! $this->statusService->canTransition($order, OrderStatus::COMPLETED)) {
                return null;
            }

            return [
                'handoff_type' => 'confirm_client_pickup_received',
                'target_status' => OrderStatus::COMPLETED->value,
                'confirm_label' => __('order.vendor_handoff_confirm_client_pickup_received'),
                'endpoint' => '/api/v1/vendor/orders/'.$order->id.'/status',
                'method' => 'PUT',
                'body' => ['status' => OrderStatus::COMPLETED->value],
            ];
        }

        if (! in_array($order->status, [OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::COMPLETED->value], true)) {
            return null;
        }

        if (! $this->statusService->canTransition($order, OrderStatus::DELIVERED)) {
            return null;
        }

        return [
            'handoff_type' => 'confirm_client_pickup_received',
            'target_status' => OrderStatus::DELIVERED->value,
            'confirm_label' => __('order.vendor_handoff_confirm_client_pickup_received'),
            'endpoint' => '/api/v1/vendor/orders/'.$order->id.'/status',
            'method' => 'PUT',
            'body' => ['status' => OrderStatus::DELIVERED->value],
        ];
    }

    public function hasPendingVendorAction(Order $order): bool
    {
        return $this->resolvePickupReceiptContext($order) !== null
            || $this->resolveClientDeliveryContext($order) !== null
            || $this->resolveClientDeliveryHandoffContext($order) !== null;
    }

    /**
     * Whether the client on-the-way screen should track this order (branch handoff legs).
     */
    public function isClientHandoffTrackable(Order $order): bool
    {
        if ($this->hasPendingVendorAction($order)) {
            return true;
        }

        if ((bool) $order->pickup_at_vendor && (bool) $order->delivery_at_vendor) {
            return in_array($order->status, [
                OrderStatus::CONFIRMED->value,
                OrderStatus::PAYMENT_CONFIRMED->value,
                OrderStatus::WAITING_PAYMENT->value,
                OrderStatus::DELIVERED_TO_BRANCH->value,
                OrderStatus::WAITING_CLIENT_RECEIPT->value,
                OrderStatus::COMPLETED->value,
            ], true);
        }

        if (
            (bool) $order->pickup_at_vendor
            && ! $order->vendor_pickup_received_at
            && in_array($order->status, [
                OrderStatus::CONFIRMED->value,
                OrderStatus::PAYMENT_CONFIRMED->value,
                OrderStatus::WAITING_PAYMENT->value,
            ], true)
        ) {
            return true;
        }

        if (
            (bool) $order->delivery_at_vendor
            && ! $order->client_delivery_handoff_at
            && in_array($order->status, [OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::COMPLETED->value], true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Client on-the-way payload: laundry receipt/delivery state + who acts next.
     *
     * @return array<string, mixed>
     */
    public function clientOnTheWayHandoff(Order $order): array
    {
        $vendorPickupReceived = $order->vendor_pickup_received_at !== null
            || $order->status === OrderStatus::DELIVERED_TO_BRANCH->value;

        $vendorDeliveryReady = $order->vendor_delivery_ready_at !== null
            || ((bool) $order->delivery_at_vendor
                ? in_array($order->status, [OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::COMPLETED->value], true)
                : $order->status === OrderStatus::DELIVERED->value);

        $clientPickupConfirmed = $order->client_pickup_handoff_at !== null;
        $clientDeliveryConfirmed = $order->client_delivery_handoff_at !== null
            || $order->vendor_client_delivery_handoff_at !== null;

        $pendingVendor = $this->resolvePickupReceiptContext($order)
            ?? $this->resolveClientDeliveryContext($order)
            ?? $this->resolveClientDeliveryHandoffContext($order);

        $pendingAction = $pendingVendor['handoff_type'] ?? null;
        $requiresVendorAction = $pendingAction !== null;

        $waitingFor = null;
        if ($requiresVendorAction) {
            $waitingFor = 'vendor';
        }

        $lang = app()->getLocale();
        [$title, $message] = $this->clientOnTheWayTexts(
            $order,
            $pendingAction,
            $vendorPickupReceived,
            $vendorDeliveryReady,
            $clientPickupConfirmed,
            $lang
        );

        return [
            'waiting_for' => $waitingFor,
            'requires_vendor_action' => $requiresVendorAction,
            'vendor_pending_action' => $pendingAction,
            'vendor_pickup_received' => $vendorPickupReceived,
            'vendor_delivery_ready' => $vendorDeliveryReady,
            'client_pickup_confirmed' => $clientPickupConfirmed,
            'client_delivery_confirmed' => $clientDeliveryConfirmed,
            'title' => $title,
            'message' => $message,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function clientOnTheWayTexts(
        Order $order,
        ?string $pendingVendorAction,
        bool $vendorPickupReceived,
        bool $vendorDeliveryReady,
        bool $clientPickupConfirmed,
        string $lang
    ): array {
        if ($pendingVendorAction === 'confirm_pickup_received' && $clientPickupConfirmed) {
            return [
                __('order.client_vendor_waiting_pickup_confirm_title', [], $lang),
                __('order.client_vendor_waiting_pickup_confirm_dropped_message', [], $lang),
            ];
        }

        if (
            (bool) $order->pickup_at_vendor
            && ! $clientPickupConfirmed
            && in_array($order->status, [
                OrderStatus::CONFIRMED->value,
                OrderStatus::PAYMENT_CONFIRMED->value,
            ], true)
        ) {
            return [
                __('order.on_the_way_handoff_give_laundry_title', [], $lang),
                __('order.on_the_way_handoff_give_laundry_message', [], $lang),
            ];
        }

        if ($pendingVendorAction === 'request_client_delivery') {
            return [
                __('order.client_vendor_waiting_delivery_ready_title', [], $lang),
                __('order.client_vendor_waiting_delivery_ready_message', [], $lang),
            ];
        }

        if ($pendingVendorAction === 'assign_delivery_driver') {
            return [
                __('order.client_vendor_waiting_driver_title', [], $lang),
                __('order.client_vendor_waiting_driver_message', [], $lang),
            ];
        }

        if ($vendorDeliveryReady && ! $order->client_delivery_handoff_at && (bool) $order->delivery_at_vendor) {
            return [
                __('order.client_vendor_ready_for_pickup_title', [], $lang),
                __('order.client_vendor_ready_for_pickup_message', [], $lang),
            ];
        }

        if ($vendorPickupReceived && (bool) $order->delivery_at_vendor && ! $vendorDeliveryReady) {
            return [
                __('order.client_vendor_processing_title', [], $lang),
                __('order.client_vendor_processing_message', [], $lang),
            ];
        }

        if ((bool) $order->pickup_at_vendor && ! $vendorPickupReceived) {
            return [
                __('order.client_vendor_go_to_branch_title', [], $lang),
                __('order.client_vendor_go_to_branch_message', [], $lang),
            ];
        }

        return [
            __('order.client_vendor_tracking_title', [], $lang),
            __('order.client_vendor_tracking_message', [], $lang),
        ];
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
}
