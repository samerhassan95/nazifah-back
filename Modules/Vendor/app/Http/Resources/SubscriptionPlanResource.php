<?php

namespace Modules\Vendor\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');
        $locale = app()->getLocale();

        // Determine price and currency based on billing cycle from request
        $billingCycle = $request->input('billing_cycle', 'monthly');
        $price = $billingCycle === 'yearly' ? $this->price_year : $this->price_month;
        $currency = $price == 0 ? 'مجانا' : 'ر.س';

        // Get plan name and tagline
        $planName = $isDetailView
            ? ['ar' => $this->getTranslation('name', 'ar'), 'en' => $this->getTranslation('name', 'en')]
            : $this->getTranslation('name', $locale);

        $planTagline = $this->tagline
            ? ($isDetailView
                ? ['ar' => $this->getTranslation('tagline', 'ar'), 'en' => $this->getTranslation('tagline', 'en')]
                : $this->getTranslation('tagline', $locale))
            : null;

        return [
            'id' => $this->id,
            'name' => $planName,
            'tagline' => $planTagline,
            'price' => (float) $price,
            'currency' => $currency,
            'is_featured' => $this->is_featured,
            'has_discount' => $this->has_discount,
            'discount_percentage' => $this->discount_percentage,
            'billing_cycle' => $billingCycle,
            'branch_count' => $this->branch_count,
            'order_count' => $this->order_count,
            'has_discount_codes' => $this->has_discount_codes,
            'has_special_delivery' => $this->has_special_delivery,
            'has_reports' => $this->has_reports,
        ];
    }
}
