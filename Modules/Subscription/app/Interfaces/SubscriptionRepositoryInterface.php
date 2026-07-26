<?php

namespace Modules\Subscription\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Subscription\Models\SubscriptionPlan;
use Modules\Subscription\Models\VendorSubscription;

interface SubscriptionRepositoryInterface
{
    // Subscription Plan methods
    public function getAllPlans(array $filters = []): LengthAwarePaginator;

    public function getPlanById(int $id): ?SubscriptionPlan;

    public function createPlan(array $data): SubscriptionPlan;

    public function updatePlan(SubscriptionPlan $plan, array $data): bool;

    public function deletePlan(SubscriptionPlan $plan): bool;

    public function getActivePlans(): Collection;

    // Vendor Subscription methods
    public function getAllSubscriptions(array $filters = []): LengthAwarePaginator;

    public function getSubscriptionById(int $id): ?VendorSubscription;

    public function getVendorSubscriptions(int $vendorId, array $filters = []): LengthAwarePaginator;

    public function getActiveVendorSubscription(int $vendorId): ?VendorSubscription;

    public function createSubscription(array $data): VendorSubscription;

    public function updateSubscription(VendorSubscription $subscription, array $data): bool;

    public function cancelSubscription(VendorSubscription $subscription): bool;

    public function expireSubscriptions(): int;
}
