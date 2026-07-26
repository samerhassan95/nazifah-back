<?php

namespace Modules\Payment\Gateways;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Payment\Contracts\AbstractPaymentGateway;
use Modules\Payment\DTOs\PaymentRequest;
use Modules\Payment\DTOs\PaymentResponse;
use Modules\Payment\DTOs\RefundRequest;
use Modules\Payment\DTOs\RefundResponse;

class AmazonPayGateway extends AbstractPaymentGateway
{
    private string $merchantId;

    private string $accessCode;

    private string $shaRequestPhrase;

    private string $shaResponsePhrase;

    private string $language;

    private string $currency;

    private string $stcpayMerchantId;

    private string $stcpayAccessCode;

    private string $stcpayShaRequestPhrase;

    private string $stcpayShaResponsePhrase;

    private string $apiUrl;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        // Helper function to clean and trim config values
        $cleanValue = function ($value) {
            if (is_null($value) || $value === '') {
                return '';
            }
            // Remove quotes if present and trim whitespace
            $cleaned = trim((string) $value, " \t\n\r\0\x0B\"'");

            return $cleaned;
        };

        // Trim whitespace and remove quotes from credentials to avoid PayFort errors
        $this->merchantId = $cleanValue($this->getConfig('merchant_id', ''));
        $this->accessCode = $cleanValue($this->getConfig('access_code', ''));
        $this->shaRequestPhrase = $cleanValue($this->getConfig('sha_request_phrase', ''));
        $this->shaResponsePhrase = $cleanValue($this->getConfig('sha_response_phrase', ''));
        $this->language = $this->getConfig('language', 'ar');
        $this->currency = $this->getConfig('currency', 'SAR');
        $this->stcpayMerchantId = $cleanValue($this->getConfig('stcpay_merchant_id', ''));
        $this->stcpayAccessCode = $cleanValue($this->getConfig('stcpay_access_code', ''));
        $this->stcpayShaRequestPhrase = $cleanValue($this->getConfig('stcpay_sha_request_phrase', ''));
        $this->stcpayShaResponsePhrase = $cleanValue($this->getConfig('stcpay_sha_response_phrase', ''));

        // Credential validation deferred to initializePayment - allows gateway to register even if .env not configured yet

        // Set API URL based on test mode
        $this->apiUrl = $this->isTestMode()
            ? 'https://sbcheckout.payfort.com/FortAPI/paymentPage'
            : 'https://checkout.payfort.com/FortAPI/paymentPage';

        // TASK 4: Environment safety check — log mode on every gateway instantiation
        if ($this->isTestMode()) {
            $this->log('warning',
                '⚠️ PayFort is running in TEST MODE. Use sandbox credentials only.',
                ['api_url' => $this->apiUrl]
            );
        } else {
            $this->log('info',
                '✅ PayFort is running in PRODUCTION MODE.',
                ['api_url' => $this->apiUrl]
            );
        }

        // Log all resolved credentials for diagnostics
        $this->log('info', '===== GATEWAY CONSTRUCTOR: CREDENTIALS SUMMARY =====', [
            'card_merchant_id' => $this->merchantId,
            'card_merchant_id_length' => strlen($this->merchantId),
            'card_access_code_length' => strlen($this->accessCode),
            'card_sha_request_phrase_length' => strlen($this->shaRequestPhrase),
            'card_sha_response_phrase_length' => strlen($this->shaResponsePhrase),
            'stcpay_merchant_id' => $this->stcpayMerchantId,
            'stcpay_merchant_id_length' => strlen($this->stcpayMerchantId),
            'stcpay_access_code_length' => strlen($this->stcpayAccessCode),
            'stcpay_sha_request_phrase_length' => strlen($this->stcpayShaRequestPhrase),
            'stcpay_sha_response_phrase_length' => strlen($this->stcpayShaResponsePhrase),
            'stcpay_has_dedicated_credentials' => ! empty($this->stcpayMerchantId) && ! empty($this->stcpayAccessCode),
            'stcpay_has_dedicated_sha_phrases' => ! empty($this->stcpayShaRequestPhrase) && ! empty($this->stcpayShaResponsePhrase),
            'language' => $this->language,
            'currency' => $this->currency,
            'test_mode' => $this->isTestMode(),
            'api_url' => $this->apiUrl,
        ]);
    }

    public function getName(): string
    {
        return 'Amazon Payment Services';
    }

    public function initializePayment(PaymentRequest $request): PaymentResponse
    {
        try {
            $this->log('info', 'Initializing Amazon Payment Services payment', $request->toArray());

            // Validate configuration
            if (! $this->validateConfiguration()) {
                $missing = array_filter([
                    empty($this->merchantId) ? 'merchant_id' : null,
                    empty($this->accessCode) ? 'access_code' : null,
                    empty($this->shaRequestPhrase) ? 'sha_request_phrase' : null,
                    empty($this->shaResponsePhrase) ? 'sha_response_phrase' : null,
                ]);
                $missingStr = implode(', ', $missing);
                throw new \Exception("Payment gateway configuration is invalid. Missing or empty: {$missingStr}. Check .env - ensure SHA phrases with special chars (\$, &, )) are in double quotes.");
            }

            $merchantReference = $request->orderId ?? 'ORD-'.date('Ymd').'-'.strtoupper(Str::random(5));

            if (strlen($merchantReference) > 40) {
                $merchantReference = substr($merchantReference, 0, 40);
            }

            $cleanReturnUrl = $this->cleanReturnUrl($request->returnUrl);

            // Determine correct Merchant ID and Access Code based on payment option
            // STCPAY uses a completely separate PayFort merchant account with its own credentials
            $normalizedOption = strtoupper(
                str_replace('_', '', $request->paymentOption ?? '')
            );
            $isStcpay = ($normalizedOption === 'STCPAY');
            $merchantIdentifier = $isStcpay ? $this->stcpayMerchantId : $this->merchantId;
            // Use STCPAY-dedicated access code when available; fall back to shared access code
            $accessCode = ($isStcpay && ! empty($this->stcpayAccessCode))
                ? $this->stcpayAccessCode
                : $this->accessCode;

            if ($isStcpay) {
                $this->log('info', 'STCPAY merchant resolution (before PayFort params)', [
                    'stcpay_merchant_id_property' => $this->stcpayMerchantId,
                    'stcpay_access_code_configured' => ! empty($this->stcpayAccessCode),
                    'card_merchant_id' => $this->merchantId,
                    'merchant_identifier_selected' => $merchantIdentifier,
                    'access_code_selected' => substr($accessCode, 0, 6).'***',
                    'uses_dedicated_stcpay_merchant' => $merchantIdentifier === $this->stcpayMerchantId
                        && $this->stcpayMerchantId !== ''
                        && $merchantIdentifier !== $this->merchantId,
                    'uses_dedicated_stcpay_access_code' => ! empty($this->stcpayAccessCode),
                ]);
            }

            $params = [
                // STC Pay is PURCHASE-only on APS: an AUTHORIZATION command is rejected
                // with "Operation not valid for this payment option". Force PURCHASE for
                // STC Pay regardless of the global command so it isn't broken under the
                // AUTHORIZATION default (it settles immediately at creation, which is the
                // only model STC Pay supports on APS). Card/express methods keep the
                // configured command (AUTHORIZATION = hold).
                'command' => $isStcpay ? 'PURCHASE' : $this->getConfig('command', 'PURCHASE'),
                'access_code' => $accessCode,
                'merchant_identifier' => $merchantIdentifier,
                'merchant_reference' => $merchantReference,
                'amount' => $this->formatAmount($request->amount),
                'currency' => strtoupper($request->currency ?? $this->currency),
                'language' => strtolower($this->language),
                'customer_email' => config('payment.default_email', 'no-reply@nathefah.com'),
                'return_url' => $cleanReturnUrl,
            ];

            // customer_phone is mandatory only for STC Pay. Sending it on card/wallet
            // checkouts makes APS reject the whole request with error 00027
            // ("invalid extra parameter: customer_phone"), so attach it for STC Pay only.
            if ($isStcpay) {
                $params['customer_phone'] = $this->formatPhone($request->customerPhone);
            }

            // Handle Payment Options and STC Pay specific logic
            if (! empty($request->paymentOption)) {
                $validPaymentOptions = ['STCPAY', 'MADA', 'VISA', 'MASTERCARD', 'APPLEPAY', 'GOOGLEPAY', 'SAMSUNGPAY'];

                if (! in_array($normalizedOption, $validPaymentOptions)) {
                    throw new \Exception("Invalid payment option: {$normalizedOption}. Valid options: ".implode(', ', $validPaymentOptions));
                }

                // Map back to exact PayFort expected strings
                $payfortOptionMap = [
                    'STCPAY' => 'STCPAY',
                    'MADA' => 'MADA',
                    'VISA' => 'VISA',
                    'MASTERCARD' => 'MASTERCARD',
                    'APPLEPAY' => 'APPLE_PAY',
                    'GOOGLEPAY' => 'PAYFORT_GOOGLE_PAY', // Payfort usually expects PAYFORT_GOOGLE_PAY or GOOGLE_PAY
                    'SAMSUNGPAY' => 'SAMSUNG_PAY',
                ];

                // Fallback to GOOGLE_PAY if that's what they use, but PayFort doc usually says PAYFORT_GOOGLE_PAY. We'll use GOOGLE_PAY as standard.
                $payfortOptionMap['GOOGLEPAY'] = 'GOOGLE_PAY';

                $params['payment_option'] = $payfortOptionMap[$normalizedOption];

                if ($normalizedOption === 'STCPAY') {
                    $this->log('info', 'Using STC Pay specific Merchant ID', [
                        'merchant_identifier' => $params['merchant_identifier'],
                    ]);
                }

                // STC Pay specific requirements
                if ($normalizedOption === 'STCPAY') {
                    // Guard: STCPAY merchant ID must be configured
                    if (empty($this->stcpayMerchantId)) {
                        throw new \Exception(
                            'STCPAY merchant ID is not configured. '.
                            'Set AMAZON_PAYMENT_SERVICES_STCPAY_MERCHANT_ID in .env '.
                            '(get it from fort.payfort.com → Integration Settings → Security Settings).'
                        );
                    }

                    // Guard: STCPAY access code must be configured (separate from the main account)
                    if (empty($this->stcpayAccessCode)) {
                        throw new \Exception(
                            'STCPAY access code is not configured — this causes PayFort error 00009. '.
                            'Set AMAZON_PAYMENT_SERVICES_STCPAY_ACCESS_CODE in .env '.
                            '(switch to the STCPAY merchant account in fort.payfort.com → Integration Settings → Security Settings).'
                        );
                    }

                    // Phone requirement
                    if (empty($params['customer_phone'])) {
                        throw new \Exception('STC Pay requires a customer phone number starting with 05 or +9665');
                    }

                    // Amount limit check (STC Pay Production Limits: 1 SAR - 5,000 SAR)
                    $amountSar = (float) $request->amount;
                    if ($amountSar < 1) {
                        throw new \Exception('STC Pay minimum transaction amount is 1 SAR');
                    }
                    if ($amountSar > 5000) {
                        throw new \Exception('STC Pay maximum per transaction is 5,000 SAR');
                    }
                }
            }

            // Safety guard: if caller requested a specific payment option, keep it in payload.
            if (! empty($request->paymentOption) && empty($params['payment_option'])) {
                throw new \Exception('Requested payment option is missing from PayFort params payload.');
            }

            // Tokenization: ask PayFort to return token_name for server-side reuse on card checkouts.
            // Enabled by default for Visa/MC/Mada; wallets (STC Pay, Apple Pay, etc.) are excluded.
            $tokenizationEnabled = config('payment.gateways.amazon_pay.tokenization_enabled', true);
            if ($tokenizationEnabled
                && $request->wantsTokenization()
                && $this->paymentOptionSupportsTokenization($normalizedOption ?? '')) {
                $params['remember_me'] = 'YES';
                $this->log('info', 'PayFort tokenization enabled for checkout', [
                    'merchant_reference' => $merchantReference,
                    'payment_option' => $params['payment_option'] ?? null,
                ]);
            }

            $signature = $this->generateSignature($params, 'request');
            $params['signature'] = $signature;

            if (($params['payment_option'] ?? '') === 'STCPAY') {
                $this->log('info', 'STCPAY signature alignment check', [
                    'merchant_identifier_in_payload' => $params['merchant_identifier'],
                    'matches_stcpay_config' => $params['merchant_identifier'] === $this->stcpayMerchantId,
                    'card_merchant_id' => $this->merchantId,
                    'payload_uses_card_merchant_id' => $params['merchant_identifier'] === $this->merchantId,
                ]);
            }

            $this->log('info', 'Payment parameters prepared for PayFort', [
                'merchant_reference' => $merchantReference,
                'amount' => $params['amount'],
                'currency' => $params['currency'],
                'command' => $params['command'],
                'customer_phone' => $params['customer_phone'] ?? 'NOT_PROVIDED',
                'payment_option' => $params['payment_option'] ?? 'NOT_SPECIFIED',
                'return_url' => $cleanReturnUrl,
                'signature' => $params['signature'],
                'all_params' => $params,
                'config' => [
                    'merchant_id_used' => $params['merchant_identifier'],
                    'merchant_id_length' => strlen($params['merchant_identifier']),
                    'access_code' => $this->accessCode,
                    'access_code_length' => strlen($this->accessCode),
                    'sha_request_phrase' => substr($this->shaRequestPhrase, 0, 5).'***',
                    'sha_response_phrase' => substr($this->shaResponsePhrase, 0, 5).'***',
                    'test_mode' => $this->isTestMode(),
                    'api_url' => $this->apiUrl,
                ],
                'payfort_troubleshooting' => [
                    'error_00009_common_causes' => [
                        'Merchant ID and Access Code must be from the same PayFort account',
                        'For STCPAY: merchant_identifier must be the STCPAY-specific MID; the same request access_code must be authorized for that MID in the Amazon Payment Services (PayFort) dashboard',
                        'Test mode credentials must be used with sandbox URL (sbcheckout.payfort.com)',
                        'Production credentials must be used with production URL (checkout.payfort.com)',
                        'Check for whitespace or special characters in credentials',
                        'Verify credentials in PayFort merchant dashboard',
                    ],
                ],
            ]);

            $paymentUrlWithOption = ! empty($params['payment_option'])
                ? $this->apiUrl.'?payment_option='.urlencode($params['payment_option'])
                : $this->apiUrl;

            return new PaymentResponse(
                success: true,
                transactionId: $merchantReference,
                paymentUrl: $paymentUrlWithOption,
                status: 'pending',
                amount: $request->amount,
                currency: $params['currency'],
                message: 'Payment initialized successfully',
                data: [
                    'gateway' => 'amazon_pay',
                    'environment' => $this->isTestMode() ? 'test' : 'production',
                    'payment_params' => $params,
                    'form_url' => $this->apiUrl,
                    'method' => 'POST',
                ]
            );

        } catch (\Exception $e) {
            $this->log('error', 'Amazon Payment Services initialization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->toArray(),
            ]);

            return new PaymentResponse(
                success: false,
                message: $e->getMessage()
            );
        }
    }

    public function verifyPayment(string $transactionId): PaymentResponse
    {
        try {
            $this->log('info', '========== START PAYMENT VERIFICATION ==========', [
                'transaction_id' => $transactionId,
                'request_method' => request()->method(),
                'request_url' => request()->fullUrl(),
                'request_ip' => request()->ip(),
            ]);

            $cacheKey = "payfort_response_{$transactionId}";
            $response = Cache::get($cacheKey);

            $this->log('info', '===== CHECKING CACHE FOR RESPONSE =====', [
                'cache_key' => $cacheKey,
                'found_in_cache' => ! is_null($response),
            ]);

            if (! $response) {
                $response = request()->all();
                $this->log('info', '===== USING REQUEST DATA (NO CACHE) =====', [
                    'has_request_data' => ! empty($response),
                ]);
            }

            $this->log('info', '===== PAYFORT RAW RESPONSE (COMPLETE) =====', [
                'complete_response' => $response,
                'response_keys' => array_keys($response),
                'response_count' => count($response),
                'is_empty' => empty($response),
            ]);

            $responseCode = $response['response_code'] ?? null;
            $status = $response['status'] ?? null;
            $responseMessage = $response['response_message'] ?? null;
            $merchantReference = $response['merchant_reference'] ?? null;
            $fortId = $response['fort_id'] ?? null;
            $authorizationCode = $response['authorization_code'] ?? null;
            $signature = $response['signature'] ?? null;

            $this->log('info', '===== EXTRACTED FIELDS FROM RESPONSE =====', [
                'response_code' => $responseCode,
                'response_code_type' => gettype($responseCode),
                'status' => $status,
                'status_type' => gettype($status),
                'response_message' => $responseMessage,
                'merchant_reference' => $merchantReference,
                'fort_id' => $fortId,
                'authorization_code' => $authorizationCode,
                'has_signature' => ! empty($signature),
                'signature_preview' => $signature ? substr($signature, 0, 20).'...' : 'NULL',
            ]);

            // Pull-based confirmation fallback: when NO gateway redirect payload is
            // present (neither a response_code nor a status arrived — e.g. the
            // /payment/confirm endpoint with no cached PayFort response), query PayFort
            // directly with a server-side CHECK_STATUS so the payment can still be
            // settled without the browser redirect. Never runs on the normal redirect
            // path (which always carries a response_code/status), so it can't regress it.
            if (empty($responseCode) && empty($status)) {
                $serverStatus = $this->queryGatewayStatus($merchantReference ?: $transactionId);

                if (! empty($serverStatus)) {
                    $this->log('info', '===== APS SERVER-SIDE CHECK_STATUS (pull confirm) =====', [
                        'merchant_reference' => $merchantReference ?: $transactionId,
                        'server_status' => $serverStatus['status'] ?? null,
                        'server_response_code' => $serverStatus['response_code'] ?? null,
                    ]);

                    $response = array_merge($response, $serverStatus);
                    $responseCode = $serverStatus['response_code'] ?? $responseCode;
                    $status = $serverStatus['status'] ?? $status;
                    $responseMessage = $serverStatus['response_message'] ?? $responseMessage;
                    $merchantReference = $serverStatus['merchant_reference'] ?? $merchantReference;
                    $fortId = $serverStatus['fort_id'] ?? $fortId;
                    $authorizationCode = $serverStatus['authorization_code'] ?? $authorizationCode;
                    // A server-side query is authenticated by our request signature and
                    // carries no redirect `signature`, so the redirect-signature check below
                    // is skipped (mirrors Moyasar's server-authoritative verify).
                    $signature = $serverStatus['signature'] ?? null;
                }
            }

            if (empty($response)) {
                $this->log('error', '===== RESPONSE IS EMPTY! =====', [
                    'post_data' => request()->post(),
                    'get_data' => request()->query(),
                    'input_data' => request()->input(),
                    'all_data' => request()->all(),
                    'raw_content' => request()->getContent(),
                ]);

                return new PaymentResponse(
                    success: false,
                    transactionId: $transactionId,
                    status: 'failed',
                    message: 'لم يتم استلام بيانات من بوابة الدفع'
                );
            }

            // PayFort response_code = 2-char status + 3-char message code. Success:
            // 14000 = PURCHASE, 02000 = AUTHORIZATION, 04000 = CAPTURE.
            $successResponseCodes = ['14000', '02000', '04000', '20064', '20080'];
            $successStatusCodes = ['14', '02', '18'];

            $isSuccessByResponseCode = ! empty($responseCode) && in_array($responseCode, $successResponseCodes);
            $isSuccessByStatus = ! empty($status) && in_array($status, $successStatusCodes);
            $isSuccess = $isSuccessByResponseCode || $isSuccessByStatus;

            $this->log('info', '===== SUCCESS DETERMINATION =====', [
                'is_success_by_response_code' => $isSuccessByResponseCode,
                'is_success_by_status' => $isSuccessByStatus,
                'final_is_success' => $isSuccess,
                'response_code_in_success_list' => ! empty($responseCode) ? in_array($responseCode, $successResponseCodes) : 'NULL',
                'status_in_success_list' => ! empty($status) ? in_array($status, $successStatusCodes) : 'NULL',
                'success_response_codes_expected' => $successResponseCodes,
                'success_status_codes_expected' => $successStatusCodes,
            ]);

            if (! empty($responseCode)) {
                if ($responseCode === '00009') {
                    $this->log('error', '===== ERROR CODE 00009: Invalid Merchant Identifier =====', [
                        'merchant_reference' => $merchantReference,
                        'full_response' => $response,
                    ]);

                    return new PaymentResponse(
                        success: false,
                        transactionId: $merchantReference ?? $transactionId,
                        status: 'failed',
                        message: 'معرّف التاجر غير صحيح. يرجى التحقق من بيانات الاعتماد.',
                        data: ['error_code' => '00009', 'raw_response' => $response]
                    );
                }

                if ($responseCode === '00046') {
                    $this->log('error', '===== ERROR CODE 00046: Payment Option Not Configured =====', [
                        'payment_option' => $response['payment_option'] ?? 'UNKNOWN',
                        'full_response' => $response,
                    ]);

                    return new PaymentResponse(
                        success: false,
                        transactionId: $merchantReference ?? $transactionId,
                        status: 'failed',
                        message: 'خيار الدفع غير مفعّل في حساب PayFort.',
                        data: ['error_code' => '00046', 'raw_response' => $response]
                    );
                }

                if ($responseCode === '00006') {
                    $this->log('error', '===== ERROR CODE 00006: Signature Mismatch =====', [
                        'full_response' => $response,
                    ]);

                    return new PaymentResponse(
                        success: false,
                        transactionId: $merchantReference ?? $transactionId,
                        status: 'failed',
                        message: 'خطأ تقني في بوابة الدفع (توقيع رقمي غير صحيح).',
                        data: ['error_code' => '00006', 'raw_response' => $response]
                    );
                }
            }

            $signatureValid = true;
            if (! empty($signature)) {
                $this->log('info', '===== STARTING SIGNATURE VERIFICATION =====', [
                    'received_signature' => $signature,
                ]);

                $payfortFields = ['amount', 'response_code', 'digital_wallet', 'merchant_identifier', 'access_code', 'language', 'response_message', 'merchant_reference', 'currency', 'phone_number', 'status', 'customer_email', 'customer_phone', 'command', 'customer_ip', 'signature', 'fort_id', 'authorization_code', 'card_number', 'payment_option', 'eci', 'expiry_date', 'card_holder_name', 'token_name', '3ds_url'];
                $signatureValid = $this->verifySignature(array_intersect_key($response, array_flip($payfortFields)));

                $this->log('info', '===== SIGNATURE VERIFICATION RESULT =====', [
                    'is_valid' => $signatureValid,
                    'received_signature' => $signature,
                ]);

                if (! $signatureValid && $isSuccess) {
                    $this->log('error', '===== SIGNATURE INVALID FOR SUCCESS RESPONSE =====', [
                        'response_code' => $responseCode,
                        'status' => $status,
                    ]);

                    throw new \Exception('Invalid signature for payment response. Security check failed.');
                }
            } else {
                $this->log('warning', '===== NO SIGNATURE IN RESPONSE =====', [
                    'response_code' => $responseCode,
                    'status' => $status,
                ]);
            }

            $mappedStatus = ! empty($status)
                ? $this->mapStatus($status)
                : $this->mapStatusByResponseCode($responseCode ?? '00');

            $this->log('info', '===== STATUS MAPPING =====', [
                'original_status' => $status,
                'response_code' => $responseCode,
                'mapped_status' => $mappedStatus,
            ]);

            $message = $this->getResponseMessage($response, $isSuccess);

            $this->log('info', '===== MESSAGE DETERMINATION =====', [
                'is_success' => $isSuccess,
                'final_message' => $message,
                'response_message' => $responseMessage,
            ]);

            $finalResponse = new PaymentResponse(
                success: $isSuccess,
                transactionId: $merchantReference ?? $transactionId,
                status: $mappedStatus,
                amount: isset($response['amount']) ? $this->parseAmount($response['amount']) : null,
                currency: $response['currency'] ?? $this->currency,
                message: $message,
                data: [
                    'fort_id' => $fortId,
                    'token_name' => $response['token_name'] ?? null,
                    'response_code' => $responseCode,
                    'status' => $status,
                    'authorization_code' => $authorizationCode,
                    'card_number' => $response['card_number'] ?? null,
                    'payment_option' => $response['payment_option'] ?? null,
                    'signature_valid' => $signatureValid,
                    'eci' => $response['eci'] ?? null,
                    'raw_response' => $response,
                ]
            );

            $this->log('info', '===== FINAL PAYMENT RESPONSE =====', [
                'success' => $finalResponse->success,
                'transaction_id' => $finalResponse->transactionId,
                'status' => $finalResponse->status,
                'amount' => $finalResponse->amount,
                'currency' => $finalResponse->currency,
                'message' => $finalResponse->message,
                'has_data' => ! empty($finalResponse->data),
            ]);

            $this->log('info', '========== END PAYMENT VERIFICATION ==========');

            return $finalResponse;

        } catch (\Exception $e) {
            $this->log('error', '===== EXCEPTION IN VERIFICATION =====', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'transaction_id' => $transactionId,
                'request_data' => request()->all(),
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
     * Map response_code to internal status
     */
    private function mapStatusByResponseCode(string $responseCode): string
    {
        $mapping = match ($responseCode) {
            '14000', '20064', '20080' => 'completed', // PURCHASE success
            '04000' => 'completed', // CAPTURE success
            '02000' => 'authorized', // AUTHORIZATION success (hold placed)
            '00000' => 'pending', // Pending
            '00' => 'failed', // Declined
            default => 'unknown',
        };

        $this->log('debug', 'Response code to status mapping', [
            'response_code' => $responseCode,
            'mapped_to' => $mapping,
        ]);

        return $mapping;
    }

    /**
     * Verify response signature
     */
    private function verifySignature(array $response): bool
    {
        if (! isset($response['signature'])) {
            $this->log('warning', 'No signature in PayFort response');

            return false;
        }

        $receivedSignature = $response['signature'];

        $this->log('info', '===== SIGNATURE CALCULATION START =====', [
            'received_signature' => $receivedSignature,
            'sha_response_phrase_length' => strlen($this->shaResponsePhrase),
            'sha_response_phrase_preview' => substr($this->shaResponsePhrase, 0, 5).'***',
        ]);

        $calculatedSignature = $this->generateSignature($response, 'response');

        $isValid = hash_equals($calculatedSignature, $receivedSignature);

        $this->log('info', '===== SIGNATURE COMPARISON =====', [
            'is_valid' => $isValid,
            'received_signature' => $receivedSignature,
            'calculated_signature' => $calculatedSignature,
            'signatures_match' => $receivedSignature === $calculatedSignature,
            'response_params_count' => count($response),
            'merchant_reference' => $response['merchant_reference'] ?? null,
        ]);

        return $isValid;
    }

    /**
     * Generate SHA-256 signature for PayFort
     */
    private function generateSignature(array $params, string $type = 'request'): string
    {
        // Select correct SHA phrases based on which merchant account is being used
        // This is critical when STCPAY and Cards use different PayFort merchant identifiers
        $currentMerchantId = $params['merchant_identifier'] ?? $this->merchantId;

        $useStcpayPhrases = ($currentMerchantId === $this->stcpayMerchantId)
            && (! empty($this->stcpayShaRequestPhrase) || ! empty($this->stcpayShaResponsePhrase));

        if ($type === 'request') {
            $phrase = $useStcpayPhrases ? $this->stcpayShaRequestPhrase : $this->shaRequestPhrase;
        } else {
            $phrase = $useStcpayPhrases ? $this->stcpayShaResponsePhrase : $this->shaResponsePhrase;
        }

        $this->log('info', "===== GENERATING {$type} SIGNATURE =====", [
            'type' => $type,
            'phrase_source' => $useStcpayPhrases ? 'STCPAY_SPECIFIC' : 'DEFAULT',
            'merchant_identifier' => $currentMerchantId,
            'phrase_length' => strlen($phrase),
            'phrase_preview' => substr($phrase, 0, 5).'***'.substr($phrase, -3),
            'params_count' => count($params),
        ]);

        if (empty($phrase)) {
            throw new \Exception("SHA {$type} phrase is not configured for merchant {$currentMerchantId}");
        }

        $paramsForSignature = $params;
        unset($paramsForSignature['signature']);

        $paramsForSignature = array_filter($paramsForSignature, function ($value) {
            return $value !== null && $value !== '';
        });

        ksort($paramsForSignature);

        $concatenatedParamString = '';
        foreach ($paramsForSignature as $key => $value) {
            $concatenatedParamString .= $key.'='.trim((string) $value);
        }
        $signatureString = $phrase.$concatenatedParamString.$phrase;

        // Never log full $signatureString: it embeds the SHA phrase and would leak credentials.
        $this->log('debug', 'Signature string built (SHA phrases not logged)', [
            'total_byte_length' => strlen($signatureString),
            'param_concat_byte_length' => strlen($concatenatedParamString),
            'sha_phrase_byte_length' => strlen($phrase),
            'merchant_identifier_used_in_signature' => $paramsForSignature['merchant_identifier'] ?? null,
        ]);

        $signature = hash('sha256', $signatureString);

        $this->log('info', "===== {$type} SIGNATURE GENERATED =====", [
            'signature' => $signature,
            'signature_length' => strlen($signature),
        ]);

        return $signature;
    }

    /**
     * Resolve merchant identifier + access code for a back-office operation
     * (capture / void / refund) based on the payment option used.
     *
     * STC Pay settles on a SEPARATE PayFort merchant account, so capture/void/
     * refund MUST use the STC Pay merchant credentials — not the card account.
     * generateSignature() then automatically selects the matching SHA phrases
     * from the merchant_identifier we set here.
     *
     * @return array{merchant_identifier: string, access_code: string}
     */
    private function resolveMerchantContext(?string $paymentOption): array
    {
        $normalized = strtoupper(str_replace('_', '', (string) $paymentOption));

        if ($normalized === 'STCPAY') {
            return [
                'merchant_identifier' => $this->stcpayMerchantId !== '' ? $this->stcpayMerchantId : $this->merchantId,
                'access_code' => $this->stcpayAccessCode !== '' ? $this->stcpayAccessCode : $this->accessCode,
            ];
        }

        return [
            'merchant_identifier' => $this->merchantId,
            'access_code' => $this->accessCode,
        ];
    }

    public function capture(string $fortId, float $amount, string $merchantReference, ?string $paymentOption = null): PaymentResponse
    {
        try {
            $this->log('info', '========== START CAPTURE ==========', [
                'fort_id' => $fortId,
                'amount' => $amount,
                'merchant_reference' => $merchantReference,
                'payment_option' => $paymentOption,
            ]);

            $merchant = $this->resolveMerchantContext($paymentOption);

            $params = [
                'command' => 'CAPTURE',
                'access_code' => $merchant['access_code'],
                'merchant_identifier' => $merchant['merchant_identifier'],
                'merchant_reference' => $merchantReference,
                'amount' => $this->formatAmount($amount),
                'currency' => $this->currency,
                'language' => $this->language,
                'fort_id' => $fortId,
            ];

            $params['signature'] = $this->generateSignature($params, 'request');

            $apiUrl = $this->isTestMode()
                ? 'https://sbpaymentservices.payfort.com/FortAPI/paymentApi'
                : 'https://paymentservices.payfort.com/FortAPI/paymentApi';

            // PayFort REST (maintenance) API requires a JSON body — form-encoding returns an HTML 500.
            $response = Http::asJson()->post($apiUrl, $params)->json() ?? [];

            $this->log('info', 'Capture response received', [
                'status' => $response['status'] ?? null,
                'response_code' => $response['response_code'] ?? null,
                'response_message' => $response['response_message'] ?? null,
                'full_response' => $response,
            ]);

            $isSuccess = in_array($response['status'] ?? '', ['04', '14']);

            return new PaymentResponse(
                success: $isSuccess,
                transactionId: $merchantReference,
                status: $isSuccess ? 'completed' : 'failed',
                amount: $amount,
                currency: $this->currency,
                message: $response['response_message'] ?? ($isSuccess ? 'Capture successful' : 'Capture failed'),
                data: $response
            );

        } catch (\Exception $e) {
            $this->log('error', 'Capture failed', [
                'error' => $e->getMessage(),
                'fort_id' => $fortId,
            ]);

            return new PaymentResponse(
                success: false,
                transactionId: $merchantReference,
                status: 'failed',
                message: $e->getMessage()
            );
        }
    }

    public function voidAuthorization(string $fortId, string $merchantReference, ?string $paymentOption = null): PaymentResponse
    {
        try {
            $this->log('info', '========== START VOID AUTHORIZATION ==========', [
                'fort_id' => $fortId,
                'merchant_reference' => $merchantReference,
                'payment_option' => $paymentOption,
            ]);

            $merchant = $this->resolveMerchantContext($paymentOption);

            $params = [
                'command' => 'VOID_AUTHORIZATION',
                'access_code' => $merchant['access_code'],
                'merchant_identifier' => $merchant['merchant_identifier'],
                'merchant_reference' => $merchantReference,
                'language' => $this->language,
                'fort_id' => $fortId,
            ];

            $params['signature'] = $this->generateSignature($params, 'request');

            $apiUrl = $this->isTestMode()
                ? 'https://sbpaymentservices.payfort.com/FortAPI/paymentApi'
                : 'https://paymentservices.payfort.com/FortAPI/paymentApi';

            // PayFort REST (maintenance) API requires a JSON body — form-encoding returns an HTML 500.
            $response = Http::asJson()->post($apiUrl, $params)->json() ?? [];

            $this->log('info', 'Void response received', [
                'status' => $response['status'] ?? null,
                'response_code' => $response['response_code'] ?? null,
                'full_response' => $response,
            ]);

            $isSuccess = in_array($response['status'] ?? '', ['08', '11']);

            return new PaymentResponse(
                success: $isSuccess,
                transactionId: $merchantReference,
                status: $isSuccess ? 'voided' : 'failed',
                message: $response['response_message'] ?? ($isSuccess ? 'Authorization voided' : 'Void failed'),
                data: $response
            );

        } catch (\Exception $e) {
            $this->log('error', 'Void authorization failed', [
                'error' => $e->getMessage(),
                'fort_id' => $fortId,
            ]);

            return new PaymentResponse(
                success: false,
                transactionId: $merchantReference,
                status: 'failed',
                message: $e->getMessage()
            );
        }
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        try {
            $this->log('info', '========== START REFUND ==========', [
                'transaction_id' => $request->transactionId,
                'amount' => $request->amount,
                'reason' => $request->reason,
                'full_request' => $request->toArray(),
            ]);

            $merchant = $this->resolveMerchantContext($request->paymentOption);

            $params = [
                'command' => 'REFUND',
                'access_code' => $merchant['access_code'],
                'merchant_identifier' => $merchant['merchant_identifier'],
                'merchant_reference' => $request->transactionId,
                'amount' => $this->formatAmount($request->amount),
                'currency' => $this->currency,
                'language' => $this->language,
            ];

            $this->log('info', 'Refund params before signature', [
                'params' => $params,
                'merchant_id_used' => $merchant['merchant_identifier'],
                'access_code_length' => strlen($merchant['access_code']),
            ]);

            $params['signature'] = $this->generateSignature($params, 'request');

            $apiUrl = $this->isTestMode()
                ? 'https://sbpaymentservices.payfort.com/FortAPI/paymentApi'
                : 'https://paymentservices.payfort.com/FortAPI/paymentApi';

            $this->log('info', 'Sending refund request to PayFort', [
                'api_url' => $apiUrl,
                'test_mode' => $this->isTestMode(),
            ]);

            // PayFort REST (maintenance) API requires a JSON body — form-encoding returns an HTML 500.
            $response = Http::asJson()
                ->post($apiUrl, $params)
                ->json() ?? [];

            $this->log('info', 'Refund response received', [
                'status' => $response['status'] ?? null,
                'response_code' => $response['response_code'] ?? null,
                'response_message' => $response['response_message'] ?? null,
                'fort_id' => $response['fort_id'] ?? null,
                'full_response' => $response,
            ]);

            $isSuccess = in_array($response['status'] ?? '', ['06', '11']); // 06=Refunded, 11=Refund Pending

            $this->log('info', '========== END REFUND ==========', [
                'is_success' => $isSuccess,
                'mapped_status' => $this->mapStatus($response['status'] ?? '00'),
            ]);

            return new RefundResponse(
                success: $isSuccess,
                refundId: $response['fort_id'] ?? null,
                status: $this->mapStatus($response['status'] ?? '00'),
                amount: $request->amount,
                message: $response['response_message'] ?? 'Refund processed',
                data: $response
            );

        } catch (\Exception $e) {
            $this->log('error', 'Amazon Payment Services refund failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
            $this->log('info', '========== START GET PAYMENT STATUS ==========', [
                'transaction_id' => $transactionId,
            ]);

            $params = [
                'query_command' => 'CHECK_STATUS',
                'access_code' => $this->accessCode,
                'merchant_identifier' => $this->merchantId,
                'merchant_reference' => $transactionId,
                'language' => $this->language,
            ];

            $this->log('info', 'Status check params before signature', [
                'params' => $params,
            ]);

            $params['signature'] = $this->generateSignature($params, 'request');

            $apiUrl = $this->isTestMode()
                ? 'https://sbpaymentservices.payfort.com/FortAPI/paymentApi'
                : 'https://paymentservices.payfort.com/FortAPI/paymentApi';

            // PayFort REST (maintenance) API requires a JSON body — form-encoding returns an HTML 500.
            $response = Http::asJson()
                ->post($apiUrl, $params)
                ->json() ?? [];

            $mappedStatus = $this->mapStatus($response['status'] ?? '00');

            $this->log('info', '========== END GET PAYMENT STATUS ==========', [
                'transaction_id' => $transactionId,
                'raw_status' => $response['status'] ?? null,
                'response_code' => $response['response_code'] ?? null,
                'response_message' => $response['response_message'] ?? null,
                'mapped_status' => $mappedStatus,
                'full_response' => $response,
            ]);

            return $mappedStatus;

        } catch (\Exception $e) {
            $this->log('error', 'Failed to get payment status', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
            ]);

            return 'unknown';
        }
    }

    /**
     * Run a server-side CHECK_STATUS query against the PayFort REST (maintenance) API
     * and return the raw decoded response (status, response_code, fort_id,
     * merchant_reference, ...). The request is signed, so PayFort's reply is
     * authoritative without a redirect signature. Returns [] on any failure.
     *
     * Shared by getPaymentStatus() and the pull-confirmation fallback in verifyPayment().
     *
     * @return array<string,mixed>
     */
    private function queryGatewayStatus(string $merchantReference): array
    {
        try {
            $params = [
                'query_command' => 'CHECK_STATUS',
                'access_code' => $this->accessCode,
                'merchant_identifier' => $this->merchantId,
                'merchant_reference' => $merchantReference,
                'language' => $this->language,
            ];

            $params['signature'] = $this->generateSignature($params, 'request');

            $apiUrl = $this->isTestMode()
                ? 'https://sbpaymentservices.payfort.com/FortAPI/paymentApi'
                : 'https://paymentservices.payfort.com/FortAPI/paymentApi';

            // PayFort REST (maintenance) API requires a JSON body — form-encoding returns an HTML 500.
            return Http::asJson()->post($apiUrl, $params)->json() ?? [];
        } catch (\Throwable $e) {
            $this->log('error', 'CHECK_STATUS query failed', [
                'error' => $e->getMessage(),
                'merchant_reference' => $merchantReference,
            ]);

            return [];
        }
    }

    /**
     * Verify the configured PayFort credentials WITHOUT taking a payment.
     *
     * Runs a CHECK_STATUS query against the PayFort REST API using the resolved
     * merchant_identifier + access_code + SHA request phrase. PayFort accepting
     * the request (any response that is not a signature / merchant / access-code
     * error) proves the credentials are valid; a signature or merchant error
     * proves they are wrong — all without charging a card.
     *
     * @return array<string,mixed>
     */
    public function verifyCredentials(bool $live = true): array
    {
        $configReport = [
            'mode' => $this->isTestMode() ? 'TEST (sandbox)' : 'LIVE (production)',
            'checkout_url' => $this->apiUrl,
            'currency' => $this->currency,
            'language' => $this->language,
            'command' => $this->getConfig('command', 'PURCHASE'),
            'merchant_id' => $this->merchantId,
            'merchant_id_length' => strlen($this->merchantId),
            'access_code_length' => strlen($this->accessCode),
            'sha_request_phrase_length' => strlen($this->shaRequestPhrase),
            'sha_response_phrase_length' => strlen($this->shaResponsePhrase),
            'stcpay_merchant_id' => $this->stcpayMerchantId,
            'stcpay_access_code_set' => $this->stcpayAccessCode !== '',
            'stcpay_sha_phrases_set' => $this->stcpayShaRequestPhrase !== '' && $this->stcpayShaResponsePhrase !== '',
            'card_config_valid' => $this->validateConfiguration(),
        ];

        // Offline check: build a sample request signature so a wrong/empty SHA
        // phrase surfaces immediately even when the network call cannot run.
        $sampleReference = 'CRED-CHECK-'.strtoupper(Str::random(8));
        $sampleParams = [
            'query_command' => 'CHECK_STATUS',
            'access_code' => $this->accessCode,
            'merchant_identifier' => $this->merchantId,
            'merchant_reference' => $sampleReference,
            'language' => $this->language,
        ];

        try {
            $sampleParams['signature'] = $this->generateSignature($sampleParams, 'request');
        } catch (\Throwable $e) {
            return [
                'accepted' => false,
                'verdict' => 'misconfigured',
                'message' => 'Could not build a request signature: '.$e->getMessage(),
                'config' => $configReport,
            ];
        }

        if (! $live) {
            return [
                'accepted' => null,
                'verdict' => 'offline_ok',
                'message' => 'Configuration is well-formed and a request signature was generated. Run the live check to confirm PayFort accepts the credentials.',
                'config' => $configReport,
                'sample_signature' => $sampleParams['signature'],
            ];
        }

        $apiUrl = $this->isTestMode()
            ? 'https://sbpaymentservices.payfort.com/FortAPI/paymentApi'
            : 'https://paymentservices.payfort.com/FortAPI/paymentApi';

        // The PayFort REST (maintenance/query) API expects a JSON body — NOT
        // form-encoding. A form-encoded request makes it return an HTML 500 page.
        try {
            $httpResponse = Http::asJson()->timeout(20)->post($apiUrl, $sampleParams);
            $response = $httpResponse->json();
        } catch (\Throwable $e) {
            return [
                'accepted' => false,
                'verdict' => 'network_error',
                'message' => 'Could not reach PayFort: '.$e->getMessage(),
                'config' => $configReport,
                'api_endpoint' => $apiUrl,
            ];
        }

        // Non-JSON body (e.g. PayFort's HTML error page) → request was malformed.
        if (! is_array($response)) {
            return [
                'accepted' => false,
                'verdict' => 'unexpected_response',
                'message' => 'PayFort did not return JSON (HTTP '.$httpResponse->status().'). The request was likely malformed.',
                'config' => $configReport,
                'api_endpoint' => $apiUrl,
                'raw_response' => substr($httpResponse->body(), 0, 500),
            ];
        }

        $responseCode = (string) ($response['response_code'] ?? '');
        $responseMessage = (string) ($response['response_message'] ?? '');

        // Did PayFort sign its reply with OUR SHA RESPONSE phrase? If so, the
        // SHA response phrase is also correct (language-independent proof).
        $responseSignatureValid = false;
        if (! empty($response['signature'])) {
            try {
                $expected = $this->generateSignature($response, 'response');
                $responseSignatureValid = hash_equals($expected, (string) $response['signature']);
            } catch (\Throwable $e) {
                $responseSignatureValid = false;
            }
        }

        // Classify by response code (messages may be localised Arabic, so don't
        // rely on text). PayFort returning ANY signed business response means the
        // request authenticated — only signature / merchant errors mean bad creds.
        $signatureErrorCodes = ['00006', '66007'];
        $merchantErrorCodes = ['00009'];

        if (in_array($responseCode, $signatureErrorCodes, true) || str_contains(strtolower($responseMessage), 'signature')) {
            $verdict = 'bad_signature';
            $accepted = false;
            $message = 'PayFort rejected the SHA signature — the SHA REQUEST phrase is wrong for this account.';
        } elseif (in_array($responseCode, $merchantErrorCodes, true) || str_contains(strtolower($responseMessage), 'merchant')) {
            $verdict = 'bad_merchant';
            $accepted = false;
            $message = 'PayFort rejected the merchant identifier — wrong merchant ID, or it does not match the access code/account.';
        } elseif ($responseCode !== '') {
            // e.g. 12036 "order not found" on the random reference — the credentials authenticated fine.
            $verdict = 'accepted';
            $accepted = true;
            $message = 'PayFort ACCEPTED the credentials — merchant ID, access code and SHA request phrase are valid'
                .($responseSignatureValid ? ' (and the SHA response phrase verified too).' : '.')
                .' Gateway reply: '.($responseMessage ?: $responseCode);
        } else {
            $verdict = 'unknown';
            $accepted = false;
            $message = 'Unexpected / empty response from PayFort — inspect the raw response below.';
        }

        return [
            'accepted' => $accepted,
            'verdict' => $verdict,
            'message' => $message,
            'response_code' => $responseCode,
            'response_message' => $responseMessage,
            'response_signature_valid' => $responseSignatureValid,
            'http_status' => $httpResponse->status(),
            'config' => $configReport,
            'api_endpoint' => $apiUrl,
            'raw_response' => $response,
        ];
    }

    public function validateConfiguration(): bool
    {
        $this->log('info', '===== validateConfiguration: START =====', []);

        $isValid = ! empty($this->merchantId)
            && ! empty($this->accessCode)
            && ! empty($this->shaRequestPhrase)
            && ! empty($this->shaResponsePhrase);

        $this->log('info', 'validateConfiguration: Card credentials check', [
            'is_valid' => $isValid,
            'has_merchant_id' => ! empty($this->merchantId),
            'merchant_id_value' => $this->merchantId,
            'merchant_id_length' => strlen($this->merchantId),
            'has_access_code' => ! empty($this->accessCode),
            'access_code_length' => strlen($this->accessCode),
            'has_sha_request' => ! empty($this->shaRequestPhrase),
            'sha_request_length' => strlen($this->shaRequestPhrase),
            'has_sha_response' => ! empty($this->shaResponsePhrase),
            'sha_response_length' => strlen($this->shaResponsePhrase),
        ]);

        if (! $isValid) {
            $this->log('error', 'Invalid PayFort configuration — missing card credentials', []);
        }

        $this->log('info', 'validateConfiguration: STCPAY credentials check', [
            'has_stcpay_merchant_id' => ! empty($this->stcpayMerchantId),
            'stcpay_merchant_id' => $this->stcpayMerchantId,
            'stcpay_merchant_id_length' => strlen($this->stcpayMerchantId),
            'has_stcpay_access_code' => ! empty($this->stcpayAccessCode),
            'stcpay_access_code_length' => strlen($this->stcpayAccessCode),
            'has_stcpay_sha_request' => ! empty($this->stcpayShaRequestPhrase),
            'stcpay_sha_request_length' => strlen($this->stcpayShaRequestPhrase),
            'has_stcpay_sha_response' => ! empty($this->stcpayShaResponsePhrase),
            'stcpay_sha_response_length' => strlen($this->stcpayShaResponsePhrase),
            'stcpay_fully_configured' => ! empty($this->stcpayMerchantId) && ! empty($this->stcpayAccessCode)
                && ! empty($this->stcpayShaRequestPhrase) && ! empty($this->stcpayShaResponsePhrase),
        ]);

        if (empty($this->stcpayMerchantId)) {
            $this->log('warning', 'STCPAY merchant ID not configured, STCPAY payments will fail', []);
        }

        $this->log('info', '===== validateConfiguration: END =====', ['result' => $isValid]);

        return $isValid;
    }

    /**
     * Normalize scheme/host for return_url (http on localhost) but **preserve query string**.
     * Wallet deposits require ?wallet_deposit=1&reference=WALLET-... on the callback URL so
     * PaymentController can detect wallet flow. Stripping the query broke wallet crediting.
     */
    private function cleanReturnUrl(string $url): string
    {
        $parsedUrl = parse_url($url);

        if (! $parsedUrl) {
            $this->log('warning', 'Invalid return URL format', ['url' => $url]);

            return $url;
        }

        $scheme = 'https';
        if (isset($parsedUrl['host']) && (
            $parsedUrl['host'] === 'localhost' ||
            $parsedUrl['host'] === '127.0.0.1' ||
            str_ends_with($parsedUrl['host'], '.test') ||
            str_ends_with($parsedUrl['host'], '.local')
        )) {
            $scheme = $parsedUrl['scheme'] ?? 'http';
        }

        $cleanUrl = $scheme.'://';
        $cleanUrl .= $parsedUrl['host'] ?? '';

        if (isset($parsedUrl['port'])) {
            $cleanUrl .= ':'.$parsedUrl['port'];
        }

        if (isset($parsedUrl['path'])) {
            $cleanUrl .= $parsedUrl['path'];
        }

        if (isset($parsedUrl['query'])) {
            $cleanUrl .= '?'.$parsedUrl['query'];
            $this->log('info', 'return_url query preserved (required for wallet_deposit callback)', [
                'has_wallet_deposit' => str_contains($parsedUrl['query'], 'wallet_deposit'),
            ]);
        }

        $this->log('info', 'Final cleaned return_url', [
            'original_scheme' => $parsedUrl['scheme'] ?? 'none',
            'forced_scheme' => $scheme,
            'final_url' => $cleanUrl,
        ]);

        return $cleanUrl;
    }

    /**
     * Get appropriate response message based on status and error code
     */
    private function getResponseMessage(array $response, bool $isSuccess): string
    {
        $this->log('info', '===== getResponseMessage: START =====', [
            'is_success' => $isSuccess,
            'response_code' => $response['response_code'] ?? null,
            'response_message' => $response['response_message'] ?? null,
        ]);

        if ($isSuccess) {
            $msg = $response['response_message'] ?? 'Payment completed successfully';
            $this->log('info', 'getResponseMessage: SUCCESS branch', ['message' => $msg]);

            return $msg;
        }

        $errorCode = $response['response_code'] ?? null;

        $errorMessages = [
            '00006' => 'خطأ تقني في معالجة الدفع. يرجى المحاولة مرة أخرى.',
            '00007' => 'معلومات الدفع غير صحيحة. يرجى التحقق من البيانات.',
            '00008' => 'خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.',
            '00009' => 'عملية دفع مكررة. يرجى التحقق من حالة الطلب.',
            '00010' => 'تم رفض العملية من البنك. يرجى استخدام بطاقة أخرى.',
            '00011' => 'رصيد غير كافٍ. يرجى استخدام بطاقة أخرى.',
            '00012' => 'بطاقة منتهية الصلاحية. يرجى استخدام بطاقة أخرى.',
            '00013' => 'رقم البطاقة غير صحيح. يرجى التحقق من البيانات.',
            '00014' => 'لا توجد صلاحية لإتمام هذه العملية.',
            '89666' => 'رفضت stc pay العملية (محفظة stc pay على رقم الجوال وليس رصيد نتيفة). تحقق من تفعيل stc pay والموافقة في التطبيق. / STC Pay declined (stc pay wallet on this phone — not your Nathefah balance). Enroll and approve in the stc pay app.',
            '00015' => 'البنك غير متاح حالياً. يرجى المحاولة لاحقاً.',
            // 89666: issuer decline on STC Pay channel (not Nathefah app wallet balance)
        ];

        $result = $errorMessages[$errorCode] ?? ($response['response_message'] ?? 'فشلت عملية الدفع. يرجى المحاولة مرة أخرى.');

        $this->log('info', '===== getResponseMessage: END =====', [
            'error_code' => $errorCode,
            'matched_known_error' => isset($errorMessages[$errorCode]),
            'final_message' => $result,
        ]);

        return $result;
    }

    /**
     * Card payment options that support PayFort remember_me / token_name.
     */
    private function paymentOptionSupportsTokenization(string $normalizedOption): bool
    {
        return in_array($normalizedOption, ['VISA', 'MASTERCARD', 'MADA'], true);
    }

    /**
     * Format amount (multiply by 100 for PayFort)
     */
    private function formatAmount(float $amount): int
    {
        $result = (int) round($amount * 100);
        $this->log('debug', 'formatAmount', [
            'input_float' => $amount,
            'output_int' => $result,
            'formula' => "{$amount} * 100 = {$result}",
        ]);

        return $result;
    }

    /**
     * Normalize a KSA mobile number to E.164 (+9665XXXXXXXX) for APS/PayFort.
     * Local formats (05XXXXXXXX) are rejected by APS with error 00027; E.164 is accepted.
     */
    private function formatPhone(?string $phone): ?string
    {
        $this->log('info', '===== formatPhone: START =====', [
            'raw_input' => $phone,
            'raw_input_length' => $phone ? strlen($phone) : 0,
            'raw_input_type' => gettype($phone),
        ]);

        if (empty($phone)) {
            $this->log('warning', 'formatPhone: EMPTY phone input — returning null', []);

            return null;
        }

        $originalPhone = $phone;
        // Remove all non-numeric characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        $this->log('info', 'formatPhone: After stripping non-numeric chars', [
            'before_strip' => $originalPhone,
            'after_strip' => $phone,
            'chars_removed' => strlen($originalPhone) - strlen($phone),
        ]);

        $formatDetected = 'UNKNOWN';
        $result = $phone;

        // APS/PayFort rejects local KSA formats (error 00027 "customer_phone invalid"),
        // so normalize every recognized KSA mobile to E.164 (+9665XXXXXXXX), the
        // international format APS accepts. Unrecognized input is passed through.
        // Already E.164 (+9665...)
        if (str_starts_with($phone, '+9665')) {
            $formatDetected = 'INTERNATIONAL_PLUS (+9665...)';
            $result = $phone; // PayFort accepts +9665...
        }
        // 9665XXXXXXXX (12 digits, no +) -> +9665XXXXXXXX
        elseif (str_starts_with($phone, '9665') && strlen($phone) === 12) {
            $formatDetected = 'INTERNATIONAL_NO_PLUS (9665...) -> E.164';
            $result = '+'.$phone;
        }
        // 009665XXXXXXXX (14 digits, 00 prefix) -> +9665XXXXXXXX
        elseif (str_starts_with($phone, '009665') && strlen($phone) === 14) {
            $formatDetected = 'INTERNATIONAL_00_PREFIX (009665...) -> E.164';
            $result = '+'.substr($phone, 2);
        }
        // 05XXXXXXXX (local, 10 digits) -> +9665XXXXXXXX
        elseif (str_starts_with($phone, '05') && strlen($phone) === 10) {
            $formatDetected = 'LOCAL_05 (05XXXXXXXX) -> E.164';
            $result = '+966'.substr($phone, 1);
        }
        // 5XXXXXXXX (9 digits, missing leading 0) -> +9665XXXXXXXX
        elseif (str_starts_with($phone, '5') && strlen($phone) === 9) {
            $formatDetected = 'LOCAL_NO_ZERO (5XXXXXXXX) -> E.164';
            $result = '+966'.$phone;
        } else {
            $formatDetected = 'NO_MATCH_PASSTHROUGH';
            $result = $phone;
        }

        $this->log('info', '===== formatPhone: END =====', [
            'raw_input' => $originalPhone,
            'cleaned_input' => $phone,
            'format_detected' => $formatDetected,
            'formatted_output' => $result,
            'output_length' => strlen($result),
            'starts_with_05' => str_starts_with($result, '05'),
            'starts_with_plus9665' => str_starts_with($result, '+9665'),
            'is_valid_stcpay_format' => (str_starts_with($result, '05') && strlen($result) === 10) || str_starts_with($result, '+9665'),
        ]);

        return $result;
    }

    /**
     * Parse amount
     */
    private function parseAmount(int $amount): float
    {
        $result = round($amount / 100, 2);
        $this->log('debug', 'parseAmount', [
            'input_int' => $amount,
            'output_float' => $result,
        ]);

        return $result;
    }

    /**
     * Map status codes to internal status
     */
    private function mapStatus(string $statusCode): string
    {
        $mapped = match ($statusCode) {
            '02' => 'authorized',
            '14' => 'completed',
            '04' => 'completed', // CAPTURE success (consistent with capture(): status 04 = captured)
            '18' => 'on_hold',
            '06' => 'refunded',
            '10' => 'partially_refunded',
            '11' => 'voided',
            '03', '00', '01', '05', '07', '08', '09', '12', '13', '15', '16', '17', '19', '20', '89' => 'failed',
            default => 'unknown',
        };

        $this->log('debug', 'mapStatus', [
            'input_status_code' => $statusCode,
            'mapped_status' => $mapped,
        ]);

        return $mapped;
    }

    /**
     * Get payment form HTML
     */
    public function getPaymentFormHtml(array $params): string
    {
        $this->log('info', '===== getPaymentFormHtml: START =====', [
            'params_count' => count($params),
            'param_keys' => array_keys($params),
            'api_url' => $this->apiUrl,
            'has_signature' => isset($params['signature']),
            'payment_option' => $params['payment_option'] ?? 'NOT_SET',
            'merchant_identifier' => $params['merchant_identifier'] ?? 'NOT_SET',
        ]);

        $form = '<form id="payfort_payment_form" method="POST" action="'.htmlspecialchars($this->apiUrl).'">';

        foreach ($params as $key => $value) {
            $form .= '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($value).'">';
        }

        $form .= '</form>';
        $form .= '<script>document.getElementById("payfort_payment_form").submit();</script>';

        $this->log('info', '===== getPaymentFormHtml: END =====', [
            'form_length' => strlen($form),
        ]);

        return $form;
    }
}
