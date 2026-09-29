<?php

namespace Modules\Admin\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Http\Resources\Concerns\DetectsDetailView;

class CategoryResource extends JsonResource
{
    use DetectsDetailView;

    public function toArray(Request $request): array
    {
        $uploadFilesService = app(UploadFilesService::class);
        $isDetailView = $this->isDetailView($request);
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'name' => $isDetailView ? ['ar' => $this->getTranslation('name', 'ar'), 'en' => $this->getTranslation('name', 'en')] : $this->getTranslation('name', $locale),
            'description' => $isDetailView ? ['ar' => $this->getTranslation('description', 'ar'), 'en' => $this->getTranslation('description', 'en')] : $this->getTranslation('description', $locale),
            'icon' => $this->whenLoaded('iconRelation', fn () => $this->iconRelation?->full_path),
            'image' => $uploadFilesService->getFullUrl($this->image),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
