<?php

namespace Modules\Service\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Category\Http\Resources\CategoryResource;

class ServiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'service_name' => $isDetailView ? ['ar' => $this->getTranslation('service_name', 'ar'), 'en' => $this->getTranslation('service_name', 'en')] : $this->getTranslation('service_name', $locale),
            'price' => (float) $this->price,
            'icon_id' => $this->icon_id,
            'icon' => $this->icon,
            'branch_price' => $this->branch_price,
            'is_active' => (bool) $this->is_active,
            'vendor_id' => $this->vendor_id,
            'category' => $this->whenLoaded('category', fn () => new CategoryResource($this->category)),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
