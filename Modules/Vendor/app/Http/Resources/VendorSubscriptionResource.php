<?php

namespace Modules\Vendor\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $planName = is_string($this->subscriptionPlan->name ?? null)
            ? json_decode($this->subscriptionPlan->name, true)
            : ($this->subscriptionPlan->name ?? null);
        $planName = is_array($planName)
            ? ($planName['ar'] ?? $planName['en'] ?? 'Unknown')
            : ($this->subscriptionPlan->name ?? 'Unknown');

        return [
            'id' => $this->id,
            'subscription_plan_id' => $this->subscription_plan_id,
            'package_type' => $planName,
            'billing_cycle' => $this->billing_cycle,
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method,
            'subscription_date' => $this->subscription_date->format('Y-m-d'),
            'expiry_date' => $this->expiry_date->format('Y-m-d'),
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'is_expired' => $this->isExpired(),
            'days_remaining' => max(0, now()->diffInDays($this->expiry_date, false)),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
