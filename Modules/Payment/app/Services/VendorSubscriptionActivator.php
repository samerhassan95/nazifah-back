<?php

namespace Modules\Payment\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Subscription\Models\VendorSubscription;
use Modules\Subscription\Services\SubscriptionService;

/**
 * Idempotent vendor-subscription activation shared by the payment
 * callback/webhook and the vendor app's own verify/poll endpoint — same
 * pattern as WalletDepositCreditor for wallet top-ups.
 */
class VendorSubscriptionActivator
{
    public function __construct(private SubscriptionService $subscriptionService) {}

    /**
     * @return array{activated: bool, subscription: VendorSubscription|null}
     */
    public function activateIfNotAlready(PaymentTransaction $transaction): array
    {
        $data = $transaction->response_data ?? [];
        $vendorId = (int) ($data['vendor_id'] ?? 0);
        $planId = (int) ($data['subscription_plan_id'] ?? 0);

        if ($vendorId <= 0 || $planId <= 0) {
            return ['activated' => false, 'subscription' => null];
        }

        return DB::transaction(function () use ($transaction, $data, $vendorId, $planId) {
            // Lock the payment row and adopt the locked snapshot, mirroring
            // WalletDepositCreditor — every settlement path (public redirect
            // callback, webhook, vendor app poll) serializes here.
            $locked = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->first() ?? $transaction;

            $existing = VendorSubscription::where('payment_transaction_id', $locked->id)->first();
            if ($existing) {
                return ['activated' => false, 'subscription' => $existing];
            }

            $billingCycle = $data['billing_cycle'] ?? VendorSubscription::BILLING_CYCLE_MONTHLY;

            try {
                $subscription = $this->subscriptionService->createSubscription([
                    'vendor_id' => $vendorId,
                    'subscription_plan_id' => $planId,
                    'payment_transaction_id' => $locked->id,
                    'billing_cycle' => $billingCycle,
                    'amount' => (float) $locked->amount,
                    'payment_method' => $locked->payment_method ?? ($data['payment_method'] ?? 'credit_card'),
                    'subscription_date' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Lost the race: another path already activated this exact payment.
                return ['activated' => false, 'subscription' => VendorSubscription::where('payment_transaction_id', $locked->id)->first()];
            }

            return ['activated' => true, 'subscription' => $subscription];
        });
    }
}
