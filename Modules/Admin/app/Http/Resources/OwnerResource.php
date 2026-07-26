<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OwnerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');

        return [
            'id' => $this->id,
            'name' => $isDetailView ? ['ar' => $this->getTranslation('name', 'ar'), 'en' => $this->getTranslation('name', 'en')] : $this->name,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
            'id_image' => $this->id_image,
            'is_verified' => (bool) $this->is_verified,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
