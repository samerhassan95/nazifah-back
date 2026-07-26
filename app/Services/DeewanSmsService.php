<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Deewan SMS Service
 *
 * Supports two authentication methods:
 * 1. Legacy: Direct JWT Token (developer.deewan.sa)
 * 2. New: Username + API Key with Sign-in flow (api.deewan.sa:8445)
 *
 * The service automatically detects which method to use based on configuration.
 */
class DeewanSmsService
{
    protected bool $enabled;

    protected ?string $legacyToken;

    protected ?string $username;

    protected ?string $apiKey;

    protected string $baseUrl;

    protected string $sender;

    protected bool $dryRun;

    protected bool $isLegacyMode;

    public function __construct()
    {
        $sms = config('deewan.sms', []);
        $this->enabled = (bool) ($sms['enabled'] ?? true);
        $this->legacyToken = $sms['legacy_token'] ?? null;
        $this->username = $sms['username'] ?? null;
        $this->apiKey = $sms['api_key'] ?? null;
        $this->baseUrl = rtrim($sms['base_url'] ?? 'https://api.deewan.sa:8445', '/');
        $this->sender = $sms['sender'] ?? 'Nathefah';
        $this->dryRun = (bool) ($sms['dry_run'] ?? false);

        // Auto-detect mode: Legacy if token exists, New if username/apiKey exist
        $this->isLegacyMode = ! empty($this->legacyToken) && empty($this->username);
    }

    /**
     * Check if SMS sending is enabled and configured.
     */
    public function isEnabled(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        // Legacy mode: needs token
        if ($this->isLegacyMode) {
            return ! empty($this->legacyToken);
        }

        // New mode: needs username and apiKey
        return ! empty($this->username) && ! empty($this->apiKey);
    }

    /**
     * Send OTP via SMS (CITC-compliant: message states the reason).
     *
     * @param  string  $phone  Phone number (e.g. 966501234567 or 0501234567)
     * @param  string  $otp  The OTP code
     * @param  string  $reason  One of: 'login', 'register', 'forgot_password', 'verify_account', 'reset_password'
     * @param  string|null  $locale  Language code (ar, en). If null, uses app locale
     * @return bool True if sent successfully (or skipped in dev), false on failure
     */
    public function sendOtp(string $phone, string $otp, string $reason = 'verify_account', ?string $locale = null): bool
    {
        // Use provided locale or fall back to app locale
        $locale = $locale ?: app()->getLocale();

        // Get localized OTP message template
        $messageKey = "auth.otp_sms_{$reason}";
        $message = __($messageKey, [
            'otp' => $otp,
            'minutes' => 3,
            'app_name' => config('app.name', 'Nathefah'),
        ], $locale);

        // Fallback to English if translation not found
        if ($message === $messageKey) {
            $message = __($messageKey, [
                'otp' => $otp,
                'minutes' => 3,
                'app_name' => config('app.name', 'Nathefah'),
            ], 'en');
        }

        return $this->sendSms($phone, $message);
    }

    /**
     * Send a raw SMS message.
     *
     * @param  string  $phone  Phone number
     * @param  string  $message  Message body
     * @return bool True if sent successfully, false on failure
     */
    public function sendSms(string $phone, string $message): bool
    {
        if (! $this->isEnabled()) {

            return true; // Don't fail the flow when SMS is off
        }

        $phone = $this->normalizePhone($phone);

        // Dry run mode - log only, don't send
        if ($this->dryRun) {

            return true;
        }

        // Route to appropriate method
        if ($this->isLegacyMode) {
            return $this->sendSmsLegacy($phone, $message);
        } else {
            return $this->sendSmsNew($phone, $message);
        }
    }

    /**
     * Send SMS using legacy method (direct JWT token)
     */
    protected function sendSmsLegacy(string $phone, string $message): bool
    {
        try {
            // Correct API endpoint: https://apis.deewan.sa/sms/v1/messages
            $url = $this->baseUrl.'/sms/v1/messages';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->legacyToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->timeout(15)
                ->post($url, [
                    'senderName' => $this->sender,
                    'messageType' => 'text',
                    'recipients' => $phone,  // Single phone number as string
                    'messageText' => $message,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return true;
            }

            return false;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Send SMS using new method (Sign-in flow with username/apiKey)
     */
    protected function sendSmsNew(string $phone, string $message): bool
    {
        try {
            // Get access token (cached)
            $accessToken = $this->getAccessToken();

            if (! $accessToken) {
                return false;
            }

            // Send SMS
            $url = $this->baseUrl.'/deewan/api/v1/messaging';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])
                ->timeout(15)
                ->post($url, [
                    'recipient' => $phone,
                    'message' => $message,
                    'senderName' => $this->sender,
                ]);

            if ($response->successful()) {

                return true;
            }

            // If unauthorized, clear cached token and retry once
            if ($response->status() === 401) {
                Cache::forget('deewan_access_token');

                $accessToken = $this->getAccessToken();
                if ($accessToken) {
                    $response = Http::withHeaders([
                        'Authorization' => 'Bearer '.$accessToken,
                        'Content-Type' => 'application/json',
                    ])
                        ->timeout(15)
                        ->post($url, [
                            'recipient' => $phone,
                            'message' => $message,
                            'senderName' => $this->sender,
                        ]);

                    if ($response->successful()) {
                        return true;
                    }
                }
            }

return false;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get access token for new API (with caching)
     */
    protected function getAccessToken(): ?string
    {
        // Try cache first
        $cachedToken = Cache::get('deewan_access_token');
        if ($cachedToken) {
            return $cachedToken;
        }

        // Sign in to get new token
        try {
            $url = $this->baseUrl.'/deewan/api/v1/signin';
            $response = Http::timeout(15)->post($url, [
                'username' => $this->username,
                'apiKey' => $this->apiKey,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $accessToken = $data['access-token'] ?? $data['accessToken'] ?? $data['token'] ?? null;

                if ($accessToken) {
                    Cache::put('deewan_access_token', $accessToken, now()->addMinutes(55));

                    return $accessToken;
                }
            }

return null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Normalize phone to E.164 format (e.g. 966501234567 for Saudi).
     */
    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        $phone = ltrim($phone, '+');

        if (strlen($phone) === 9 && str_starts_with($phone, '5')) {
            return '966'.$phone;
        }
        if (strlen($phone) === 10 && str_starts_with($phone, '05')) {
            return '966'.substr($phone, 1);
        }
        if (! str_starts_with($phone, '966')) {
            return '966'.ltrim($phone, '0');
        }

        return $phone;
    }

    /**
     * Get current mode
     */
    public function getMode(): string
    {
        return $this->isLegacyMode ? 'legacy' : 'new';
    }
}
