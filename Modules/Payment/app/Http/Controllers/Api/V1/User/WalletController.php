<?php

namespace Modules\Payment\Http\Controllers\Api\V1\User;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Address\Models\Address;
use Modules\Client\Models\ClientCard;
use Modules\Discount\Services\DiscountService;
use Modules\Payment\DTOs\PaymentRequest;
use Modules\Payment\Models\PaymentMethod as PaymentMethodRecord;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\ActiveGatewayResolver;
use Modules\Payment\Services\PaymentService;
use Modules\Payment\Services\WalletDepositCreditor;

class WalletController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private WalletDepositCreditor $walletDepositCreditor,
        private DiscountService $discountService,
    ) {}

    /**
     * Get wallet details (balance, transactions, cards)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get wallet balance
        $balance = (float) ($user->wallet_balance ?? 0);
        $holdAmount = (float) ($user->wallet_hold_amount ?? 0);
        $availableBalance = max(0.0, round($balance - $holdAmount, 2));

        // Get recent transactions with pagination
        $transactionsQuery = DB::table('wallet_transactions')
            ->where('client_id', $user->id)
            ->orderBy('created_at', 'desc');

        $transactions = $transactionsQuery->paginate($request->per_page ?? 15);

        // Transform transaction items
        $transactions->getCollection()->transform(fn ($txn) => $this->formatWalletTransactionRow($txn));

        $cards = ClientCard::query()
            ->where('client_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at')
            ->paginate($request->cards_per_page ?? 15);

        $cards->getCollection()->transform(function (ClientCard $card) {
            return [
                'card_id' => $card->id,
                'card_type' => $card->card_brand,
                'card_name' => $card->card_holder_name,
                'card_number' => $card->maskedNumber(),
                'expiry_date' => $card->expiry_date,
                'is_default' => $card->is_default,
            ];
        });

        // Return wallet data with pagination meta at the top level
        return successResponse([
            'balance' => (float) $balance,
            'available_balance' => $availableBalance,
            'hold_amount' => $holdAmount,
            'transactions' => $transactions->items(),
            'cards' => $cards->items(),
        ], __('client.wallet_details_retrieved'), 200, [
            'transactions' => paginationMeta($transactions),
            'cards' => paginationMeta($cards),
        ]);
    }

    /**
     * Cards are tokenized automatically via PayFort when the client pays with Visa/Mastercard/Mada.
     */
    public function addCard(Request $request): JsonResponse
    {
        return errorResponse(
            __('client.cards_saved_via_checkout'),
            400
        );
    }

    /**
     * Delete credit card
     */
    public function deleteCard(Request $request, int $card_id): JsonResponse
    {
        $user = $request->user();

        $card = ClientCard::query()
            ->where('id', $card_id)
            ->where('client_id', $user->id)
            ->first();

        if (! $card) {
            return notFoundResponse(__('client.card_not_found'));
        }

        $wasDefault = $card->is_default;
        $card->delete();

        if ($wasDefault) {
            $next = ClientCard::query()
                ->where('client_id', $user->id)
                ->orderByDesc('last_used_at')
                ->first();
            if ($next) {
                $next->update(['is_default' => true]);
            }
        }

        return successResponse(null, __('client.card_deleted'));
    }

    /**
     * Add deposit to wallet
     */
    public function addDeposit(Request $request): JsonResponse
    {
        $blockedMethods = [
            PaymentMethod::CASH_ON_DELIVERY->value,
            PaymentMethod::Nathefah_WALLET->value,
        ];
        $gatewayMethods = array_values(array_filter(
            PaymentMethod::values(),
            fn ($method) => ! in_array($method, $blockedMethods, true)
        ));

        $resolvedMethod = PaymentMethodRecord::resolveFromClientInput(
            $request->input('payment_method')
            ?? $request->input('payment_methods')
            ?? $request->input('method')
            ?? $request->input('type')
        );

        if ($resolvedMethod !== null && in_array($resolvedMethod, $blockedMethods, true)) {
            return validationErrorResponse([
                'payment_method' => [__('validation.in', ['attribute' => 'payment method'])],
            ]);
        }

        if ($resolvedMethod === null || ! in_array($resolvedMethod, $gatewayMethods, true)) {
            $resolvedMethod = PaymentMethod::CREDIT_CARD->value;
        }

        $request->merge(['payment_method' => $resolvedMethod]);

        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'string', Rule::in($gatewayMethods)],
            'card_id' => ['nullable', 'integer', 'exists:client_cards,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();
        $paymentMethod = PaymentMethod::normalize((string) $request->payment_method);
        if (ActiveGatewayResolver::name() === 'moyasar'
            && in_array($paymentMethod, [
                PaymentMethod::VISA->value,
                PaymentMethod::MASTERCARD->value,
                PaymentMethod::MADA->value,
                PaymentMethod::APPLE_PAY->value,
                PaymentMethod::GOOGLE_PAY->value,
                PaymentMethod::SAMSUNG_PAY->value,
                PaymentMethod::CREDIT_CARD->value,
            ], true)
        ) {
            // Same aggregate as order checkout: do not lock the hosted page to mada/visa.
            $paymentMethod = PaymentMethod::CREDIT_CARD->value;
            $request->merge(['payment_method' => $paymentMethod]);
        }

        // Validate card belongs to user if payment method is visa/mastercard AND card_id is provided
        if (in_array($paymentMethod, [
            PaymentMethod::VISA->value,
            PaymentMethod::MASTERCARD->value,
        ]) && $request->has('card_id') && $request->card_id) {
            $card = ClientCard::query()
                ->where('id', $request->card_id)
                ->where('client_id', $user->id)
                ->first();

            if (! $card) {
                return errorResponse('Card not found or does not belong to user', 404);
            }
        }

        // Map payment method to the ACTIVE gateway (admin-selected, DB-backed:
        // amazon_pay <-> moyasar). cash/wallet are settled in-app.
        $gatewayName = PaymentMethod::getGatewayName($paymentMethod);

        try {
            // Set the payment gateway
            $this->paymentService->setGateway($gatewayName);

            // Generate unique reference ID for wallet deposit. The random suffix makes it
            // collision-proof: two deposits by the same client in the same second would
            // otherwise share this reference and the second would collide on the UNIQUE
            // payment_transactions.transaction_id (losing the deposit).
            $walletReferenceId = 'WALLET-'.$user->id.'-'.time().'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));

            // Get customer information
            $customerEmail = $user->email ?? $request->input('customer_email') ?? config('payment.default_customer_email', 'noreply@nathefah.com');
            $customerName = $user->name ?? $request->input('customer_name');
            $customerPhone = $user->phone ?? $request->input('customer_phone');
            $defaultAddress = Address::query()
                ->where('client_id', $user->id)
                ->where('is_default', true)
                ->latest('id')
                ->first()
                ?? Address::query()->where('client_id', $user->id)->latest('id')->first();
            $walletPromotion = $this->discountService->findBestWalletTopupDiscount(
                (float) $request->amount,
                (int) $user->id,
                null,
                $defaultAddress?->city,
                app()->getLocale()
            );

            // Build payment callback URLs
            $baseUrl = config('app.url');
            $returnUrl = $baseUrl.'/api/v1/payments/callback?wallet_deposit=1&reference='.$walletReferenceId;
            $cancelUrl = $baseUrl.'/api/v1/payments/cancel?wallet_deposit=1&reference='.$walletReferenceId;

            try {
                // The Payment module registers routes under the 'api.' name prefix.
                if (Route::has('api.payment.callback')) {
                    $returnUrl = route('api.payment.callback').'?wallet_deposit=1&reference='.$walletReferenceId;
                    $cancelUrl = route('api.payment.cancel').'?wallet_deposit=1&reference='.$walletReferenceId;
                }
            } catch (\Exception $e) {
            }

            // Create payment request
            $paymentRequest = new PaymentRequest(
                amount: (float) $request->amount,
                currency: config('payment.currency', 'SAR'),
                orderId: $walletReferenceId,
                customerEmail: $customerEmail,
                customerName: $customerName,
                customerPhone: $customerPhone,
                returnUrl: $returnUrl,
                cancelUrl: $cancelUrl,
                paymentOption: $gatewayName === 'moyasar' ? null : $this->getPayfortPaymentOption($paymentMethod),
                metadata: [
                    'wallet_deposit' => true,
                    'client_id' => $user->id,
                    'payment_method' => $paymentMethod,
                    'payment_option' => $gatewayName === 'moyasar' ? null : $this->getPayfortPaymentOption($paymentMethod),
                    'card_id' => $request->card_id ?? null,
                    'wallet_bonus_discount_id' => $walletPromotion['applied'] ? $walletPromotion['discount']?->id : null,
                    'wallet_bonus_amount' => $walletPromotion['applied'] ? (float) $walletPromotion['bonus_amount'] : 0,
                ],
                enableTokenization: false,
            );

            // Initialize payment through gateway
            $paymentResponse = $this->paymentService->initializePayment($paymentRequest);

            // Gateway failed - return error (never return 202 for PayFort/redirect gateways)
            if (! $paymentResponse->isSuccessful()) {
                return errorResponse(
                    $paymentResponse->message ?? 'Failed to initialize payment',
                    null,
                    400
                );
            }

            // Save payment transaction
            $paymentTransaction = PaymentTransaction::create([
                'order_id' => null, // Wallet deposits don't have orders
                'gateway' => $this->paymentService->getActiveGateway()->getName(),
                'transaction_id' => $paymentResponse->transactionId ?? $walletReferenceId,
                'amount' => $paymentResponse->amount ?? $request->amount,
                'currency' => $paymentResponse->currency ?? config('payment.currency', 'SAR'),
                'status' => $paymentResponse->status ?? 'pending',
                'payment_method' => $paymentMethod,
                'customer_email' => $customerEmail,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'response_data' => array_merge($paymentResponse->data, [
                    'wallet_reference_id' => $walletReferenceId,
                    'wallet_deposit' => true,
                    'client_id' => $user->id,
                    'wallet_bonus_discount_id' => $walletPromotion['applied'] ? $walletPromotion['discount']?->id : null,
                    'wallet_bonus_amount' => $walletPromotion['applied'] ? (float) $walletPromotion['bonus_amount'] : 0,
                ]),
            ]);

            // PayFort/Amazon (STC Pay, MADA, Visa, etc.) - always has payment_params for redirect
            $paymentParams = $paymentResponse->data['payment_params'] ?? null;
            $paymentUrl = $paymentResponse->paymentUrl ?? $paymentResponse->data['form_url'] ?? null;

            // Fallback: PayFort always uses redirect - URL from config if missing
            if (empty($paymentUrl) && $paymentParams) {
                $testMode = filter_var(config('payment.gateways.amazon_pay.test_mode', false), FILTER_VALIDATE_BOOLEAN);
                $paymentUrl = $testMode
                    ? 'https://sbcheckout.payfort.com/FortAPI/paymentPage'
                    : 'https://checkout.payfort.com/FortAPI/paymentPage';
            }

            if ($paymentUrl || $paymentParams) {
                $redirectUrl = $paymentUrl ?: ($paymentResponse->data['form_url'] ?? null);
                $redirectInstructions = $paymentResponse->redirectInstructions();

                // Generate verify URL
                $verifyUrl = route('user.wallet.deposit.verify', ['transactionId' => $walletReferenceId]);

                $creditCardMethod = collect(PaymentMethodRecord::getActivePayloadForUser(app()->getLocale())['payment_methods'] ?? [])
                    ->firstWhere('value', PaymentMethod::CREDIT_CARD->value);

                return successResponse([
                    'payment_url' => $redirectUrl,
                    'transaction_id' => $paymentTransaction->transaction_id,
                    'status' => $paymentTransaction->status,
                    'reference_id' => $walletReferenceId,
                    'wallet_bonus_amount' => $walletPromotion['applied'] ? (float) $walletPromotion['bonus_amount'] : 0,
                    'wallet_bonus_discount' => $walletPromotion['applied'] ? [
                        'id' => $walletPromotion['discount']?->id,
                        'code' => $walletPromotion['discount']?->code,
                        'name' => method_exists($walletPromotion['discount'], 'getTranslation')
                            ? $walletPromotion['discount']?->getTranslation('name', app()->getLocale())
                            : $walletPromotion['discount']?->name,
                    ] : null,
                    'verify_url' => $verifyUrl,
                    'payment_params' => $paymentParams,
                    'payment_method' => $paymentMethod,
                    'payment_method_type' => 'redirect',
                    'redirect_instructions' => $redirectInstructions,
                    'mode' => $paymentResponse->data['mode'] ?? null,
                    'moyasar' => $paymentResponse->data['moyasar'] ?? null,
                    'available_methods' => $paymentResponse->data['available_methods'] ?? [
                        PaymentMethod::CREDIT_CARD->value,
                        PaymentMethod::VISA->value,
                        PaymentMethod::MASTERCARD->value,
                        PaymentMethod::MADA->value,
                        PaymentMethod::STC_PAY->value,
                        PaymentMethod::APPLE_PAY->value,
                        PaymentMethod::SAMSUNG_PAY->value,
                    ],
                    'grouped_method_values' => $creditCardMethod['grouped_method_values'] ?? [
                        PaymentMethod::VISA->value,
                        PaymentMethod::MASTERCARD->value,
                        PaymentMethod::MADA->value,
                        PaymentMethod::STC_PAY->value,
                        PaymentMethod::APPLE_PAY->value,
                        PaymentMethod::SAMSUNG_PAY->value,
                    ],
                ], 'Payment initialized. Please complete payment.', 200);
            }

            // If payment is synchronous and successful, update wallet balance immediately.
            // Only a CAPTURED ('completed') deposit credits balance — a bare 'authorized'
            // hold must not (under AUTHORIZATION the redirect/callback path captures it first).
            if ($paymentResponse->isSuccessful() && $paymentResponse->status === 'completed') {
                DB::beginTransaction();
                try {
                    $paymentTransaction->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                    ]);

                    $settlement = $this->walletDepositCreditor->creditIfNotAlready(
                        $paymentTransaction->fresh(),
                        'Nathefah Wallet deposit'
                    );

                    DB::commit();

                    $newBalance = DB::table('clients')
                        ->where('id', $user->id)
                        ->value('wallet_balance');

                    $walletTxn = $settlement['wallet_txn'];

                    return successResponse([
                        'transaction' => [
                            'wallet_txn_id' => $walletTxn?->id,
                            'payment_transaction_id' => $paymentTransaction->id,
                            'transaction_id' => $paymentTransaction->transaction_id,
                            'amount' => (float) $request->amount,
                            'payment_method' => $paymentMethod,
                            'status' => 'completed',
                            'date' => now()->toISOString(),
                        ],
                        'new_balance' => (float) $newBalance,
                        'wallet_bonus_amount' => $walletPromotion['applied'] ? (float) $walletPromotion['bonus_amount'] : 0,
                        'verify_url' => route('user.wallet.deposit.verify', ['transactionId' => $walletReferenceId]),
                    ], __('client.deposit_added'), 201);

                } catch (\Exception $e) {
                    DB::rollBack();

                    return serverErrorResponse('Failed to process deposit: '.$e->getMessage());
                }
            }

            // PayFort/amazon_pay must return payment_url and payment_params - if we reach here, something is wrong
            $gatewayName = $this->paymentService->getActiveGateway()?->getName() ?? '';
            if (stripos($gatewayName, 'Amazon') !== false || stripos($gatewayName, 'PayFort') !== false) {
                return errorResponse(
                    'Payment gateway did not return redirect data. Please check merchant credentials and configuration.',
                    null,
                    500
                );
            }

            // Other gateways: payment is pending
            return successResponse([
                'transaction' => [
                    'payment_transaction_id' => $paymentTransaction->id,
                    'transaction_id' => $paymentTransaction->transaction_id,
                    'status' => $paymentTransaction->status,
                    'reference_id' => $walletReferenceId,
                ],
                'verify_url' => route('user.wallet.deposit.verify', ['transactionId' => $walletReferenceId]),
            ], 'Payment is pending. Please check status later.', 202);

        } catch (\Exception $e) {
            return serverErrorResponse('Failed to initialize payment: '.$e->getMessage());
        }
    }

    /**
     * Add direct deposit to wallet (no payment gateway)
     */
    public function addDirectDeposit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();
        $amount = (float) $request->amount;
        $referenceId = 'DIRECT-WALLET-'.$user->id.'-'.time();

        DB::beginTransaction();

        try {
            DB::table('clients')
                ->where('id', $user->id)
                ->increment('wallet_balance', $amount);

            $walletTxnId = DB::table('wallet_transactions')->insertGetId([
                'client_id' => $user->id,
                'type' => 'credit',
                'amount' => $amount,
                'payment_method' => 'direct',
                'description' => 'Nathefah Wallet direct deposit',
                'transaction_id' => $referenceId,
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            $newBalance = DB::table('clients')
                ->where('id', $user->id)
                ->value('wallet_balance');

            return successResponse([
                'transaction' => [
                    'wallet_txn_id' => $walletTxnId,
                    'transaction_id' => $referenceId,
                    'amount' => $amount,
                    'payment_method' => 'direct',
                    'status' => 'completed',
                    'date' => now()->toISOString(),
                ],
                'new_balance' => (float) $newBalance,
            ], 'Deposit added to wallet successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return serverErrorResponse('Failed to process direct deposit: '.$e->getMessage());
        }
    }

    /**
     * Verify a wallet deposit transaction
     */
    public function verifyDeposit(Request $request, string $transactionId): JsonResponse
    {
        $user = $request->user();

        try {
            // Find payment transaction by transaction_id or wallet_reference_id
            $paymentTransaction = PaymentTransaction::where('transaction_id', $transactionId)
                ->orWhereJsonContains('response_data->wallet_reference_id', $transactionId)
                ->first();

            if (! $paymentTransaction) {
                return notFoundResponse(__('payment.transaction_not_found'));
            }

            // Verify this transaction belongs to this user (from metadata)
            $metadata = $paymentTransaction->response_data ?? [];
            if (isset($metadata['client_id']) && $metadata['client_id'] != $user->id) {
                return errorResponse(__('payment.unauthorized_transaction'), 403);
            }

            $lookupIds = array_values(array_unique(array_filter([
                $transactionId,
                $paymentTransaction->transaction_id,
                $metadata['wallet_reference_id'] ?? null,
            ])));

            $existingWalletTxn = $this->walletDepositCreditor->findCompletedWalletTxn((int) $user->id, $lookupIds);

            if ($existingWalletTxn) {
                $currentBalance = DB::table('clients')
                    ->where('id', $user->id)
                    ->value('wallet_balance');

                return successResponse([
                    'status' => 'completed',
                    'message' => __('payment.deposit_already_processed'),
                    'transaction' => [
                        'wallet_txn_id' => $existingWalletTxn->id,
                        'payment_transaction_id' => $paymentTransaction->id,
                        'transaction_id' => $paymentTransaction->transaction_id,
                        'amount' => (float) $existingWalletTxn->amount,
                        'payment_method' => $existingWalletTxn->payment_method,
                        'status' => 'completed',
                        'date' => $existingWalletTxn->created_at,
                    ],
                    'balance' => (float) $currentBalance,
                ], __('payment.deposit_already_verified'));
            }

            $this->paymentService->setGateway(strtolower(str_replace(' ', '_', $paymentTransaction->gateway)));

            $verificationResponse = $this->paymentService->verifyPayment($paymentTransaction->transaction_id);

            $verifiedStatus = in_array($verificationResponse->status, ['pending', 'authorized', 'completed', 'failed', 'cancelled', 'refunded', 'partially_refunded'])
                ? $verificationResponse->status
                : 'failed';

            // Persist the gateway payment id so the capture below (and any later
            // refund/void) can address the Moyasar payment directly.
            $fortId = $verificationResponse->data['fort_id'] ?? $paymentTransaction->fort_id;

            $updateData = [
                'status' => $verifiedStatus,
                'response_data' => array_merge($paymentTransaction->response_data ?? [], $verificationResponse->data),
                'paid_at' => $verificationResponse->isSuccessful() ? now() : null,
            ];
            if ($fortId) {
                $updateData['fort_id'] = $fortId;
            }
            if ($verifiedStatus === 'authorized' && ! $paymentTransaction->authorized_amount) {
                $updateData['authorized_amount'] = $paymentTransaction->amount;
            }
            $paymentTransaction->update($updateData);
            $paymentTransaction = $paymentTransaction->fresh();

            // A wallet top-up has no fulfilment step, so an AUTHORIZATION hold must be
            // captured immediately on this pull/verify path — otherwise, when the gateway
            // runs in AUTHORIZATION mode and BOTH the redirect and the webhook were lost,
            // the hold expires uncaptured and the balance is never credited. Under the
            // PURCHASE command the leg already returns 'completed' and this is skipped.
            // Mirrors the wallet branch of PaymentController::callback().
            if ($verificationResponse->isSuccessful() && $verifiedStatus === 'authorized' && $paymentTransaction->fort_id) {
                $capture = $this->paymentService->capture(
                    $paymentTransaction->fort_id,
                    (float) ($paymentTransaction->authorized_amount ?: $paymentTransaction->amount),
                    $paymentTransaction->transaction_id,
                    $paymentTransaction->payfortPaymentOption()
                );

                if ($capture->isSuccessful()) {
                    $paymentTransaction->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                        'response_data' => array_merge($paymentTransaction->response_data ?? [], $capture->data ?? []),
                    ]);
                    $paymentTransaction = $paymentTransaction->fresh();
                    $verifiedStatus = 'completed';
                } else {
                    Log::channel('payment')->error('Wallet deposit verify: capture of AUTHORIZATION hold failed — balance NOT credited', [
                        'transaction_id' => $paymentTransaction->transaction_id,
                        'message' => $capture->message,
                    ]);

                    return errorResponse(
                        $capture->message ?? __('payment.payment_verification_failed'),
                        400,
                        [
                            'status' => 'authorized',
                            'transaction_id' => $paymentTransaction->transaction_id,
                        ]
                    );
                }
            }

            if ($verificationResponse->isSuccessful() && $verifiedStatus === 'completed') {
                try {
                    $settlement = $this->walletDepositCreditor->creditIfNotAlready(
                        $paymentTransaction->fresh(),
                        'Nathefah Wallet deposit - Verified'
                    );

                    $walletTxn = $settlement['wallet_txn'];
                    $newBalance = DB::table('clients')
                        ->where('id', $user->id)
                        ->value('wallet_balance');

                    return successResponse([
                        'status' => 'completed',
                        'message' => $settlement['credited']
                            ? __('payment.deposit_verified_successfully')
                            : __('payment.deposit_already_processed'),
                        'transaction' => [
                            'wallet_txn_id' => $walletTxn?->id,
                            'payment_transaction_id' => $paymentTransaction->id,
                            'transaction_id' => $paymentTransaction->transaction_id,
                            'amount' => (float) $paymentTransaction->amount,
                            'payment_method' => $metadata['payment_method'] ?? $paymentTransaction->payment_method ?? 'unknown',
                            'status' => 'completed',
                            'date' => now()->toISOString(),
                        ],
                        'balance' => (float) $newBalance,
                    ], $settlement['credited']
                        ? __('payment.deposit_verified_wallet_updated')
                        : __('payment.deposit_already_verified'));
                } catch (\Exception $e) {
                    return serverErrorResponse(__('payment.failed_to_update_wallet').': '.$e->getMessage());
                }
            }

            // Payment verification failed or is still pending
            return errorResponse(
                $verificationResponse->message ?? __('payment.payment_verification_failed'),
                $verificationResponse->status === 'pending' ? 202 : 400,
                [
                    'status' => $verificationResponse->status,
                    'transaction_id' => $paymentTransaction->transaction_id,
                ]
            );

        } catch (\Exception $e) {
            return serverErrorResponse(__('payment.failed_to_verify_deposit').': '.$e->getMessage());
        }
    }

    /**
     * Get transaction history
     */
    public function getTransactions(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = DB::table('wallet_transactions')
            ->where('client_id', $user->id)
            ->orderBy('created_at', 'desc');

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->to_date);
        }

        $transactions = $query->paginate($request->per_page ?? 20);

        $transactions->getCollection()->transform(fn ($txn) => $this->formatWalletTransactionRow($txn));

        // Pass paginator directly to successResponse for automatic meta extraction
        return successResponse($transactions, __('client.transactions_retrieved'));
    }

    /**
     * Locale-aware wallet transaction row (single description / operation_type).
     *
     * @param  object{id:int,amount:mixed,type:string,created_at:mixed,payment_method:?string,description:?string}  $txn
     * @return array<string, mixed>
     */
    private function formatWalletTransactionRow(object $txn): array
    {
        $isAddition = $txn->type === 'credit';

        return [
            'txn_id' => $txn->id,
            'amount' => (float) $txn->amount,
            'type' => $txn->type,
            'date' => $txn->created_at,
            'payment_method' => $txn->payment_method,
            'description' => $this->localizeWalletTransactionDescription((string) ($txn->description ?? '')),
            'operation_type' => $isAddition
                ? __('payment.wallet_txn_addition')
                : __('payment.wallet_txn_deduction'),
            'is_addition' => $isAddition,
        ];
    }

    /**
     * Build a fully localized description from the stored English (or mixed) wallet note.
     */
    private function localizeWalletTransactionDescription(string $raw): string
    {
        $lower = strtolower($raw);
        $orderNumber = $this->extractOrderNumberFromWalletDescription($raw);

        if (str_contains($lower, 'deposit') || str_contains($raw, 'إيداع')) {
            return __('payment.wallet_txn_deposit');
        }

        if ($orderNumber) {
            if (str_contains($lower, 'deleted')) {
                return __('payment.wallet_txn_order_deleted', ['order' => $orderNumber]);
            }

            if (str_contains($lower, 'additional charge') || str_contains($lower, 'order update')) {
                return __('payment.wallet_txn_order_update_charge', ['order' => $orderNumber]);
            }

            if (str_contains($lower, 'refund') || str_contains($raw, 'استرداد')) {
                return __('payment.wallet_txn_order_refund', ['order' => $orderNumber]);
            }

            if (str_contains($lower, 'surcharge')) {
                if (str_contains($lower, 'awaiting')) {
                    return __('payment.wallet_txn_order_surcharge_awaiting', ['order' => $orderNumber]);
                }

                return __('payment.wallet_txn_order_surcharge', ['order' => $orderNumber]);
            }

            if (str_contains($lower, 'reserved')) {
                return __('payment.wallet_txn_order_payment_reserved', ['order' => $orderNumber]);
            }

            if (str_contains($lower, 'awaiting card')) {
                return __('payment.wallet_txn_order_payment_awaiting_card', ['order' => $orderNumber]);
            }

            if (str_contains($lower, 'awaiting gateway') || str_contains($lower, 'awaiting')) {
                return __('payment.wallet_txn_order_payment_awaiting_gateway', ['order' => $orderNumber]);
            }

            if (str_contains($lower, 'payment') || str_contains($raw, 'دفع')) {
                return __('payment.wallet_txn_order_payment', ['order' => $orderNumber]);
            }

            return __('payment.wallet_txn_order_generic', ['order' => $orderNumber]);
        }

        return $raw !== '' ? $raw : __('payment.wallet_txn_deposit');
    }

    private function extractOrderNumberFromWalletDescription(string $raw): ?string
    {
        if (preg_match('/ORD-[A-Z0-9-]+/i', $raw, $matches)) {
            return strtoupper($matches[0]);
        }

        return null;
    }

    /**
     * Get Payfort payment option based on payment method
     * Maps internal payment methods to Payfort payment_option values
     */
    private function getPayfortPaymentOption(string $paymentMethod): ?string
    {
        // Use PaymentMethod enum if available, otherwise fallback to direct mapping
        try {
            $method = PaymentMethod::from($paymentMethod);

            return $method->getPayfortPaymentOption();
        } catch (\ValueError $e) {
            // Fallback for backward compatibility
            return match ($paymentMethod) {
                'visa' => 'VISA',                    // Visa cards
                'mastercard' => 'MASTERCARD',        // MasterCard
                'mada' => 'MADA',                    // Saudi debit cards
                'stc_pay' => 'STCPAY',               // STC Pay
                default => null,
            };
        }
    }
}
