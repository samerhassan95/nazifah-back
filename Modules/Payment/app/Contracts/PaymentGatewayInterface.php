<?php

namespace Modules\Payment\Contracts;

use Modules\Payment\DTOs\PaymentRequest;
use Modules\Payment\DTOs\PaymentResponse;
use Modules\Payment\DTOs\RefundRequest;
use Modules\Payment\DTOs\RefundResponse;

interface PaymentGatewayInterface
{
    /**
     * Get the gateway name
     */
    public function getName(): string;

    /**
     * Initialize a payment
     */
    public function initializePayment(PaymentRequest $request): PaymentResponse;

    /**
     * Verify a payment
     */
    public function verifyPayment(string $transactionId): PaymentResponse;

    /**
     * Capture a previously authorized payment.
     * $paymentOption (e.g. STCPAY, VISA) selects the correct merchant credentials.
     */
    public function capture(string $fortId, float $amount, string $merchantReference, ?string $paymentOption = null): PaymentResponse;

    /**
     * Void a previously authorized payment.
     * $paymentOption (e.g. STCPAY, VISA) selects the correct merchant credentials.
     */
    public function voidAuthorization(string $fortId, string $merchantReference, ?string $paymentOption = null): PaymentResponse;

    /**
     * Process a refund
     */
    public function refund(RefundRequest $request): RefundResponse;

    /**
     * Get payment status
     */
    public function getPaymentStatus(string $transactionId): string;

    /**
     * Check if gateway is enabled
     */
    public function isEnabled(): bool;

    /**
     * Validate gateway configuration
     */
    public function validateConfiguration(): bool;
}
