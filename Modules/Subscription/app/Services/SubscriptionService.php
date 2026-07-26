<?php

namespace Modules\Subscription\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Subscription\Interfaces\SubscriptionRepositoryInterface;
use Modules\Subscription\Models\SubscriptionPlan;
use Modules\Subscription\Models\VendorSubscription;

class SubscriptionService
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository
    ) {}

    // Subscription Plan methods
    public function getAllPlans(array $filters = []): LengthAwarePaginator
    {
        return $this->subscriptionRepository->getAllPlans($filters);
    }

    public function getPlanById(int $id): ?SubscriptionPlan
    {
        return $this->subscriptionRepository->getPlanById($id);
    }

    public function createPlan(array $data): SubscriptionPlan
    {
        // Prepare translatable fields - only if they come as strings
        if (isset($data['plan_name']) && is_string($data['plan_name'])) {
            $data['name'] = [
                'en' => $data['plan_name'],
                'ar' => $data['plan_name_ar'] ?? $data['plan_name'],
            ];
            unset($data['plan_name'], $data['plan_name_ar']);
        }

        // Tagline is already an array from the request, no need to transform
        // Just ensure it's properly formatted if it exists
        if (isset($data['tagline']) && is_string($data['tagline'])) {
            $data['tagline'] = [
                'en' => $data['tagline'],
                'ar' => $data['tagline'],
            ];
        }

        return $this->subscriptionRepository->createPlan($data);
    }

    public function updatePlan(int $id, array $data): ?SubscriptionPlan
    {
        $plan = $this->subscriptionRepository->getPlanById($id);

        if (! $plan) {
            return null;
        }

        // Prepare translatable fields - only if they come as strings
        if (isset($data['plan_name']) && is_string($data['plan_name'])) {
            $data['name'] = [
                'en' => $data['plan_name'],
                'ar' => $data['plan_name_ar'] ?? $data['plan_name'],
            ];
            unset($data['plan_name'], $data['plan_name_ar']);
        }

        // Tagline is already an array from the request, no need to transform
        if (isset($data['tagline']) && is_string($data['tagline'])) {
            $data['tagline'] = [
                'en' => $data['tagline'],
                'ar' => $data['tagline'],
            ];
        }

        $this->subscriptionRepository->updatePlan($plan, $data);

        return $plan->fresh();
    }

    public function deletePlan(int $id): bool
    {
        $plan = $this->subscriptionRepository->getPlanById($id);

        if (! $plan) {
            return false;
        }

        return $this->subscriptionRepository->deletePlan($plan);
    }

    public function getActivePlans(): Collection
    {
        return $this->subscriptionRepository->getActivePlans();
    }

    // Vendor Subscription methods
    public function getAllSubscriptions(array $filters = []): LengthAwarePaginator
    {
        return $this->subscriptionRepository->getAllSubscriptions($filters);
    }

    public function getSubscriptionById(int $id): ?VendorSubscription
    {
        return $this->subscriptionRepository->getSubscriptionById($id);
    }

    public function getVendorSubscriptions(int $vendorId, array $filters = []): LengthAwarePaginator
    {
        return $this->subscriptionRepository->getVendorSubscriptions($vendorId, $filters);
    }

    public function getActiveVendorSubscription(int $vendorId): ?VendorSubscription
    {
        return $this->subscriptionRepository->getActiveVendorSubscription($vendorId);
    }

    public function createSubscription(array $data): VendorSubscription
    {
        // 1. Fetch the Plan details
        $plan = SubscriptionPlan::findOrFail($data['subscription_plan_id']);

        // 2. Set the Amount from Plan if not provided by Admin
        if (! isset($data['amount']) || $data['amount'] === null) {
            $data['amount'] = ($data['billing_cycle'] === 'yearly')
                ? $plan->price_year
                : $plan->price_month;
        }

        // 3. Handle Dates
        $subscriptionDate = isset($data['subscription_date'])
            ? Carbon::parse($data['subscription_date'])
            : now();

        $billingCycle = $data['billing_cycle'] ?? 'monthly';

        // 4. Calculate Expiry based on Plan cycle
        $expiryDate = $billingCycle === 'yearly'
            ? $subscriptionDate->copy()->addYear()
            : $subscriptionDate->copy()->addMonth();

        $data['subscription_date'] = $subscriptionDate->toDateString();
        $data['expiry_date'] = $expiryDate->toDateString();
        $data['status'] = $data['status'] ?? VendorSubscription::STATUS_ACTIVE;

        // 5. Send to Repository to Save
        return $this->subscriptionRepository->createSubscription($data);
    }

    public function updateSubscription(int $id, array $data): ?VendorSubscription
    {
        $subscription = $this->subscriptionRepository->getSubscriptionById($id);

        if (! $subscription) {
            return null;
        }

        $this->subscriptionRepository->updateSubscription($subscription, $data);

        return $subscription->fresh();
    }

    public function cancelSubscription(int $id): bool
    {
        $subscription = $this->subscriptionRepository->getSubscriptionById($id);

        if (! $subscription) {
            return false;
        }

        return $this->subscriptionRepository->cancelSubscription($subscription);
    }

    public function expireSubscriptions(): int
    {
        return $this->subscriptionRepository->expireSubscriptions();
    }
}
