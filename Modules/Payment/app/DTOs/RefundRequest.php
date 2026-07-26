<?php

namespace Modules\Payment\DTOs;

class RefundRequest
{
    public function __construct(
        public readonly string $transactionId,
        public readonly float $amount,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
        // PayFort payment_option (e.g. STCPAY, VISA) — selects merchant credentials.
        public readonly ?string $paymentOption = null,
        // Gateway-native payment id (the transaction's fort_id). APS refunds by
        // merchant_reference and ignores this; Moyasar refunds BY this id.
        public readonly ?string $gatewayPaymentId = null
    ) {}

    public function toArray(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
            'payment_option' => $this->paymentOption,
            'gateway_payment_id' => $this->gatewayPaymentId,
        ];
    }
}
