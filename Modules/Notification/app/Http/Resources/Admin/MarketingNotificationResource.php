<?php

namespace Modules\Notification\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $readRate = 0;
        if ($this->sent_count > 0) {
            $readRate = round(($this->read_count / $this->sent_count) * 100, 2);
        }

        return [
            'id' => $this->id,
            'title' => $this->notification_title,
            'body' => $this->description,
            'target_type' => $this->user_target_type ? $this->user_target_type->value : null,
            'target_ids' => $this->target_user_ids,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at ? $this->scheduled_at->format('Y-m-d H:i:s') : null,
            'sent_at' => $this->sent_at ? $this->sent_at->format('Y-m-d H:i:s') : null,
            'deep_link' => $this->deep_link,
            'image_url' => $this->image_url,
            'segment_filters' => $this->segment_filters,
            'total_recipients' => $this->total_recipients,
            'sent_count' => $this->sent_count,
            'read_count' => $this->read_count,
            'failed_count' => $this->failed_count,
            'read_rate' => $readRate.'%',
            'created_at' => $this->created_at,
        ];
    }
}
