<?php

namespace Modules\Branch\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'zone_id' => $this->zone_id,
            'name' => $this->name,
            'phone_number' => $this->phone_number,
            'land_phone' => $this->land_phone,
            'location' => $this->location,
            'national_address' => $this->national_address,
            'store_front' => $this->store_front,
            'logo' => $this->logo,
            'description' => $this->description,
            'working_hours' => $this->getApiWorkingHours(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'delivery_and_pickup' => (bool) $this->delivery_and_pickup,
            'is_active' => (bool) $this->is_active,
            'rating' => (float) $this->rating,
            'rate_count' => (int) $this->rate_count,
            'zone' => $this->whenLoaded('zone', function () {
                return [
                    'id' => $this->zone->id,
                    'name' => $this->zone->name,
                    'zone_color' => $this->zone->zone_color,
                ];
            }),
            'vendor' => $this->whenLoaded('vendor', function () {
                return [
                    'id' => $this->vendor->id,
                    'name' => $this->vendor->name,
                    'logo' => $this->vendor->logo,
                ];
            }),
            'services' => $this->whenLoaded('services', function () {
                return $this->services->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'name' => $service->service_name,
                        'description' => $service->description,
                        'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                        'price' => $this->getServicePrice($service->id),
                        'branch_price' => $service->pivot->price,
                        'branch_name' => $service->pivot->name ? json_decode($service->pivot->name, true) : null,
                        'branch_description' => $service->pivot->description ? json_decode($service->pivot->description, true) : null,
                        'is_active' => (bool) $service->pivot->is_active,
                    ];
                });
            }),
            'pieces' => $this->whenLoaded('pieces', function () {
                return $this->pieces->map(function ($piece) {
                    return [
                        'id' => $piece->id,
                        'name' => $piece->name,
                        'description' => $piece->description,
                        'icon' => $this->uploadFilesService->getFullUrl($piece->iconRelation?->full_path ?? $piece->iconRelation?->path),
                        'is_active' => (bool) $piece->pivot->is_active,
                    ];
                });
            }),
            'drivers' => $this->whenLoaded('drivers', function () {
                return $this->drivers->map(function ($driver) {
                    return [
                        'id' => $driver->id,
                        'name' => $driver->full_name,
                        'phone' => $driver->phone,
                        'is_available' => (bool) $driver->is_available,
                    ];
                });
            }),
            'services_count' => $this->whenCounted('services'),
            'pieces_count' => $this->whenCounted('pieces'),
            'drivers_count' => $this->whenCounted('drivers'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
