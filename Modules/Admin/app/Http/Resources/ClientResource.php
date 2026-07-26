<?php

namespace Modules\Admin\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Address\Models\Address;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $uploadFilesService = app(UploadFilesService::class);

        // Get national address from default address
        $defaultAddress = $this->addresses()->where('is_default', true)->first();
        $nationalAddress = $defaultAddress ? $defaultAddress->national_address : null;

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $uploadFilesService->getFullUrl($this->image),
            'default_address' => $defaultAddress ? [
                'id' => $defaultAddress->id,
                'title' => $defaultAddress->title,
                'national_address' => $defaultAddress->national_address,
                'street_name' => $defaultAddress->street_name,
                'building_number' => $defaultAddress->building_number,
                'street_number' => $defaultAddress->street_number,
                'latitude' => $defaultAddress->latitude,
                'longitude' => $defaultAddress->longitude,
            ] : null,
            'is_verified' => $this->is_verified,
            'is_active' => $this->is_active ?? true,
            'is_banned' => $this->is_banned ?? false,
            'ban_reason' => $this->ban_reason,
            'banned_at' => $this->banned_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
