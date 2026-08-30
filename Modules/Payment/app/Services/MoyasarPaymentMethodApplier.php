<?php

namespace Modules\Payment\Services;

use App\Enums\PaymentMethod;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPayment;
use Modules\Payment\Models\PaymentTransaction;

/**
 * After Moyasar confirms a payment, persist the real wallet method (samsung_pay /
 * apple_pay / stc_pay) and the underlying card network (visa / mastercard / mada).
 */
class MoyasarPaymentMethodApplier
{
    /**
     * @param  array<string, mixed>|null  $source
     */
    public function applyFromVerifiedSource(
        PaymentTransaction $transaction,
        ?array $source,
        ?Order $order = null,
    ): PaymentTransaction {
        if ($source === null || $source === []) {
            return $transaction;
        }

        $details = PaymentMethod::resolveMoyasarSourceDetails($source);
        $wallet = $details['wallet_method'];
        $brand = $details['card_brand'];

        $updates = [];

        if ($wallet !== null) {
            $updates['payment_method'] = $wallet->value;
            if ($brand !== null) {
                $updates['card_brand'] = $brand->value;
            }
        } elseif (
            $transaction->payment_method === PaymentMethod::CREDIT_CARD->value
            && $brand !== null
        ) {
            // Hosted card form: keep storing the concrete scheme as payment_method.
            $updates['payment_method'] = $brand->value;
            $updates['card_brand'] = $brand->value;
        } elseif ($brand !== null && empty($transaction->card_brand)) {
            $updates['card_brand'] = $brand->value;
        }

        if ($updates === []) {
            return $transaction;
        }

        $responseData = is_array($transaction->response_data) ? $transaction->response_data : [];
        if (isset($updates['card_brand'])) {
            $responseData['card_brand'] = $updates['card_brand'];
        }
        if (isset($updates['payment_method'])) {
            $responseData['resolved_payment_method'] = $updates['payment_method'];
        }
        $updates['response_data'] = $responseData;

        $transaction->update($updates);
        $transaction = $transaction->fresh() ?? $transaction;

        if (isset($updates['payment_method'])) {
            OrderPayment::where('payment_transaction_id', $transaction->id)
                ->update(['payment_method' => $updates['payment_method']]);
        }

        if ($order && ! $transaction->is_additional_charge) {
            $orderUpdates = [];
            if (isset($updates['payment_method'])) {
                $orderUpdates['payment_method'] = $updates['payment_method'];
            }
            if (isset($updates['card_brand'])) {
                $orderUpdates['card_brand'] = $updates['card_brand'];
            }
            if ($orderUpdates !== []) {
                $order->update($orderUpdates);
            }
        }

        return $transaction;
    }
}
