<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;

/**
 * Locale-aware labels for order/transaction payment_status values.
 */
class PaymentStatusPresenter
{
    public static function label(?string $status): string
    {
        $status = $status ?: 'pending';

        if ($tx = PaymentTransactionStatus::tryFrom($status)) {
            return $tx->localizedLabel();
        }

        if ($client = PaymentStatus::tryFrom($status)) {
            return $client->localizedLabel();
        }

        // Common aliases / display normalizations
        return match ($status) {
            'paid' => __('payment.status_completed'),
            default => $status,
        };
    }
}
