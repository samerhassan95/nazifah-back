<?php

namespace Modules\Driver\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');

        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'full_name' => $isDetailView ? ['ar' => $this->getTranslation('full_name', 'ar'), 'en' => $this->getTranslation('full_name', 'en')] : $this->full_name,
            'email' => $this->email,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
