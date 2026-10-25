<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransitionException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderStatusLog;

/**
 * Unified Two-Step Piece Handoff Service across Client, Driver, and Laundry.
 *
 * Rule:
 * The person holding the items declares: "I handed over the items" (declare_handoff).
 * The receiving party confirms: "I confirm receiving the items" (confirm_handoff).
 *
 * Types:
 * - client_to_driver: Client hands to Driver at home.
 * - driver_to_laundry: Driver hands to Laundry at branch.
 * - laundry_to_driver: Laundry hands to Driver after washing.
 * - driver_to_client: Driver hands to Client at home.
 * - client_to_laundry: Direct branch dropoff by client.
 * - laundry_to_client: Direct branch pickup by client.
 */
class OrderPieceHandoffService
{
    public const TYPE_CLIENT_TO_DRIVER = 'client_to_driver';
    public const TYPE_DRIVER_TO_LAUNDRY = 'driver_to_laundry';
    public const TYPE_LAUNDRY_TO_DRIVER = 'laundry_to_driver';
    public const TYPE_DRIVER_TO_CLIENT = 'driver_to_client';
    public const TYPE_CLIENT_TO_LAUNDRY = 'client_to_laundry';
    public const TYPE_LAUNDRY_TO_CLIENT = 'laundry_to_client';

    public function __construct(
        protected OrderStatusService $statusService
    ) {}

    /**
     * Determine what handoff stage the order is currently in, if any.
     */
    public function determineCurrentHandoffStage(Order $order): ?string
    {
        $status = $order->status;
        $pickupAtVendor = (bool) $order->pickup_at_vendor;
        $deliveryAtVendor = (bool) $order->delivery_at_vendor;

        // 1. Stage: Home pickup (Client -> Driver)
        // On the way: only after the client confirmed the pickup visit.
        // picked_up: always (legacy driver-first flow, or already collected).
        if (! $pickupAtVendor && ! $order->client_pickup_handoff_at) {
            if ($status === OrderStatus::ON_WAY_TO_PICKUP->value && $this->hasConfirmedPickupVisit($order)) {
                return self::TYPE_CLIENT_TO_DRIVER;
            }
            if ($status === OrderStatus::PICKED_UP->value) {
                return self::TYPE_CLIENT_TO_DRIVER;
            }
        }

        // 2. Stage: Branch dropoff by client (Client -> Laundry)
        // Client can self-dropoff OR declare handoff, laundry must confirm
        if ($pickupAtVendor && ! $order->vendor_pickup_received_at) {
            if (in_array($status, [OrderStatus::CONFIRMED->value, OrderStatus::PAYMENT_CONFIRMED->value, OrderStatus::DELIVERED_TO_BRANCH->value], true)) {
                return self::TYPE_CLIENT_TO_LAUNDRY;
            }
        }

        // 3. Stage: Delivery to branch by driver (Driver -> Laundry)
        if (! $pickupAtVendor && $order->client_pickup_handoff_at && ! $order->vendor_pickup_received_at) {
            if ($status === OrderStatus::PICKED_UP->value) {
                return self::TYPE_DRIVER_TO_LAUNDRY;
            }
        }

        // 4. Stage: Handover from laundry to delivery driver (Laundry -> Driver)
        if (! $deliveryAtVendor && $order->needsDeliveryDriver()) {
            if ($status === OrderStatus::DRIVER_DELIVERY_ACCEPTED->value && $order->vendor_handed_to_delivery_at === null) {
                return self::TYPE_LAUNDRY_TO_DRIVER;
            }
        }

        // 5. Stage: Delivery to client at home (Driver -> Client)
        if (! $deliveryAtVendor && $order->vendor_handed_to_delivery_at !== null && ! $order->client_delivery_handoff_at) {
            if (in_array($status, [OrderStatus::ON_WAY_TO_DELIVERY->value, OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::DELIVERED->value], true)) {
                return self::TYPE_DRIVER_TO_CLIENT;
            }
        }

        // 6. Stage: Branch pickup by client (Laundry -> Client)
        if ($deliveryAtVendor && ! $order->client_delivery_handoff_at) {
            if (in_array($status, [OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::COMPLETED->value, OrderStatus::DELIVERED_TO_BRANCH->value], true)) {
                return self::TYPE_LAUNDRY_TO_CLIENT;
            }
        }

        return null;
    }

    /**
     * Get sender role and receiver role for a given handoff type.
     */
    public function getHandoffRoles(string $type): array
    {
        return match ($type) {
            self::TYPE_CLIENT_TO_DRIVER => ['sender' => 'client', 'receiver' => 'driver'],
            self::TYPE_DRIVER_TO_LAUNDRY => ['sender' => 'driver', 'receiver' => 'laundry'],
            self::TYPE_LAUNDRY_TO_DRIVER => ['sender' => 'laundry', 'receiver' => 'driver'],
            self::TYPE_DRIVER_TO_CLIENT => ['sender' => 'driver', 'receiver' => 'client'],
            self::TYPE_CLIENT_TO_LAUNDRY => ['sender' => 'client', 'receiver' => 'laundry'],
            self::TYPE_LAUNDRY_TO_CLIENT => ['sender' => 'laundry', 'receiver' => 'client'],
            default => ['sender' => 'unknown', 'receiver' => 'unknown'],
        };
    }

    /**
     * Build unified handoff API structure for a specific viewer role.
     * Viewer role can be: 'client', 'driver', 'laundry' (or 'vendor').
     */
    public function buildHandoffPayload(Order $order, string $viewerRole): array
    {
        $viewerRole = $viewerRole === 'vendor' ? 'laundry' : $viewerRole;
        $currentStage = $this->determineCurrentHandoffStage($order);

        if (! $currentStage) {
            return [
                'requires_handoff_confirmation' => false,
                'handoff' => null,
                'available_actions' => [
                    'declare_handoff' => false,
                    'confirm_handoff' => false,
                    'handoff_type' => null,
                ],
            ];
        }

        $roles = $this->getHandoffRoles($currentStage);
        $senderRole = $roles['sender'];
        $receiverRole = $roles['receiver'];

        // Is there an active declared handoff waiting confirmation for this stage?
        $isDeclared = ($order->active_handoff_type === $currentStage && $order->active_handoff_declared_at !== null);

        $isSender = ($viewerRole === $senderRole);
        $isReceiver = ($viewerRole === $receiverRole);

        $canDeclare = $isSender && ! $isDeclared;
        $canConfirm = $isReceiver && $isDeclared;

        $labels = $this->getLocalizedLabels($currentStage, $order);

        return [
            'requires_handoff_confirmation' => true,
            'handoff' => [
                'type' => $currentStage,
                'status' => $isDeclared ? 'waiting_confirmation' : 'pending_declaration',
                'sender_role' => $senderRole,
                'receiver_role' => $receiverRole,
                'is_declared' => $isDeclared,
                'can_declare' => $canDeclare,
                'can_confirm' => $canConfirm,
                'declare_label' => $labels['declare_label'],
                'confirm_label' => $labels['confirm_label'],
                'waiting_label' => $labels['waiting_label'],
                'declared_at' => $order->active_handoff_declared_at?->toISOString(),
            ],
            'available_actions' => [
                'declare_handoff' => $canDeclare,
                'confirm_handoff' => $canConfirm,
                'handoff_type' => $currentStage,
            ],
        ];
    }

    /**
     * Merge two-step piece handoff with visit-response fields for client APIs.
     * Visit stays first: handoff is hidden until the client confirms the visit.
     *
     * @param  array<string, mixed>  $visitApi
     * @return array<string, mixed>
     */
    public function mergeClientPayload(Order $order, array $visitApi): array
    {
        $handoff = $this->buildHandoffPayload($order, 'client');
        $visitRequired = (bool) ($visitApi['requires_visit_response'] ?? false);
        $handoffObj = $visitRequired ? null : ($handoff['handoff'] ?? null);
        $canDeclare = (bool) ($handoffObj['can_declare'] ?? false);
        $canConfirm = (bool) ($handoffObj['can_confirm'] ?? false);
        $isDeclared = (bool) ($handoffObj['is_declared'] ?? false);

        $waitingFor = null;
        $requiredAction = null;

        if ($visitRequired) {
            $waitingFor = 'client';
        } elseif ($canDeclare || $canConfirm) {
            $waitingFor = 'client';
        } elseif ($isDeclared) {
            $waitingFor = match ($handoffObj['receiver_role'] ?? null) {
                'driver' => 'driver_handover',
                'laundry' => 'vendor',
                'client' => 'client',
                default => $handoffObj['receiver_role'] ?? null,
            };
            if ($waitingFor === 'driver_handover') {
                $requiredAction = 'driver_handover';
            }
        }

        return [
            'requires_handoff_confirmation' => $handoffObj !== null,
            'handoff' => $handoffObj,
            'available_actions' => $visitRequired
                ? ($visitApi['available_actions'] ?? [])
                : ($handoff['available_actions'] ?? []),
            'waiting_for' => $waitingFor,
            'required_action' => $requiredAction,
            'visit' => $visitApi['visit'] ?? null,
            'requires_visit_response' => $visitRequired,
        ];
    }

    /**
     * Step 1: Sender declares "I handed over the items" (أنا سلمت).
     */
    public function declareHandoff(Order $order, string $role, int $actorId, ?string $handoffType = null, ?string $notes = null): Order
    {
        $role = $role === 'vendor' ? 'laundry' : $role;
        $currentStage = $this->determineCurrentHandoffStage($order);

        if (! $currentStage) {
            throw new \LogicException(__('order.handoff_error_not_awaiting_confirmation'));
        }

        if ($handoffType && $handoffType !== $currentStage) {
            throw new \LogicException(__('order.handoff_type_mismatch', ['expected' => $currentStage, 'given' => $handoffType]));
        }

        $roles = $this->getHandoffRoles($currentStage);
        if ($roles['sender'] !== $role) {
            throw new \LogicException(__('order.handoff_not_sender_authorized'));
        }

        if ($order->active_handoff_type === $currentStage && $order->active_handoff_declared_at !== null) {
            throw new \LogicException(__('order.handoff_already_declared'));
        }

        // Handle pre-conditions or initial transitions if needed
        if ($currentStage === self::TYPE_CLIENT_TO_DRIVER) {
            // Client declared handoff to driver; driver confirms next (driver_handover).
        } elseif ($currentStage === self::TYPE_DRIVER_TO_LAUNDRY) {
            // Driver arrived at branch and declared handing over
            $order->update([
                'can_confirm_pickup_from_driver' => true,
            ]);
        } elseif ($currentStage === self::TYPE_LAUNDRY_TO_DRIVER) {
            // Laundry declared handing over cleaned clothes to driver
            $order->update([
                'can_confirm_handover_to_delivery' => true,
            ]);
        } elseif ($currentStage === self::TYPE_DRIVER_TO_CLIENT) {
            // Driver arrived at client home and declared handing over
            if ($order->status === OrderStatus::ON_WAY_TO_DELIVERY->value) {
                try {
                    $this->statusService->transitionTo($order, OrderStatus::WAITING_CLIENT_RECEIPT, [
                        'notes' => 'Driver arrived and declared handoff to client',
                        'changed_by' => $actorId,
                        'actor_type' => 'driver',
                        'actor_id' => $actorId,
                    ]);
                } catch (\Exception $e) {
                    // status might already be waiting_client_receipt
                }
            }
        }

        $order->update([
            'active_handoff_type' => $currentStage,
            'active_handoff_declared_by_role' => $role,
            'active_handoff_declared_by_id' => $actorId,
            'active_handoff_declared_at' => now(),
        ]);

        OrderStatusLog::create([
            'order_id' => $order->id,
            'status' => $order->status,
            'notes' => $notes ?? __('order.handoff_declared_log', ['role' => $role, 'type' => $currentStage]),
            'changed_by' => $actorId,
        ]);

        $order = $order->fresh();
        $this->notifyReceiverOfDeclaration($order, $currentStage);

        return $order;
    }

    /**
     * Step 2: Receiver confirms "I confirm receiving the items" (أؤكد الاستلام).
     */
    public function confirmHandoff(Order $order, string $role, int $actorId, ?string $handoffType = null, ?string $notes = null): Order
    {
        $role = $role === 'vendor' ? 'laundry' : $role;
        $currentStage = $this->determineCurrentHandoffStage($order);

        if (! $currentStage) {
            throw new \LogicException(__('order.handoff_error_not_awaiting_confirmation'));
        }

        if ($handoffType && $handoffType !== $currentStage) {
            throw new \LogicException(__('order.handoff_type_mismatch', ['expected' => $currentStage, 'given' => $handoffType]));
        }

        $roles = $this->getHandoffRoles($currentStage);
        if ($roles['receiver'] !== $role) {
            throw new \LogicException(__('order.handoff_not_receiver_authorized'));
        }

        // Must be declared first by sender
        // Exception: for client_to_laundry, allow confirmation if client used legacy flow (client_pickup_handoff_at is set)
        $legacyClientDropoffAllowed = ($currentStage === self::TYPE_CLIENT_TO_LAUNDRY && $order->client_pickup_handoff_at !== null);
        
        if (!$legacyClientDropoffAllowed && ($order->active_handoff_type !== $currentStage || $order->active_handoff_declared_at === null)) {
            throw new \LogicException(__('order.handoff_must_be_declared_first'));
        }

        // Perform actual order state transition based on handoff type
        switch ($currentStage) {
            case self::TYPE_CLIENT_TO_DRIVER:
                // Driver confirms receiving from client -> transition to picked_up
                if ($order->status !== OrderStatus::PICKED_UP->value) {
                    $this->statusService->transitionTo($order, OrderStatus::PICKED_UP, [
                        'notes' => $notes ?? 'Driver confirmed receipt of items from client',
                        'changed_by' => $actorId,
                        'actor_type' => 'driver',
                        'actor_id' => $actorId,
                    ]);
                }
                $order->update([
                    'client_pickup_handoff_at' => now(),
                ]);
                break;

            case self::TYPE_CLIENT_TO_LAUNDRY:
                // Laundry confirms receiving from client directly at branch -> transition to delivered_to_branch
                if ($order->status !== OrderStatus::DELIVERED_TO_BRANCH->value) {
                    $this->statusService->transitionTo($order, OrderStatus::DELIVERED_TO_BRANCH, [
                        'notes' => $notes ?? 'Laundry confirmed receipt of items from client at branch',
                        'changed_by' => $actorId,
                        'actor_type' => 'vendor',
                        'actor_id' => $actorId,
                    ]);
                }
                $order->update([
                    'client_pickup_handoff_at' => now(),
                    'vendor_pickup_received_at' => now(),
                ]);
                break;

            case self::TYPE_DRIVER_TO_LAUNDRY:
                // Laundry confirms receiving from driver -> transition to delivered_to_branch
                $this->statusService->transitionTo($order, OrderStatus::DELIVERED_TO_BRANCH, [
                    'notes' => $notes ?? 'Laundry confirmed receiving order from pickup driver',
                    'changed_by' => $actorId,
                    'actor_type' => 'vendor',
                    'actor_id' => $actorId,
                ]);
                $order->update([
                    'vendor_pickup_received_at' => now(),
                    'can_confirm_pickup_from_driver' => false,
                ]);
                break;

            case self::TYPE_LAUNDRY_TO_DRIVER:
                // Driver confirms receiving clean clothes from laundry
                $order->update([
                    'vendor_handed_to_delivery_at' => now(),
                    'can_confirm_handover_to_delivery' => false,
                ]);
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'status' => $order->status,
                    'notes' => $notes ?? 'Driver confirmed receiving clean items from laundry',
                    'changed_by' => $actorId,
                ]);
                break;

            case self::TYPE_DRIVER_TO_CLIENT:
                // Client confirms receiving items from delivery driver -> transition to delivered / completed
                $targetStatus = $order->delivery_at_vendor ? OrderStatus::COMPLETED : OrderStatus::DELIVERED;
                if ($this->statusService->canTransition($order, $targetStatus)) {
                    $this->statusService->transitionTo($order, $targetStatus, [
                        'notes' => $notes ?? 'Client confirmed delivery receipt from driver',
                        'changed_by' => $actorId,
                        'actor_type' => 'client',
                        'actor_id' => $actorId,
                    ]);
                }
                $order->update([
                    'client_delivery_handoff_at' => now(),
                    'actual_delivery_time' => now(),
                ]);
                $this->markCashOnDeliveryPaidIfNeeded($order->fresh());
                break;

            case self::TYPE_LAUNDRY_TO_CLIENT:
                // Client confirms receiving items at branch from laundry -> transition to completed
                if ($this->statusService->canTransition($order, OrderStatus::COMPLETED)) {
                    $this->statusService->transitionTo($order, OrderStatus::COMPLETED, [
                        'notes' => $notes ?? 'Client confirmed pickup receipt at laundry branch',
                        'changed_by' => $actorId,
                        'actor_type' => 'client',
                        'actor_id' => $actorId,
                    ]);
                }
                $order->update([
                    'client_delivery_handoff_at' => now(),
                    'vendor_client_delivery_handoff_at' => now(),
                    'actual_delivery_time' => now(),
                ]);
                $this->markCashOnDeliveryPaidIfNeeded($order->fresh());
                break;
        }

        // Reset active handoff state
        $order->update([
            'active_handoff_type' => null,
            'active_handoff_declared_by_role' => null,
            'active_handoff_declared_by_id' => null,
            'active_handoff_declared_at' => null,
        ]);

        return $order->fresh();
    }

    protected function getLocalizedLabels(string $type, Order $order): array
    {
        $lang = app()->getLocale();
        $isAr = ($lang === 'ar');

        return match ($type) {
            self::TYPE_CLIENT_TO_DRIVER => [
                'declare_label' => $isAr ? 'تسليم القطع للسائق' : 'Hand items to the driver',
                'confirm_label' => $isAr ? 'أؤكد الاستلام' : 'Confirm receipt',
                'waiting_label' => $isAr ? 'بانتظار تأكيد استلام السائق' : 'Waiting for driver confirmation',
            ],
            self::TYPE_DRIVER_TO_LAUNDRY => [
                'declare_label' => $isAr ? 'أنا سلمت الطلب للمغسلة' : 'I delivered the order to the laundry',
                'confirm_label' => $isAr ? 'أؤكد الاستلام' : 'Confirm receipt',
                'waiting_label' => $isAr ? 'بانتظار تأكيد استلام المغسلة' : 'Waiting for laundry confirmation',
            ],
            self::TYPE_LAUNDRY_TO_DRIVER => [
                'declare_label' => $isAr ? 'أنا سلمت الطلب للسائق' : 'I handed the order to the driver',
                'confirm_label' => $isAr ? 'أؤكد الاستلام' : 'Confirm receipt',
                'waiting_label' => $isAr ? 'بانتظار تأكيد استلام السائق' : 'Waiting for driver confirmation',
            ],
            self::TYPE_DRIVER_TO_CLIENT => [
                'declare_label' => $isAr ? 'أنا سلمت الطلب للعميل' : 'I delivered the order to the client',
                'confirm_label' => $isAr ? 'أؤكد الاستلام' : 'Confirm receipt',
                'waiting_label' => $isAr ? 'قام السائق بتسليم طلبك، يرجى تأكيد استلامك للطلب' : 'Driver delivered order, please confirm receipt',
            ],
            self::TYPE_CLIENT_TO_LAUNDRY => [
                'declare_label' => $isAr ? 'أنا سلمت الطلب للمغسلة' : 'I delivered the order to the laundry',
                'confirm_label' => $isAr ? 'أؤكد الاستلام' : 'Confirm receipt',
                'waiting_label' => $isAr ? 'تم تسليم الطلب للمغسلة، بانتظار تأكيد المغسلة' : 'Handed to laundry, waiting for laundry confirmation',
            ],
            self::TYPE_LAUNDRY_TO_CLIENT => [
                'declare_label' => $isAr ? 'أنا سلمت الطلب للعميل' : 'I handed the order to the client',
                'confirm_label' => $isAr ? 'أؤكد الاستلام' : 'Confirm receipt',
                'waiting_label' => $isAr ? 'المغسلة سلّمت طلبك، يرجى تأكيد الاستلام' : 'Laundry handed over your order, please confirm receipt',
            ],
            default => [
                'declare_label' => $isAr ? 'أنا سلّمت' : 'I handed over',
                'confirm_label' => $isAr ? 'أؤكد الاستلام' : 'Confirm receipt',
                'waiting_label' => $isAr ? 'بانتظار تأكيد الاستلام' : 'Waiting for confirmation',
            ],
        };
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

    protected function notifyReceiverOfDeclaration(Order $order, string $type): void
    {
        if ($type !== self::TYPE_CLIENT_TO_DRIVER) {
            return;
        }

        $driverId = (int) ($order->pickup_driver_id ?? $order->driver_id ?? 0);
        if ($driverId <= 0) {
            return;
        }

        $num = $order->order_number;
        app(OrderNotificationService::class)->sendToDriver(
            $order,
            $driverId,
            __('order.handoff_notify_driver_client_declared_title'),
            'Client handed over the order',
            __('order.handoff_notify_driver_client_declared_body', ['order_number' => $num]),
            __('order.handoff_notify_driver_client_declared_body_en', ['order_number' => $num]),
            'driver_handover'
        );
    }

    protected function markCashOnDeliveryPaidIfNeeded(Order $order): void
    {
        if (($order->payment_method ?? '') !== \App\Enums\PaymentMethod::CASH_ON_DELIVERY->value || $order->isPaid()) {
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
