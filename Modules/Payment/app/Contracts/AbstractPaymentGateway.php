<?php

namespace Modules\Payment\Contracts;

use Illuminate\Support\Facades\Log;

abstract class AbstractPaymentGateway implements PaymentGatewayInterface
{
    protected array $config;

    protected bool $isTestMode;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->isTestMode = $config['test_mode'] ?? false;
    }

    /**
     * Get configuration value
     */
    protected function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Check if gateway is in test mode
     */
    public function isTestMode(): bool
    {
        return $this->isTestMode;
    }

    /**
     * Check if gateway is enabled
     */
    public function isEnabled(): bool
    {
        return $this->getConfig('enabled', false);
    }

    /**
     * Validate gateway configuration
     */
    public function validateConfiguration(): bool
    {
        return ! empty($this->config);
    }

    /**
     * Gateway log hook — writes every APS request/response to the dedicated
     * 'payment' channel (storage/logs/payment.log), with sensitive fields masked.
     * Logging must never break payment processing, so failures are swallowed.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('payment')->log($level, $message, $this->maskSensitive($context));
        } catch (\Throwable $e) {
            // Never let a logging failure interrupt the payment flow.
        }
    }

    /**
     * Redact PAN / card / credential fields (recursively) before they are logged.
     */
    protected function maskSensitive(array $context): array
    {
        $sensitiveKeys = [
            'card_number', 'expiry_date', 'card_holder_name', 'cvv', 'card_security_code',
            'access_code', 'sha_request_phrase', 'sha_response_phrase',
        ];

        array_walk_recursive($context, function (&$value, $key) use ($sensitiveKeys) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true) && is_scalar($value) && (string) $value !== '') {
                $str = (string) $value;
                $value = strlen($str) <= 4
                    ? '****'
                    : substr($str, 0, 2).str_repeat('*', max(1, strlen($str) - 4)).substr($str, -2);
            }
        });

        return $context;
    }
}
