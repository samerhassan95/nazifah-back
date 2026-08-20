<?php

namespace Modules\Payment\Services;

/**
 * Map Moyasar English failure messages / failure_reason codes to localized copy.
 * Moyasar's API always returns English; we translate before exposing to clients.
 */
class MoyasarErrorLocalizer
{
    /**
     * @param  array<string, mixed>|null  $source  Moyasar payment `source` object
     */
    public function localize(?string $rawMessage, ?array $source = null): string
    {
        $failureReason = is_array($source)
            ? strtolower(trim((string) ($source['failure_reason'] ?? $source['response_code'] ?? '')))
            : '';

        if ($failureReason !== '' && ($byReason = $this->byFailureReason($failureReason)) !== null) {
            return $byReason;
        }

        $normalized = $this->normalize((string) $rawMessage);

        if ($normalized !== '' && ($byMessage = $this->byMessage($normalized)) !== null) {
            return $byMessage;
        }

        if (is_string($rawMessage) && trim($rawMessage) !== '') {
            // Unknown Moyasar English string — fall back to a safe generic message
            // rather than showing English to Arabic users.
            return __('payment.moyasar_error_generic');
        }

        return __('payment.moyasar_error_generic');
    }

    private function byFailureReason(string $reason): ?string
    {
        $map = [
            '3ds_timeout' => 'payment.moyasar_error_3ds_timeout',
            '3ds_open_timeout' => 'payment.moyasar_error_3ds_timeout',
            '3ds_dns_error' => 'payment.moyasar_error_3ds_connection',
            '3ds_connection_error' => 'payment.moyasar_error_3ds_connection',
            '3ds_authentication_error' => 'payment.moyasar_error_3ds_auth',
            '3ds_ds_timeout' => 'payment.moyasar_error_3ds_timeout',
            '3ds_ds_connection_error' => 'payment.moyasar_error_3ds_connection',
            '3ds_ptsp_authentication_failed' => 'payment.moyasar_error_3ds_auth',
            '3ds_ptsp_invalid_request' => 'payment.moyasar_error_3ds_generic',
            '3ds_ptsp_missing_required_field' => 'payment.moyasar_error_3ds_generic',
            '3ds_ptsp_invalid_data' => 'payment.moyasar_error_3ds_generic',
            '3ds_service_error' => 'payment.moyasar_error_3ds_generic',
            '3ds_service_busy' => 'payment.moyasar_error_3ds_busy',
            '3ds_acs_error' => 'payment.moyasar_error_3ds_generic',
            '3ds_ds_error' => 'payment.moyasar_error_3ds_generic',
            '3ds_unsupported_device' => 'payment.moyasar_error_3ds_unsupported_device',
            '3ds_declined_exceeds_frequency_limit' => 'payment.moyasar_error_3ds_frequency',
            '3ds_declined_expired_card' => 'payment.moyasar_error_expired_card',
            '3ds_declined_invalid_card' => 'payment.moyasar_error_invalid_card',
            '3ds_invalid_transaction' => 'payment.moyasar_error_3ds_generic',
            '3ds_declined_card_unregistered' => 'payment.moyasar_error_3ds_not_enrolled',
            '3ds_blocked_security_failure' => 'payment.moyasar_error_blocked',
            '3ds_blocked_stolen_card' => 'payment.moyasar_error_stolen_card',
            '3ds_blocked_suspected_fraud' => 'payment.moyasar_error_fraud',
            '3ds_confidence_issue' => 'payment.moyasar_error_3ds_auth',
            '3ds_declined_exceeds_acs_max_challenges' => 'payment.moyasar_error_3ds_auth',
            '3ds_unsupported_transaction' => 'payment.moyasar_error_3ds_generic',
            '3ds_decoupled_issue' => 'payment.moyasar_error_3ds_generic',
            '3ds_declined_authentication_failed' => 'payment.moyasar_error_3ds_declined',
            '3ds_declined_challenge_bypassed' => 'payment.moyasar_error_3ds_declined',
            '3ds_rejected_transaction' => 'payment.moyasar_error_3ds_rejected',
            '3ds_unavailable_transaction' => 'payment.moyasar_error_3ds_unavailable',
            '3ds_expiration_check' => 'payment.moyasar_error_3ds_session_expired',
            '3ds_unspecified' => 'payment.moyasar_error_3ds_generic',
            'insufficient_funds' => 'payment.moyasar_error_insufficient_funds',
            'declined' => 'payment.moyasar_error_declined',
            'blocked' => 'payment.moyasar_error_blocked',
            'expired_card' => 'payment.moyasar_error_expired_card',
            'timed_out' => 'payment.moyasar_error_timed_out',
            'unspecified_failure' => 'payment.moyasar_error_unspecified',
            'referred' => 'payment.moyasar_error_referred',
        ];

        $key = $map[$reason] ?? null;

        return $key !== null ? __($key) : null;
    }

    private function byMessage(string $normalized): ?string
    {
        // Exact / contains matches against Moyasar's documented English messages.
        $exact = [
            '3ds: card authentication declined' => 'payment.moyasar_error_3ds_declined',
            '3ds: request timed out' => 'payment.moyasar_error_3ds_timeout',
            '3ds: connection timed out' => 'payment.moyasar_error_3ds_timeout',
            '3ds: dns error occurred' => 'payment.moyasar_error_3ds_connection',
            '3ds: service connection failed' => 'payment.moyasar_error_3ds_connection',
            '3ds: authentication error occurred' => 'payment.moyasar_error_3ds_auth',
            '3ds: authentication with the payment processor failed' => 'payment.moyasar_error_3ds_auth',
            '3ds: service error occurred' => 'payment.moyasar_error_3ds_generic',
            '3ds: service busy' => 'payment.moyasar_error_3ds_busy',
            '3ds: the device is unsupported' => 'payment.moyasar_error_3ds_unsupported_device',
            '3ds: exceeds authentication frequency limit' => 'payment.moyasar_error_3ds_frequency',
            '3ds: the card has expired' => 'payment.moyasar_error_expired_card',
            '3ds: invalid card number' => 'payment.moyasar_error_invalid_card',
            '3ds: the card is not enrolled in 3ds service' => 'payment.moyasar_error_3ds_not_enrolled',
            '3ds: security failure' => 'payment.moyasar_error_blocked',
            '3ds: the card has been reported as stolen' => 'payment.moyasar_error_stolen_card',
            '3ds: the transaction is suspected to be fraudulent' => 'payment.moyasar_error_fraud',
            '3ds: the authentication attempt was rejected by the issuer bank' => 'payment.moyasar_error_3ds_rejected',
            '3ds: the authentication session has expired' => 'payment.moyasar_error_3ds_session_expired',
            '3ds: an unspecified error occurred' => 'payment.moyasar_error_3ds_generic',
            'insufficient funds' => 'payment.moyasar_error_insufficient_funds',
            'declined' => 'payment.moyasar_error_declined',
            'blocked' => 'payment.moyasar_error_blocked',
            'expired card' => 'payment.moyasar_error_expired_card',
            'timed out' => 'payment.moyasar_error_timed_out',
            'unspecified failure' => 'payment.moyasar_error_unspecified',
            'referred' => 'payment.moyasar_error_referred',
            'allowed time frame for transaction has been expired' => 'payment.moyasar_error_timeframe_expired',
        ];

        if (isset($exact[$normalized])) {
            return __($exact[$normalized]);
        }

        $contains = [
            'card authentication declined' => 'payment.moyasar_error_3ds_declined',
            'authentication_failed' => 'payment.moyasar_error_3ds_auth',
            'authentication_attempted' => 'payment.moyasar_error_3ds_not_enrolled',
            'authentication_not_available' => 'payment.moyasar_error_3ds_not_enrolled',
            'card_not_enrolled' => 'payment.moyasar_error_3ds_not_enrolled',
            '3-d secure' => 'payment.moyasar_error_3ds_auth',
            '3ds' => 'payment.moyasar_error_3ds_generic',
            'insufficient funds' => 'payment.moyasar_error_insufficient_funds',
            'invalid secure code' => 'payment.moyasar_error_invalid_cvc',
            'cannot determine card brand' => 'payment.moyasar_error_invalid_card',
            'unable to determine card payment' => 'payment.moyasar_error_invalid_card',
            'amount exceeds maximum' => 'payment.moyasar_error_amount_exceeded',
            'expired card' => 'payment.moyasar_error_expired_card',
            'timed out' => 'payment.moyasar_error_timed_out',
        ];

        foreach ($contains as $needle => $langKey) {
            if (str_contains($normalized, $needle)) {
                return __($langKey);
            }
        }

        return null;
    }

    private function normalize(string $message): string
    {
        $message = strtolower(trim($message));
        $message = str_replace(['"', "'"], '', $message);
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return $message;
    }
}
