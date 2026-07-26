<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Vendor\Http\Requests\SubscribeRequest;
use Modules\Vendor\Http\Resources\SubscriptionPlanResource;
use Modules\Vendor\Http\Resources\VendorSubscriptionResource;

class SubscriptionController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService
    ) {}

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
        $vendor = $request->user();

        $filters = [
            'status' => $request->input('status'),
            'per_page' => $request->input('per_page', 15),
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $subscriptions = $this->subscriptionService->getVendorSubscriptions($vendor->id, $filters);

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
        $vendor = $request->user();

        $subscription = $this->subscriptionService->getActiveVendorSubscription($vendor->id);

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
     */
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $vendor = $request->user();

        // Check if vendor already has an active subscription
        $activeSubscription = $this->subscriptionService->getActiveVendorSubscription($vendor->id);
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
        $amount = $billingCycle === 'yearly' ? $plan->price_year : $plan->price_month;

        try {
            $subscription = $this->subscriptionService->createSubscription([
                'vendor_id' => $vendor->id,
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => $billingCycle,
                'amount' => $amount,
                'payment_method' => $request->input('payment_method'),
                'subscription_date' => now(),
            ]);

            return successResponse(
                new VendorSubscriptionResource($subscription),
                __('subscription.subscription_created'),
                201
            );
        } catch (\Exception $e) {
            return errorResponse('Failed to create subscription: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Cancel subscription
     * POST /subscriptions/{id}/cancel
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $vendor = $request->user();

        $subscription = $this->subscriptionService->getSubscriptionById($id);

        if (! $subscription) {
            return notFoundResponse(__('subscription.subscription_not_found'));
        }

        // Verify the subscription belongs to the vendor
        if ($subscription->vendor_id !== $vendor->id) {
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
        $vendor = $request->user();

        $subscription = $this->subscriptionService->getSubscriptionById($id);

        if (! $subscription) {
            return notFoundResponse(__('subscription.subscription_not_found'));
        }

        // Verify the subscription belongs to the vendor
        if ($subscription->vendor_id !== $vendor->id) {
            return forbiddenResponse(__('subscription.unauthorized_view'));
        }

        return successResponse(
            new VendorSubscriptionResource($subscription),
            __('subscription.subscription_retrieved')
        );
    }
}
