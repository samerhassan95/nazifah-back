<?php

namespace Modules\Piece\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Resources\Json\JsonResource;

class PieceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');
        $locale = app()->getLocale();

        $data = [
            'id' => $this->id,
            'name' => $isDetailView ? ['ar' => $this->getTranslation('name', 'ar'), 'en' => $this->getTranslation('name', 'en')] : $this->getTranslation('name', $locale),
            'icon_id' => $this->icon_id,
            'icon' => $this->iconRelation?->full_path,
            'image' => $this->image ? UploadFilesService::getFullUrl($this->image) : null,
            'is_active' => (bool) $this->is_active,
            'vendor_id' => $this->vendor_id,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];

        // Include services if they are loaded
        if ($this->relationLoaded('services')) {
            $data['services'] = $this->services->map(function ($service) use ($locale, $isDetailView) {
                return [
                    'service_id' => $service->id,
                    'service_name' => $isDetailView
                        ? ['ar' => $service->getTranslation('service_name', 'ar'), 'en' => $service->getTranslation('service_name', 'en')]
                        : $service->getTranslation('service_name', $locale),
                    'price' => (float) ($service->pivot->price ?? 0),
                ];
            });
        }

        return $data;
    }
}
