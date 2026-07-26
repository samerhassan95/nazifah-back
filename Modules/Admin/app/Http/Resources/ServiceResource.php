<?php

namespace Modules\Admin\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $uploadFilesService = app(UploadFilesService::class);
        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $isDetailView ? ['ar' => $this->getTranslation('service_name', 'ar'), 'en' => $this->getTranslation('service_name', 'en')] : $this->getTranslation('service_name', $locale),
            'description' => $isDetailView ? ['ar' => $this->getTranslation('description', 'ar'), 'en' => $this->getTranslation('description', 'en')] : $this->getTranslation('description', $locale),
            'image' => $uploadFilesService->getFullUrl($this->image),
            'icon_id' => $this->icon_id,
            'icon' => $this->icon,
            'discount_price' => $this->discount_price,
            'preparation_time' => $this->preparation_time,
            'is_active' => $this->is_active,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
