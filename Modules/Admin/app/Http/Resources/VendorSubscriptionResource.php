<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vendorName = is_string($this->vendor->name ?? null)
            ? json_decode($this->vendor->name, true)
            : ($this->vendor->name ?? null);
        $vendorName = is_array($vendorName)
            ? ($vendorName['ar'] ?? $vendorName['en'] ?? 'Unknown')
            : ($this->vendor->name ?? 'Unknown');

        $planName = is_string($this->subscriptionPlan->name ?? null)
            ? json_decode($this->subscriptionPlan->name, true)
            : ($this->subscriptionPlan->name ?? null);
        $planName = is_array($planName)
            ? ($planName['ar'] ?? $planName['en'] ?? 'Unknown')
            : ($this->subscriptionPlan->name ?? 'Unknown');

        // Determine account status
        $accountStatus = $this->status;
        if ($this->status === 'active' && $this->expiry_date < now()->toDateString()) {
            $accountStatus = 'expired';
        }

        $branchCount = \Modules\Branch\Models\Branch::where('vendor_id', $this->vendor_id)->count();

        $ordersSinceSubscription = \Modules\Order\Models\Order::whereIn(
            'branch_id',
            \Modules\Branch\Models\Branch::where('vendor_id', $this->vendor_id)->pluck('id')
        )
            ->whereDate('created_at', '>=', $this->subscription_date->toDateString())
            ->count();

        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $vendorName,
            'subscription_plan_id' => $this->subscription_plan_id,
            'package_type' => $planName,
            'billing_cycle' => $this->billing_cycle,
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method,
            'subscription_date' => $this->subscription_date->format('Y-m-d'),
            'expiry_date' => $this->expiry_date->format('Y-m-d'),
            'days_remaining' => max(0, now()->diffInDays($this->expiry_date, false)),
            'status' => $this->status,
            'account_status' => $accountStatus,
            'is_active' => $this->isActive(),
            'is_expired' => $this->isExpired(),
            'branch_count' => $branchCount,
            'branch_limit' => $this->subscriptionPlan->branch_count,
            'daily_order_limit' => $this->subscriptionPlan->order_count,
            'orders_since_subscription' => $ordersSinceSubscription,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
