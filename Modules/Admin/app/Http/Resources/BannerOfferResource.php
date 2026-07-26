<?php

namespace Modules\Admin\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $uploadFilesService = app(UploadFilesService::class);
        $locale = app()->getLocale();
        $isDetailView = $request->routeIs('*.show') || str_contains($request->path(), '/banner-offers/'.$this->id);

        $targetUsers = $this->resolveTargetUsers($locale);
        $destinationName = $this->resolveDestinationName($locale);

        return [
            'id' => $this->id,
            'title' => $isDetailView
                ? ['ar' => $this->getTranslation('title', 'ar'), 'en' => $this->getTranslation('title', 'en')]
                : ($this->getTranslation('title', $locale) ?: $this->title),
            'description' => $isDetailView
                ? ['ar' => $this->getTranslation('description', 'ar'), 'en' => $this->getTranslation('description', 'en')]
                : ($this->getTranslation('description', $locale) ?: $this->description),
            'image' => $uploadFilesService->getFullUrl($this->getRawOriginal('image') ?: $this->image),
            'link' => $this->link,
            'destination_type' => $this->destination_type?->value ?? $this->destination_type,
            'destination_id' => $this->destination_id,
            'destination' => $destinationName ? [
                'id' => $this->destination_id,
                'type' => $this->destination_type?->value ?? $this->destination_type,
                'name' => $destinationName,
            ] : null,
            'user_target_type' => $this->user_target_type ?? 'all',
            'target_user_ids' => $this->target_user_ids ?? [],
            'users' => $targetUsers,
            'user_labels' => array_column($targetUsers, 'label'),
            'send_date' => $this->created_at?->format('Y-m-d'),
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'is_active' => (bool) $this->is_active,
            'order' => $this->order,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
