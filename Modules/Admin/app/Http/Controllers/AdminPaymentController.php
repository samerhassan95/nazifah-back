<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Order\Models\Order;
use Modules\Payment\DTOs\RefundRequest;
use Modules\Payment\Models\PaymentRefund;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentService;

/**
 * Admin payment management for orders: view transactions and
 * capture / void / refund through the gateway (reserve→capture flow).
 */
class AdminPaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    /**
     * GET admin/orders/{orderId}/payments
     * List all payment transactions (and refunds) for an order.
     */
    public function transactions(int $orderId): JsonResponse
    {
        $order = Order::find($orderId);
        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $transactions = PaymentTransaction::where('order_id', $order->id)
            ->with('refunds')
            ->latest()
            ->get()
            ->map(function (PaymentTransaction $t) {
                $isAdditionalCharge = (bool) ($t->response_data['is_additional_charge'] ?? false);

                return [
                    'id' => $t->id,
                    'transaction_id' => $t->transaction_id,
                    'fort_id' => $t->fort_id,
                    'gateway' => $t->gateway,
                    'payment_method' => $t->payment_method,
                    'status' => $t->status,
                    'amount' => (float) $t->amount,
                    'authorized_amount' => $t->authorized_amount !== null ? (float) $t->authorized_amount : null,
                    'refund_amount' => (float) $t->refund_amount,
                    'currency' => $t->currency,
                    'is_additional_charge' => $isAdditionalCharge,
                    'paid_at' => $t->paid_at?->toISOString(),
                    'refunded_at' => $t->refunded_at?->toISOString(),
                    'created_at' => $t->created_at?->toISOString(),
                    'refunds' => $t->refunds->map(fn (PaymentRefund $r) => [
                        'id' => $r->id,
                        'refund_id' => $r->refund_id,
                        'amount' => (float) $r->amount,
                        'status' => $r->status,
                        'reason' => $r->reason,
                        'processed_at' => $r->processed_at?->toISOString(),
                    ])->values(),
                ];
            });

        $captured = (float) PaymentTransaction::where('order_id', $order->id)
            ->where('status', 'completed')->sum('amount');
        $reserved = (float) PaymentTransaction::where('order_id', $order->id)
            ->where('status', 'authorized')->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(authorized_amount, amount)'));
        $refunded = (float) PaymentTransaction::where('order_id', $order->id)->sum('refund_amount');

        return successResponse([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_status' => $order->status,
            'final_amount' => (float) $order->final_amount,
            'summary' => [
                'captured' => $captured,
                'reserved' => $reserved,
                'refunded' => $refunded,
            ],
            'transactions' => $transactions,
        ], 'Order payments retrieved successfully');
    }

    /**
     * POST admin/orders/{orderId}/payments/capture
     * Capture an authorized (reserved) payment. Optional `amount` (defaults to the
     * authorized amount); never exceeds the authorized hold.
     */
    public function capture(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'transaction_id' => 'nullable|string',
        ]);

        $order = Order::find($orderId);
        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        // Already captured at checkout (PURCHASE) -> nothing to do.
        $completed = PaymentTransaction::where('order_id', $order->id)
            ->where('status', 'completed')
            ->whereNotNull('paid_at')
            ->when(! empty($validated['transaction_id']), fn ($q) => $q->where('transaction_id', $validated['transaction_id']))
            ->latest()
            ->first();

        if ($completed && empty($validated['transaction_id'])) {
            return successResponse([
                'transaction_id' => $completed->transaction_id,
                'captured_amount' => (float) $completed->amount,
                'status' => 'completed',
            ], 'Payment already captured');
        }

        $transaction = PaymentTransaction::where('order_id', $order->id)
            ->where('status', 'authorized')
            ->whereNotNull('fort_id')
            ->when(! empty($validated['transaction_id']), fn ($q) => $q->where('transaction_id', $validated['transaction_id']))
            ->latest()
            ->first();

        if (! $transaction) {
            return errorResponse('No authorized transaction found for this order', 404);
        }

        $authorized = (float) ($transaction->authorized_amount ?: $transaction->amount);
        $amount = isset($validated['amount']) ? (float) $validated['amount'] : $authorized;

        if ($amount > $authorized) {
            return errorResponse('Capture amount exceeds authorized amount', 400);
        }

        try {
            $this->paymentService->setGateway(strtolower(str_replace(' ', '_', $transaction->gateway)));

            $response = $this->paymentService->capture(
                $transaction->fort_id,
                $amount,
                $transaction->transaction_id,
                $transaction->payfortPaymentOption()
            );

            if ($response->isSuccessful()) {
                $newStatus = $response->status === 'authorized' ? 'authorized' : 'completed';
                $transaction->update([
                    'status' => $newStatus,
                    'amount' => $newStatus === 'completed' ? $amount : $transaction->amount,
                    'authorized_amount' => $transaction->authorized_amount ?: $authorized,
                    'paid_at' => $newStatus === 'completed' ? now() : $transaction->paid_at,
                    'response_data' => array_merge($transaction->response_data ?? [], $response->data ?? []),
                ]);
            }

            return successResponse([
                'transaction_id' => $transaction->transaction_id,
                'captured_amount' => $amount,
                'authorized_amount' => $authorized,
                'status' => $response->status,
            ], $response->message ?? 'Capture processed', $response->isSuccessful() ? 200 : 400);
        } catch (\Exception $e) {
            return serverErrorResponse($e->getMessage());
        }
    }

    /**
     * POST admin/orders/{orderId}/payments/void
     * Release an authorized (reserved) hold that was never captured.
     */
    public function void(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'transaction_id' => 'nullable|string',
        ]);

        $order = Order::find($orderId);
        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $transaction = PaymentTransaction::where('order_id', $order->id)
            ->where('status', 'authorized')
            ->whereNotNull('fort_id')
            ->when(! empty($validated['transaction_id']), fn ($q) => $q->where('transaction_id', $validated['transaction_id']))
            ->latest()
            ->first();

        if (! $transaction) {
            return errorResponse('No authorized transaction found to void', 404);
        }

        try {
            $this->paymentService->setGateway(strtolower(str_replace(' ', '_', $transaction->gateway)));

            $response = $this->paymentService->voidAuthorization(
                $transaction->fort_id,
                $transaction->transaction_id,
                $transaction->payfortPaymentOption()
            );

            if ($response->isSuccessful()) {
                // 'voided' is not a valid transactions ENUM value -> use 'cancelled'.
                $transaction->update([
                    'status' => 'cancelled',
                    'error_message' => null,
                    'response_data' => array_merge($transaction->response_data ?? [], $response->data ?? []),
                ]);
            }

            return successResponse([
                'transaction_id' => $transaction->transaction_id,
                'status' => $response->isSuccessful() ? 'cancelled' : $transaction->status,
            ], $response->message ?? 'Void processed', $response->isSuccessful() ? 200 : 400);
        } catch (\Exception $e) {
            return serverErrorResponse($e->getMessage());
        }
    }

    /**
     * POST admin/orders/{orderId}/payments/refund
     * Refund a captured (completed) payment. Optional `amount` (defaults to the full
     * remaining refundable amount); supports partial refunds.
     */
    public function refund(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
            'transaction_id' => 'nullable|string',
        ]);

        $order = Order::find($orderId);
        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $transaction = PaymentTransaction::where('order_id', $order->id)
            ->whereIn('status', ['completed', 'partially_refunded'])
            ->when(! empty($validated['transaction_id']), fn ($q) => $q->where('transaction_id', $validated['transaction_id']))
            ->latest()
            ->first();

        if (! $transaction) {
            return errorResponse('No captured transaction found to refund', 404);
        }

        $alreadyRefunded = (float) $transaction->refund_amount;
        $refundable = (float) $transaction->amount - $alreadyRefunded;

        if ($refundable <= 0) {
            return errorResponse('Transaction is already fully refunded', 400);
        }

        $amount = isset($validated['amount']) ? (float) $validated['amount'] : $refundable;

        if ($amount > $refundable) {
            return errorResponse('Refund amount exceeds the remaining refundable amount', 400);
        }

        try {
            $this->paymentService->setGateway(strtolower(str_replace(' ', '_', $transaction->gateway)));

            $refundRequest = new RefundRequest(
                transactionId: $transaction->transaction_id,
                amount: $amount,
                reason: $validated['reason'] ?? ('Admin refund for order #'.$order->order_number),
                paymentOption: $transaction->payfortPaymentOption(),
                gatewayPaymentId: $transaction->fort_id
            );

            $response = $this->paymentService->refund($refundRequest);

            if ($response->isSuccessful()) {
                PaymentRefund::create([
                    'payment_transaction_id' => $transaction->id,
                    'refund_id' => $response->refundId,
                    'amount' => $amount,
                    'currency' => $transaction->currency,
                    'status' => in_array($response->status, ['pending', 'completed', 'failed']) ? $response->status : 'completed',
                    'reason' => $validated['reason'] ?? ('Admin refund for order #'.$order->order_number),
                    'response_data' => $response->data,
                    'processed_at' => now(),
                ]);

                $newRefundTotal = $alreadyRefunded + $amount;
                $transaction->update([
                    'refund_amount' => $newRefundTotal,
                    'status' => $newRefundTotal >= (float) $transaction->amount ? 'refunded' : 'partially_refunded',
                    'refunded_at' => now(),
                ]);
            }

            return successResponse([
                'transaction_id' => $transaction->transaction_id,
                'refund_id' => $response->refundId,
                'refunded_amount' => $amount,
                'total_refunded' => $alreadyRefunded + ($response->isSuccessful() ? $amount : 0),
                'status' => $response->status,
            ], $response->message ?? 'Refund processed', $response->isSuccessful() ? 200 : 400);
        } catch (\Exception $e) {
            return serverErrorResponse($e->getMessage());
        }
    }
}
