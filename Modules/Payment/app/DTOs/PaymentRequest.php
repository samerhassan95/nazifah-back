<?php

namespace Modules\Payment\DTOs;

class PaymentRequest
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $orderId,
        public readonly string $customerEmail,
        public readonly ?string $customerName = null,
        public readonly ?string $customerPhone = null,
        public readonly ?string $returnUrl = null,
        public readonly ?string $cancelUrl = null,
        public readonly ?string $paymentOption = null,  // Payfort payment_option: VISA, MASTERCARD, MADA, STCPAY
        public readonly array $metadata = [],
        public readonly bool $enableTokenization = false,
    ) {}

    /**
     * Whether PayFort should return a reusable token_name on this checkout.
     * Enabled only when enableTokenization is true and AMAZON_PAY_TOKENIZATION_ENABLED=true in .env.
     */
    public function wantsTokenization(): bool
    {
        if (! $this->enableTokenization) {
            return false;
        }

        if ((bool) ($this->metadata['disable_tokenization'] ?? false)) {
            return false;
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'order_id' => $this->orderId,
            'customer_email' => $this->customerEmail,
            'customer_name' => $this->customerName,
            'customer_phone' => $this->customerPhone,
            'return_url' => $this->returnUrl,
            'cancel_url' => $this->cancelUrl,
            'payment_option' => $this->paymentOption,
            'metadata' => $this->metadata,
            'enable_tokenization' => $this->enableTokenization,
        ];
    }
}
