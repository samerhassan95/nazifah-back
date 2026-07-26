<?php

namespace Modules\Vendor\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorEmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $uploadFilesService = app(UploadFilesService::class);

        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $uploadFilesService->getFullUrl($this->image),
            'role' => $this->role,
            'is_verified' => (bool) $this->is_verified,
            'is_active' => (bool) $this->is_active,
            'is_banned' => (bool) $this->is_banned,
            'ban_reason' => $this->ban_reason,
            'banned_at' => $this->banned_at?->format('Y-m-d H:i:s'),
            'vendor' => $this->whenLoaded('vendor', function () {
                $uploadFilesService = app(UploadFilesService::class);

                return [
                    'id' => $this->vendor->id,
                    'name' => $this->vendor->getTranslation('name', app()->getLocale()),
                    'logo' => $uploadFilesService->getFullUrl($this->vendor->logo),
                    'is_active' => (bool) $this->vendor->is_active,
                ];
            }),
            'branch' => $this->whenLoaded('branch', function () {
                return [
                    'id' => $this->branch->id,
                    'name' => $this->branch->name,
                    'is_active' => (bool) $this->branch->is_active,
                ];
            }),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
