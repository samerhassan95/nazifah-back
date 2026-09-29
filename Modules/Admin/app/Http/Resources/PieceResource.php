<?php

namespace Modules\Admin\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Http\Resources\Concerns\DetectsDetailView;

class PieceResource extends JsonResource
{
    use DetectsDetailView;

    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $uploadFilesService = app(UploadFilesService::class);
        $isDetailView = $this->isDetailView($request);
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'name' => $isDetailView ? ['ar' => $this->getTranslation('name', 'ar'), 'en' => $this->getTranslation('name', 'en')] : $this->getTranslation('name', $locale),
            'description' => $isDetailView ? ['ar' => $this->getTranslation('description', 'ar'), 'en' => $this->getTranslation('description', 'en')] : $this->getTranslation('description', $locale),
            'icon_id' => $this->icon_id,
            'icon' => $this->iconRelation?->full_path,
            'price' => (float) $this->price,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
