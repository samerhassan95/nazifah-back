<?php

namespace Modules\Notification\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');
        $user = $request->user();
        $lang = $user && method_exists($user, 'getNotificationLang')
            ? $user->getNotificationLang($request->input('device_id'))
            : \App\Support\NotificationLocale::normalize(app()->getLocale());

        $data = is_array($this->data) ? $this->data : [];

        return array_merge([
            'id' => $this->id,
            'title' => $isDetailView
                ? ['ar' => $this->getTranslation('title', 'ar'), 'en' => $this->getTranslation('title', 'en')]
                : \App\Support\NotificationLocale::fromTranslations($this->getTranslations('title'), $lang),
            'body' => $isDetailView
                ? ['ar' => $this->getTranslation('message', 'ar'), 'en' => $this->getTranslation('message', 'en')]
                : \App\Support\NotificationLocale::fromTranslations($this->getTranslations('message'), $lang),
            'type' => $this->type,
            'user_type' => $this->user_type,
            'user_id' => $this->user_id,
            'image' => $this->image ? (str_starts_with($this->image, 'http') ? $this->image : config('app.url').'/storage/'.ltrim($this->image, '/')) : null,
            'is_read' => (bool) $this->is_read,
            'read_at' => $this->read_at?->toISOString(),
            'data' => ! empty($data) ? [
                'visit_type' => $data['visit_type'] ?? null,
                'visit_type_label' => $data['visit_type_label'] ?? null,
                'order_status_label' => $data['order_status_label'] ?? null,
                'subtype' => $data['subtype'] ?? null,
            ] : null,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ], $this->orderMetaForApi());
    }
}
