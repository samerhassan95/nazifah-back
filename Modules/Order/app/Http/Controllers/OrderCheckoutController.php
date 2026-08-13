<?php

namespace Modules\Order\Http\Controllers;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentService;

class OrderCheckoutController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    public function handleCallback(Request $request)
    {
        // Trace the raw gateway hit BEFORE anything can throw, so we always know
        // whether PayFort reached the callback and what it sent.
        Log::channel('payment')->info('==== CHECKOUT CALLBACK HIT ====', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'param_keys' => array_keys($request->all()),
            'merchant_reference' => $request->input('merchant_reference'),
            'response_code' => $request->input('response_code'),
            'status' => $request->input('status'),
            'fort_id' => $request->input('fort_id'),
            'order_id' => $request->input('order_id') ?? $request->query('order_id'),
        ]);

        try {
            // PayFort returns its reference as `merchant_reference`. Accept the
            // legacy/alternate keys too, plus the order_id carried on the return
            // URL, so the transaction is always resolvable (GET redirect or POST).
            $merchantReference = $request->input('merchant_reference')
                ?? $request->input('transaction_id')
                ?? $request->input('amazonCheckoutSessionId')
                ?? $request->input('checkoutSessionId');

            $orderId = $request->input('order_id') ?? $request->query('order_id');

            if (! $merchantReference && ! $orderId) {
                Log::channel('payment')->warning('Checkout callback: no merchant_reference or order_id — cannot resolve transaction', [
                    'param_keys' => array_keys($request->all()),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => __('order.missing_order_or_transaction'),
                ], 400);
            }

            // Cache the raw gateway payload so the gateway verifyPayment() reads the
            // real APS response (keyed by merchant_reference == transaction_id).
            if ($merchantReference) {
                Cache::put("payfort_response_{$merchantReference}", $request->all(), now()->addMinutes(10));
            }

            // Resolve order + transaction by merchant_reference (order_number or the
            // unique transaction_id), then fall back to order_id from the return URL.
            $order = null;
            $transaction = null;

            if ($merchantReference) {
                $order = Order::where('order_number', $merchantReference)->first();
                if ($order) {
                    $transaction = PaymentTransaction::where('order_id', $order->id)
                        ->where('transaction_id', $merchantReference)
                        ->first()
                        ?? PaymentTransaction::where('order_id', $order->id)->latest()->first();
                } else {
                    $transaction = PaymentTransaction::where('transaction_id', $merchantReference)
                        ->latest()
                        ->first();
                }
            }

            if (! $transaction && $orderId) {
                $order = Order::find($orderId);
                if ($order) {
                    $transaction = PaymentTransaction::where('order_id', $order->id)->latest()->first();
                }
            }

            if (! $transaction) {
                Log::channel('payment')->warning('Checkout callback: transaction NOT FOUND', [
                    'merchant_reference' => $merchantReference,
                    'order_id' => $orderId,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => __('order.missing_order_or_transaction'),
                ], 400);
            }

            if (! $order) {
                $order = $transaction->order;
            }

            Log::channel('payment')->info('Checkout callback: transaction resolved', [
                'transaction_id' => $transaction->transaction_id,
                'transaction_db_id' => $transaction->id,
                'current_status' => $transaction->status,
                'order_id' => $order->id ?? null,
                'order_number' => $order->order_number ?? null,
                'order_status' => $order->status ?? null,
                'gateway' => $transaction->gateway,
            ]);

            // Set the gateway
            $gatewayName = strtolower(str_replace(' ', '_', $transaction->gateway));
            $this->paymentService->setGateway($gatewayName);

            // Verify payment (uses the cached APS payload above)
            $response = $this->paymentService->verifyPayment($transaction->transaction_id);

            // Map to the persisted enum set; unknown values are treated as failed.
            $validStatuses = ['pending', 'authorized', 'completed', 'failed', 'cancelled', 'refunded', 'partially_refunded'];
            $mappedStatus = in_array($response->status, $validStatuses, true) ? $response->status : 'failed';

            Log::channel('payment')->info('Checkout callback: verify result', [
                'transaction_id' => $transaction->transaction_id,
                'gateway_success' => $response->isSuccessful(),
                'gateway_status' => $response->status,
                'mapped_status' => $mappedStatus,
                'fort_id' => $response->data['fort_id'] ?? $request->input('fort_id'),
                'message' => $response->message,
            ]);

            // Persist fort_id (required for later CAPTURE/VOID) and the authorized
            // amount so the hold can be captured on confirmation.
            $fortId = $response->data['fort_id'] ?? $request->input('fort_id') ?? $transaction->fort_id;

            $updateData = [
                'status' => $mappedStatus,
                'response_data' => array_merge($transaction->response_data ?? [], $response->data ?? [], $request->all()),
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

            // Moyasar's generic "credit_card" tile only reveals the actual scheme
            // (visa/mastercard/mada) once the gateway confirms the payment — correct
            // the stored method now so the order/transaction reflect what was really used.
            if ($response->isSuccessful() && $transaction->payment_method === \App\Enums\PaymentMethod::CREDIT_CARD->value) {
                $resolvedBrand = \App\Enums\PaymentMethod::resolveBrandFromMoyasarSource($response->data['source'] ?? null);
                if ($resolvedBrand) {
                    $transaction->update(['payment_method' => $resolvedBrand->value]);
                    if ($order) {
                        $order->update(['payment_method' => $resolvedBrand->value]);
                    }
                }
            }

            if ($response->isSuccessful() && ! $order) {
                $pOrderId = $transaction->response_data['pending_order_id'] ?? null;
                if (! $pOrderId) {
                    $pOrderId = $request->get('pending_order_id') ?? $request->query('pending_order_id');
                }

                if ($pOrderId) {
                    $pendingOrder = \Modules\Order\Models\PendingOrder::where('id', $pOrderId)
                        ->where('status', 'pending')
                        ->first();

                    if ($pendingOrder) {
                        $pendingOrderService = app(\Modules\Order\Services\PendingOrderService::class);
                        try {
                            \Illuminate\Support\Facades\DB::transaction(function () use ($pendingOrderService, $pendingOrder, $transaction, &$order) {
                                $order = $pendingOrderService->createOrderFromPending($pendingOrder);
                                $transaction->update(['order_id' => $order->id]);
                            });
                        } catch (\Throwable $e) {
                            Log::channel('payment')->error('Checkout callback pending order materialization failed', [
                                'pending_order_id' => $pOrderId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            if ($response->isSuccessful() && $order) {
                $newStatus = OrderStatus::CONFIRMED->value;
                if ($order->driver_id) {
                    $newStatus = ($order->pickup_at_vendor && ! $order->delivery_at_vendor)
                        ? OrderStatus::ON_WAY_TO_DELIVERY->value
                        : OrderStatus::ON_WAY_TO_PICKUP->value;
                }
                $transitioned = false;
                if (in_array($order->status, [OrderStatus::PENDING->value, OrderStatus::WAITING_PAYMENT->value])) {
                    $previousStatus = OrderStatus::from($order->status);
                    $order->update(['status' => $newStatus]);
                    $transitioned = true;

                    // Fire the lifecycle event so the capture/distribution/notification
                    // listeners run. The raw write above is kept because PENDING /
                    // WAITING_PAYMENT -> CONFIRMED is not a standard transition edge, but
                    // without dispatching this event an AUTHORIZATION hold would never be
                    // captured (HandlePaymentCapture listens on OrderStatusChanged).
                    // Wrapped so a listener failure can never break the payment callback.
                    try {
                        event(new OrderStatusChanged($order, $previousStatus, OrderStatus::from($newStatus), [
                            'changed_by' => $order->client_id,
                            'notes' => 'Payment settled via checkout callback',
                        ]));
                    } catch (\Throwable $e) {
                        Log::channel('payment')->error('Checkout callback: post-payment lifecycle event failed', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                Log::channel('payment')->info('Checkout callback: payment SUCCESS', [
                    'transaction_id' => $transaction->transaction_id,
                    'transaction_status' => $transaction->status,
                    'order_id' => $order->id,
                    'order_status_to' => $transitioned ? $newStatus : $order->status,
                    'order_status_transitioned' => $transitioned,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => __('order.payment_completed'),
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'transaction_id' => $transaction->transaction_id,
                        'status' => $transaction->status,
                        'amount' => $transaction->amount,
                    ],
                ]);
            }

            Log::channel('payment')->warning('Checkout callback: payment NOT successful', [
                'transaction_id' => $transaction->transaction_id,
                'transaction_status' => $transaction->status,
                'gateway_status' => $response->status,
                'message' => $response->message,
                'order_id' => $order->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('order.payment_verification_failed'),
                'data' => [
                    'order_id' => $order->id ?? null,
                    'status' => $response->status,
                ],
            ], 400);

        } catch (\Exception $e) {
            Log::channel('payment')->error('checkout handleCallback failed', [
                'error' => $e->getMessage(),
                'order_id' => $request->input('order_id'),
                'merchant_reference' => $request->input('merchant_reference'),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle payment cancellation.
     *
     * Never cancels a transaction that already authorized/completed at the gateway
     * (guards the cancel-redirect vs success-callback race).
     */
    public function handleCancel(Request $request)
    {
        try {
            $orderId = $request->input('order_id') ?? $request->query('order_id');

            if (! $orderId) {
                return response()->json([
                    'success' => false,
                    'message' => __('order.missing_order_id'),
                ], 400);
            }

            $order = Order::findOrFail($orderId);

            // If the money was already authorized/captured, ignore the cancel — do
            // NOT overwrite a successful payment.
            $alreadySettled = PaymentTransaction::where('order_id', $order->id)
                ->whereIn('status', ['authorized', 'completed'])
                ->exists();

            if ($alreadySettled) {
                Log::channel('payment')->warning('Cancel ignored — order already has an authorized/completed payment', [
                    'order_id' => $order->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => __('order.payment_cancelled'),
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                    ],
                ]);
            }

            // Only release still-pending, un-authorized transactions.
            PaymentTransaction::where('order_id', $order->id)
                ->where('status', 'pending')
                ->whereNull('fort_id')
                ->whereNull('paid_at')
                ->update(['status' => 'cancelled']);

            return response()->json([
                'success' => true,
                'message' => __('order.payment_cancelled'),
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
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
     * Get order payment status
     *
     * NOTE: This function will be moved to OrderController in Phase 2
     */
    public function getPaymentStatus(Request $request, $orderId)
    {
        try {
            $order = Order::findOrFail($orderId);

            // Validate order belongs to authenticated user
            if ($request->user()->id !== $order->client_id) {
                return response()->json([
                    'success' => false,
                    'message' => __('order.unauthorized_access'),
                ], 403);
            }

            $transaction = PaymentTransaction::where('order_id', $order->id)
                ->latest()
                ->first();

            if (! $transaction) {
                return response()->json([
                    'success' => true,
                    'message' => __('order.no_payment_initiated'),
                    'data' => [
                        'order_id' => $order->id,
                        'payment_status' => 'not_initiated',
                        'payment_status_label' => \App\Support\PaymentStatusPresenter::label('not_initiated'),
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('order.payment_status_retrieved'),
                'data' => [
                    'order_id' => $order->id,
                    'transaction_id' => $transaction->transaction_id,
                    'gateway' => $transaction->gateway,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'status' => $transaction->status,
                    'payment_status' => $transaction->status,
                    'payment_status_label' => \App\Support\PaymentStatusPresenter::label($transaction->status),
                    'paid_at' => $transaction->paid_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
