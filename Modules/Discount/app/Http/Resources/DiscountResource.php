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
            'promotion_domain' => $this->promotion_domain ?? 'order',
            'promotion_kind' => $this->promotion_kind,
            'is_automatic' => (bool) ($this->is_automatic ?? false),
            'priority' => (int) ($this->priority ?? 100),
            'funding_source' => $this->funding_source ?? 'platform',
            'usage_condition' => $this->usage_condition ?? 'all',
            'usage_service_ids' => $this->usage_service_ids ?? [],
            'application_scope' => $this->application_scope ?? 'order_total',
            'discount_service_ids' => $this->discount_service_ids ?? [],
            'value' => (float) $this->value,
            'min_order_amount' => (float) $this->min_order_amount,
            'min_items_count' => $this->min_items_count,
            'min_repeat_orders' => $this->min_repeat_orders,
            'first_order_only' => (bool) ($this->first_order_only ?? false),
            'applies_to_delivery' => (bool) ($this->applies_to_delivery ?? false),
            'delivery_discount_type' => $this->delivery_discount_type,
            'max_discount_amount' => (float) $this->max_discount_amount,
            'min_wallet_topup_amount' => $this->min_wallet_topup_amount !== null ? (float) $this->min_wallet_topup_amount : null,
            'wallet_bonus_amount' => $this->wallet_bonus_amount !== null ? (float) $this->wallet_bonus_amount : null,
            'wallet_bonus_percent' => $this->wallet_bonus_percent !== null ? (float) $this->wallet_bonus_percent : null,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'active_days_of_week' => $this->active_days_of_week ?? [],
            'active_time_from' => $this->active_time_from?->format('H:i'),
            'active_time_to' => $this->active_time_to?->format('H:i'),
            'usage_limit' => $this->usage_limit,
            'used_count' => $this->used_count,
            'is_active' => (bool) $this->is_active,
            'branch_ids' => $this->branch_ids ?? [],
            'city_names' => $this->city_names ?? [],
            'segment_filters' => $this->segment_filters ?? [],
            'required_piece_ids' => $this->required_piece_ids ?? [],
            'bundle_rules' => $this->bundle_rules ?? [],
            'metadata' => $this->metadata ?? [],
            'vendors' => $this->whenLoaded('vendors'),
            'zones' => $this->whenLoaded('zones'),
            'clients' => $this->whenLoaded('clients'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            // Legacy dashboard aliases kept for backward compatibility.
            'discount_title' => data_get($this->name, app()->getLocale()) ?? data_get($this->name, 'ar') ?? data_get($this->name, 'en'),
            'discount_code' => $this->code,
            'discount_amount' => (float) $this->value,
            'target_category' => $this->discount_type,
            'status_toggle' => (bool) $this->is_active,
            'discount_expiration' => $this->end_date && $this->end_date->isPast() ? 'expired' : ((bool) $this->is_active ? 'active' : 'inactive'),
        ];
    }
}
