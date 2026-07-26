<?php

namespace Modules\Payment\DTOs;

class PaymentResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $transactionId = null,
        public readonly ?string $paymentUrl = null,
        public readonly ?string $status = null,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $message = null,
        public readonly array $data = []
    ) {}

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function resolvePaymentUrl(): ?string
    {
        $url = $this->paymentUrl ?? $this->data['redirect_url'] ?? $this->data['form_url'] ?? null;
        $paymentParams = $this->data['payment_params'] ?? null;

        if (($url === null || $url === '') && is_array($paymentParams) && $paymentParams !== []) {
            $testMode = filter_var(config('payment.gateways.amazon_pay.test_mode', false), FILTER_VALIDATE_BOOLEAN);

            return $testMode
                ? 'https://sbcheckout.payfort.com/FortAPI/paymentPage'
                : 'https://checkout.payfort.com/FortAPI/paymentPage';
        }

        return ($url !== null && $url !== '') ? $url : null;
    }

    public function requiresBrowserRedirect(): bool
    {
        $params = $this->data['payment_params'] ?? null;

        return $this->resolvePaymentUrl() !== null
            || (is_array($params) && $params !== []);
    }

    /** @param array<string, mixed> $extra */
    public function clientCheckoutPayload(?string $paymentMethod = null, ?string $gatewayName = null, array $extra = []): array
    {
        return array_merge([
            'transaction_id' => $this->transactionId,
            'payment_url' => $this->resolvePaymentUrl(),
            'status' => $this->status ?? 'pending',
            'gateway' => $gatewayName,
            'payment_method' => $paymentMethod,
            'payment_method_type' => $this->requiresBrowserRedirect() ? 'redirect' : $paymentMethod,
            'payment_params' => $this->data['payment_params'] ?? null,
            'redirect_instructions' => $this->redirectInstructions(),
        ], $extra);
    }

    /** @param array<string, mixed> $responseData @param array<string, mixed> $paymentPayload */
    public static function mergeTopLevelRedirectFields(array $responseData, array $paymentPayload): array
    {
        foreach ([
            'payment_url', 'transaction_id', 'status', 'payment_params',
            'payment_method_type', 'redirect_instructions', 'gateway', 'payment_method',
        ] as $key) {
            if (array_key_exists($key, $paymentPayload) && $paymentPayload[$key] !== null) {
                $responseData[$key] = $paymentPayload[$key];
            }
        }
        if ($paymentPayload['payment_url'] ?? null) {
            $responseData['payment_required'] = true;
        }

        return $responseData;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'transaction_id' => $this->transactionId,
            'payment_url' => $this->paymentUrl,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'message' => $this->message,
            'data' => $this->data,
            'redirect_instructions' => $this->redirectInstructions(),
        ];
    }

    /**
     * How the client should open the gateway checkout.
     * APS/PayFort: POST form with payment_params. Moyasar: GET redirect to payment_url.
     */
    public function redirectInstructions(): ?array
    {
        $url = $this->resolvePaymentUrl();
        if ($url === null || $url === '') {
            return null;
        }

        $params = $this->data['payment_params'] ?? null;
        if (is_array($params) && $params !== []) {
            return [
                'method' => 'POST',
                'url' => $url,
                'params' => $params,
                'note' => 'Submit payment_params as form data to payment_url using POST method.',
            ];
        }

        return [
            'method' => 'GET',
            'url' => $url,
            'params' => null,
            'note' => 'Open payment_url in the browser (Moyasar hosted page).',
        ];
    }
}
