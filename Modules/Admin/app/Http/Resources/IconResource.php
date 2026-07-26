<?php

namespace Modules\Admin\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Resources\Json\JsonResource;

class IconResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $uploadService = app(UploadFilesService::class);

        return [
            'id' => $this->id,
            'icon' => $uploadService->getFullUrl($this->path),
            'type' => $this->type?->value,
        ];
    }
}
