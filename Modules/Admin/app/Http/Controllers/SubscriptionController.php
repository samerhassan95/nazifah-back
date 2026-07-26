<?php

namespace Modules\Admin\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Admin\Http\Requests\StoreSubscriptionPlanRequest;
use Modules\Admin\Http\Requests\UpdateSubscriptionPlanRequest;
use Modules\Admin\Http\Resources\SubscriptionPlanResource;
use Modules\Admin\Http\Resources\VendorSubscriptionResource;
use Modules\Subscription\Services\SubscriptionService;

class SubscriptionController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService
    ) {}

    /**
     * Get subscribers list
     * GET /subscriptions/subscribers
     */
    public function subscribers(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->input('status'),
            'vendor_id' => $request->input('vendor_id'),
            'plan_id' => $request->input('plan_id'),
            'billing_cycle' => $request->input('billing_cycle'),
            'per_page' => $request->input('per_page', 15),
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $subscribers = $this->subscriptionService->getAllSubscriptions($filters);

        return successResponse(
            VendorSubscriptionResource::collection($subscribers),
            'Subscribers retrieved successfully'
        );
    }

    /**
     * Get subscription plans
     * GET /subscriptions/plans
     */
    public function plans(Request $request): JsonResponse
    {
        $billingCycle = $request->input('billing_cycle'); // 'monthly' or 'yearly' - optional filter
        $locale = app()->getLocale(); // Get current locale from middleware

        $filters = [
            'is_active' => true,
            'per_page' => $request->input('per_page', 100),
        ];

        $basePlans = $this->subscriptionService->getAllPlans($filters);
        $plans = [];

        foreach ($basePlans->items() as $plan) {
            // Get translated name based on locale
            $name = is_string($plan->name) ? json_decode($plan->name, true) : $plan->name;
            $planName = is_array($name) ? ($name[$locale] ?? $name['ar'] ?? $name['en'] ?? 'Unknown') : $plan->name;

            // Get translated tagline based on locale - handle empty strings and null
            $tagline = null;
            if ($plan->tagline) {
                $taglineData = is_string($plan->tagline) ? json_decode($plan->tagline, true) : $plan->tagline;
                if (is_array($taglineData)) {
                    $tagline = $taglineData[$locale] ?? $taglineData['ar'] ?? $taglineData['en'] ?? null;
                } else {
                    $tagline = $plan->tagline;
                }
                // Convert empty string to null
                $tagline = $tagline === '' ? null : $tagline;
            }

            if ($billingCycle === 'monthly') {
                $price = $plan->price_month;
                $currency = $price == 0 ? 'مجانا' : 'ر.س';

                $plans[] = [
                    'id' => $plan->id,
                    'name' => $planName,
                    'tagline' => $tagline,
                    'price' => (float) $price,
                    'currency' => $currency,
                    'has_discount' => $plan->has_discount,
                    'discount_percentage' => $plan->discount_percentage,
                    'billing_cycle' => 'monthly',
                    'branch_count' => $plan->branch_count,
                    'order_count' => $plan->order_count,
                    'has_discount_codes' => $plan->has_discount_codes,
                    'has_special_delivery' => $plan->has_special_delivery,
                    'has_reports' => $plan->has_reports,
                ];
            } elseif ($billingCycle === 'yearly') {
                $price = $plan->price_year;
                $currency = $price == 0 ? 'مجانا' : 'ر.س';

                $plans[] = [
                    'id' => $plan->id,
                    'name' => $planName,
                    'tagline' => $tagline,
                    'price' => (float) $price,
                    'currency' => $currency,
                    'has_discount' => $plan->has_discount,
                    'discount_percentage' => $plan->discount_percentage,
                    'billing_cycle' => 'yearly',
                    'branch_count' => $plan->branch_count,
                    'order_count' => $plan->order_count,
                    'has_discount_codes' => $plan->has_discount_codes,
                    'has_special_delivery' => $plan->has_special_delivery,
                    'has_reports' => $plan->has_reports,
                ];
            } else {
                // Return both monthly and yearly versions
                // Monthly version
                $monthlyPrice = $plan->price_month;
                $monthlyCurrency = $monthlyPrice == 0 ? 'مجانا' : 'ر.س';

                $plans[] = [
                    'id' => $plan->id,
                    'name' => $planName,
                    'tagline' => $tagline,
                    'price' => (float) $monthlyPrice,
                    'currency' => $monthlyCurrency,
                    'has_discount' => $plan->has_discount,
                    'discount_percentage' => $plan->discount_percentage,
                    'billing_cycle' => 'monthly',
                    'branch_count' => $plan->branch_count,
                    'order_count' => $plan->order_count,
                    'has_discount_codes' => $plan->has_discount_codes,
                    'has_special_delivery' => $plan->has_special_delivery,
                    'has_reports' => $plan->has_reports,
                ];

                // Yearly version
                $yearlyPrice = $plan->price_year;
                $yearlyCurrency = $yearlyPrice == 0 ? 'مجانا' : 'ر.س';

                $plans[] = [
                    'id' => $plan->id,
                    'name' => $planName,
                    'tagline' => $tagline,
                    'price' => (float) $yearlyPrice,
                    'currency' => $yearlyCurrency,
                    'has_discount' => $plan->has_discount,
                    'discount_percentage' => $plan->discount_percentage,
                    'billing_cycle' => 'yearly',
                    'branch_count' => $plan->branch_count,
                    'order_count' => $plan->order_count,
                    'has_discount_codes' => $plan->has_discount_codes,
                    'has_special_delivery' => $plan->has_special_delivery,
                    'has_reports' => $plan->has_reports,
                ];
            }
        }

        // Return in the exact format requested by the user
        return successResponse($plans, 'Subscription plans retrieved successfully');
    }

    /**
     * Get single subscription plan
     * GET /subscriptions/plans/{id}
     */
    public function showPlan(int $id): JsonResponse
    {
        $plan = $this->subscriptionService->getPlanById($id);

        if (! $plan) {
            return notFoundResponse('Subscription plan not found');
        }

        return successResponse(
            new SubscriptionPlanResource($plan),
            'Subscription plan retrieved successfully'
        );
    }

    /**
     * Create subscription plan
     * POST /subscriptions/plans
     */
    public function createPlan(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        try {
            $plan = $this->subscriptionService->createPlan($request->validated());

            return successResponse(
                new SubscriptionPlanResource($plan),
                'Subscription plan created successfully',
                201
            );
        } catch (\Exception $e) {
            return errorResponse('Failed to create subscription plan: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Update subscription plan
     * PUT /subscriptions/plans/{id}
     */
    public function updatePlan(UpdateSubscriptionPlanRequest $request, int $id): JsonResponse
    {
        $plan = $this->subscriptionService->updatePlan($id, $request->validated());

        if (! $plan) {
            return notFoundResponse('Subscription plan not found');
        }

        return successResponse(
            new SubscriptionPlanResource($plan),
            'Subscription plan updated successfully'
        );
    }

    /**
     * Delete subscription plan
     * DELETE /subscriptions/plans/{id}
     */
    public function deletePlan(int $id): JsonResponse
    {
        $deleted = $this->subscriptionService->deletePlan($id);

        if (! $deleted) {
            return notFoundResponse('Subscription plan not found');
        }

        return successResponse(null, 'Subscription plan deleted successfully');
    }

    /**
     * Get single subscription
     * GET /subscriptions/{id}
     */
    public function showSubscription(int $id): JsonResponse
    {
        $subscription = $this->subscriptionService->getSubscriptionById($id);

        if (! $subscription) {
            return notFoundResponse('Subscription not found');
        }

        return successResponse(
            new VendorSubscriptionResource($subscription),
            'Subscription retrieved successfully'
        );
    }

    /**
     * ASSIGN/CREATE Subscription for a Vendor
     * POST /admin/subscriptions/assign
     */
    public function assignToVendor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'subscription_plan_id' => 'required|exists:subscription_plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
            // Validate against the Enum values
            'payment_method' => ['required', Rule::in(PaymentMethod::values())],
        ]);

        try {
            // The service now handles amount lookup and date calculation
            $subscription = $this->subscriptionService->createSubscription($validated);

            return successResponse(
                new VendorSubscriptionResource($subscription),
                'Subscription assigned successfully',
                201
            );
        } catch (\Exception $e) {
            return errorResponse('Failed: '.$e->getMessage());
        }
    }

    /**
     * UPDATE a vendor's specific subscription (e.g., change expiry or status)
     * PUT /admin/subscriptions/{id}
     */
    public function updateVendorSubscription(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|in:active,expired,banned,cancelled',
            'expiry_date' => 'sometimes|date',
            'amount' => 'sometimes|numeric',
        ]);

        $subscription = $this->subscriptionService->updateSubscription($id, $validated);

        if (! $subscription) {
            return notFoundResponse('Subscription record not found');
        }

        return successResponse(new VendorSubscriptionResource($subscription), 'Subscription updated');
    }

    /**
     * CANCEL a vendor's subscription
     * PATCH /admin/subscriptions/{id}/cancel
     */
    public function cancelVendorSubscription(int $id): JsonResponse
    {
        $result = $this->subscriptionService->cancelSubscription($id);

        if (! $result) {
            return errorResponse('Could not cancel subscription. It might not exist.');
        }

        return successResponse(null, 'Subscription cancelled successfully');
    }

    /**
     * EXPIRE check (Trigger manually or via Cron)
     * POST /admin/subscriptions/run-expiry-check
     */
    public function triggerExpiryCheck(): JsonResponse
    {
        $count = $this->subscriptionService->expireSubscriptions();

        return successResponse(['expired_count' => $count], 'Expiry check completed');
    }

    /**
     * Get all available payment methods for subscriptions
     * GET /admin/subscriptions/payment-methods
     */
    public function getPaymentMethods(Request $request): JsonResponse
    {
        $lang = $request->header('Accept-Language', app()->getLocale());

        // Use the helper method defined in your Enum
        $methods = PaymentMethod::getAllWithDisplayNames($lang);

        return successResponse($methods, 'Payment methods retrieved successfully');
    }
}
