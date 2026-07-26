<?php

namespace Modules\Discount\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DiscountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'code' => $this->code,
            'type' => $this->type,
            'discount_type' => $this->discount_type,
            'value' => (float) $this->value,
            'min_order_amount' => (float) $this->min_order_amount,
            'max_discount_amount' => (float) $this->max_discount_amount,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'usage_limit' => $this->usage_limit,
            'used_count' => $this->used_count,
            'is_active' => (bool) $this->is_active,
            'vendors' => $this->whenLoaded('vendors'),
            'zones' => $this->whenLoaded('zones'),
            'clients' => $this->whenLoaded('clients'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
