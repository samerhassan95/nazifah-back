<?php

namespace Modules\Category\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $uploadFilesService = app(UploadFilesService::class);
        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'name' => $isDetailView ? ['ar' => $this->getTranslation('name', 'ar'), 'en' => $this->getTranslation('name', 'en')] : $this->getTranslation('name', $locale),
            'description' => $isDetailView ? ['ar' => $this->getTranslation('description', 'ar'), 'en' => $this->getTranslation('description', 'en')] : $this->getTranslation('description', $locale),
            'icon_id' => $this->icon_id,
            'icon' => $this->iconRelation ? $this->iconRelation->full_path : null,
            'image' => $uploadFilesService->getFullUrl($this->image),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
