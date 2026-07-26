<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransitionException;
use Illuminate\Support\Carbon;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;

class ClientOrderVisitService
{
    public function __construct(
        protected OrderStatusService $statusService,
        protected ClientOrderVisitNotificationService $notificationService
    ) {}

    /**
     * Client confirms readiness (driver visit) or receipt acknowledgment.
     * Does not change order status — use confirm-handoff or driver/vendor APIs for status transitions.
     */
    public function confirmVisit(Order $order, int $clientId): Order
    {
        $this->assertOrderOwner($order, $clientId);
        $context = $this->resolveVisitContext($order);
        $this->assertContext($context);

        if ($context['visit_type'] === 'receipt') {
            return $this->confirmReceipt($order, $clientId);
        }

        $visitType = $context['visit_type'];
        $update = ['client_visit_confirmed_at' => now()];

        if ($visitType === 'pickup') {
            $update['client_pickup_visit_confirmed_at'] = now();
            $update['driver_pickup_notified_client_at'] = null;
        } elseif ($visitType === 'delivery') {
            $update['client_delivery_visit_confirmed_at'] = now();
        }

        $order->update($update);
        $order = $order->fresh();

        $this->notificationService->notifyConfirmed($order, $this->notificationLegFor($visitType));

        return $order;
    }

    /**
     * Pickup leg: driver notified client they are on the way to collect laundry from the client.
     * Enables requires_visit_response once per pickup leg — never re-prompts after client confirmed.
     */
    public function enablePickupVisitResponseAfterDriverNotify(Order $order): Order
    {
        if ($this->hasConfirmedPickupVisit($order)) {
            return $order->fresh();
        }

        $order->update(['driver_pickup_notified_client_at' => now()]);

        return $order->fresh();
    }

    public function clearPickupVisitResponsePrompt(Order $order): void
    {
        if ($order->driver_pickup_notified_client_at !== null) {
            $order->update(['driver_pickup_notified_client_at' => null]);
        }
    }

    /**
     * Client acknowledges receipt — notification only; status changes via confirm-handoff.
     */
    public function confirmReceipt(Order $order, int $clientId): Order
    {
        $this->assertOrderOwner($order, $clientId);

        if (! OrderStatus::isReceiptConfirmStatus($order->status) || (bool) $order->delivery_at_vendor) {
            throw new \LogicException(__('order.visit_error_not_awaiting_receipt'));
        }

        $order->update(['client_visit_confirmed_at' => now()]);

        return $order->fresh();
    }

    /**
     * Client postpones pickup or delivery with reason and new scheduled time.
     */
    public function postponeVisit(Order $order, int $clientId, string $reason, Carbon $rescheduledAt): Order
    {
        $this->assertOrderOwner($order, $clientId);
        $context = $this->resolveVisitContext($order);
        $this->assertContext($context);

        if (! $context['can_postpone']) {
            throw new \LogicException(__('order.visit_error_postpone_not_available'));
        }

        $leg = $this->notificationLegFor($context['visit_type']);
        $targetStatus = in_array($leg, ['pickup', 'branch_dropoff'], true)
            ? OrderStatus::CLIENT_POSTPONED_PICKUP
            : OrderStatus::CLIENT_POSTPONED_DELIVERY;

        if (! $this->statusService->canTransition($order, $targetStatus)) {
            throw new InvalidStatusTransitionException(
                OrderStatus::from($order->status),
                $targetStatus,
                $order
            );
        }

        $timeField = in_array($leg, ['pickup', 'branch_dropoff'], true)
            ? 'pickup_time'
            : 'estimated_delivery_time';

        $pickupDriverId = (int) ($order->pickup_driver_id ?? 0);
        $deliveryDriverId = (int) ($order->delivery_driver_id ?? 0);

        $update = [
            $timeField => $rescheduledAt,
            'client_postpone_reason' => $reason,
            'client_postponed_at' => now(),
            'client_visit_confirmed_at' => null,
        ];

        if ($leg === 'pickup') {
            $update['client_pickup_visit_confirmed_at'] = null;
            $update['driver_pickup_notified_client_at'] = null;
        } else {
            $update['client_delivery_visit_confirmed_at'] = null;
        }

        $order->update($update);

        if ($leg === 'pickup') {
            $this->releasePickupDriver($order);
        } elseif ($leg === 'delivery') {
            $this->releaseDeliveryDriver($order);
        }

        $this->statusService->transitionTo($order, $targetStatus, [
            'notes' => __('order.visit_log_postponed', [
                'leg' => __('order.visit_log_leg_'.$leg),
                'reason' => $reason,
                'time' => $rescheduledAt->toDateTimeString(),
            ]),
            'changed_by' => $clientId,
        ]);

        $this->notificationService->notifyPostponed(
            $order->fresh(),
            $leg,
            $pickupDriverId ?: null,
            $deliveryDriverId ?: null
        );

        return $order->fresh();
    }

    public function canRespond(Order $order): bool
    {
        return $this->resolveVisitContext($order) !== null;
    }

    /**
     * Visit-response flags for client API payloads (on-the-way, order show, etc.).
     *
     * @return array<string, mixed>
     */
    public function apiResponseFields(Order $order): array
    {
        $context = $this->resolveVisitContext($order);
        $actions = $this->availableActions($order);

        return [
            'requires_visit_response' => $context !== null,
            'available_actions' => $actions,
            'visit' => $context ? [
                'type' => $context['visit_type'],
                'can_postpone' => (bool) ($context['can_postpone'] ?? false),
                'confirm_label' => $context['confirm_label'],
                'postpone_label' => $context['postpone_label'] ?? null,
                'endpoint' => '/api/v1/user/orders/'.$order->id.'/visit-response',
                'confirm_action' => 'confirm',
                'postpone_action' => 'postpone',
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function availableActions(Order $order): array
    {
        $context = $this->resolveVisitContext($order);
        if (! $context) {
            return [];
        }

        return [
            'confirm' => true,
            'postpone' => $context['can_postpone'],
            'visit_type' => $context['visit_type'],
            'confirm_label' => $context['confirm_label'],
            'postpone_label' => $context['can_postpone'] ? $context['postpone_label'] : null,
            'endpoint' => '/api/v1/user/orders/'.$order->id.'/visit-response',
            'confirm_action' => 'confirm',
            'postpone_action' => 'postpone',
        ];
    }

    /**
     * @return array{visit_type: string, can_postpone: bool, confirm_label: string, postpone_label: string}|null
     */
    public function resolveVisitContext(Order $order): ?array
    {
        $status = $order->status;
        $deliveryAtVendor = (bool) $order->delivery_at_vendor;

        if (
            ! $deliveryAtVendor
            && OrderStatus::isReceiptConfirmStatus($status)
            && ! $this->hasConfirmedReceiptVisit($order)
        ) {
            return [
                'visit_type' => 'receipt',
                'can_postpone' => false,
                'confirm_label' => __('order.visit_confirm_label_receipt'),
                'postpone_label' => '',
            ];
        }

        // Branch drop-off/pick-up at the laundry: use confirm-handoff (give/receive_from_laundry), not visit-response.

        if (
            ! $deliveryAtVendor
            && OrderStatus::isDeliveryVisitResponseStatus($status)
            && ! $this->hasConfirmedDeliveryVisit($order)
        ) {
            return [
                'visit_type' => 'delivery',
                'can_postpone' => true,
                'confirm_label' => __('order.visit_confirm_label_delivery'),
                'postpone_label' => __('order.visit_postpone_label_delivery'),
            ];
        }

        if (
            $this->isPickupVisitResponseEligible($order)
            && ! $this->hasConfirmedPickupVisit($order)
        ) {
            return [
                'visit_type' => 'pickup',
                'can_postpone' => true,
                'confirm_label' => __('order.visit_confirm_label_pickup'),
                'postpone_label' => __('order.visit_postpone_label_pickup'),
            ];
        }

        return null;
    }

    protected function isPickupVisitResponseEligible(Order $order): bool
    {
        if ((bool) $order->pickup_at_vendor) {
            return false;
        }

        $status = $order->status;

        if (OrderStatus::isPickupVisitResponseStatus($status)) {
            return true;
        }

        return $order->driver_pickup_notified_client_at !== null
            && in_array($status, ClientDriverOnTheWayNotificationService::pickupDriverCanNotifyStatuses(), true);
    }

    protected function hasConfirmedPickupVisit(Order $order): bool
    {
        if (! $order->client_pickup_visit_confirmed_at) {
            return false;
        }

        if (
            $order->client_postponed_at
            && $order->client_postponed_at->gt($order->client_pickup_visit_confirmed_at)
        ) {
            return false;
        }

        return true;
    }

    protected function hasConfirmedDeliveryVisit(Order $order): bool
    {
        if (! $order->client_delivery_visit_confirmed_at) {
            return false;
        }

        if (
            $order->client_postponed_at
            && $order->client_postponed_at->gt($order->client_delivery_visit_confirmed_at)
        ) {
            return false;
        }

        return true;
    }

    protected function hasConfirmedReceiptVisit(Order $order): bool
    {
        if (! $order->client_visit_confirmed_at) {
            return false;
        }

        if (
            $order->client_postponed_at
            && $order->client_postponed_at->gt($order->client_visit_confirmed_at)
        ) {
            return false;
        }

        return true;
    }

    protected function notificationLegFor(string $visitType): string
    {
        return match ($visitType) {
            'pickup', 'branch_dropoff' => 'pickup',
            'delivery', 'branch_pickup', 'receipt' => 'delivery',
            default => 'pickup',
        };
    }

    protected function assertOrderOwner(Order $order, int $clientId): void
    {
        if ((int) $order->client_id !== $clientId) {
            throw new \InvalidArgumentException(__('order.handoff_error_order_not_owned'));
        }
    }

    protected function assertContext(?array $context): void
    {
        if (! $context) {
            throw new \LogicException(__('order.visit_error_not_awaiting_response'));
        }
    }

    protected function releasePickupDriver(Order $order): void
    {
        $pickupDriverId = $order->pickup_driver_id;
        if (! $pickupDriverId) {
            return;
        }

        Driver::where('id', $pickupDriverId)->update(['is_available' => true]);

        $driverIdUpdate = (int) $order->driver_id === (int) $pickupDriverId
            ? $order->delivery_driver_id
            : $order->driver_id;

        $order->update([
            'pickup_driver_id' => null,
            'driver_id' => $driverIdUpdate,
        ]);
    }

    protected function releaseDeliveryDriver(Order $order): void
    {
        $deliveryDriverId = $order->delivery_driver_id;
        if (! $deliveryDriverId) {
            return;
        }

        Driver::where('id', $deliveryDriverId)->update(['is_available' => true]);

        $driverIdUpdate = (int) $order->driver_id === (int) $deliveryDriverId
            ? $order->pickup_driver_id
            : $order->driver_id;

        $order->update([
            'delivery_driver_id' => null,
            'driver_id' => $driverIdUpdate,
        ]);
    }
}
