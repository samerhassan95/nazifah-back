<?php

namespace App\Services;

use App\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;
use Modules\Order\Exceptions\InsufficientWalletBalanceException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderItemAdditionalService;
use Modules\Order\Models\OrderPayment;
use Modules\Order\Services\OrderPaymentService;
use Modules\Order\Support\OrderItemGrouper;
use Modules\Payment\Models\PaymentTransaction;

class VendorOrderReviewService
{
    /**
     * Review order items by vendor
     *
     * @param  array  $itemsReview  [
     *                              ['item_id' => 1, 'status' => 'accepted'],
     *                              ['item_id' => 2, 'status' => 'rejected', 'notes' => 'Out of stock'],
     *                              ['item_id' => 3, 'status' => 'modified', 'quantity' => 5, 'unit_price' => 10, 'notes' => 'Price changed']
     *                              ]
     */
    public function reviewOrderItems(Order $order, array $itemsReview, ?string $generalNotes = null): array
    {
        DB::beginTransaction();

        try {
            if (! in_array($order->status, [OrderStatus::PENDING->value, OrderStatus::BRANCH_REVIEW->value])) {
                return [
                    'success' => false,
                    'message' => __('order.order_must_be_pending_to_review'),
                ];
            }

            $order->loadMissing([
                'items.piece',
                'items.service',
                'items.additionalServicesPivot.serviceAddition',
            ]);

            $orderItemIds = $order->items->pluck('id')->map(fn ($id) => (int) $id)->all();
            $reviewItemIds = collect($itemsReview)->pluck('item_id')->map(fn ($id) => (int) $id)->all();
            $invalidItemIds = array_diff($reviewItemIds, $orderItemIds);

            if (! empty($invalidItemIds)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => __('order.invalid_item_ids', ['ids' => implode(', ', $invalidItemIds)]),
                    'invalid_item_ids' => $invalidItemIds,
                ];
            }

            // Rejecting/accepting a piece line must apply to ALL main services on that piece.
            $itemsReview = $this->expandReviewsToPieceServiceSiblings($order, $itemsReview);

            foreach ($itemsReview as $review) {
                if (isset($review['additional_services']) && is_array($review['additional_services'])) {
                    $item = OrderItem::find($review['item_id']);
                    $itemAdditionalServiceIds = \Modules\Order\Models\OrderItemAdditionalService::where('order_item_id', $item->id)
                        ->pluck('service_addition_id')
                        ->toArray();

                    $reviewAdditionalServiceIds = collect($review['additional_services'])->pluck('service_addition_id')->toArray();
                    $invalidAdditionalServiceIds = array_diff($reviewAdditionalServiceIds, $itemAdditionalServiceIds);

                    if (! empty($invalidAdditionalServiceIds)) {
                        DB::rollBack();

                        return [
                            'success' => false,
                            'message' => __('order.invalid_additional_service_ids', [
                                'item_id' => $review['item_id'],
                                'ids' => implode(', ', $invalidAdditionalServiceIds),
                            ]),
                            'invalid_additional_service_ids' => $invalidAdditionalServiceIds,
                            'item_id' => $review['item_id'],
                        ];
                    }
                }
            }

            if (! $order->original_total_amount) {
                $order->update([
                    'original_total_amount' => $order->total_amount,
                    'original_final_amount' => $order->final_amount,
                ]);
            }

            $hasRejections = false;
            $hasModifications = false;
            $newTotalAmount = 0;
            $rejectedServiceAdditions = [];

            $allOrderItems = OrderItem::where('order_id', $order->id)->get();
            $reviewedItemIds = collect($itemsReview)->pluck('item_id')->map(fn ($id) => (int) $id)->all();

            foreach ($itemsReview as $review) {
                $item = OrderItem::where('order_id', $order->id)
                    ->where('id', $review['item_id'])
                    ->first();

                if (! $item) {
                    continue;
                }

                if (! $item->original_quantity) {
                    $item->update([
                        'original_quantity' => $item->quantity,
                        'original_unit_price' => $item->unit_price,
                        'original_total_price' => $item->total_price,
                    ]);
                }

                $status = $review['status'] ?? 'accepted';
                $item->vendor_status = $status;
                $item->vendor_notes = $review['notes'] ?? null;

                // Handle additional services review
                $additionalServicesTotal = 0;
                if (isset($review['additional_services']) && is_array($review['additional_services'])) {
                    foreach ($review['additional_services'] as $addServiceReview) {
                        $additionalService = \Modules\Order\Models\OrderItemAdditionalService::where('order_item_id', $item->id)
                            ->where('service_addition_id', $addServiceReview['service_addition_id'])
                            ->first();

                        if ($additionalService) {
                            $addServiceStatus = $addServiceReview['status'] ?? 'accepted';
                            $additionalService->vendor_status = $addServiceStatus;
                            $additionalService->vendor_notes = $addServiceReview['notes'] ?? null;
                            $additionalService->save();

                            // Only add to total if accepted
                            if ($addServiceStatus === 'accepted') {
                                $additionalServicesTotal += $additionalService->price * $additionalService->quantity;
                            } else {
                                $hasRejections = true;
                                // Add to rejected list
                                $serviceAddition = $additionalService->serviceAddition;
                                if ($serviceAddition) {
                                    $rejectedServiceAdditions[] = [
                                        'item_id' => $item->id,
                                        'service_addition_id' => $serviceAddition->id,
                                        'service_addition_name' => $serviceAddition->name,
                                        'price' => (float) $additionalService->price,
                                        'quantity' => (int) $additionalService->quantity,
                                        'notes' => $additionalService->vendor_notes,
                                    ];
                                }
                            }
                        }
                    }
                } else {
                    // If no additional services review provided:
                    // - accepted/modified → accept additions by default
                    // - rejected piece → reject all additions on that piece too
                    $additionalServices = OrderItemAdditionalService::where('order_item_id', $item->id)->get();
                    foreach ($additionalServices as $additionalService) {
                        if ($status === 'rejected') {
                            $additionalService->vendor_status = 'rejected';
                            $additionalService->save();
                            $hasRejections = true;
                        } else {
                            if (! $additionalService->vendor_status) {
                                $additionalService->vendor_status = 'accepted';
                                $additionalService->save();
                            }
                            if ($additionalService->vendor_status === 'accepted') {
                                $additionalServicesTotal += $additionalService->price * $additionalService->quantity;
                            }
                        }
                    }
                }

                switch ($status) {
                    case 'accepted':
                        // Accepted total = piece+service only + accepted additions (item->total_price already includes all additions, so avoid double-count)
                        $acceptedItemTotal = ((float) $item->piece_price + (float) $item->service_price) * $item->quantity + $additionalServicesTotal;
                        $newTotalAmount += $acceptedItemTotal;
                        // Update stored item prices so all APIs show the updated amount for this line
                        $item->unit_price = $item->quantity > 0 ? round($acceptedItemTotal / $item->quantity, 2) : 0;
                        $item->total_price = round($acceptedItemTotal, 2);
                        break;

                    case 'rejected':
                        $hasRejections = true;
                        // Don't add to total; optional: set total_price to 0 so line reflects no charge
                        $item->total_price = 0;
                        $item->unit_price = 0;
                        break;

                    case 'modified':
                        $hasModifications = true;
                        $modifiedQuantity = (int) ($review['quantity'] ?? $item->quantity);
                        // For expanded sibling rows, keep each service's own base price unless this
                        // row is the explicitly reviewed primary item_id.
                        $isExplicitReview = (int) ($review['explicit_item_id'] ?? $review['item_id']) === (int) $item->id;
                        $modifiedUnitPrice = $isExplicitReview
                            ? (float) ($review['unit_price'] ?? ((float) $item->piece_price + (float) $item->service_price))
                            : ((float) $item->piece_price + (float) $item->service_price);
                        $modifiedItemTotal = ($modifiedUnitPrice * $modifiedQuantity) + $additionalServicesTotal;
                        $modifiedTotalPrice = round($modifiedItemTotal, 2);

                        $item->modified_quantity = $modifiedQuantity;
                        $item->modified_unit_price = $modifiedQuantity > 0 ? round($modifiedItemTotal / $modifiedQuantity, 2) : 0;
                        $item->modified_total_price = $modifiedTotalPrice;

                        $newTotalAmount += $modifiedItemTotal;
                        // Update stored item prices so all APIs show the new amount for this line
                        $item->quantity = $modifiedQuantity;
                        $item->unit_price = $modifiedQuantity > 0 ? round($modifiedItemTotal / $modifiedQuantity, 2) : 0;
                        $item->total_price = round($modifiedItemTotal, 2);
                        break;
                }

                $item->save();
            }

            // Now add items that were NOT reviewed (they should be counted as accepted)
            foreach ($allOrderItems as $item) {
                if (! in_array($item->id, $reviewedItemIds)) {
                    // Item was not reviewed, mark it as accepted and count with correct price (piece+service+accepted additions only)
                    if (! $item->original_quantity) {
                        $item->update([
                            'original_quantity' => $item->quantity,
                            'original_unit_price' => $item->unit_price,
                            'original_total_price' => $item->total_price,
                        ]);
                    }

                    $item->vendor_status = 'accepted';

                    $additionalServicesTotal = 0;
                    $additionalServices = OrderItemAdditionalService::where('order_item_id', $item->id)->get();
                    foreach ($additionalServices as $additionalService) {
                        if (! $additionalService->vendor_status) {
                            $additionalService->vendor_status = 'accepted';
                            $additionalService->save();
                        }
                        if ($additionalService->vendor_status === 'accepted') {
                            $additionalServicesTotal += $additionalService->price * $additionalService->quantity;
                        }
                    }

                    $acceptedItemTotal = ((float) $item->piece_price + (float) $item->service_price) * $item->quantity + $additionalServicesTotal;
                    $newTotalAmount += $acceptedItemTotal;
                    // Update stored item prices so all APIs show the same amount
                    $item->unit_price = $item->quantity > 0 ? round($acceptedItemTotal / $item->quantity, 2) : 0;
                    $item->total_price = round($acceptedItemTotal, 2);
                    $item->save();
                }
            }

            // Always recalculate and update order amounts so APIs return the updated totals (after accept/reject)
            $discountAmount = $this->recalculateDiscount($order, $newTotalAmount);
            $deliveryFee = (float) $order->delivery_fee;
            $pricingTotals = Order::calculatePricingTotals($newTotalAmount, $discountAmount, $deliveryFee);
            $taxAmount = $pricingTotals['tax_amount'];
            $finalAmount = $pricingTotals['final_amount'];

            $order->update([
                'total_amount' => round($newTotalAmount, 2),
                'discount_amount' => round($discountAmount, 2),
                'tax_amount' => round($taxAmount, 2),
                'final_amount' => $finalAmount,
                'vendor_reviewed' => true,
                'vendor_reviewed_at' => now(),
                'vendor_review_notes' => $generalNotes,
            ]);

            if ($hasRejections || $hasModifications) {
                $statusService = app(\App\Services\OrderStatusService::class);
                if (OrderStatus::from($order->status) === OrderStatus::PENDING) {
                    $statusService->transitionTo($order, OrderStatus::BRANCH_REVIEW, [
                        'notes' => 'Vendor reviewed order with modifications. Waiting for client approval.',
                        'changed_by' => auth('sanctum')->id() ?? null,
                    ]);
                }
            } else {
                $order->update([
                    'client_approved' => true,
                    'client_approved_at' => now(),
                ]);
                $statusService = app(\App\Services\OrderStatusService::class);
                if (OrderStatus::from($order->status) === OrderStatus::PENDING) {
                    $statusService->transitionTo($order, OrderStatus::BRANCH_REVIEW, [
                        'notes' => 'Vendor accepted all items.',
                        'changed_by' => auth('sanctum')->id() ?? null,
                        // Internal hop before auto-confirm — do not notify "order reviewed".
                        'skip_notifications' => true,
                    ]);
                }
                $statusService->transitionTo($order, OrderStatus::CONFIRMED, [
                    'notes' => 'All items accepted — auto-confirmed.',
                    'changed_by' => auth('sanctum')->id() ?? null,
                    'auto_confirmed' => true,
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => $hasRejections || $hasModifications
                    ? __('order.order_reviewed_with_modifications')
                    : __('order.order_approved_successfully'),
                'order' => $order->fresh(['items.additionalServicesPivot.serviceAddition', 'items.piece', 'items.service']),
                'requires_client_approval' => $hasRejections || $hasModifications,
                'rejected_service_additions' => $rejectedServiceAdditions,
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => $e instanceof \App\Exceptions\InvalidStatusTransitionException
                    ? $e->userMessage()
                    : __('order.failed_to_review_order_generic'),
            ];
        }
    }

    /**
     * Client approves vendor modifications.
     *
     * Reconciles the price difference the vendor's review introduced against what the
     * customer already paid up-front (source baseline = original_final_amount):
     *   - reduced total (rejected/reduced items) -> refund the difference to the
     *     original payment method, then confirm;
     *   - raised total (a `modified` line) -> collect the difference via surcharge;
     *     confirm ONLY after the surcharge is fully paid (wallet settles in-request;
     *     card/gateway confirms later via payment callback).
     * COD / not-yet-paid orders settle the full final amount later, so nothing is
     * charged or refunded here — approval is confirmed immediately.
     *
     * @param  list<string>|null  $paymentMethods  Methods chosen to settle a surcharge.
     */
    public function clientApproveModifications(Order $order, ?array $paymentMethods = null): array
    {
        DB::beginTransaction();

        try {
            if ($order->status !== OrderStatus::BRANCH_REVIEW->value) {
                return [
                    'success' => false,
                    'message' => __('order.order_not_pending_approval'),
                ];
            }

            $delta = $this->reviewedPaymentDelta($order);
            $paidUpfront = $order->isPaid() && ! $order->isCashOnDelivery();

            // Paid up-front and the vendor RAISED the total: the customer must settle
            // the difference. With no chosen method we can't charge, so ask them to pay
            // before anything is applied or confirmed.
            if ($paidUpfront && $delta > 0.005 && empty($paymentMethods)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'payment_required' => true,
                    'amount_due' => $delta,
                    'available_methods' => app(OrderPaymentService::class)->allowedSurchargeMethods(),
                    'message' => __('order.update_requires_surcharge'),
                ];
            }

            $reconciliation = ['type' => 'none', 'amount' => 0.0];
            $payment = null;
            $awaitingPayment = false;

            // COD can optionally settle a positive delta online (visa/wallet) when methods
            // are provided. Otherwise confirmation proceeds and cash is due at delivery.
            $onlineIncrease = $delta > 0.005
                && ! empty($paymentMethods)
                && ($paidUpfront || $order->isCashOnDelivery());

            if (($paidUpfront || $order->isCashOnDelivery()) && $delta < -0.005) {
                // COD hasn't actually been charged, so refundDecrease() won't issue a
                // cash refund for it — it lowers the still-uncollected COD leg's
                // recorded amount instead (reduceCodLegAmount), keeping the payment
                // breakdown in sync with the reduced final_amount. Paid-upfront orders
                // still get a real refund to their original payment method.
                $expectedRefund = round(-$delta, 2);
                app(OrderPaymentService::class)->refundDecrease(
                    $order,
                    $expectedRefund,
                    'Vendor review reduced order total'
                );
                $order->update(['amount_remaining' => 0]);
                $reconciliation = ['type' => 'refund', 'amount' => $expectedRefund];
            } elseif ($onlineIncrease) {
                $surcharge = $this->collectReviewSurcharge($order, $delta, $paymentMethods);
                if (! $surcharge['success']) {
                    DB::rollBack();

                    return $surcharge;
                }
                $payment = $surcharge['payment'];
                $reconciliation = ['type' => 'surcharge', 'amount' => round($delta, 2)];
                // Gateway / staged legs: customer must finish payment before approval.
                $awaitingPayment = (bool) ($payment['staged'] ?? false)
                    || ! empty($payment['gateway_payments'] ?? []);
            }

            if ($awaitingPayment) {
                // Keep branch_review until surcharge settles (callback → finalizeClientApproval).
                DB::commit();

                return [
                    'success' => true,
                    'awaiting_payment' => true,
                    'message' => __('order.modifications_awaiting_payment'),
                    'order' => $order->fresh(['items']),
                    'reconciliation' => $reconciliation,
                    'payment' => $payment,
                ];
            }

            $this->finalizeClientApproval($order, 'Client approved vendor modifications.');

            DB::commit();

            return [
                'success' => true,
                'awaiting_payment' => false,
                'message' => __('order.modifications_approved'),
                'order' => $order->fresh(['items']),
                'reconciliation' => $reconciliation,
                'payment' => $payment,
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => $e instanceof \App\Exceptions\InvalidStatusTransitionException
                    ? $e->userMessage()
                    : __('order.failed_to_approve_modifications_generic'),
            ];
        }
    }

    /**
     * Apply modified lines + mark client approved + transition to confirmed.
     * Idempotent when already past branch_review / already client_approved.
     */
    public function finalizeClientApproval(Order $order, string $notes = 'Client approved vendor modifications.'): void
    {
        $order = $order->fresh(['items']) ?? $order;

        if ($order->status !== OrderStatus::BRANCH_REVIEW->value) {
            return;
        }

        foreach ($order->items as $item) {
            if ($item->vendor_status === 'modified' && ! $item->client_approved_modification) {
                $item->update([
                    'quantity' => $item->modified_quantity,
                    'unit_price' => $item->modified_unit_price,
                    'total_price' => $item->modified_total_price,
                    'client_approved_modification' => true,
                ]);
            }
        }

        if (! $order->client_approved) {
            $order->update([
                'client_approved' => true,
                'client_approved_at' => now(),
            ]);
        }

        $statusService = app(\App\Services\OrderStatusService::class);
        $statusService->transitionTo($order, OrderStatus::CONFIRMED, [
            'notes' => $notes,
            'changed_by' => $order->client_id,
        ]);
    }

    /**
     * Difference the vendor's review introduced: reviewed final_amount minus the
     * amount the customer was originally charged (captured on first review).
     */
    private function reviewedPaymentDelta(Order $order): float
    {
        $baseline = (float) ($order->original_final_amount ?? $order->final_amount);

        return round((float) $order->final_amount - $baseline, 2);
    }

    /**
     * Collect a review-induced surcharge on an already-paid order, reusing the same
     * split-leg + modification-intent machinery as a customer edit. The vendor review
     * has already applied the new total, so the intent carries empty staged pricing —
     * it only tracks and settles the surcharge for the delta.
     *
     * @param  list<string>  $paymentMethods
     * @return array{success: bool, payment?: array<string, mixed>, message?: string, payment_required?: bool, amount_due?: float, available_methods?: array<int, string>, wallet_balance?: float}
     */
    private function collectReviewSurcharge(Order $order, float $delta, array $paymentMethods): array
    {
        $ops = app(OrderPaymentService::class);
        $client = $order->client;
        $walletBalance = $ops->availableWalletBalance((int) $order->client_id);

        $alloc = $ops->allocateSurcharge($paymentMethods, $delta, $walletBalance);
        if ($alloc['error']) {
            return [
                'success' => false,
                'payment_required' => true,
                'amount_due' => round($delta, 2),
                'available_methods' => $ops->allowedSurchargeMethods(),
                'message' => __($alloc['error'], $alloc['params'] ?? []),
            ];
        }

        // Empty staged pricing: final_amount was already committed by the vendor review,
        // so applying the intent must be a no-op on the order — it just resolves once
        // the surcharge is paid.
        $intent = $ops->createModificationIntent(
            $order,
            number_format($delta, 2, '.', ''),
            (float) $order->total_amount,
            (float) $order->final_amount,
            []
        );

        try {
            $settle = $ops->settleSplitLegs($order, $alloc['legs'], $client, [
                'is_surcharge' => true,
                'original_method' => $order->payment_method,
                'modification_intent_id' => $intent->id,
                'meta' => ['reason' => 'vendor_review_surcharge'],
            ]);
        } catch (InsufficientWalletBalanceException $e) {
            return [
                'success' => false,
                'payment_required' => true,
                'amount_due' => round($delta, 2),
                'wallet_balance' => $e->available,
                'available_methods' => $ops->allowedSurchargeMethods(),
                'message' => __('order.insufficient_wallet_balance_short'),
            ];
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'message' => __('order.payment_init_failed'),
            ];
        }

        $gatewayPayments = $settle['gateway_payments'];

        // Wallet / COD settle in-request (no gateway link): resolve the intent now so
        // it doesn't linger pending. Gateway legs resolve later via the callback.
        if (empty($gatewayPayments)) {
            $paidLeg = OrderPayment::where('modification_intent_id', $intent->id)
                ->where('status', OrderPayment::STATUS_PAID)
                ->whereNotNull('payment_transaction_id')
                ->first();

            $tx = $paidLeg ? PaymentTransaction::find($paidLeg->payment_transaction_id) : null;
            $ops->applyModificationIntentIfFullyPaid($intent->fresh(), $tx);
        }

        return [
            'success' => true,
            'payment' => $ops->buildSurchargePaymentResponse(
                $order,
                $intent->id,
                $gatewayPayments,
                round($delta, 2),
                ! empty($gatewayPayments)
            ),
        ];
    }

    /**
     * Client rejects vendor modifications and cancels order
     */
    public function clientRejectModifications(Order $order, ?string $reason = null): array
    {
        DB::beginTransaction();

        try {
            if ($order->status !== OrderStatus::BRANCH_REVIEW->value) {
                return [
                    'success' => false,
                    'message' => __('order.order_not_pending_approval'),
                ];
            }

            $statusService = app(\App\Services\OrderStatusService::class);
            $statusService->transitionTo($order, OrderStatus::CANCELLED, [
                'notes' => 'Client rejected vendor modifications',
                'reason' => $reason ?? 'Client rejected vendor modifications',
                'changed_by' => $order->client_id,
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => __('order.modifications_rejected'),
                'order' => $order->fresh(),
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => $e instanceof \App\Exceptions\InvalidStatusTransitionException
                    ? $e->userMessage()
                    : __('order.failed_to_reject_modifications_generic'),
            ];
        }
    }

    /**
     * Recalculate discount based on new total (rounded to 2 decimals for storage)
     */
    private function recalculateDiscount(Order $order, float $newTotal): float
    {
        if ($order->discount) {
            return app(\Modules\Discount\Services\DiscountService::class)
                ->amountForOrderTotal($order->discount, $newTotal);
        }

        if (! $order->original_total_amount || (float) $order->original_total_amount == 0) {
            return 0;
        }
        $discountPercentage = ((float) $order->discount_amount / (float) $order->original_total_amount) * 100;

        return round(($newTotal * $discountPercentage) / 100, 2);
    }

    /**
     * Recalculate tax based on new total (rounded to 2 decimals for storage)
     */
    private function recalculateTax(Order $order, float $amountAfterDiscount): float
    {
        $taxRate = Order::getTaxRate();

        return round(($amountAfterDiscount * $taxRate) / 100, 2);
    }

    private function sendClientApprovalNotification(Order $order): void
    {
        $acceptedCount = $order->items->where('vendor_status', 'accepted')->count();
        $rejectedCount = $order->items->where('vendor_status', 'rejected')->count();
        $modifiedCount = $order->items->where('vendor_status', 'modified')->count();

        $messageAr = "قامت المغسلة بمراجعة طلبك #{$order->order_number}. ";
        $messageEn = "The laundry has reviewed your order #{$order->order_number}. ";

        if ($rejectedCount > 0 && $acceptedCount > 0) {
            $messageAr .= 'المغسلة تقدم بعض الخدمات فقط. يرجى مراجعة العناصر غير المتوفرة.';
            $messageEn .= 'The laundry offers only some services. Please review the unavailable items.';
        } elseif ($rejectedCount > 0) {
            $messageAr .= 'المغسلة لا تقدم جميع الخدمات. يرجى مراجعة العناصر غير المتوفرة.';
            $messageEn .= 'The laundry does not offer all services. Please review the unavailable items.';
        } elseif ($modifiedCount > 0) {
            $messageAr .= 'تم تعديل بعض العناصر. يرجى المراجعة والموافقة.';
            $messageEn .= 'Some items have been modified. Please review and approve.';
        }

        app(OrderNotificationService::class)->sendToClient(
            $order,
            'تحديثات على طلبك',
            'Updates on Your Order',
            $messageAr,
            $messageEn,
            'order_reviewed',
            [
                'accepted_count' => $acceptedCount,
                'rejected_count' => $rejectedCount,
                'modified_count' => $modifiedCount,
            ]
        );
    }

    private function sendOrderApprovedNotification(Order $order): void
    {
        app(OrderNotificationService::class)->sendToClient(
            $order,
            'تم قبول طلبك',
            'Order Accepted',
            "تم قبول طلبك #{$order->order_number}. يمكنك الآن الدفع.",
            "Your order #{$order->order_number} has been accepted. You can now proceed to payment.",
            'order_approved',
        );
    }

    private function sendVendorApprovalNotification(Order $order): void
    {
        app(OrderNotificationService::class)->sendToVendorBranch(
            $order,
            'وافق العميل على التعديلات',
            'Client Approved Modifications',
            "وافق العميل على التعديلات للطلب #{$order->order_number}",
            "Client approved modifications for order #{$order->order_number}",
            'client_approved_modifications',
        );
    }

    private function sendVendorRejectionNotification(Order $order): void
    {
        app(OrderNotificationService::class)->sendToVendorBranch(
            $order,
            'رفض العميل التعديلات',
            'Client Rejected Modifications',
            "رفض العميل التعديلات وتم إلغاء الطلب #{$order->order_number}",
            "Client rejected modifications and order #{$order->order_number} was cancelled",
            'client_rejected_modifications',
        );
    }

    /**
     * When the vendor reviews one row of a multi-service piece line, apply the same
     * decision to every main-service sibling on that piece (same line_group / bucket).
     * Otherwise unreviewed siblings are auto-accepted and only one service looks rejected.
     *
     * @param  list<array<string, mixed>>  $itemsReview
     * @return list<array<string, mixed>>
     */
    private function expandReviewsToPieceServiceSiblings(Order $order, array $itemsReview): array
    {
        $idToSiblingIds = [];
        foreach (OrderItemGrouper::buckets($order->items) as $group) {
            $ids = $group->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            foreach ($ids as $id) {
                $idToSiblingIds[$id] = $ids;
            }
        }

        $expanded = [];
        $seen = [];

        foreach ($itemsReview as $review) {
            $explicitId = (int) ($review['item_id'] ?? 0);
            if ($explicitId <= 0) {
                continue;
            }

            $siblingIds = $idToSiblingIds[$explicitId] ?? [$explicitId];
            if (! empty($review['item_ids']) && is_array($review['item_ids'])) {
                $siblingIds = array_values(array_unique(array_merge(
                    $siblingIds,
                    collect($review['item_ids'])->map(fn ($id) => (int) $id)->all()
                )));
            }

            foreach ($siblingIds as $siblingId) {
                if (isset($seen[$siblingId])) {
                    continue;
                }
                $seen[$siblingId] = true;

                $copy = $review;
                $copy['item_id'] = $siblingId;
                $copy['explicit_item_id'] = $explicitId;

                // Additional-services payload only applies to the explicitly reviewed row;
                // siblings without an explicit additions review follow status defaults.
                if ($siblingId !== $explicitId) {
                    unset($copy['additional_services']);
                }

                $expanded[] = $copy;
            }
        }

        return $expanded;
    }
}
