<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrderStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPayment;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentService;

class HandlePaymentCapture
{
    public function __construct(private PaymentService $paymentService) {}

    public function handle(OrderStatusChanged $event): void
    {
        // Capture whenever an order ENTERS a settled state — CONFIRMED (the laundry
        // accepted the order) or PAYMENT_CONFIRMED (payment was settled). Both are
        // covered because, depending on the flow, the gateway authorization hold can
        // be created either before confirmation (prepaid checkout) or at payment time
        // (pay-after-review), and the latter advances straight to PAYMENT_CONFIRMED
        // without ever re-entering CONFIRMED. This is idempotent: only 'authorized'
        // holds carrying a fort_id are captured, and each capture flips the
        // transaction to 'completed', so a repeat transition finds nothing to do.
        if (! in_array($event->newStatus, [OrderStatus::CONFIRMED, OrderStatus::PAYMENT_CONFIRMED], true)) {
            return;
        }

        try {
            $order = $event->order->fresh();

            // Capture ALL authorized holds for this order (the original reservation
            // plus any "added items" top-ups) up to the effective amount owed. Any
            // hold beyond the effective amount is released (voided), and no single
            // capture ever exceeds its own authorized amount (PayFort rejects that).
            $transactions = PaymentTransaction::where('order_id', $order->id)
                ->where('status', 'authorized')
                ->whereNotNull('fort_id')
                ->orderBy('id')
                ->get();

            if ($transactions->isEmpty()) {
                return;
            }

            $effectiveAmount = $order->getEffectiveFinalAmount();

            // Split orders settle part of the total with wallet/cash legs up front;
            // those are NOT authorized holds. Only the remainder is captured from the
            // gateway holds, and the already-paid legs are added back into the final
            // amount below — otherwise capturing the gateway portion would erase what
            // the wallet/cash legs already covered.
            //
            // Only count legs whose amount is already reflected in the order total:
            // primary legs (is_surcharge = false) and surcharge legs whose staged
            // total has been applied (modification intent resolved). A surcharge leg
            // whose intent is still pending has NOT bumped final_amount yet, so adding
            // it here would over-state the captured total.
            $prepaidLegs = (float) OrderPayment::where('order_id', $order->id)
                ->where('status', OrderPayment::STATUS_PAID)
                ->whereIn('payment_method', [
                    PaymentMethod::Nathefah_WALLET->value,
                    PaymentMethod::CASH_ON_DELIVERY->value,
                ])
                ->where(function ($q) {
                    $q->where('is_surcharge', false)
                        ->orWhereHas('modificationIntent', function ($q2) {
                            $q2->where('status', 'resolved');
                        });
                })
                ->sum('amount');

            $gatewayName = strtolower(str_replace(' ', '_', $transactions->first()->gateway));
            $this->paymentService->setGateway($gatewayName);

            $remaining = max(0.0, (float) $effectiveAmount - $prepaidLegs);
            $totalCaptured = 0.0;

            foreach ($transactions as $transaction) {
                $authorized = (float) ($transaction->authorized_amount ?: $transaction->amount);
                $captureAmount = min($authorized, $remaining);

                // Serialize concurrent settlement triggers. A gateway webhook can race
                // the browser-redirect callback and both reach this listener for the
                // same authorized hold (e.g. Moyasar, which has both paths). Claim the
                // row under a lock and re-check it is still an uncaptured 'authorized'
                // hold before issuing the capture/void, so a hold is never captured or
                // voided twice — a second concurrent trigger blocks here, then sees
                // 'completed'/'cancelled' and skips. (Idempotent for APS too.)
                $captured = DB::transaction(function () use ($transaction, $captureAmount, $authorized, $order) {
                    $locked = PaymentTransaction::whereKey($transaction->id)
                        ->where('status', 'authorized')
                        ->whereNotNull('fort_id')
                        ->lockForUpdate()
                        ->first();

                    if (! $locked) {
                        // Already captured/voided by a concurrent settlement trigger.
                        return 0.0;
                    }

                    if ($captureAmount <= 0) {
                        // Nothing left to charge against this hold — release it.
                        $void = $this->paymentService->voidAuthorization(
                            $locked->fort_id,
                            $locked->transaction_id,
                            $locked->payfortPaymentOption()
                        );

                        if ($void->isSuccessful()) {
                            $locked->update([
                                'status' => 'cancelled',
                                'response_data' => array_merge($locked->response_data ?? [], $void->data ?? []),
                            ]);
                        } else {
                            // Previously silent — a failed void leaks an authorized hold
                            // (customer funds stay encumbered until the gateway auto-expires it).
                            Log::error('Void of unneeded authorization hold failed — hold may leak until expiry', [
                                'order_id' => $order->id,
                                'transaction_id' => $locked->transaction_id,
                                'fort_id' => $locked->fort_id,
                                'message' => $void->message,
                            ]);
                        }

                        return 0.0;
                    }

                    $response = $this->paymentService->capture(
                        $locked->fort_id,
                        $captureAmount,
                        $locked->transaction_id,
                        $locked->payfortPaymentOption()
                    );

                    if ($response->isSuccessful()) {
                        $locked->update([
                            'status' => 'completed',
                            'amount' => $captureAmount,
                            'authorized_amount' => $authorized,
                            'paid_at' => now(),
                            'response_data' => array_merge($locked->response_data ?? [], $response->data ?? []),
                        ]);

                        return $captureAmount;
                    }

                    Log::error('Payment capture failed', [
                        'order_id' => $order->id,
                        'fort_id' => $locked->fort_id,
                        'amount' => $captureAmount,
                        'message' => $response->message,
                    ]);

                    return 0.0;
                });

                $remaining -= $captured;
                $totalCaptured += $captured;
            }

            // Reconcile what the order COSTS against what we could actually collect.
            // final_amount is set to the amount DUE (the agreed/effective total) — it is
            // NOT silently rewritten down to whatever we managed to capture, so a
            // shortfall is never hidden. The uncovered remainder is recorded on
            // amount_remaining; a positive value means the order is awaiting the
            // remaining payment (Phase-2 Case B: due > captured + prepaid).
            $due = (float) $effectiveAmount;
            $covered = round($totalCaptured + $prepaidLegs, 2);
            $shortfall = round(max(0.0, $due - $covered), 2);

            $order->update([
                'final_amount' => round($due, 2),
                'amount_remaining' => $shortfall,
            ]);

            if ($shortfall > 0.005) {
                $this->handleRemainingPaymentShortfall($order, $shortfall);
            }
        } catch (\Throwable $e) {
            Log::error('HandlePaymentCapture exception', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Phase-2 Case B: the order owes more than we could capture from its holds +
     * prepaid legs. Never silently advance it as fully paid — surface the shortfall
     * so the customer can settle the remainder. The durable signal is the
     * order.amount_remaining column (set by the caller); this method logs the
     * condition and notifies the customer. The order status is intentionally NOT
     * rewound here: the confirmation-time auto-transitions (CONFIRMED -> ... ->
     * PAYMENT_CONFIRMED) would race a status change made from inside this listener,
     * so the remaining-payment settlement flow performs the explicit
     * AWAITING_REMAINING_PAYMENT transition instead.
     */
    private function handleRemainingPaymentShortfall(Order $order, float $shortfall): void
    {
        Log::warning('Order confirmed with a remaining unpaid balance (awaiting remaining payment)', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'amount_remaining' => $shortfall,
            'final_amount' => (float) $order->final_amount,
        ]);

        // Best-effort notification — amount_remaining is the source of truth.
        try {
            app(\App\Services\OrderNotificationService::class)->sendToClient(
                $order,
                'سداد مبلغ متبقٍ',
                'Remaining Payment Required',
                "طلبك رقم {$order->order_number} يتطلب سداد مبلغ متبقٍ قدره {$shortfall}.",
                "Order #{$order->order_number} requires a remaining payment of {$shortfall}.",
                'awaiting_remaining_payment',
            );
        } catch (\Throwable $e) {
            // ignore — notification failure must never break settlement
        }
    }
}
