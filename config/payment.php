<?php

// Credentials come from the environment first, then the gitignored
// config/payment_credentials.php. NEVER hard-code real credentials here — this
// file is committed to version control.
$getCred = function (string $envKey, string $configKey) {
    $v = env($envKey) ?: (config($configKey) ?? '');

    return trim((string) $v, " \t\n\r\0\x0B\"'");
};

// AMAZON_PAYMENT_SERVICES_TEST_MODE: true = test (sbcheckout), false = live (checkout).
// Defaults to false (live) so production never silently runs in sandbox.
$testMode = (bool) filter_var(env('AMAZON_PAYMENT_SERVICES_TEST_MODE') ?? config('payment_credentials.amazon_pay.test_mode', false), FILTER_VALIDATE_BOOLEAN);

// Test: sbcheckout + test creds. Live: checkout + live creds.
$merchantId = $testMode
    ? $getCred('AMAZON_PAYMENT_TEST_MERCHANT_ID', 'payment_credentials.amazon_pay.test.merchant_id')
    : $getCred('AMAZON_PAYMENT_MERCHANT_ID', 'payment_credentials.amazon_pay.live.merchant_id');
$accessCode = $testMode
    ? $getCred('AMAZON_PAYMENT_TEST_ACCESS_CODE', 'payment_credentials.amazon_pay.test.access_code')
    : $getCred('AMAZON_PAYMENT_ACCESS_CODE', 'payment_credentials.amazon_pay.live.access_code');
$shaRequest = $testMode
    ? $getCred('AMAZON_PAYMENT_TEST_SHA_REQUEST_PHRASE', 'payment_credentials.amazon_pay.test.sha_request_phrase')
    : $getCred('AMAZON_PAYMENT_SHA_REQUEST_PHRASE', 'payment_credentials.amazon_pay.live.sha_request_phrase');
$shaResponse = $testMode
    ? $getCred('AMAZON_PAYMENT_TEST_SHA_RESPONSE_PHRASE', 'payment_credentials.amazon_pay.test.sha_response_phrase')
    : $getCred('AMAZON_PAYMENT_SHA_RESPONSE_PHRASE', 'payment_credentials.amazon_pay.live.sha_response_phrase');

// ============================ Moyasar =====================================
// MOYASAR_TEST_MODE: true = test keys (sk_test_/pk_test_), false = live keys.
// Defaults to false (live) for the same fail-to-live safety as APS above.
$moyasarTestMode = (bool) filter_var(env('MOYASAR_TEST_MODE') ?? config('payment_credentials.moyasar.test_mode', false), FILTER_VALIDATE_BOOLEAN);

$moyasarSecretKey = $moyasarTestMode
    ? $getCred('MOYASAR_TEST_SECRET_KEY', 'payment_credentials.moyasar.test.secret_key')
    : $getCred('MOYASAR_SECRET_KEY', 'payment_credentials.moyasar.live.secret_key');
$moyasarPublishableKey = $moyasarTestMode
    ? $getCred('MOYASAR_TEST_PUBLISHABLE_KEY', 'payment_credentials.moyasar.test.publishable_key')
    : $getCred('MOYASAR_PUBLISHABLE_KEY', 'payment_credentials.moyasar.live.publishable_key');
$moyasarWebhookSecret = $moyasarTestMode
    ? $getCred('MOYASAR_TEST_WEBHOOK_SECRET', 'payment_credentials.moyasar.test.webhook_secret')
    : $getCred('MOYASAR_WEBHOOK_SECRET', 'payment_credentials.moyasar.live.webhook_secret');

return [
    'gateways' => [
        'amazon_pay' => [
            'enabled' => filter_var(env('AMAZON_PAY_ENABLED') ?: env('AMAZON_PAYMENT_SERVICES_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN) ?: true,
            'test_mode' => $testMode,
            'merchant_id' => $merchantId,
            'access_code' => $accessCode,
            'sha_request_phrase' => $shaRequest,
            'sha_response_phrase' => $shaResponse,
            'language' => env('AMAZON_PAY_LANGUAGE') ?: env('AMAZON_PAYMENT_LANGUAGE', 'ar'),
            'currency' => env('AMAZON_PAY_CURRENCY') ?: env('AMAZON_PAYMENT_CURRENCY', 'SAR'),
            'command' => env('AMAZON_PAY_COMMAND') ?: env('AMAZON_PAYMENT_COMMAND', 'AUTHORIZATION'),
            // Requires AMAZON_PAY_TOKENIZATION_ENABLED=true AND PayFort "Remember me" enabled on the MID.
            'tokenization_enabled' => filter_var(
                env('AMAZON_PAY_TOKENIZATION_ENABLED', false),
                FILTER_VALIDATE_BOOLEAN
            ),
            'stcpay_merchant_id' => $testMode
                ? $getCred('AMAZON_PAYMENT_TEST_STCPAY_MERCHANT_ID', 'payment_credentials.amazon_pay.test.stcpay_merchant_id', $merchantId)
                : $getCred('AMAZON_PAYMENT_SERVICES_STCPAY_MERCHANT_ID', 'payment_credentials.amazon_pay.live.stcpay_merchant_id', $merchantId),
            'stcpay_access_code' => $testMode
                ? $getCred('AMAZON_PAYMENT_TEST_STCPAY_ACCESS_CODE', 'payment_credentials.amazon_pay.test.stcpay_access_code', $accessCode)
                : $getCred('AMAZON_PAYMENT_SERVICES_STCPAY_ACCESS_CODE', 'payment_credentials.amazon_pay.live.stcpay_access_code', $accessCode),
            'stcpay_sha_request_phrase' => $testMode
                ? $getCred('AMAZON_PAYMENT_TEST_STCPAY_SHA_REQUEST_PHRASE', 'payment_credentials.amazon_pay.test.stcpay_sha_request_phrase', $shaRequest)
                : $getCred('AMAZON_PAYMENT_SERVICES_STCPAY_SHA_REQUEST_PHRASE', 'payment_credentials.amazon_pay.live.stcpay_sha_request_phrase', $shaRequest),
            'stcpay_sha_response_phrase' => $testMode
                ? $getCred('AMAZON_PAYMENT_TEST_STCPAY_SHA_RESPONSE_PHRASE', 'payment_credentials.amazon_pay.test.stcpay_sha_response_phrase', $shaResponse)
                : $getCred('AMAZON_PAYMENT_SERVICES_STCPAY_SHA_RESPONSE_PHRASE', 'payment_credentials.amazon_pay.live.stcpay_sha_response_phrase', $shaResponse),
        ],

        // Moyasar (https://moyasar.com) — second gateway, switchable via the admin
        // panel (admin_settings 'active_payment_gateway'). Registered/ready whenever
        // MOYASAR_ENABLED is true; only ACTIVE when selected by the admin toggle.
        'moyasar' => [
            'enabled' => filter_var(env('MOYASAR_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'test_mode' => $moyasarTestMode,
            'secret_key' => $moyasarSecretKey,
            'publishable_key' => $moyasarPublishableKey,
            'webhook_secret' => $moyasarWebhookSecret,
            // Moyasar uses one host for test+live; the key prefix selects the env.
            'api_base' => env('MOYASAR_API_BASE', 'https://api.moyasar.com/v1'),
            'currency' => env('MOYASAR_CURRENCY') ?: env('AMAZON_PAY_CURRENCY', 'SAR'),
            'language' => env('MOYASAR_LANGUAGE', 'ar'),
            // 'invoice' (default) = hosted redirect to checkout.moyasar.com/invoices/{id}.
            // 'embedded' = return moyasar.js form config; the card form renders on the
            // client's OWN page, so no hosted URL / invoice id is produced. The web
            // frontend must render the moyasar.js form for this to work.
            'mode' => env('MOYASAR_INTEGRATION_MODE', 'invoice'),
            // Samsung Pay (moyasar.js). Service ID comes from Moyasar Dashboard after
            // Samsung Pay is enabled on the account. Without it the button is hidden.
            'samsung_pay_service_id' => env('MOYASAR_SAMSUNG_PAY_SERVICE_ID', ''),
            'samsung_pay_label' => env('MOYASAR_SAMSUNG_PAY_LABEL', 'Nathefah'),
            'samsung_pay_country' => env('MOYASAR_SAMSUNG_PAY_COUNTRY', 'SA'),
            'samsung_pay_environment' => env('MOYASAR_SAMSUNG_PAY_ENVIRONMENT', 'PRODUCTION'),
            // PURCHASE = charge immediately (default, universally supported).
            // AUTHORIZATION = hold then capture/void — REQUIRES Moyasar manual capture
            // enabled on the account (confirm before using). Mirrors AMAZON_PAY_COMMAND.
            'command' => env('MOYASAR_COMMAND', 'PURCHASE'),
        ],
    ],

    // Inbound gateway webhooks (Moyasar server-to-server, etc.).
    'webhooks' => [
        // When true (default), a webhook must present a valid shared token / HMAC
        // signature or it is rejected (401). MoyasarGateway::verifyWebhook reads this;
        // without this key the flag was unconfigurable and always defaulted true, so an
        // empty MOYASAR_WEBHOOK_SECRET rejected every webhook. Set the secret in prod;
        // set PAYMENT_WEBHOOKS_VERIFY_SIGNATURE=false only for local testing.
        'verify_signature' => filter_var(env('PAYMENT_WEBHOOKS_VERIFY_SIGNATURE', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // Default email for clients without email addresses
    'default_customer_email' => env('PAYMENT_DEFAULT_CUSTOMER_EMAIL', 'noreply@nathefah.com'),
];
