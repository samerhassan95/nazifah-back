<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');

        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'name' => $isDetailView ? ['ar' => $this->getTranslation('name', 'ar'), 'en' => $this->getTranslation('name', 'en')] : $this->name,
            'phone_number' => $this->phone_number,
            'land_phone' => $this->land_phone,
            'location' => $isDetailView ? ['ar' => $this->getTranslation('location', 'ar'), 'en' => $this->getTranslation('location', 'en')] : $this->location,
            'store_front' => $this->store_front,
            'description' => $isDetailView ? ['ar' => $this->getTranslation('description', 'ar'), 'en' => $this->getTranslation('description', 'en')] : $this->description,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'delivery_and_pickup' => $this->delivery_and_pickup,
            'is_active' => (bool) $this->is_active,
            'vendor' => new VendorResource($this->whenLoaded('vendor')),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
