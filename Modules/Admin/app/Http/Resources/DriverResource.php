<?php

namespace Modules\Admin\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Http\Resources\Concerns\DetectsDetailView;

class DriverResource extends JsonResource
{
    use DetectsDetailView;

    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $isDetailView = $this->isDetailView($request);
        $uploadFilesService = app(UploadFilesService::class);

        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'full_name' => $isDetailView ? ['ar' => $this->getTranslation('full_name', 'ar'), 'en' => $this->getTranslation('full_name', 'en')] : $this->full_name,
            'email' => $this->email,
            'image' => $uploadFilesService->getFullUrl($this->image),
            'id_number' => $this->id_number,
            'image_document' => $uploadFilesService->getFullUrl($this->image_document),
            'is_active' => (bool) $this->is_active,
            'is_available' => (bool) $this->is_available,
            'is_banned' => (bool) ($this->is_banned ?? false),
            'ban_reason' => $this->ban_reason,
            'banned_at' => $this->banned_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
