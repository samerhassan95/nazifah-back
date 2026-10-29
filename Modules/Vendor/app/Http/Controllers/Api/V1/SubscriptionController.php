<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Payment\DTOs\PaymentRequest;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentService;
use Modules\Payment\Services\VendorSubscriptionActivator;
use Modules\Subscription\Models\VendorSubscription;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Vendor\Http\Requests\SubscribeRequest;
use Modules\Vendor\Http\Resources\SubscriptionPlanResource;
use Modules\Vendor\Http\Resources\VendorSubscriptionResource;

class SubscriptionController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private PaymentService $paymentService,
        private VendorSubscriptionActivator $subscriptionActivator,
    ) {}

    /**
     * auth:vendor authenticates a VendorEmployee (config/auth.php: guard
     * 'vendor' -> provider 'vendors' -> Modules\Vendor\Models\VendorEmployee),
     * not the Vendor itself — $request->user()->id is the employee's own id.
     * Every subscription record must key off the real vendors.id instead.
     */
    private function resolveVendorId(Request $request): int
    {
        $user = $request->user();

        return (int) ($user->vendor_id ?? $user->id);
    }

    /**
     * Module routes are registered under the 'api.' name prefix (see
     * Modules\Vendor\Providers\RouteServiceProvider::mapApiRoutes()), so the
     * real name is 'api.vendor.subscriptions.payment.verify', not the bare
     * one — try both, then fall back to the known path.
     */
    private function verifyPaymentUrl(string $transactionId): string
    {
        foreach (['vendor.subscriptions.payment.verify', 'api.vendor.subscriptions.payment.verify'] as $name) {
            if (Route::has($name)) {
                return route($name, ['transactionId' => $transactionId]);
            }
        }

        return url('/api/v1/vendor/subscriptions/payment/verify/'.$transactionId);
    }

    /**
     * Get available subscription plans
     * GET /subscriptions/plans
     */
    public function plans(Request $request): JsonResponse
    {
        $billingCycle = $request->input('billing_cycle'); // 'monthly' or 'yearly' - optional filter

        $basePlans = $this->subscriptionService->getActivePlans();
        $plans = [];

        foreach ($basePlans as $plan) {
            // If billing cycle filter is set, return only that cycle
            if ($billingCycle === 'monthly') {
                $plans[] = new SubscriptionPlanResource($plan);
            } elseif ($billingCycle === 'yearly') {
                $plans[] = new SubscriptionPlanResource($plan);
            } else {
                // Return both monthly and yearly versions
                $request->merge(['billing_cycle' => 'monthly']);
                $plans[] = new SubscriptionPlanResource($plan);

                $request->merge(['billing_cycle' => 'yearly']);
                $plans[] = new SubscriptionPlanResource($plan);
            }
        }

        // Return in the exact format requested by the user
        return successResponse([
            'subscription_plans' => SubscriptionPlanResource::collection($plans)->resolve(),
        ], __('subscription.plans_retrieved'));
    }

    /**
     * Get vendor's subscriptions
     * GET /subscriptions
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $this->resolveVendorId($request);

        $filters = [
            'status' => $request->input('status'),
            'per_page' => $request->input('per_page', 15),
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $subscriptions = $this->subscriptionService->getVendorSubscriptions($vendorId, $filters);

        return successResponse(
            VendorSubscriptionResource::collection($subscriptions),
            __('subscription.subscriptions_retrieved')
        );
    }

    /**
     * Get vendor's active subscription
     * GET /subscriptions/active
     */
    public function active(Request $request): JsonResponse
    {
        $vendorId = $this->resolveVendorId($request);

        $subscription = $this->subscriptionService->getActiveVendorSubscription($vendorId);

        if (! $subscription) {
            return successResponse(null, __('subscription.no_active_subscription'));
        }

        return successResponse(
            new VendorSubscriptionResource($subscription),
            __('subscription.active_subscription_retrieved')
        );
    }

    /**
     * Subscribe to a plan
     * POST /subscriptions/subscribe
     *
     * A paid plan on a gateway-eligible payment method (card/etc) does NOT
     * create the subscription here — it only initializes a Moyasar payment
     * and returns `payment_url` for the vendor to complete it. The
     * subscription row is created once the payment actually settles, via
     * PaymentController::callback()/moyasarWebhook() or verifyPayment()
     * below — never on this request alone, otherwise an unpaid request
     * would activate a subscription for free.
     */
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $vendor = $request->user();
        $vendorId = $this->resolveVendorId($request);

        // Check if vendor already has an active subscription
        $activeSubscription = $this->subscriptionService->getActiveVendorSubscription($vendorId);
        if ($activeSubscription) {
            return errorResponse(__('subscription.already_have_active_subscription'), null, 400);
        }

        // Get the plan
        $plan = $this->subscriptionService->getPlanById($request->input('subscription_plan_id'));
        if (! $plan) {
            return notFoundResponse(__('subscription.plan_not_found'));
        }

        // Calculate amount based on billing cycle
        $billingCycle = $request->input('billing_cycle');
        $amount = (float) ($billingCycle === 'yearly' ? $plan->price_year : $plan->price_month);

        $paymentMethod = PaymentMethod::normalize((string) $request->input('payment_method'));
        $usesGateway = ! in_array($paymentMethod, [
            PaymentMethod::CASH_ON_DELIVERY->value,
            PaymentMethod::Nathefah_WALLET->value,
        ], true);

        // Free plan or an offline payment method (e.g. manually recorded bank
        // transfer): activate immediately, exactly like before, no gateway involved.
        if ($amount <= 0 || ! $usesGateway) {
            try {
                $subscription = $this->subscriptionService->createSubscription([
                    'vendor_id' => $vendorId,
                    'subscription_plan_id' => $plan->id,
                    'billing_cycle' => $billingCycle,
                    'amount' => $amount,
                    'payment_method' => $request->input('payment_method'),
                    'subscription_date' => now(),
                ]);

                return successResponse(
                    new VendorSubscriptionResource($subscription),
                    $amount <= 0 ? __('subscription.free_subscription_success') : __('subscription.subscription_created'),
                    201
                );
            } catch (\Exception $e) {
                return errorResponse('Failed to create subscription: '.$e->getMessage(), null, 500);
            }
        }

        try {
            $gatewayName = PaymentMethod::getGatewayName($paymentMethod);
            $this->paymentService->setGateway($gatewayName);

            // Mirror the order checkout flow exactly, so the gateway shows the same
            // set of payment methods for a subscription as it does for an order.
            $paymentOption = PaymentMethod::tryFrom($paymentMethod)?->getPayfortPaymentOption();

            $reference = 'SUB-'.$vendorId.'-'.time().'-'.Str::lower(Str::random(6));

            $baseUrl = rtrim((string) config('app.url'), '/');
            $returnUrl = Route::has('api.payment.callback')
                ? route('api.payment.callback').'?vendor_subscription=1&reference='.$reference
                : $baseUrl.'/api/v1/payments/callback?vendor_subscription=1&reference='.$reference;
            $cancelUrl = Route::has('api.payment.cancel')
                ? route('api.payment.cancel').'?vendor_subscription=1&reference='.$reference
                : $baseUrl.'/api/v1/payments/cancel?vendor_subscription=1&reference='.$reference;

            $paymentRequest = new PaymentRequest(
                amount: $amount,
                currency: config('payment.currency', 'SAR'),
                orderId: $reference,
                customerEmail: $vendor->email ?? config('payment.default_email', 'no-reply@nathefah.com'),
                customerName: $vendor->name ?? null,
                customerPhone: $vendor->phone ?? null,
                returnUrl: $returnUrl,
                cancelUrl: $cancelUrl,
                paymentOption: $paymentOption,
                metadata: [
                    'vendor_subscription' => true,
                    'vendor_subscription_reference' => $reference,
                    'vendor_id' => $vendorId,
                    'subscription_plan_id' => $plan->id,
                    'billing_cycle' => $billingCycle,
                    'payment_method' => $paymentMethod,
                    'payment_option' => $paymentOption,
                ],
            );

            $paymentResponse = $this->paymentService->initializePayment($paymentRequest);

            if (! $paymentResponse->isSuccessful()) {
                return errorResponse($paymentResponse->message ?? 'Failed to initialize payment', null, 400);
            }

            $transaction = PaymentTransaction::create([
                'order_id' => null,
                'gateway' => $this->paymentService->getActiveGateway()->getName(),
                'transaction_id' => $paymentResponse->transactionId ?? $reference,
                'amount' => $paymentResponse->amount ?? $amount,
                'currency' => $paymentResponse->currency ?? config('payment.currency', 'SAR'),
                'status' => $paymentResponse->status ?? 'pending',
                'payment_method' => $paymentMethod,
                'customer_email' => $vendor->email,
                'customer_name' => $vendor->name,
                'customer_phone' => $vendor->phone,
                'response_data' => array_merge($paymentResponse->data ?? [], [
                    'vendor_subscription' => true,
                    'vendor_subscription_reference' => $reference,
                    'vendor_id' => $vendorId,
                    'subscription_plan_id' => $plan->id,
                    'billing_cycle' => $billingCycle,
                    'payment_method' => $paymentMethod,
                ]),
            ]);

            return successResponse([
                'payment_url' => $paymentResponse->paymentUrl,
                'transaction_id' => $transaction->transaction_id,
                'status' => $transaction->status,
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'verify_url' => $this->verifyPaymentUrl($transaction->transaction_id),
            ], __('subscription.payment_initialized'));
        } catch (\Exception $e) {
            return errorResponse('Failed to initialize payment: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Pull-based confirmation for a gateway subscription payment — lets the
     * vendor app actively confirm/poll when the browser redirect back from
     * Moyasar is missed, same role as the client wallet's verifyDeposit().
     * Idempotent: a payment already activated just returns the existing
     * subscription instead of re-settling it.
     *
     * GET /subscriptions/payment/verify/{transactionId}
     */
    public function verifyPayment(Request $request, string $transactionId): JsonResponse
    {
        $vendorId = $this->resolveVendorId($request);

        $transaction = PaymentTransaction::where('transaction_id', $transactionId)
            ->orWhereJsonContains('response_data->vendor_subscription_reference', $transactionId)
            ->first();

        if (! $transaction) {
            return notFoundResponse(__('payment.transaction_not_found'));
        }

        $metadata = $transaction->response_data ?? [];
        if ((int) ($metadata['vendor_id'] ?? 0) !== $vendorId) {
            return errorResponse(__('payment.unauthorized_transaction'), null, 403);
        }

        $existingSubscription = VendorSubscription::where('payment_transaction_id', $transaction->id)->first();
        if ($existingSubscription) {
            return successResponse(new VendorSubscriptionResource($existingSubscription), __('subscription.subscription_created'));
        }

        $this->paymentService->setGateway(strtolower(str_replace(' ', '_', $transaction->gateway)));
        $verificationResponse = $this->paymentService->verifyPayment($transaction->transaction_id);

        $validStatuses = ['pending', 'authorized', 'completed', 'failed', 'cancelled', 'refunded', 'partially_refunded'];
        $mappedStatus = in_array($verificationResponse->status, $validStatuses, true) ? $verificationResponse->status : 'failed';

        $transaction->update([
            'status' => $mappedStatus,
            'response_data' => array_merge($transaction->response_data ?? [], $verificationResponse->data ?? []),
            'paid_at' => $verificationResponse->isSuccessful() ? now() : $transaction->paid_at,
        ]);

        if (! ($verificationResponse->isSuccessful() && $mappedStatus === 'completed')) {
            return errorResponse(
                $verificationResponse->message ?? __('subscription.payment_not_completed'),
                ['status' => $mappedStatus],
                $mappedStatus === 'pending' ? 202 : 400
            );
        }

        $result = $this->subscriptionActivator->activateIfNotAlready($transaction->fresh());

        if (! $result['subscription']) {
            return errorResponse(__('subscription.subscription_activation_failed'), null, 500);
        }

        return successResponse(new VendorSubscriptionResource($result['subscription']), __('subscription.subscription_created'));
    }

    /**
     * Cancel subscription
     * POST /subscriptions/{id}/cancel
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $vendorId = $this->resolveVendorId($request);

        $subscription = $this->subscriptionService->getSubscriptionById($id);

        if (! $subscription) {
            return notFoundResponse(__('subscription.subscription_not_found'));
        }

        // Verify the subscription belongs to the vendor
        if ((int) $subscription->vendor_id !== $vendorId) {
            return forbiddenResponse(__('subscription.unauthorized_cancel'));
        }

        $cancelled = $this->subscriptionService->cancelSubscription($id);

        if (! $cancelled) {
            return errorResponse(__('subscription.cancel_failed'), null, 500);
        }

        return successResponse(
            new VendorSubscriptionResource($subscription->fresh()),
            __('subscription.subscription_cancelled')
        );
    }

    /**
     * Get single subscription
     * GET /subscriptions/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $vendorId = $this->resolveVendorId($request);

        $subscription = $this->subscriptionService->getSubscriptionById($id);

        if (! $subscription) {
            return notFoundResponse(__('subscription.subscription_not_found'));
        }

        // Verify the subscription belongs to the vendor
        if ((int) $subscription->vendor_id !== $vendorId) {
            return forbiddenResponse(__('subscription.unauthorized_view'));
        }

        return successResponse(
            new VendorSubscriptionResource($subscription),
            __('subscription.subscription_retrieved')
        );
    }
}
