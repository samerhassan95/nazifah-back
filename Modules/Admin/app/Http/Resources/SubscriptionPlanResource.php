<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');
        $locale = app()->getLocale();

        // Get plan name and tagline
        $planName = $isDetailView
            ? $this->getTranslations('name')
            : $this->getTranslation('name', $locale);

        $planTagline = $this->tagline
            ? ($isDetailView
                ? $this->getTranslations('tagline')
                : $this->getTranslation('tagline', $locale))
            : null;

        return [
            'id' => $this->id,
            'name' => $planName,
            'tagline' => $planTagline,
            'price_month' => (float) $this->price_month,
            'price_year' => (float) $this->price_year,
            'currency' => $this->currency,
            'is_featured' => $this->is_featured,
            'has_discount' => $this->has_discount,
            'discount_percentage' => $this->discount_percentage,
            'branch_count' => $this->branch_count,
            'order_count' => $this->order_count,
            'has_discount_codes' => $this->has_discount_codes,
            'has_special_delivery' => $this->has_special_delivery,
            'has_reports' => $this->has_reports,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
