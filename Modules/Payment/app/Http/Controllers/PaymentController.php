<?php

namespace Modules\Payment\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Services\OrderNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderModificationIntent;
use Modules\Order\Models\OrderPayment;
use Modules\Order\Models\OrderStatusLog;
use Modules\Order\Services\OrderPaymentService;
use Modules\Payment\DTOs\PaymentRequest;
use Modules\Payment\DTOs\RefundRequest;
use Modules\Payment\Models\PaymentRefund;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\ClientCardService;
use Modules\Payment\Services\PaymentService;
use Modules\Payment\Services\WalletDepositCreditor;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private ClientCardService $clientCardService,
        private WalletDepositCreditor $walletDepositCreditor,
    ) {}

    /**
     * Resolve a payment callback/cancel URL robustly.
     *
     * The Payment module registers its routes under the 'api.' name prefix
     * (api.payment.callback), so route('payment.callback') is NOT defined and
     * would throw. Try the bare name, then the api-prefixed name, then fall back
     * to the known path.
     */
    private function resolvePaymentRouteUrl(string $name, string $fallbackPath): string
    {
        foreach ([$name, 'api.'.$name] as $candidate) {
            if (Route::has($candidate)) {
                return route($candidate);
            }
        }

        return url($fallbackPath);
    }

    /**
     * Initialize a payment
     */
    public function initializePayment(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'gateway' => 'nullable|string',
            'customer_email' => 'nullable|email',
            'customer_name' => 'nullable|string',
            'customer_phone' => 'nullable|string',
            'payment_option' => 'nullable|string',
        ]);

        try {
            $gatewayKey = ! empty($validated['gateway'])
                ? $validated['gateway']
                : \Modules\Payment\Services\ActiveGatewayResolver::name();
            $this->paymentService->setGateway($gatewayKey);
            $paymentOption = $validated['payment_option'] ?? null;
            $normalizedOption = strtoupper(str_replace('_', '', (string) $paymentOption));
            $enableTokenization = filter_var(config('payment.gateways.amazon_pay.tokenization_enabled', false), FILTER_VALIDATE_BOOLEAN)
                && in_array($normalizedOption, ['VISA', 'MASTERCARD', 'MADA'], true);

            $paymentRequest = new PaymentRequest(
                amount: $validated['amount'],
                currency: $validated['currency'] ?? config('payment.currency', 'SAR'),
                orderId: $validated['order_id'],
                customerEmail: config('payment.default_email', 'no-reply@nathefah.com'),
                customerName: $validated['customer_name'] ?? null,
                customerPhone: $validated['customer_phone'] ?? null,
                returnUrl: $this->resolvePaymentRouteUrl('payment.callback', '/api/v1/payments/callback'),
                cancelUrl: $this->resolvePaymentRouteUrl('payment.cancel', '/api/v1/payments/cancel'),
                paymentOption: $paymentOption,
                metadata: ['order_id' => $validated['order_id']],
                enableTokenization: $enableTokenization,
            );

            $response = $this->paymentService->initializePayment($paymentRequest);

            $transaction = PaymentTransaction::create([
                'order_id' => $validated['order_id'],
                'gateway' => $this->paymentService->getActiveGateway()->getName(),
                'transaction_id' => $response->transactionId,
                'amount' => $response->amount ?? $validated['amount'],
                'currency' => $response->currency ?? $validated['currency'] ?? 'SAR',
                'status' => $response->status ?? 'pending',
                'customer_email' => config('payment.default_email', 'no-reply@nathefah.com'),
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'response_data' => $response->data,
            ]);

            return response()->json([
                'success' => $response->isSuccessful(),
                'message' => $response->message,
                'data' => [
                    'transaction_id' => $response->transactionId,
                    'payment_url' => $response->paymentUrl,
                    'status' => $response->status,
                    'redirect_instructions' => $response->redirectInstructions(),
                ],
            ], $response->isSuccessful() ? 200 : 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify a payment
     */
    public function verifyPayment(Request $request, string $transactionId)
    {
        try {
            $transaction = PaymentTransaction::where('transaction_id', $transactionId)->firstOrFail();

            // Set the gateway that was used for this transaction
            $this->paymentService->setGateway(strtolower(str_replace(' ', '_', $transaction->gateway)));

            // Verify payment
            $response = $this->paymentService->verifyPayment($transactionId);

            // Update transaction
            $transaction->update([
                'status' => in_array($response->status, ['pending', 'authorized', 'completed', 'failed', 'cancelled', 'refunded', 'partially_refunded']) ? $response->status : 'failed',
                'response_data' => array_merge($transaction->response_data ?? [], $response->data),
                'paid_at' => $response->isSuccessful() ? now() : null,
            ]);

            return response()->json([
                'success' => $response->isSuccessful(),
                'message' => $response->message,
                'data' => [
                    'transaction_id' => $response->transactionId,
                    'status' => $response->status,
                    'amount' => $response->amount,
                    'currency' => $response->currency,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process a refund
     */
    public function refund(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string',
        ]);

        try {
            $transaction = PaymentTransaction::where('transaction_id', $validated['transaction_id'])->firstOrFail();

            // Validate refund amount
            $totalRefunded = $transaction->refunds()->sum('amount');
            if (($totalRefunded + $validated['amount']) > $transaction->amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund amount exceeds transaction amount',
                ], 400);
            }

            // Set the gateway
            $this->paymentService->setGateway(strtolower(str_replace(' ', '_', $transaction->gateway)));

            // Create refund request (route to the correct merchant for STC Pay etc.)
            $refundRequest = new RefundRequest(
                transactionId: $validated['transaction_id'],
                amount: $validated['amount'],
                reason: $validated['reason'] ?? null,
                paymentOption: $transaction->payfortPaymentOption(),
                gatewayPaymentId: $transaction->fort_id
            );

            // Process refund
            $response = $this->paymentService->refund($refundRequest);

            // Save refund to database
            if ($response->isSuccessful()) {
                $refund = PaymentRefund::create([
                    'payment_transaction_id' => $transaction->id,
                    'refund_id' => $response->refundId,
                    'amount' => $response->amount ?? $validated['amount'],
                    'currency' => $transaction->currency,
                    'status' => $response->status,
                    'reason' => $validated['reason'] ?? null,
                    'response_data' => $response->data,
                    'processed_at' => now(),
                ]);

                // Update transaction
                $newRefundAmount = $transaction->refund_amount + $validated['amount'];
                $transaction->update([
                    'refund_amount' => $newRefundAmount,
                    'status' => $newRefundAmount >= $transaction->amount ? 'refunded' : 'partially_refunded',
                    'refunded_at' => now(),
                ]);
            }

            return response()->json([
                'success' => $response->isSuccessful(),
                'message' => $response->message,
                'data' => [
                    'refund_id' => $response->refundId,
                    'status' => $response->status,
                    'amount' => $response->amount,
                ],
            ], $response->isSuccessful() ? 200 : 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payment status
     */
    public function getStatus(string $transactionId)
    {
        try {
            $transaction = PaymentTransaction::where('transaction_id', $transactionId)->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'status' => $transaction->status,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'gateway' => $transaction->gateway,
                    'paid_at' => $transaction->paid_at,
                    'refunded_at' => $transaction->refunded_at,
                    'refund_amount' => $transaction->refund_amount,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Handle payment callback (success/return)
     * Supports both GET and POST requests from payment gateways
     *
     * THIS IS THE MAIN CALLBACK ENDPOINT THAT RECEIVES DATA FROM PAYFORT
     */
    public function callback(Request $request)
    {
        Log::channel('payment')->info('==== PAYMENT CALLBACK HIT ====', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'from_webhook' => $request->boolean('from_webhook'),
            'param_keys' => array_keys($request->all()),
            'merchant_reference' => $request->get('merchant_reference'),
            'response_code' => $request->get('response_code'),
            'status' => $request->get('status'),
            'fort_id' => $request->get('fort_id'),
            'order_id' => $request->get('order_id') ?? $request->query('order_id'),
            'pending_order_id' => $request->get('pending_order_id') ?? $request->query('pending_order_id'),
        ]);

        try {
            DB::beginTransaction();

            // Get PayFort response data
            $payfortData = $request->all();

            $isWalletDeposit = $request->get('wallet_deposit') == '1'
                || $request->query('wallet_deposit') == '1';

            $walletReference = $request->get('reference') ?? $request->query('reference');

            $merchantReference = $request->get('merchant_reference')
                ?? $request->get('transaction_id')
                ?? $request->get('checkoutSessionId')
                ?? $request->get('amazonCheckoutSessionId')
                ?? ($isWalletDeposit ? $walletReference : null);

            if (! $merchantReference) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing transaction identifier',
                ], 400);
            }

            $cacheKey = "payfort_response_{$merchantReference}";
            Cache::put($cacheKey, $payfortData, now()->addMinutes(10));

            // Also check for order_id from query params
            $orderId = $request->get('order_id') ?? $request->query('order_id');

            // Check if this is a wallet deposit (walletReference resolved above)

            // Find transaction
            $transaction = null;
            $order = null;

            if ($isWalletDeposit && $walletReference) {
                // For wallet deposits
                $transaction = PaymentTransaction::where('transaction_id', $walletReference)
                    ->orWhereJsonContains('response_data->wallet_reference_id', $walletReference)
                    ->latest()
                    ->first();

            } elseif ($merchantReference) {
                // Try to find by merchant_reference (order_number or transaction_id)
                $order = Order::where('order_number', $merchantReference)->first();
                if ($order) {
                    // Prefer the exact leg whose transaction_id echoes this merchant
                    // reference (the primary payment's id == order_number); fall back
                    // to the latest tx. This stops a later surcharge leg from being
                    // mistaken for the primary payment on a replayed callback.
                    $transaction = PaymentTransaction::where('order_id', $order->id)
                        ->where('transaction_id', $merchantReference)
                        ->first()
                        ?? PaymentTransaction::where('order_id', $order->id)
                            ->latest()
                            ->first();
                    $orderId = $order->id;
                } else {
                    // Try transaction_id directly
                    $transaction = PaymentTransaction::where('transaction_id', $merchantReference)
                        ->latest()
                        ->first();
                }

            }

            // Fallback: find by order_id
            if (! $transaction && $orderId) {
                $order = Order::find($orderId);
                if ($order) {
                    $transaction = PaymentTransaction::where('order_id', $order->id)
                        ->latest()
                        ->first();
                }
            }

            // Fallback: find by pending_order_id
            $pendingOrderId = $request->get('pending_order_id') ?? $request->query('pending_order_id');
            if (! $transaction && $pendingOrderId) {
                $transaction = PaymentTransaction::whereNull('order_id')
                    ->where('response_data->pending_order_id', (int) $pendingOrderId)
                    ->latest()
                    ->first();
            }

            if (! $transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found',
                ], 404);
            }

            // Idempotency + concurrency guard: lock the row and short-circuit when
            // this payment was already captured/credited (duplicate/replayed callback).
            $transaction = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->first();

            if ($transaction->status === 'completed' && $transaction->paid_at !== null) {
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'تمت معالجة العملية مسبقاً',
                    'data' => [
                        'transaction_id' => $transaction->transaction_id,
                        'status' => $transaction->status,
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'order_id' => $transaction->order_id,
                        'order_number' => optional($transaction->order)->order_number,
                    ],
                ], 200);
            }

            // If return_url lost query params (legacy), still treat WALLET-* / stored metadata as wallet deposit
            if (! $isWalletDeposit && $transaction) {
                $rd = $transaction->response_data ?? [];
                if ($transaction->order_id === null && (
                    ! empty($rd['wallet_deposit']) ||
                    ! empty($rd['wallet_reference_id']) ||
                    (is_string($transaction->transaction_id) && str_starts_with($transaction->transaction_id, 'WALLET-'))
                )) {
                    $isWalletDeposit = true;
                    if (! $walletReference) {
                        $walletReference = $rd['wallet_reference_id'] ?? $transaction->transaction_id;
                    }
                }
            }

            // Get order if not wallet deposit
            if (! $isWalletDeposit) {
                $order = $transaction->order;
            }

            // Set gateway
            $gatewayName = strtolower(str_replace(' ', '_', $transaction->gateway));
            $this->paymentService->setGateway($gatewayName);

            $response = $this->paymentService->verifyPayment($transaction->transaction_id);

            Log::channel('payment')->info('Callback verify result', [
                'transaction_id' => $transaction->transaction_id,
                'order_id' => $transaction->order_id,
                'success' => $response->isSuccessful(),
                'status' => $response->status,
                'message' => $response->message,
            ]);

            // Map response status to valid ENUM values
            $validStatuses = ['pending', 'authorized', 'completed', 'failed', 'cancelled', 'refunded', 'partially_refunded'];
            $mappedStatus = in_array($response->status, $validStatuses) ? $response->status : 'failed';

            // Extract fort_id from response for future CAPTURE/VOID operations
            $fortId = $response->data['fort_id']
                ?? $payfortData['fort_id']
                ?? $transaction->fort_id;

            // The browser-redirect /payments/callback route is PUBLIC and unauthenticated,
            // and $payfortData is the raw request payload. Merge it for gateway fields
            // (fort_id, token_name, status…) but NEVER let it overwrite the immutable
            // identity captured at init: a forged client_id would misdirect the wallet
            // credit (theft) and a forged wallet_reference_id / pending_order_id would fork
            // the settlement into a double credit or a wrong-order materialization.
            $mergedResponseData = array_merge($transaction->response_data ?? [], $response->data ?? [], $payfortData);
            foreach (['client_id', 'wallet_reference_id', 'wallet_deposit', 'pending_order_id'] as $immutableKey) {
                if (array_key_exists($immutableKey, $transaction->response_data ?? [])) {
                    $mergedResponseData[$immutableKey] = $transaction->response_data[$immutableKey];
                }
            }

            // Update transaction
            $updateData = [
                'status' => $mappedStatus,
                'response_data' => $mergedResponseData,
                'paid_at' => $response->isSuccessful() ? now() : $transaction->paid_at,
                'error_message' => $response->isSuccessful() ? null : ($response->message ?? 'Payment failed'),
            ];

            if ($fortId) {
                $updateData['fort_id'] = $fortId;
            }

            $tokenName = $response->data['token_name']
                ?? $payfortData['token_name']
                ?? ($response->data['raw_response']['token_name'] ?? null);

            if ($tokenName) {
                $updateData['payfort_token_name'] = $tokenName;
            }

            // For AUTHORIZATION: store the authorized amount for later capture comparison
            if ($mappedStatus === 'authorized' && ! $transaction->authorized_amount) {
                $updateData['authorized_amount'] = $transaction->amount;
            }

            $transaction->update($updateData);

            // Moyasar's generic "credit_card" tile only reveals the actual scheme
            // (visa/mastercard/mada) once the gateway confirms the payment — correct
            // the stored method now so the order/transaction/leg reflect what was really used.
            if ($response->isSuccessful() && $transaction->payment_method === PaymentMethod::CREDIT_CARD->value) {
                $resolvedBrand = PaymentMethod::resolveBrandFromMoyasarSource($response->data['source'] ?? null);
                if ($resolvedBrand) {
                    $transaction->update(['payment_method' => $resolvedBrand->value]);

                    OrderPayment::where('payment_transaction_id', $transaction->id)
                        ->update(['payment_method' => $resolvedBrand->value]);

                    if ($order && ! $transaction->is_additional_charge) {
                        $order->update(['payment_method' => $resolvedBrand->value]);
                    }
                }
            }

            if ($response->isSuccessful() && $tokenName) {
                $this->clientCardService->upsertFromPaymentTransaction(
                    $transaction->fresh(),
                    $tokenName,
                    array_merge($payfortData, $response->data ?? [])
                );
            }

            // Handle wallet deposit
            if ($isWalletDeposit) {
                // A wallet top-up has no fulfillment step, so an AUTHORIZATION hold must be
                // captured immediately — otherwise the hold expires unsettled (the merchant
                // never receives the money) while the customer's balance was credited.
                // Under the PURCHASE command the leg already comes back 'completed', so this
                // capture is skipped.
                if ($response->isSuccessful() && $mappedStatus === 'authorized' && $transaction->fort_id) {
                    $walletCapture = $this->paymentService->capture(
                        $transaction->fort_id,
                        (float) ($transaction->authorized_amount ?: $transaction->amount),
                        $transaction->transaction_id,
                        $transaction->payfortPaymentOption()
                    );

                    if ($walletCapture->isSuccessful()) {
                        $transaction->update([
                            'status' => 'completed',
                            'paid_at' => now(),
                            'response_data' => array_merge($transaction->response_data ?? [], $walletCapture->data ?? []),
                        ]);
                        $mappedStatus = 'completed';
                    } else {
                        Log::channel('payment')->error('Wallet deposit: capture of AUTHORIZATION hold failed — balance NOT credited', [
                            'transaction_id' => $transaction->transaction_id,
                            'message' => $walletCapture->message,
                        ]);
                    }
                }

                // Credit the wallet only once the deposit is actually captured ('completed').
                // Never credit on a bare 'authorized' hold.
                if ($mappedStatus === 'completed') {
                    $this->walletDepositCreditor->creditIfNotAlready(
                        $transaction->fresh(),
                        'Nathefah Wallet deposit'
                    );
                }
            } elseif ($order) {

                // Source of truth is the real column; fall back to the legacy JSON
                // flag for rows created before the column existed.
                $isAdditionalCharge = (bool) $transaction->is_additional_charge
                    || (bool) ($transaction->response_data['is_additional_charge'] ?? false);

                if ($isAdditionalCharge) {
                    if ($response->isSuccessful() && in_array($mappedStatus, ['authorized', 'completed'], true)) {
                        $this->settleAdditionalChargeLeg($order, $transaction);
                    } elseif (! $response->isSuccessful() || in_array($mappedStatus, ['failed', 'cancelled'], true)) {
                        $failedLeg = OrderPayment::where('payment_transaction_id', $transaction->id)->first();
                        $intentId = $failedLeg?->modification_intent_id
                            ?? ($transaction->response_data['modification_intent_id'] ?? null);
                        app(OrderPaymentService::class)->releaseOrderWalletReservations($order->id, $intentId);
                    }
                } elseif ($response->isSuccessful() && in_array($mappedStatus, ['authorized', 'completed'], true)) {
                    $this->settlePrimaryGatewayLeg($order, $transaction);
                } elseif (! $response->isSuccessful() || in_array($mappedStatus, ['failed', 'cancelled'], true)) {
                    app(OrderPaymentService::class)->releaseOrderWalletReservations($order->id);
                }
            } elseif (! $isWalletDeposit && $response->isSuccessful()) {
                $pOrderId = $transaction->response_data['pending_order_id'] ?? null;
                if (! $pOrderId && $pendingOrderId) {
                    $pOrderId = (int) $pendingOrderId;
                }

                if ($pOrderId) {
                    $pendingOrder = \Modules\Order\Models\PendingOrder::where('id', $pOrderId)
                        ->where('status', 'pending')
                        ->first();

                    if ($pendingOrder) {
                        $pendingOrderService = app(\Modules\Order\Services\PendingOrderService::class);

                        // Materialize the order inside a SAVEPOINT. The payment status
                        // update above is already part of the outer transaction and
                        // represents real, captured money — it MUST survive even if order
                        // creation fails for a non-essential reason. On failure we roll back
                        // ONLY the half-created order (not the payment status) and leave the
                        // pending order intact so it can be re-materialized idempotently via
                        // the confirm endpoint / webhook.
                        try {
                            DB::transaction(function () use ($pendingOrderService, $pendingOrder, $transaction, &$order) {
                                $order = $pendingOrderService->createOrderFromPending($pendingOrder);

                                $transaction->update(['order_id' => $order->id]);

                                // The order is built from the pending order's originally-stored
                                // payment_method (e.g. generic "credit_card"), which predates the
                                // brand resolution above — sync it now so the order reflects the
                                // actual gateway-confirmed method (visa/mastercard/mada).
                                if ($transaction->payment_method && $order->payment_method !== $transaction->payment_method) {
                                    $order->update(['payment_method' => $transaction->payment_method]);
                                }

                                // Payment status is updated on the transaction above.
                                // Order workflow stays PENDING until the client confirms.
                            });
                        } catch (\Throwable $e) {
                            $order = null;
                            Log::channel('payment')->error('Pending-order materialization failed; captured payment status preserved for retry', [
                                'transaction_id' => $transaction->transaction_id,
                                'pending_order_id' => $pendingOrder->id,
                                'error' => $e->getMessage(),
                                'line' => $e->getLine(),
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            // Prepare response
            $responseData = [
                'transaction_id' => $transaction->transaction_id,
                'status' => $transaction->status,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
            ];

            if ($isWalletDeposit) {
                $clientId = $transaction->response_data['client_id'] ?? null;
                if ($clientId && $response->isSuccessful()) {
                    $newBalance = DB::table('clients')
                        ->where('id', $clientId)
                        ->value('wallet_balance');

                    $responseData['client_id'] = $clientId;
                    $responseData['new_balance'] = (float) $newBalance;
                    $responseData['type'] = 'wallet_deposit';
                }
            } else {
                $responseData['order_id'] = $order->id ?? null;
                $responseData['order_number'] = $order->order_number ?? null;
            }

            $responseMessage = $response->isSuccessful()
                ? ($isWalletDeposit ? 'تمت عملية الإيداع بنجاح' : 'تمت عملية الدفع بنجاح')
                : ($response->message ?? 'فشلت عملية الدفع');

            // Return JSON response
            return response()->json([
                'success' => $response->isSuccessful(),
                'message' => $responseMessage,
                'data' => $responseData,
            ], $response->isSuccessful() ? 200 : 400);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::channel('payment')->error('Payment callback processing failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'merchant_reference' => $request->get('merchant_reference'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment callback processing failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pull-based payment confirmation by transaction_id.
     *
     * Why this exists: the Moyasar hosted-invoice page does NOT reliably redirect the
     * browser back to our callback_url, and the server-to-server webhook can be
     * undeliverable (non-public APP_URL, unconfigured webhook secret). When that
     * happens a genuinely-paid payment never reaches callback()/moyasarWebhook(), so
     * the PendingOrder is never converted into a real Order and the transaction stays
     * 'pending'. This endpoint lets the client ACTIVELY confirm a payment by its
     * transaction_id (== order_number == merchant_reference): it asks the gateway for
     * the authoritative status server-side and runs the SAME idempotent, row-locked
     * settlement callback() uses, then returns the resulting order data + status.
     *
     * Idempotent and safe to poll: repeated calls return the same order once settled.
     */
    public function confirmByTransaction(Request $request, string $transactionId)
    {
        $transaction = PaymentTransaction::where('transaction_id', $transactionId)
            ->latest()
            ->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'message' => __('order.missing_order_or_transaction'),
                'data' => null,
            ], 404);
        }

        // Ownership: the authenticated client must own the (pending) order behind this
        // transaction. For a pending checkout the client id lives in response_data; once
        // the order exists it lives on the order.
        $authId = optional($request->user())->id;
        $ownerId = optional($transaction->order)->client_id
            ?? ($transaction->response_data['client_id'] ?? null);

        if ($authId !== null && $ownerId !== null && (int) $authId !== (int) $ownerId) {
            return response()->json([
                'success' => false,
                'message' => __('order.unauthorized_access'),
                'data' => null,
            ], 403);
        }

        // Settle only when not already settled+linked; otherwise return the existing
        // order without re-hitting the gateway (idempotent fast path).
        $alreadyLinked = $transaction->order_id !== null
            && in_array($transaction->status, ['authorized', 'completed'], true);

        if (! $alreadyLinked) {
            // Give the shared settlement everything it needs to resolve the transaction
            // and verify with the gateway server-side. The gateway payment id (Moyasar)
            // may arrive on the redirect URL as `id`/`payment_id`; otherwise fall back to
            // what we stored at init (fort_id / moyasar payment id / invoice id).
            $gatewayPaymentId = $request->input('id')
                ?? $request->input('payment_id')
                ?? $transaction->fort_id
                ?? ($transaction->response_data['moyasar_payment_id'] ?? null)
                ?? ($transaction->response_data['invoice_id'] ?? null);

            $request->merge([
                'merchant_reference' => $transaction->transaction_id,
                'pending_order_id' => $transaction->response_data['pending_order_id'] ?? null,
            ]);

            if ($gatewayPaymentId) {
                $request->merge(['id' => $gatewayPaymentId]);
            }

            Log::channel('payment')->info('==== CONFIRM-BY-TRANSACTION ====', [
                'transaction_id' => $transaction->transaction_id,
                'gateway' => $transaction->gateway,
                'current_status' => $transaction->status,
                'pending_order_id' => $transaction->response_data['pending_order_id'] ?? null,
                'gateway_payment_id' => $gatewayPaymentId,
            ]);

            // Reuse the battle-tested, row-locked, idempotent settlement path
            // (verify -> create order from pending -> update status -> confirm).
            try {
                $this->callback($request);
            } catch (\Throwable $e) {
                Log::channel('payment')->error('confirmByTransaction settlement failed', [
                    'transaction_id' => $transaction->transaction_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $transaction = $transaction->fresh();
        }

        $order = $transaction->order_id ? Order::find($transaction->order_id) : null;
        $paid = in_array($transaction->status, ['authorized', 'completed'], true);

        if ($paid && $order) {
            $message = __('order.payment_completed');
        } elseif ($transaction->status === 'pending') {
            $message = __('order.no_payment_initiated');
        } else {
            $message = __('order.payment_verification_failed');
        }

        return response()->json([
            'success' => $paid && $order !== null,
            'message' => $message,
            'data' => [
                'transaction_id' => $transaction->transaction_id,
                'gateway' => $transaction->gateway,
                'payment_status' => $transaction->status,
                'payment_status_label' => \App\Support\PaymentStatusPresenter::label($transaction->status),
                'paid' => $paid,
                'paid_at' => optional($transaction->paid_at)->toIso8601String(),
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'order' => $order ? $this->orderSummaryPayload($order) : null,
            ],
        ]);
    }

    /**
     * Compact order payload returned by the confirm endpoint. The client can call the
     * full order-details endpoint with `id` for the complete object.
     *
     * @return array<string,mixed>
     */
    private function orderSummaryPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->payment_status ?? 'pending'),
            'payment_method' => $order->payment_method,
            'total_amount' => (float) $order->total_amount,
            'discount_amount' => (float) $order->discount_amount,
            'tax_amount' => (float) $order->tax_amount,
            'delivery_fee' => (float) $order->delivery_fee,
            'final_amount' => (float) $order->final_amount,
            'created_at' => optional($order->created_at)->toIso8601String(),
        ];
    }

    /**
     * Local Moyasar checkout (moyasar.js). Used for hosted_local mode and for
     * invoice payments so Samsung Pay / Apple Pay / STC Pay can render.
     */
    public function showMoyasarCheckout(string $transactionId)
    {
        $transaction = PaymentTransaction::query()
            ->where('transaction_id', $transactionId)
            ->first();

        if (! $transaction) {
            abort(404, 'Checkout session not found');
        }

        $moyasarConfig = $transaction->response_data['moyasar'] ?? null;
        if (! is_array($moyasarConfig) || empty($moyasarConfig['publishable_api_key'])) {
            abort(404, 'Checkout session is not ready');
        }

        return response()
            ->view('payment::moyasar_checkout', [
                'transaction' => $transaction,
                'moyasarConfig' => $moyasarConfig,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Moyasar server-to-server webhook (the reliable settlement path; the browser
     * redirect may be lost if the customer closes the tab).
     *
     * Verifies the webhook signature, then normalizes Moyasar's nested payload
     * ({ type, data: { id, status, metadata } }) into the flat shape callback()
     * expects and reuses the SAME idempotent, row-locked settlement — so a webhook
     * and a redirect for the same payment can never double-process.
     *
     * Order workflow status is NOT changed here — only payment transaction / leg
     * state is updated. The order remains pending until the client confirms.
     */
    public function moyasarWebhook(Request $request)
    {
        Log::channel('payment')->info('==== MOYASAR WEBHOOK HIT ====', [
            'type' => $request->input('type'),
            'ip' => $request->ip(),
            'data_id' => $request->input('data.id'),
            'data_status' => $request->input('data.status'),
        ]);

        try {
            $gateway = $this->paymentService->getGateway('moyasar');

            if (! $gateway || ! method_exists($gateway, 'verifyWebhook')) {
                Log::channel('payment')->error('Moyasar webhook: gateway not registered/available');

                return response()->json(['success' => false, 'message' => 'Moyasar gateway unavailable'], 503);
            }

            $payload = $request->all();
            $signatureHeader = $request->header('X-Moyasar-Signature')
                ?? $request->header('X-Signature')
                ?? $request->header('Signature');

            if (! $gateway->verifyWebhook($payload, $signatureHeader, $request->getContent())) {
                return response()->json(['success' => false, 'message' => 'Invalid webhook signature'], 401);
            }

            // Flatten Moyasar's payment object onto the request and reuse callback().
            $data = (array) $request->input('data', []);
            $meta = (array) ($data['metadata'] ?? []);

            $request->merge([
                'merchant_reference' => $meta['merchant_reference'] ?? $request->input('merchant_reference'),
                'id' => $data['id'] ?? $request->input('id'),
                'status' => $data['status'] ?? $request->input('status'),
                'wallet_deposit' => $meta['wallet_deposit'] ?? null,
                'reference' => $meta['wallet_reference'] ?? ($meta['merchant_reference'] ?? null),
                'order_id' => $meta['order_id'] ?? null,
                'pending_order_id' => $meta['pending_order_id'] ?? null,
                'from_webhook' => true,
            ]);

            return $this->callback($request);
        } catch (\Throwable $e) {
            Log::channel('payment')->error('Moyasar webhook failed', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Re-verify pending/authorized gateway transactions for an order and apply
     * settlement (used when the PayFort return callback was missed or delayed).
     */
    public function syncOrderPayment(Order $order): void
    {
        $transactions = PaymentTransaction::where('order_id', $order->id)
            ->whereIn('status', ['pending', 'authorized'])
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            if ($transaction->status === 'authorized') {
                $isAdditionalCharge = (bool) $transaction->is_additional_charge
                    || (bool) ($transaction->response_data['is_additional_charge'] ?? false);

                if ($isAdditionalCharge) {
                    $this->settleAdditionalChargeLeg($order, $transaction);
                } else {
                    $this->settlePrimaryGatewayLeg($order, $transaction);
                }

                continue;
            }

            $gatewayName = strtolower(str_replace(' ', '_', $transaction->gateway));
            $this->paymentService->setGateway($gatewayName);

            $response = $this->paymentService->verifyPayment($transaction->transaction_id);
            $validStatuses = ['pending', 'authorized', 'completed', 'failed', 'cancelled', 'refunded', 'partially_refunded'];
            $mappedStatus = in_array($response->status, $validStatuses) ? $response->status : 'failed';

            $fortId = $response->data['fort_id'] ?? $transaction->fort_id;

            $updateData = [
                'status' => $mappedStatus,
                'response_data' => array_merge($transaction->response_data ?? [], $response->data ?? []),
                'paid_at' => $response->isSuccessful() ? now() : $transaction->paid_at,
                'error_message' => $response->isSuccessful() ? null : ($response->message ?? 'Payment failed'),
            ];

            if ($fortId) {
                $updateData['fort_id'] = $fortId;
            }

            if ($mappedStatus === 'authorized' && ! $transaction->authorized_amount) {
                $updateData['authorized_amount'] = $transaction->amount;
            }

            $transaction->update($updateData);
            $transaction = $transaction->fresh();

            if (! $response->isSuccessful()) {
                if (in_array($mappedStatus, ['failed', 'cancelled'], true)) {
                    app(OrderPaymentService::class)->releaseOrderWalletReservations($order->id);
                }

                continue;
            }

            $isAdditionalCharge = (bool) $transaction->is_additional_charge
                || (bool) ($transaction->response_data['is_additional_charge'] ?? false);

            if ($isAdditionalCharge && in_array($mappedStatus, ['authorized', 'completed'], true)) {
                $this->settleAdditionalChargeLeg($order, $transaction);
            } elseif (in_array($mappedStatus, ['authorized', 'completed'], true)) {
                $this->settlePrimaryGatewayLeg($order, $transaction);
            }
        }

        $order->refresh();
    }

    /**
     * Move an order to PAYMENT_CONFIRMED after a successful payment.
     *
     * Idempotent and transition-safe: it never throws on a successful callback,
     * even if the order is already confirmed or in an unexpected state. This is
     * what prevents the "money taken but order rolled back" bug — a hard-coded
     * PENDING -> PAYMENT_CONFIRMED transition used to throw and roll back the
     * whole callback transaction.
     */
    private function confirmOrderPayment(Order $order, ?int $changedBy, string $notes): void
    {
        $statusService = app(\App\Services\OrderStatusService::class);
        $order->refresh();

        // Already confirmed (or further along) — idempotent no-op.
        if ($order->status === OrderStatus::PAYMENT_CONFIRMED->value) {
            return;
        }

        if ($statusService->canTransition($order, OrderStatus::PAYMENT_CONFIRMED)) {
            $statusService->transitionTo($order, OrderStatus::PAYMENT_CONFIRMED, [
                'notes' => $notes,
                'changed_by' => $changedBy,
            ]);

            return;
        }

        // Fallback: route through WAITING_PAYMENT when a direct edge isn't allowed.
        if ($statusService->canTransition($order, OrderStatus::WAITING_PAYMENT)) {
            $statusService->transitionTo($order, OrderStatus::WAITING_PAYMENT, [
                'notes' => 'Auto: awaiting payment confirmation',
                'changed_by' => $changedBy,
            ]);
            $order->refresh();

            if ($statusService->canTransition($order, OrderStatus::PAYMENT_CONFIRMED)) {
                $statusService->transitionTo($order, OrderStatus::PAYMENT_CONFIRMED, [
                    'notes' => $notes,
                    'changed_by' => $changedBy,
                ]);
            }
        }
        // If the order has already advanced past payment, leave it untouched.
    }

    /**
     * Settle a paid additional-charge leg: a surcharge produced by an order edit,
     * or a split-payment leg covering part of the total.
     *
     * - Marks the matching pending split-payment leg (OrderPayment) as paid.
     * - When the leg pays a staged total increase (OrderModificationIntent), applies
     *   the new total/final_amount to the order and resolves the intent.
     * - NEVER advances the order's workflow status — an additional charge is recorded
     *   against an already-confirmed order, not a primary checkout payment.
     *
     * Idempotent and transaction-safe: re-running on a replayed/duplicate callback
     * is a no-op once the leg is paid and the intent is resolved.
     */
    private function settleAdditionalChargeLeg(Order $order, PaymentTransaction $transaction): void
    {
        $orderPaymentService = app(OrderPaymentService::class);

        // Mark the matching pending leg paid (split-payment ledger). The conditional
        // update is atomic, so a replayed callback can't double-pay the leg.
        $leg = OrderPayment::where('payment_transaction_id', $transaction->id)->first();
        if ($leg) {
            OrderPayment::whereKey($leg->id)
                ->where('status', '!=', OrderPayment::STATUS_PAID)
                ->update(['status' => OrderPayment::STATUS_PAID, 'paid_at' => now()]);
        }

        $orderPaymentService->captureOrderWalletReservations($order->id);

        // Resolve & apply the staged total increase, if this leg pays one.
        $intentId = $leg?->modification_intent_id
            ?? ($transaction->response_data['modification_intent_id'] ?? null);

        if (! $intentId) {
            return;
        }

        // Lock the intent row BEFORE reading surcharge coverage so concurrent
        // surcharge-leg callbacks serialize. The last one to acquire the lock sees
        // every committed leg and applies the staged total — preventing the
        // "all legs paid but total never applied" lost-update.
        $intent = OrderModificationIntent::whereKey($intentId)->lockForUpdate()->first();
        if (! $intent || $intent->status !== OrderModificationIntent::STATUS_PENDING) {
            return; // nothing staged, or already applied (idempotent)
        }

        // A surcharge may itself be split across methods. Apply the staged total
        // only once the surcharge legs fully cover the delta; otherwise wait for
        // the remaining legs' callbacks.
        if ($orderPaymentService->surchargePaidTotal($intent->id) + 0.01 < (float) $intent->delta_amount) {
            return;
        }

        $orderPaymentService->applyModificationIntent($intent, $transaction);

        // Vendor-review surcharge: confirmation was deferred until this payment completed.
        $order = $order->fresh();
        if ($order && $order->status === OrderStatus::BRANCH_REVIEW->value) {
            app(\App\Services\VendorOrderReviewService::class)->finalizeClientApproval(
                $order,
                'Client approved vendor modifications (surcharge paid).'
            );
        }

        // Audit trail only for non-review or already-transitioned orders.
        OrderStatusLog::create([
            'order_id' => $order->id,
            'status' => $order->fresh()->status ?? $order->status,
            'notes' => 'Surcharge payment confirmed',
            'changed_by' => $order->client_id ?? null,
        ]);
    }

    /**
     * Settle a completed PRIMARY payment (payment ledger only).
     *
     * - Split-paid order (has OrderPayment legs): mark this leg paid.
     * - Single-payment order (no leg ledger): transaction status already updated.
     *
     * Order workflow status is intentionally unchanged — confirmation is a
     * separate client/vendor step after payment is held or captured.
     *
     * Idempotent: safe on a replayed callback.
     */
    private function settlePrimaryGatewayLeg(Order $order, PaymentTransaction $transaction): void
    {
        $leg = OrderPayment::where('payment_transaction_id', $transaction->id)->first();

        if (! $leg) {
            return;
        }

        // Lock the order so concurrent leg callbacks serialize leg updates.
        $order = Order::whereKey($order->id)->lockForUpdate()->first() ?? $order;

        // Atomic mark-paid; a replayed callback can't double-pay the leg.
        OrderPayment::whereKey($leg->id)
            ->where('status', '!=', OrderPayment::STATUS_PAID)
            ->update(['status' => OrderPayment::STATUS_PAID, 'paid_at' => now()]);

        app(OrderPaymentService::class)->captureOrderWalletReservations($order->id);

        $order = $order->fresh();
        $paymentService = app(OrderPaymentService::class);

        if ($order && $paymentService->isFullyPaid($order)) {
            $this->confirmOrderPayment($order, $order->client_id, 'Primary payment settled');
            $order = $order->fresh();
        }

        if ($order && $paymentService->shouldSendOrderCreatedNotifications($order)) {
            app(OrderNotificationService::class)->sendOrderCreatedNotificationsIfNeeded($order);
        }

        if ($order) {
            app(\Modules\Invoice\Services\InvoiceService::class)
                ->issueForOrder($order->fresh(['client', 'branch.vendor', 'items.piece', 'items.service']), $transaction->fresh(), 'primary_gateway_leg');
        }
    }

    /**
     * Capture a previously authorized payment (partial or full).
     *
     * - PURCHASE flow: funds were already captured at checkout (transaction is
     *   'completed'); capture is a no-op and returns success (idempotent).
     * - AUTHORIZATION flow: captures the reserved hold (transaction 'authorized'),
     *   never exceeding the authorized amount, using the correct merchant account.
     */
    public function capturePayment(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        try {
            $order = Order::findOrFail($validated['order_id']);

            $completed = PaymentTransaction::where('order_id', $order->id)
                ->where('status', 'completed')
                ->whereNotNull('paid_at')
                ->latest()
                ->first();

            if ($completed) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment already captured',
                    'data' => [
                        'transaction_id' => $completed->transaction_id,
                        'captured_amount' => (float) $completed->amount,
                        'authorized_amount' => (float) ($completed->authorized_amount ?: $completed->amount),
                        'status' => 'completed',
                    ],
                ], 200);
            }

            // AUTHORIZATION flow: capture a reserved (authorized) hold.
            $transaction = PaymentTransaction::where('order_id', $order->id)
                ->where('status', 'authorized')
                ->whereNotNull('fort_id')
                ->latest()
                ->first();

            if (! $transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authorized transaction found for this order',
                ], 404);
            }

            // Never capture more than what was reserved.
            $authorized = (float) ($transaction->authorized_amount ?: $transaction->amount);
            if ($validated['amount'] > $authorized) {
                return response()->json([
                    'success' => false,
                    'message' => 'Capture amount exceeds authorized amount',
                ], 400);
            }

            $gatewayName = strtolower(str_replace(' ', '_', $transaction->gateway));
            $this->paymentService->setGateway($gatewayName);

            // Claim the hold under a row lock and re-check it is still 'authorized'
            // before capturing, so this manual endpoint can't race the confirmation
            // capture listener (or a duplicate request) into a double-capture.
            $response = DB::transaction(function () use ($transaction, $validated, $authorized) {
                $locked = PaymentTransaction::whereKey($transaction->id)
                    ->where('status', 'authorized')
                    ->whereNotNull('fort_id')
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return null; // already captured/voided by a concurrent trigger
                }

                $resp = $this->paymentService->capture(
                    $locked->fort_id,
                    $validated['amount'],
                    $locked->transaction_id,
                    $locked->payfortPaymentOption()
                );

                if ($resp->isSuccessful()) {
                    $newStatus = $resp->status === 'authorized' ? 'authorized' : 'completed';
                    $locked->update([
                        'status' => $newStatus,
                        'amount' => $newStatus === 'completed' ? $validated['amount'] : $locked->amount,
                        // preserve the original hold for partial-capture auditing
                        'authorized_amount' => $locked->authorized_amount ?: $authorized,
                        'paid_at' => $newStatus === 'completed' ? now() : $locked->paid_at,
                        'response_data' => array_merge($locked->response_data ?? [], $resp->data ?? []),
                    ]);
                }

                return $resp;
            });

            // Lost the race: a concurrent trigger already settled this hold — report idempotently.
            if ($response === null) {
                $current = $transaction->fresh();

                return response()->json([
                    'success' => true,
                    'message' => 'Payment already settled',
                    'data' => [
                        'transaction_id' => $current->transaction_id,
                        'captured_amount' => (float) $current->amount,
                        'authorized_amount' => $authorized,
                        'status' => $current->status,
                    ],
                ], 200);
            }

            $current = $transaction->fresh();
            if ($current && $current->status === 'completed') {
                app(\Modules\Invoice\Services\InvoiceService::class)
                    ->issueForOrder($order->fresh(['client', 'branch.vendor', 'items.piece', 'items.service']), $current, 'manual_capture');
            }

            return response()->json([
                'success' => $response->isSuccessful(),
                'message' => $response->message,
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'captured_amount' => $validated['amount'],
                    'authorized_amount' => $authorized,
                    'status' => $response->status,
                ],
            ], $response->isSuccessful() ? 200 : 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Void (release) a previously authorized payment that was never captured —
     * e.g. when an order is cancelled before the laundry confirms it.
     * No-op-safe: only acts on an 'authorized' transaction.
     */
    public function voidPayment(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        try {
            $order = Order::findOrFail($validated['order_id']);

            $transaction = PaymentTransaction::where('order_id', $order->id)
                ->where('status', 'authorized')
                ->whereNotNull('fort_id')
                ->latest()
                ->first();

            if (! $transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authorized transaction found to void',
                ], 404);
            }

            $gatewayName = strtolower(str_replace(' ', '_', $transaction->gateway));
            $this->paymentService->setGateway($gatewayName);

            // Claim the hold under a row lock and re-check it is still 'authorized'
            // before voiding, so this manual endpoint can't race the capture listener
            // (which may be capturing the same hold) into an inconsistent outcome.
            $response = DB::transaction(function () use ($transaction) {
                $locked = PaymentTransaction::whereKey($transaction->id)
                    ->where('status', 'authorized')
                    ->whereNotNull('fort_id')
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return null; // already captured/voided by a concurrent trigger
                }

                $resp = $this->paymentService->voidAuthorization(
                    $locked->fort_id,
                    $locked->transaction_id,
                    $locked->payfortPaymentOption()
                );

                if ($resp->isSuccessful()) {
                    // NOTE: 'voided' is not a valid transactions ENUM value -> use 'cancelled'.
                    $locked->update([
                        'status' => 'cancelled',
                        'error_message' => null,
                        'response_data' => array_merge($locked->response_data ?? [], $resp->data ?? []),
                    ]);
                }

                return $resp;
            });

            // Lost the race: a concurrent trigger already settled this hold.
            if ($response === null) {
                $current = $transaction->fresh();

                return response()->json([
                    'success' => true,
                    'message' => 'Authorization already settled',
                    'data' => [
                        'transaction_id' => $current->transaction_id,
                        'status' => $current->status,
                    ],
                ], 200);
            }

            return response()->json([
                'success' => $response->isSuccessful(),
                'message' => $response->message,
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'status' => $response->isSuccessful() ? 'cancelled' : $transaction->status,
                ],
            ], $response->isSuccessful() ? 200 : 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle payment cancellation
     */
    public function cancel(Request $request)
    {
        try {
            $orderId = $request->get('order_id') ?? $request->query('order_id');
            $pendingOrderId = $request->get('pending_order_id') ?? $request->query('pending_order_id');

            if ($orderId) {
                $order = Order::find($orderId);

                if ($order) {
                    // Never cancel an order whose payment already authorized/completed
                    // at the gateway (cancel-redirect racing the success callback).
                    $alreadySettled = PaymentTransaction::where('order_id', $order->id)
                        ->whereIn('status', ['authorized', 'completed'])
                        ->exists();

                    if ($alreadySettled) {
                        Log::channel('payment')->warning('Cancel ignored — order already has an authorized/completed payment', [
                            'order_id' => $order->id,
                        ]);
                    } else {
                        // Only release still-pending, un-authorized transactions.
                        PaymentTransaction::where('order_id', $order->id)
                            ->where('status', 'pending')
                            ->whereNull('fort_id')
                            ->whereNull('paid_at')
                            ->update(['status' => 'cancelled']);

                        app(OrderPaymentService::class)->releaseOrderWalletReservations($order->id);
                    }
                }
            }

            if ($pendingOrderId) {
                \Modules\Order\Models\PendingOrder::where('id', $pendingOrderId)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled']);

                app(\Modules\Order\Services\OrderPaymentService::class)->releasePendingOrderWalletReservations((int) $pendingOrderId);

                PaymentTransaction::whereNull('order_id')
                    ->where('response_data->pending_order_id', (int) $pendingOrderId)
                    ->where('status', 'pending')
                    ->whereNull('fort_id')
                    ->whereNull('paid_at')
                    ->update(['status' => 'cancelled']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment was cancelled',
                'data' => [
                    'order_id' => $orderId,
                    'pending_order_id' => $pendingOrderId,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing cancellation',
            ], 500);
        }
    }

    /**
     * Get available payment gateways
     */
    public function getGateways()
    {
        $gateways = $this->paymentService->getEnabledGateways();

        $result = [];
        foreach ($gateways as $name => $gateway) {
            $result[] = [
                'name' => $name,
                'display_name' => $gateway->getName(),
                'is_enabled' => $gateway->isEnabled(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
