<?php

namespace Modules\Subscription\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Subscription\Cache\SubscriptionCacheKey;
use Modules\Subscription\Interfaces\SubscriptionRepositoryInterface;
use Modules\Subscription\Models\SubscriptionPlan;
use Modules\Subscription\Models\VendorSubscription;

class SubscriptionRepository implements SubscriptionRepositoryInterface
{
    // Subscription Plan methods
    public function getAllPlans(array $filters = []): LengthAwarePaginator
    {
        $key = SubscriptionCacheKey::filteredCollection($filters, 'plans');

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_MEDIUM,
            callback: function () use ($filters) {
                $query = SubscriptionPlan::query();

                if (isset($filters['is_active'])) {
                    $query->where('is_active', $filters['is_active']);
                }

                if (isset($filters['is_featured'])) {
                    $query->where('is_featured', $filters['is_featured']);
                }

                if (isset($filters['search'])) {
                    $search = $filters['search'];
                    $query->where(function ($q) use ($search) {
                        $q->whereRaw("JSON_EXTRACT(name, '$$.ar') LIKE ?", ["%{$search}%"])
                            ->orWhereRaw("JSON_EXTRACT(name, '$$.en') LIKE ?", ["%{$search}%"]);
                    });
                }

                $sortBy = $filters['sort_by'] ?? 'price_month';
                $sortOrder = $filters['sort_order'] ?? 'asc';
                $query->orderBy($sortBy, $sortOrder);

                return $query->paginate($filters['per_page'] ?? 15);
            },
            tags: ['subscription_plans']
        );
    }

    public function getPlanById(int $id): ?SubscriptionPlan
    {
        return CacheManager::remember(
            key: "subscription_plan:{$id}",
            ttl: CacheManager::TTL_MEDIUM,
            callback: fn () => SubscriptionPlan::find($id),
            tags: ['subscription_plans']
        );
    }

    public function createPlan(array $data): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create($data);
        CacheManager::forgetByTags(['subscription_plans']);

        return $plan;
    }

    public function updatePlan(SubscriptionPlan $plan, array $data): bool
    {
        $result = $plan->update($data);
        CacheManager::forgetByTags(['subscription_plans']);

        return $result;
    }

    public function deletePlan(SubscriptionPlan $plan): bool
    {
        $result = $plan->delete();
        CacheManager::forgetByTags(['subscription_plans']);

        return $result;
    }

    public function getActivePlans(): Collection
    {
        return CacheManager::remember(
            key: 'subscription_plans:active',
            ttl: CacheManager::TTL_MEDIUM,
            callback: fn () => SubscriptionPlan::where('is_active', true)
                ->orderBy('price_month', 'asc')
                ->get(),
            tags: ['subscription_plans']
        );
    }

    // Vendor Subscription methods
    public function getAllSubscriptions(array $filters = []): LengthAwarePaginator
    {
        $key = SubscriptionCacheKey::filteredCollection($filters, 'vendor_subscriptions');

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_SHORT,
            callback: function () use ($filters) {
                $query = VendorSubscription::with(['vendor', 'subscriptionPlan']);

                if (isset($filters['status'])) {
                    $query->where('status', $filters['status']);
                }

                if (isset($filters['vendor_id'])) {
                    $query->where('vendor_id', $filters['vendor_id']);
                }

                if (isset($filters['plan_id'])) {
                    $query->where('subscription_plan_id', $filters['plan_id']);
                }

                if (isset($filters['billing_cycle'])) {
                    $query->where('billing_cycle', $filters['billing_cycle']);
                }

                $sortBy = $filters['sort_by'] ?? 'created_at';
                $sortOrder = $filters['sort_order'] ?? 'desc';
                $query->orderBy($sortBy, $sortOrder);

                return $query->paginate($filters['per_page'] ?? 15);
            },
            tags: ['vendor_subscriptions']
        );
    }

    public function getSubscriptionById(int $id): ?VendorSubscription
    {
        return CacheManager::remember(
            key: SubscriptionCacheKey::withRelations($id),
            ttl: CacheManager::TTL_SHORT,
            callback: fn () => VendorSubscription::with(['vendor', 'subscriptionPlan'])->find($id),
            tags: ['vendor_subscriptions']
        );
    }

    public function getVendorSubscriptions(int $vendorId, array $filters = []): LengthAwarePaginator
    {
        $key = SubscriptionCacheKey::filteredCollection(array_merge($filters, ['vendor_id' => $vendorId]), 'vendor_specific');

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_SHORT,
            callback: function () use ($vendorId, $filters) {
                $query = VendorSubscription::with('subscriptionPlan')
                    ->where('vendor_id', $vendorId);

                if (isset($filters['status'])) {
                    $query->where('status', $filters['status']);
                }

                $sortBy = $filters['sort_by'] ?? 'created_at';
                $sortOrder = $filters['sort_order'] ?? 'desc';
                $query->orderBy($sortBy, $sortOrder);

                return $query->paginate($filters['per_page'] ?? 15);
            },
            tags: ['vendor_subscriptions', "vendor:{$vendorId}:subscriptions"]
        );
    }

    public function getActiveVendorSubscription(int $vendorId): ?VendorSubscription
    {
        return CacheManager::remember(
            key: SubscriptionCacheKey::vendorActive($vendorId),
            ttl: CacheManager::TTL_SHORT,
            callback: fn () => VendorSubscription::with('subscriptionPlan')
                ->where('vendor_id', $vendorId)
                ->where('status', VendorSubscription::STATUS_ACTIVE)
                ->where('expiry_date', '>=', now()->toDateString())
                ->latest()
                ->first(),
            tags: ['vendor_subscriptions', "vendor:{$vendorId}:subscriptions"]
        );
    }

    public function createSubscription(array $data): VendorSubscription
    {
        $subscription = VendorSubscription::create($data);
        $this->clearSubscriptionCache($subscription->vendor_id);

        return $subscription;
    }

    public function updateSubscription(VendorSubscription $subscription, array $data): bool
    {
        $result = $subscription->update($data);
        $this->clearSubscriptionCache($subscription->vendor_id);

        return $result;
    }

    public function cancelSubscription(VendorSubscription $subscription): bool
    {
        $result = $subscription->update([
            'status' => VendorSubscription::STATUS_CANCELLED,
        ]);
        $this->clearSubscriptionCache($subscription->vendor_id);

        return $result;
    }

    public function expireSubscriptions(): int
    {
        $count = VendorSubscription::where('status', VendorSubscription::STATUS_ACTIVE)
            ->where('expiry_date', '<', now()->toDateString())
            ->update(['status' => VendorSubscription::STATUS_EXPIRED]);

        if ($count > 0) {
            CacheManager::forgetByTags(['vendor_subscriptions']);
        }

        return $count;
    }

    private function clearSubscriptionCache(int $vendorId): void
    {
        CacheManager::forgetKeys([
            SubscriptionCacheKey::collection(),
            SubscriptionCacheKey::vendorActive($vendorId),
        ]);
        CacheManager::forgetByTags(['vendor_subscriptions', "vendor:{$vendorId}:subscriptions"]);
    }
}
