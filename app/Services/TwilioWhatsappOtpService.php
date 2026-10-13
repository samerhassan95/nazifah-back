<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Sends OTP codes over WhatsApp via a Twilio approved Authentication-category
 * Content Template. Mirrors DeewanSmsService's sendOtp() shape so the two
 * channels are interchangeable at the call site based on config('deewan.otp_channel').
 *
 * An Authentication template is a distinct Meta-approved category from the
 * invoice-link Utility template in TwilioWhatsappGateway — it takes exactly
 * one ContentVariable (the code) and has no "reason"/free text, so the
 * $reason parameter here only affects which Deewan-equivalent fallback
 * logging happens, not the WhatsApp message content itself.
 */
class TwilioWhatsappOtpService
{
    public function isEnabled(): bool
    {
        return (bool) config('twilio.enabled')
            && (string) config('twilio.account_sid') !== ''
            && (string) config('twilio.auth_token') !== ''
            && (string) config('twilio.whatsapp_from') !== ''
            && (string) config('twilio.otp_content_sid') !== '';
    }

    /**
     * @param  string  $phone  Phone number (e.g. 966501234567 or 0501234567)
     * @param  string  $otp  The OTP code
     * @param  string  $reason  One of: 'login', 'register', 'forgot_password', 'verify_account', 'reset_password', 'change_phone'
     */
    public function sendOtp(string $phone, string $otp, string $reason = 'verify_account', ?string $locale = null): bool
    {
        if (! $this->isEnabled()) {
            Log::warning('Twilio WhatsApp OTP not configured', ['reason' => $reason]);

            return false;
        }

        $to = $this->normalizePhone($phone);
        if ($to === '') {
            return false;
        }

        $accountSid = (string) config('twilio.account_sid');
        $authToken = (string) config('twilio.auth_token');
        $from = (string) config('twilio.whatsapp_from');
        $contentSid = (string) config('twilio.otp_content_sid');

        $form = [
            'From' => 'whatsapp:'.ltrim($from, '+'),
            'To' => 'whatsapp:'.$to,
            'ContentSid' => $contentSid,
            'ContentVariables' => json_encode(['1' => $otp]),
        ];

        try {
            $client = new Client(['timeout' => 15]);

            $response = $client->post(
                "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json",
                [
                    'auth' => [$accountSid, $authToken],
                    'form_params' => $form,
                ]
            );

            $decoded = json_decode((string) $response->getBody(), true) ?: [];
            $status = (string) ($decoded['status'] ?? 'accepted');
            $success = $response->getStatusCode() >= 200
                && $response->getStatusCode() < 300
                && ! in_array($status, ['failed', 'undelivered'], true);

            if (! $success) {
                Log::warning('Twilio WhatsApp OTP send failed', [
                    'reason' => $reason,
                    'status' => $status,
                    'error' => $decoded['error_message'] ?? $decoded['message'] ?? null,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('Twilio WhatsApp OTP send threw', ['reason' => $reason, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * E.164, no "whatsapp:" prefix (added by the caller) — same normalization as
     * TwilioWhatsappGateway / DeewanSmsService for consistency across channels.
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (str_starts_with($digits, '966')) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '0')) {
            return '+966'.substr($digits, 1);
        }
        if ($digits !== '') {
            return '+'.$digits;
        }

        return '';
    }
}
