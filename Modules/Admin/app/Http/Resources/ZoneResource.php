<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ZoneResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $isDetailView = $request->route() && (str_contains($request->route()->getName() ?? '', 'show') || $request->route()->getActionMethod() === 'show');
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'name' => $isDetailView ? ['ar' => $this->getTranslation('name', 'ar'), 'en' => $this->getTranslation('name', 'en')] : $this->getTranslation('name', $locale),
            'description' => $isDetailView ? ['ar' => $this->getTranslation('description', 'ar'), 'en' => $this->getTranslation('description', 'en')] : $this->getTranslation('description', $locale),
            'points' => $this->normalizePoints($this->points),
            'is_active' => (bool) $this->is_active,
            'zone_color' => $this->zone_color,
            'statistics' => [
                'branches_count' => (int) ($this->branches_count ?? 0),
                'addresses_count' => (int) ($this->addresses_count ?? 0),
                'clients_count' => (int) ($this->clients_count ?? 0),
                'orders_count' => (int) ($this->orders_count ?? 0),
            ],
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function normalizePoints(mixed $points): array
    {
        if (is_string($points)) {
            $points = json_decode($points, true);
        } elseif (is_object($points)) {
            $points = (array) $points;
        }

        if (! is_array($points)) {
            return [];
        }

        $normalized = [];

        foreach ($points as $point) {
            if (! is_array($point) && ! is_object($point)) {
                continue;
            }

            $pointArr = (array) $point;
            $latitude = $pointArr['latitude'] ?? $pointArr['lat'] ?? $pointArr[1] ?? null;
            $longitude = $pointArr['longitude'] ?? $pointArr['lng'] ?? $pointArr['long'] ?? $pointArr[0] ?? null;

            if ($latitude === null || $longitude === null) {
                continue;
            }

            $normalized[] = [
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        return $normalized;
    }
}
