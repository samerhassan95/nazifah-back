<?php

namespace Modules\Payment\Gateways;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Payment\Contracts\AbstractPaymentGateway;
use Modules\Payment\DTOs\PaymentRequest;
use Modules\Payment\DTOs\PaymentResponse;
use Modules\Payment\DTOs\RefundRequest;
use Modules\Payment\DTOs\RefundResponse;

/**
 * Moyasar payment gateway (https://moyasar.com) — second gateway with full
 * parity to the Amazon Payment Services (APS/PayFort) integration.
 *
 * Implements the SAME PaymentGatewayInterface the AmazonPayGateway does, so it
 * plugs into the existing PaymentService registry, the two callback controllers,
 * and the capture/void/refund listeners with no business-logic duplication.
 *
 * Flow model:
 *  - initializePayment(): creates a Moyasar INVOICE (hosted payment page) and
 *    returns its `url`. The customer is redirected there (a plain GET redirect —
 *    Moyasar has no auto-submit form like PayFort).
 *  - After payment, Moyasar redirects the browser to our callback_url and (for
 *    reliability) also POSTs a server-to-server webhook.
 *  - verifyPayment(): fetches the payment from Moyasar's API by id and trusts
 *    that authoritative status (the redirect itself is NOT relied upon for
 *    security — the authenticated GET cannot be spoofed, so no signature check
 *    of the redirect is needed).
 *
 * ⚠️ Items to CONFIRM against Moyasar's official docs/dashboard are tagged
 *    `CONFIRM:` inline and listed in MOYASAR_INTEGRATION.md. They are isolated to
 *    config + a few clearly-marked spots so they can be corrected without a rewrite.
 */
class MoyasarGateway extends AbstractPaymentGateway
{
    private string $secretKey;

    private string $publishableKey;

    private string $webhookSecret;

    private string $apiBase;

    private string $currency;

    private string $language;

    private string $command;

    /** 'invoice' (hosted redirect) or 'embedded' (moyasar.js form on our own page). */
    private string $mode;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $clean = function ($value): string {
            if ($value === null || $value === '') {
                return '';
            }

            return trim((string) $value, " \t\n\r\0\x0B\"'");
        };

        $this->secretKey = $clean($this->getConfig('secret_key', ''));
        $this->publishableKey = $clean($this->getConfig('publishable_key', ''));
        $this->webhookSecret = $clean($this->getConfig('webhook_secret', ''));
        // Moyasar uses ONE API host for both test and live; the key prefix
        // (sk_test_ / sk_live_) selects the environment, not the URL.
        $this->apiBase = rtrim($clean($this->getConfig('api_base', 'https://api.moyasar.com/v1')), '/');
        $this->currency = strtoupper($clean($this->getConfig('currency', 'SAR')) ?: 'SAR');
        $this->language = $clean($this->getConfig('language', 'ar')) ?: 'ar';
        $this->command = strtoupper($clean($this->getConfig('command', 'PURCHASE')) ?: 'PURCHASE');
        // 'embedded' makes initializePayment return moyasar.js config.
        // 'hosted_local' generates the embedded config but also returns a paymentUrl
        // that points to a local Laravel blade view which renders the moyasar.js form.
        $mode = strtolower($clean($this->getConfig('mode', 'invoice')));
        $this->mode = in_array($mode, ['embedded', 'hosted_local']) ? $mode : 'invoice';

        // Credential validation is deferred to initializePayment so the gateway can
        // still register (and be "ready to activate") even before keys are filled in.

        if ($this->isTestMode()) {
            $this->log('warning', '⚠️ Moyasar is running in TEST MODE. Use sk_test_/pk_test_ keys only.', [
                'api_base' => $this->apiBase,
                'command' => $this->command,
            ]);
        } else {
            $this->log('info', '✅ Moyasar is running in PRODUCTION MODE.', [
                'api_base' => $this->apiBase,
                'command' => $this->command,
            ]);
        }

        $this->log('info', '===== MOYASAR GATEWAY CONSTRUCTOR: CONFIG SUMMARY =====', [
            'secret_key_length' => strlen($this->secretKey),
            'secret_key_prefix' => $this->secretKey !== '' ? substr($this->secretKey, 0, 8).'…' : '(not set)',
            'publishable_key_length' => strlen($this->publishableKey),
            'webhook_secret_set' => $this->webhookSecret !== '',
            'api_base' => $this->apiBase,
            'currency' => $this->currency,
            'language' => $this->language,
            'command' => $this->command,
            'test_mode' => $this->isTestMode(),
        ]);

        $this->warnOnMissingCredentials();
    }

    /**
     * Fail LOUDLY (in the log) when the flag is set but the matching key set is
     * empty — e.g. flipping MOYASAR_TEST_MODE to live without filling the live keys.
     * Without this the gateway silently registers with blank keys and only errors
     * deep inside a payment attempt. Also flags a missing key prefix / wrong-mode key
     * (sk_test_ under live, sk_live_ under test) and a missing publishable key when the
     * embedded moyasar.js form (which needs it) is active.
     */
    private function warnOnMissingCredentials(): void
    {
        $test = $this->isTestMode();
        $env = $test ? 'TEST' : 'LIVE';
        $secretVar = $test ? 'MOYASAR_TEST_SECRET_KEY' : 'MOYASAR_SECRET_KEY';
        $publishableVar = $test ? 'MOYASAR_TEST_PUBLISHABLE_KEY' : 'MOYASAR_PUBLISHABLE_KEY';
        $expectedPrefix = $test ? 'sk_test_' : 'sk_live_';

        if ($this->secretKey === '') {
            $this->log('error', "🚫 Moyasar {$env} MODE is active but the secret key is EMPTY — payments will fail. Set {$secretVar} in .env, then run `php artisan config:clear`.");
        } elseif (! str_starts_with($this->secretKey, $expectedPrefix)) {
            $this->log('error', "🚫 Moyasar {$env} MODE is active but {$secretVar} does not start with '{$expectedPrefix}' — a wrong-environment key is set (test/live mismatch). Fix {$secretVar} and run `php artisan config:clear`.");
        }

        // The embedded moyasar.js form authenticates with the publishable key, so an
        // empty one is fatal there; in hosted-invoice mode it only affects branding.
        if ($this->publishableKey === '') {
            $level = $this->mode === 'embedded' ? 'error' : 'warning';
            $suffix = $this->mode === 'embedded'
                ? 'the embedded moyasar.js form CANNOT render without it'
                : 'hosted-page branding will be unavailable';
            $this->log($level, "⚠️ Moyasar {$env} MODE publishable key is EMPTY — {$suffix}. Set {$publishableVar} in .env, then run `php artisan config:clear`.");
        }
    }

    public function getName(): string
    {
        // Normalizes to 'moyasar' (strtolower + spaces→_), which is the registered
        // gateway key the controllers/listeners resolve callbacks back to.
        return 'Moyasar';
    }

    /**
     * Create a Moyasar invoice (hosted payment page) and return its redirect URL.
     */
    public function initializePayment(PaymentRequest $request): PaymentResponse
    {
        // Embedded / Hosted Local modes return the client-side form config instead of
        // creating a hosted invoice — no API call, no hosted URL/invoice id.
        if ($this->mode === 'embedded' || $this->mode === 'hosted_local') {
            return $this->initializeEmbeddedPayment($request);
        }

        try {
            $this->log('info', 'Initializing Moyasar payment', $request->toArray());

            if (! $this->validateConfiguration()) {
                throw new \Exception(
                    'Moyasar configuration is invalid: missing secret_key. '
                    .'Set MOYASAR_SECRET_KEY (live) or MOYASAR_TEST_SECRET_KEY (test) in .env.'
                );
            }

            // Keep the SAME merchant reference semantics as APS: transactionId equals
            // the order_number (orders) or WALLET-* reference (wallet deposits), so the
            // existing callback controllers resolve the transaction unchanged.
            $merchantReference = $request->orderId !== '' ? $request->orderId : 'ORD-'.date('Ymd').'-'.strtoupper(Str::random(5));

            // Carry our reference on the redirect URL so the browser-redirect callback
            // can resolve the transaction (mirrors how APS preserves return_url query).
            $callbackUrl = $this->appendQuery((string) $request->returnUrl, ['merchant_reference' => $merchantReference]);

            $metadata = $this->buildMetadata($request, $merchantReference, $callbackUrl);

            $payload = [
                'amount' => $this->formatAmount($request->amount), // halalas (minor units)
                'currency' => strtoupper($request->currency !== '' ? $request->currency : $this->currency),
                'description' => $this->buildDescription($request, $merchantReference),
                // CONFIRM: Moyasar Invoice redirect field. `callback_url` is the documented
                // field; if your account expects `success_url`, set it via the dashboard too.
                'callback_url' => $callbackUrl,
                'metadata' => $metadata,
            ];

            // Send publishable key so Moyasar can display the merchant's account
            // branding (logo, colours) on the hosted checkout page.
            if ($this->publishableKey !== '') {
                $payload['publishable_api_key'] = $this->publishableKey;
            }

            // NOTE: the hosted-page logo is NOT settable here — Moyasar's invoice
            // `logo_url` is read-only ("configured through Moyasar Dashboard"), so any
            // value sent is ignored. Upload the logo in the Moyasar Dashboard account
            // branding settings instead.

            // Always send methods (including samsungpay). Omitting this field lets
            // Moyasar's hosted invoice fall back to card-only on many test accounts.
            $paymentOption = $request->paymentOption
                ?? ($request->metadata['payment_option'] ?? null);
            $allowedMethods = $this->mapToMoyasarAllowedMethods($paymentOption);
            $payload['allowed_payment_methods'] = $allowedMethods;

            // AUTHORIZATION (hold→capture) parity with APS. CONFIRM: Moyasar manual
            // capture must be enabled on the account, and the exact field for invoices
            // ('manual' => true) must be verified. Default command is PURCHASE, which
            // never sends this and is universally supported.
            if ($this->isAuthorizationMode()) {
                $payload['manual'] = true;
            }

            $response = $this->httpClient()->post($this->apiBase.'/invoices', $payload);
            $body = $response->json() ?? [];

            if (! $response->successful() && isset($payload['allowed_payment_methods'])) {
                $this->log('warning', 'Moyasar invoice rejected with allowed_payment_methods; retrying without it', [
                    'http_status' => $response->status(),
                    'error' => $this->extractError($body),
                ]);
                unset($payload['allowed_payment_methods']);
                $response = $this->httpClient()->post($this->apiBase.'/invoices', $payload);
                $body = $response->json() ?? [];
            }

            $this->log('info', 'Moyasar invoice create response', [
                'http_status' => $response->status(),
                'invoice_id' => $body['id'] ?? null,
                'moyasar_status' => $body['status'] ?? null,
                'has_url' => ! empty($body['url']),
                'full_response' => $body,
            ]);

            if (! $response->successful() || empty($body['url'])) {
                $message = $this->extractError($body)
                    ?? ('Moyasar did not return a payment URL (HTTP '.$response->status().').');
                throw new \Exception($message);
            }

            $invoiceId = $body['id'] ?? null;
            $hostedInvoiceUrl = $body['url'];
            $moyasarConfig = $this->buildMoyasarJsConfig(
                $request,
                $merchantReference,
                $callbackUrl,
                $metadata,
                $allowedMethods,
                is_string($invoiceId) ? $invoiceId : null,
            );

            // moyasar.js (our checkout page) can show Samsung Pay / Apple Pay / STC Pay.
            // Moyasar's hosted invoice page typically only renders the card form.
            $localCheckoutUrl = $this->publishableKey !== ''
                ? $this->localCheckoutUrl($merchantReference)
                : null;
            $paymentUrl = $localCheckoutUrl ?: $hostedInvoiceUrl;

            return new PaymentResponse(
                success: true,
                transactionId: $merchantReference,
                paymentUrl: $paymentUrl,
                status: 'pending',
                amount: $request->amount,
                currency: $payload['currency'],
                message: 'Payment initialized successfully',
                data: [
                    'gateway' => 'moyasar',
                    'environment' => $this->isTestMode() ? 'test' : 'production',
                    'invoice_id' => $invoiceId,
                    'moyasar_status' => $body['status'] ?? null,
                    'callback_url' => $callbackUrl,
                    // No POST form params (simple GET redirect to `url`). Kept null so
                    // the controllers treat this as a redirect, not an auto-submit form.
                    'payment_params' => null,
                    'redirect_url' => $paymentUrl,
                    'hosted_invoice_url' => $hostedInvoiceUrl,
                    'moyasar' => $moyasarConfig,
                    'raw_response' => $body,
                ]
            );
        } catch (\Throwable $e) {
            $this->log('error', 'Moyasar initialization failed', [
                'error' => $e->getMessage(),
                'request' => $request->toArray(),
            ]);

            return new PaymentResponse(
                success: false,
                message: $e->getMessage()
            );
        }
    }

    /**
     * Embedded (moyasar.js) initialization.
     *
     * No server-side invoice/payment is created here — the payment is created on the
     * client by moyasar.js using the publishable key. We only build and return the
     * config the frontend feeds to `Moyasar.init(...)` and persist a pending
     * transaction (the caller does that with transactionId == merchant_reference).
     *
     * Settlement is IDENTICAL to the invoice flow: moyasar.js redirects the browser
     * to `callback_url?id={payment_id}&status=...` (carrying our merchant_reference in
     * the query) and Moyasar also fires the server webhook (metadata carries the same
     * merchant_reference + order identifiers). Both reuse the existing callback().
     */
    private function initializeEmbeddedPayment(PaymentRequest $request): PaymentResponse
    {
        $this->log('info', 'Initializing Moyasar EMBEDDED (moyasar.js) payment', $request->toArray());

        if (! $this->validateConfiguration()) {
            return new PaymentResponse(
                success: false,
                message: 'Moyasar configuration is invalid: missing secret_key. '
                    .'Set MOYASAR_SECRET_KEY (live) or MOYASAR_TEST_SECRET_KEY (test) in .env.'
            );
        }

        // moyasar.js authenticates with the PUBLISHABLE key — without it there is no
        // embedded form to render.
        if ($this->publishableKey === '') {
            return new PaymentResponse(
                success: false,
                message: 'Moyasar publishable_key is required for the moyasar.js embedded form. '
                    .'Set MOYASAR_PUBLISHABLE_KEY (live) or MOYASAR_TEST_PUBLISHABLE_KEY (test).'
            );
        }

        $merchantReference = $request->orderId !== ''
            ? $request->orderId
            : 'ORD-'.date('Ymd').'-'.strtoupper(Str::random(5));

        // Carry our reference on the redirect URL so the browser-redirect callback can
        // resolve the transaction (mirrors the invoice flow).
        $callbackUrl = $this->appendQuery((string) $request->returnUrl, ['merchant_reference' => $merchantReference]);
        $metadata = $this->buildMetadata($request, $merchantReference, $callbackUrl);

        $paymentOption = $request->paymentOption ?? ($request->metadata['payment_option'] ?? null);
        $methods = $this->mapToMoyasarAllowedMethods($paymentOption);
        $currency = strtoupper($request->currency !== '' ? $request->currency : $this->currency);
        $moyasarConfig = $this->buildMoyasarJsConfig(
            $request,
            $merchantReference,
            $callbackUrl,
            $metadata,
            $methods,
        );

        return new PaymentResponse(
            success: true,
            transactionId: $merchantReference,
            // If hosted_local, provide a payment URL to our local Blade view.
            paymentUrl: $this->mode === 'hosted_local'
                ? route('api.payment.moyasar.checkout', ['transactionId' => $merchantReference])
                : null,
            status: 'pending',
            amount: $request->amount,
            currency: $currency,
            message: __('payment.embedded_payment_initialized'),
            data: [
                'gateway' => 'moyasar',
                'environment' => $this->isTestMode() ? 'test' : 'production',
                'mode' => 'embedded',
                'integration' => 'moyasar.js',
                // Kept null so controllers treat this as neither a hosted redirect nor
                // an auto-submit form — the client renders the embedded form instead.
                'payment_params' => null,
                'callback_url' => $callbackUrl,
                'moyasar' => $moyasarConfig,
            ]
        );
    }

    /**
     * Verify a payment by asking Moyasar for the authoritative payment object.
     *
     * The Moyasar payment id arrives on the redirect/webhook as `id`. For the
     * Invoice flow that id may be the INVOICE id, so we transparently fall back to
     * GET /invoices/{id} and use its underlying payment.
     */
    public function verifyPayment(string $transactionId): PaymentResponse
    {
        try {
            $this->log('info', '========== MOYASAR VERIFY PAYMENT START ==========', [
                'transaction_id' => $transactionId,
                'request_method' => request()->method(),
                'request_url' => request()->fullUrl(),
            ]);

            $reference = $this->resolveMoyasarReference($transactionId);

            if (! $reference) {
                $this->log('warning', 'Moyasar verifyPayment: no payment/invoice id available', [
                    'transaction_id' => $transactionId,
                ]);

                return new PaymentResponse(
                    success: false,
                    transactionId: $transactionId,
                    status: 'pending',
                    message: __('payment.awaiting_moyasar_payment_confirmation')
                );
            }

            $payment = $this->fetchPayment($reference);

            if (! $payment) {
                return new PaymentResponse(
                    success: false,
                    transactionId: $transactionId,
                    status: 'failed',
                    message: __('payment.could_not_retrieve_payment')
                );
            }

            $moyasarStatus = strtolower((string) ($payment['status'] ?? ''));
            // 'authorized' counts as success (a hold is placed) — same as APS.
            $isSuccess = in_array($moyasarStatus, ['paid', 'captured', 'authorized'], true);
            $mappedStatus = $this->mapStatus($moyasarStatus);

            $this->log('info', '===== MOYASAR PAYMENT RESOLVED =====', [
                'moyasar_payment_id' => $payment['id'] ?? $reference,
                'moyasar_status' => $moyasarStatus,
                'mapped_status' => $mappedStatus,
                'is_success' => $isSuccess,
            ]);

            return new PaymentResponse(
                success: $isSuccess,
                transactionId: $transactionId,
                status: $mappedStatus,
                amount: isset($payment['amount']) ? $this->parseAmount((int) $payment['amount']) : null,
                currency: $payment['currency'] ?? $this->currency,
                message: $this->paymentMessage($payment, $isSuccess),
                data: [
                    'gateway' => 'moyasar',
                    // Stored by the controllers in the transaction's `fort_id` column and
                    // reused for capture/void/refund — must be the PAYMENT id (not invoice).
                    'fort_id' => $payment['id'] ?? $reference,
                    'moyasar_payment_id' => $payment['id'] ?? $reference,
                    'moyasar_status' => $moyasarStatus,
                    'source' => $payment['source'] ?? null,
                    'raw_response' => $payment,
                ]
            );
        } catch (\Throwable $e) {
            $this->log('error', 'Moyasar verifyPayment failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
            ]);

            return new PaymentResponse(
                success: false,
                transactionId: $transactionId,
                status: 'failed',
                message: $e->getMessage()
            );
        }
    }

    /**
     * Capture a previously authorized payment (AUTHORIZATION/manual-capture mode).
     * $fortId is the Moyasar payment id. $paymentOption is unused by Moyasar.
     */
    public function capture(string $fortId, float $amount, string $merchantReference, ?string $paymentOption = null): PaymentResponse
    {
        try {
            $this->log('info', '========== MOYASAR CAPTURE ==========', [
                'payment_id' => $fortId,
                'amount' => $amount,
                'merchant_reference' => $merchantReference,
            ]);

            // CONFIRM: capture endpoint + whether a partial `amount` is accepted.
            $response = $this->httpClient()->post($this->apiBase.'/payments/'.$fortId.'/capture', [
                'amount' => $this->formatAmount($amount),
            ]);
            $body = $response->json() ?? [];

            $status = strtolower((string) ($body['status'] ?? ''));
            // Partial capture leaves the payment `authorized` with a reduced capturable balance.
            $isSuccess = $response->successful() && in_array($status, ['captured', 'paid', 'authorized'], true);

            $this->log('info', 'Moyasar capture response', [
                'http_status' => $response->status(),
                'moyasar_status' => $status,
                'is_success' => $isSuccess,
                'full_response' => $body,
            ]);

            return new PaymentResponse(
                success: $isSuccess,
                transactionId: $merchantReference,
                status: $this->mapStatus($status),
                amount: $amount,
                currency: $this->currency,
                message: $this->paymentMessage($body, $isSuccess) ?? ($isSuccess ? 'Capture successful' : 'Capture failed'),
                data: ['gateway' => 'moyasar', 'fort_id' => $body['id'] ?? $fortId, 'raw_response' => $body]
            );
        } catch (\Throwable $e) {
            $this->log('error', 'Moyasar capture failed', ['error' => $e->getMessage(), 'payment_id' => $fortId]);

            return new PaymentResponse(
                success: false,
                transactionId: $merchantReference,
                status: 'failed',
                message: $e->getMessage()
            );
        }
    }

    /**
     * Void (release) an authorized hold that was never captured.
     * Returns status 'voided' on success; the callers persist it as 'cancelled'
     * (same convention as the APS gateway).
     */
    public function voidAuthorization(string $fortId, string $merchantReference, ?string $paymentOption = null): PaymentResponse
    {
        try {
            $this->log('info', '========== MOYASAR VOID ==========', [
                'payment_id' => $fortId,
                'merchant_reference' => $merchantReference,
            ]);

            // CONFIRM: void endpoint name.
            $response = $this->httpClient()->post($this->apiBase.'/payments/'.$fortId.'/void', []);
            $body = $response->json() ?? [];

            $status = strtolower((string) ($body['status'] ?? ''));
            $isSuccess = $response->successful() && $status === 'voided';

            $this->log('info', 'Moyasar void response', [
                'http_status' => $response->status(),
                'moyasar_status' => $status,
                'is_success' => $isSuccess,
                'full_response' => $body,
            ]);

            return new PaymentResponse(
                success: $isSuccess,
                transactionId: $merchantReference,
                status: $isSuccess ? 'voided' : 'failed',
                message: $this->paymentMessage($body, $isSuccess) ?? ($isSuccess ? 'Authorization voided' : 'Void failed'),
                data: ['gateway' => 'moyasar', 'fort_id' => $body['id'] ?? $fortId, 'raw_response' => $body]
            );
        } catch (\Throwable $e) {
            $this->log('error', 'Moyasar void failed', ['error' => $e->getMessage(), 'payment_id' => $fortId]);

            return new PaymentResponse(
                success: false,
                transactionId: $merchantReference,
                status: 'failed',
                message: $e->getMessage()
            );
        }
    }

    /**
     * Refund a captured payment (full or partial).
     *
     * Moyasar refunds by PAYMENT id, so the caller must pass it via
     * RefundRequest::$gatewayPaymentId (the transaction's fort_id). As a fallback we
     * try to resolve it from the current request/cache.
     */
    public function refund(RefundRequest $request): RefundResponse
    {
        try {
            $this->log('info', '========== MOYASAR REFUND ==========', [
                'transaction_id' => $request->transactionId,
                'amount' => $request->amount,
                'gateway_payment_id' => $request->gatewayPaymentId,
            ]);

            $paymentId = $request->gatewayPaymentId ?: $this->resolveMoyasarReference($request->transactionId);

            if (! $paymentId) {
                throw new \Exception(
                    'Moyasar refund requires the gateway payment id (transaction fort_id), '
                    .'which was not provided for transaction '.$request->transactionId.'.'
                );
            }

            // CONFIRM: refund endpoint + whether omitting `amount` triggers a full refund.
            $response = $this->httpClient()->post($this->apiBase.'/payments/'.$paymentId.'/refund', [
                'amount' => $this->formatAmount($request->amount),
            ]);
            $body = $response->json() ?? [];

            $status = strtolower((string) ($body['status'] ?? ''));
            // Moyasar may report 'refunded' synchronously, or accept the refund (HTTP 2xx)
            // and finalize it asynchronously via a webhook. Treat an accepted request as success.
            $isSuccess = $response->successful() && in_array($status, ['refunded', 'paid', 'captured'], true);

            $this->log('info', 'Moyasar refund response', [
                'http_status' => $response->status(),
                'moyasar_status' => $status,
                'is_success' => $isSuccess,
                'full_response' => $body,
            ]);

            return new RefundResponse(
                success: $isSuccess,
                refundId: $body['id'] ?? $paymentId,
                // The PaymentRefund table accepts pending|completed|failed.
                status: $isSuccess ? ($status === 'refunded' ? 'completed' : 'pending') : 'failed',
                amount: $request->amount,
                message: $this->paymentMessage($body, $isSuccess) ?? ($isSuccess ? 'Refund processed' : 'Refund failed'),
                data: ['gateway' => 'moyasar', 'raw_response' => $body]
            );
        } catch (\Throwable $e) {
            $this->log('error', 'Moyasar refund failed', [
                'error' => $e->getMessage(),
                'request' => $request->toArray(),
            ]);

            return new RefundResponse(
                success: false,
                message: $e->getMessage()
            );
        }
    }

    public function getPaymentStatus(string $transactionId): string
    {
        try {
            $reference = $this->resolveMoyasarReference($transactionId);
            if (! $reference) {
                return 'unknown';
            }

            $payment = $this->fetchPayment($reference);

            return $payment ? $this->mapStatus(strtolower((string) ($payment['status'] ?? ''))) : 'unknown';
        } catch (\Throwable $e) {
            $this->log('error', 'Moyasar getPaymentStatus failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
            ]);

            return 'unknown';
        }
    }

    public function validateConfiguration(): bool
    {
        return $this->secretKey !== '';
    }

    /**
     * Verify an incoming Moyasar webhook before it is allowed to settle a payment.
     *
     * ⚠️ CONFIRM the exact scheme with Moyasar. Two schemes are supported here:
     *   1. Shared `secret_token` echoed in the JSON body (the historically
     *      documented scheme) — compared to MOYASAR_WEBHOOK_SECRET.
     *   2. HMAC-SHA256 of the raw body sent in a signature header.
     * If signature verification is disabled (PAYMENT_WEBHOOKS_VERIFY_SIGNATURE=false)
     * this returns true.
     */
    public function verifyWebhook(array $payload, ?string $signatureHeader = null, ?string $rawBody = null): bool
    {
        if (! filter_var(config('payment.webhooks.verify_signature', true), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        if ($this->webhookSecret === '') {
            $this->log('warning', 'Moyasar webhook secret not configured — rejecting webhook. Set MOYASAR_WEBHOOK_SECRET.');

            return false;
        }

        // Scheme 1: shared token in the body.
        $bodyToken = $payload['secret_token'] ?? ($payload['token'] ?? null);
        if (is_string($bodyToken) && $bodyToken !== '' && hash_equals($this->webhookSecret, $bodyToken)) {
            return true;
        }

        // Scheme 2: HMAC-SHA256 signature header over the raw body.
        if ($signatureHeader) {
            $computed = hash_hmac('sha256', (string) ($rawBody ?? json_encode($payload)), $this->webhookSecret);
            if (hash_equals($computed, $signatureHeader)) {
                return true;
            }
        }

        $this->log('warning', 'Moyasar webhook signature verification failed', [
            'had_body_token' => isset($bodyToken),
            'had_signature_header' => $signatureHeader !== null,
        ]);

        return false;
    }

    // ----------------------------------------------------------------------
    // Internal helpers
    // ----------------------------------------------------------------------

    private function isAuthorizationMode(): bool
    {
        return $this->command === 'AUTHORIZATION';
    }

    /**
     * Authenticated HTTP client. Moyasar uses HTTP Basic auth with the secret key
     * as the username and an empty password.
     */
    private function httpClient(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asForm()
            ->timeout(30);
    }

    /**
     * GET a payment by id, falling back to the invoice (and its underlying payment)
     * when the id turns out to be an invoice id (Invoice-flow redirect).
     *
     * @return array<string,mixed>|null
     */
    private function fetchPayment(string $reference): ?array
    {
        $response = $this->httpClient()->get($this->apiBase.'/payments/'.$reference);
        $body = $response->json() ?? [];

        if ($response->successful() && ! empty($body['id'])) {
            return $body;
        }

        // Fallback: treat the reference as an invoice id.
        $invoiceResponse = $this->httpClient()->get($this->apiBase.'/invoices/'.$reference);
        $invoice = $invoiceResponse->json() ?? [];

        if ($invoiceResponse->successful() && ! empty($invoice['id'])) {
            return $this->paymentFromInvoice($invoice);
        }

        $this->log('warning', 'Moyasar fetchPayment: neither payment nor invoice found', [
            'reference' => $reference,
            'payment_http_status' => $response->status(),
            'invoice_http_status' => $invoiceResponse->status(),
        ]);

        return null;
    }

    /**
     * Reduce a Moyasar invoice object to a payment-like array.
     *
     * @param  array<string,mixed>  $invoice
     * @return array<string,mixed>
     */
    private function paymentFromInvoice(array $invoice): array
    {
        $payments = $invoice['payments'] ?? [];
        if (is_array($payments) && count($payments) > 0) {
            // Prefer a settled payment; otherwise the latest attempt.
            foreach ($payments as $payment) {
                if (in_array(strtolower((string) ($payment['status'] ?? '')), ['paid', 'captured', 'authorized', 'refunded'], true)) {
                    return $payment;
                }
            }

            return (array) end($payments);
        }

        // No payment yet — surface the invoice-level status (initiated/paid/failed/expired).
        return [
            'id' => $invoice['id'] ?? null,
            'status' => $invoice['status'] ?? '',
            'amount' => $invoice['amount'] ?? null,
            'currency' => $invoice['currency'] ?? $this->currency,
            'source' => null,
            '_from_invoice' => true,
        ];
    }

    /**
     * Resolve Moyasar's payment/invoice id for this transaction from the current
     * request (redirect or webhook carries it as `id`) or the cached callback payload.
     */
    private function resolveMoyasarReference(string $transactionId): ?string
    {
        $id = request()->input('id') ?? request()->input('payment_id') ?? request()->input('invoice_id');
        if (! empty($id)) {
            return (string) $id;
        }

        // The callback controllers cache the raw payload under this key.
        $cached = Cache::get("payfort_response_{$transactionId}");
        if (is_array($cached)) {
            $id = $cached['id']
                ?? $cached['payment_id']
                ?? $cached['invoice_id']
                ?? ($cached['data']['id'] ?? null);
            if (! empty($id)) {
                return (string) $id;
            }
        }

        // Fallback: look up the transaction in the database directly to get the invoice_id/payment_id
        $transaction = \Modules\Payment\Models\PaymentTransaction::where('transaction_id', $transactionId)->first();
        if ($transaction && is_array($transaction->response_data)) {
            $id = $transaction->response_data['invoice_id'] 
                ?? $transaction->response_data['moyasar_payment_id'] 
                ?? $transaction->response_data['fort_id'] 
                ?? null;
                
            if (! empty($id)) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * Append query parameters to an absolute URL without dropping existing ones.
     *
     * @param  array<string,scalar|null>  $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        if ($url === '' || $params === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($params);
    }

    /**
     * Build Moyasar metadata. The browser-redirect callback reads identifiers off
     * the callback_url query; the server-to-server WEBHOOK reads them from here, so
     * everything needed to resolve & settle the transaction is carried in both.
     *
     * @return array<string,string>
     */
    private function buildMetadata(PaymentRequest $request, string $merchantReference, string $callbackUrl): array
    {
        $meta = [
            'merchant_reference' => $merchantReference,
            'callback_url' => $callbackUrl,
        ];

        foreach ((array) $request->metadata as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $meta[(string) $key] = $value === null ? '' : (string) $value;
            }
        }

        // Pull wallet/order identifiers out of the return URL query too.
        $parsed = [];
        $query = parse_url((string) $request->returnUrl, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $parsed);
        }
        foreach (['wallet_deposit', 'reference', 'order_id', 'pending_order_id'] as $key) {
            if (isset($parsed[$key]) && ! isset($meta[$key])) {
                $meta[$key] = (string) $parsed[$key];
            }
        }
        if (! empty($parsed['reference']) && empty($meta['wallet_reference'])) {
            $meta['wallet_reference'] = (string) $parsed['reference'];
        }

        // CONFIRM: Moyasar caps metadata (keys/length). Keep it lean.
        return $meta;
    }

    private function buildDescription(PaymentRequest $request, string $merchantReference): string
    {
        $isWallet = $request->metadata['wallet_deposit'] ?? false;
        return $isWallet ? __('payment.moyasar_description_wallet') : __('payment.moyasar_description_order');
    }

    /**
     * Map a Payfort/internal payment option to Moyasar's allowed methods list.
     *
     * Moyasar values: creditcard, mada, stcpay, applepay, samsungpay.
     * credit_card / visa / mastercard (and empty) keep every method so Samsung Pay
     * still appears on wallet top-up and checkout.
     *
     * @return list<string>
     */
    private function mapToMoyasarAllowedMethods(?string $payfortOption): array
    {
        if ($payfortOption === null || $payfortOption === '') {
            return $this->defaultMoyasarMethods();
        }

        return match (strtoupper($payfortOption)) {
            'MADA' => ['mada'],
            'STCPAY' => ['stcpay'],
            'APPLEPAY' => ['applepay'],
            'SAMSUNGPAY' => ['samsungpay', 'creditcard'],
            default => $this->defaultMoyasarMethods(),
        };
    }

    /**
     * @return list<string>
     */
    private function defaultMoyasarMethods(): array
    {
        return ['creditcard', 'mada', 'stcpay', 'applepay', 'samsungpay'];
    }

    /**
     * Config passed to Moyasar.init(...) on our checkout page (and to mobile clients
     * in embedded mode). Includes Samsung Pay + Apple Pay objects required by moyasar.js.
     *
     * @param  list<string>  $methods
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildMoyasarJsConfig(
        PaymentRequest $request,
        string $merchantReference,
        string $callbackUrl,
        array $metadata,
        array $methods,
        ?string $invoiceId = null,
    ): array {
        $currency = strtoupper($request->currency !== '' ? $request->currency : $this->currency);
        $config = [
            'publishable_api_key' => $this->publishableKey,
            'amount' => $this->formatAmount($request->amount),
            'currency' => $currency,
            'description' => $this->buildDescription($request, $merchantReference),
            'callback_url' => $callbackUrl,
            'language' => $this->language,
            'methods' => $methods !== [] ? $methods : $this->defaultMoyasarMethods(),
            'supported_networks' => ['mada', 'visa', 'mastercard'],
            'metadata' => $metadata,
            'apple_pay' => [
                'country' => 'SA',
                'label' => 'Nathefah',
                'validate_merchant_url' => 'https://api.moyasar.com/v1/applepay/initiate',
            ],
        ];

        if ($invoiceId) {
            $config['invoice_id'] = $invoiceId;
        }

        if ($this->isAuthorizationMode()) {
            $config['manual'] = true;
        }

        $samsungPay = $this->buildSamsungPayConfig($merchantReference);
        if ($samsungPay !== null) {
            $config['samsung_pay'] = $samsungPay;
        } elseif (in_array('samsungpay', $config['methods'], true)) {
            $this->log('warning', 'Samsung Pay is requested but MOYASAR_SAMSUNG_PAY_SERVICE_ID is empty — Moyasar will hide the Samsung Pay button. Set the Service ID from the Moyasar Dashboard.');
        }

        return $config;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildSamsungPayConfig(string $merchantReference): ?array
    {
        $serviceId = trim((string) $this->getConfig('samsung_pay_service_id', ''), " \t\n\r\0\x0B\"'");
        if ($serviceId === '') {
            return null;
        }

        $orderNumber = preg_replace('/[^A-Za-z0-9-]/', '-', $merchantReference) ?: $merchantReference;
        $orderNumber = substr($orderNumber, 0, 36);
        $environment = strtoupper((string) $this->getConfig('samsung_pay_environment', 'PRODUCTION'));
        if (! in_array($environment, ['PRODUCTION', 'STAGE'], true)) {
            $environment = 'PRODUCTION';
        }

        return [
            'service_id' => $serviceId,
            'order_number' => $orderNumber,
            'country' => strtoupper((string) ($this->getConfig('samsung_pay_country', 'SA') ?: 'SA')),
            'label' => (string) ($this->getConfig('samsung_pay_label', 'Nathefah') ?: 'Nathefah'),
            'environment' => $environment,
        ];
    }

    private function localCheckoutUrl(string $merchantReference): string
    {
        try {
            if (Route::has('api.payment.moyasar.checkout')) {
                return route('api.payment.moyasar.checkout', ['transactionId' => $merchantReference]);
            }
            if (Route::has('payment.moyasar.checkout')) {
                return route('payment.moyasar.checkout', ['transactionId' => $merchantReference]);
            }
        } catch (\Throwable) {
        }

        return url('/api/v1/payments/moyasar/checkout/'.$merchantReference);
    }

    /**
     * Amount → Moyasar minor units (halalas for SAR).
     */
    private function formatAmount(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function parseAmount(int $amount): float
    {
        return round($amount / 100, 2);
    }

    /**
     * Map a Moyasar status to the app's internal transaction status vocabulary.
     */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'paid', 'captured' => 'completed',
            'authorized' => 'authorized',
            'refunded' => 'refunded',
            'voided' => 'voided', // callers persist a successful void as 'cancelled'
            'failed', 'declined', 'expired' => 'failed',
            'initiated', 'pending' => 'pending',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string,mixed>  $payment
     */
    private function paymentMessage(array $payment, bool $isSuccess): ?string
    {
        $source = $payment['source'] ?? [];
        $sourceMessage = is_array($source) ? ($source['message'] ?? null) : null;

        if ($isSuccess) {
            return $sourceMessage ?? ($payment['message'] ?? 'Payment successful');
        }

        return $sourceMessage
            ?? ($payment['message'] ?? null)
            ?? $this->extractError($payment)
            ?? 'فشلت عملية الدفع. يرجى المحاولة مرة أخرى.';
    }

    /**
     * Flatten Moyasar's error payload (`message` can be a string or a
     * field→messages map) into a single human-readable string.
     *
     * @param  array<string,mixed>  $body
     */
    private function extractError(array $body): ?string
    {
        if (! empty($body['message'])) {
            $message = $body['message'];
            if (is_array($message)) {
                $flat = [];
                array_walk_recursive($message, function ($value) use (&$flat) {
                    $flat[] = is_scalar($value) ? (string) $value : json_encode($value);
                });

                return implode('; ', $flat);
            }

            return (string) $message;
        }

        if (! empty($body['errors'])) {
            return is_string($body['errors']) ? $body['errors'] : json_encode($body['errors']);
        }

        if (! empty($body['type']) && ! empty($body['detail'])) {
            return (string) $body['detail'];
        }

        return null;
    }
}
