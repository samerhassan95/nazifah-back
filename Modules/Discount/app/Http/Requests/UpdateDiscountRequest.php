<?php

namespace Modules\Discount\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDiscountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        $id = $this->route('id') ?? $this->route('discount');

        return [
            'code' => ['sometimes', 'string', 'max:50', 'unique:discounts,code,'.$id],
            'name' => ['sometimes', 'array'],
            'name.ar' => ['sometimes', 'string', 'max:255'],
            'name.en' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:500'],
            'description.en' => ['nullable', 'string', 'max:500'],
            'type' => ['sometimes', 'string', 'in:percentage,fixed'],
            'discount_type' => ['sometimes', 'string', 'in:delivery_free,vendors,zone,client,global'],
            'promotion_domain' => ['nullable', 'string', 'in:order,wallet_topup'],
            'promotion_kind' => ['nullable', 'string', 'max:80'],
            'is_automatic' => ['sometimes', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'funding_source' => ['nullable', 'string', 'in:platform,vendor,mixed'],
            'usage_condition' => ['sometimes', 'string', 'in:all,services'],
            'usage_service_ids' => ['nullable', 'array'],
            'usage_service_ids.*' => ['integer', 'exists:services,id'],
            'application_scope' => ['sometimes', 'string', 'in:order_total,services'],
            'discount_service_ids' => ['nullable', 'array'],
            'discount_service_ids.*' => ['integer', 'exists:services,id'],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'min_items_count' => ['nullable', 'integer', 'min:1'],
            'min_repeat_orders' => ['nullable', 'integer', 'min:1'],
            'first_order_only' => ['sometimes', 'boolean'],
            'applies_to_delivery' => ['sometimes', 'boolean'],
            'delivery_discount_type' => ['nullable', 'string', 'in:free,percentage,fixed'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'min_wallet_topup_amount' => ['nullable', 'numeric', 'min:0'],
            'wallet_bonus_amount' => ['nullable', 'numeric', 'min:0'],
            'wallet_bonus_percent' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after:start_date'],
            'active_days_of_week' => ['nullable', 'array'],
            'active_days_of_week.*' => ['integer', 'between:1,7'],
            'active_time_from' => ['nullable', 'date_format:H:i'],
            'active_time_to' => ['nullable', 'date_format:H:i'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'vendor_ids' => ['nullable', 'array'],
            'vendor_ids.*' => ['exists:vendors,id'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['exists:zones,id'],
            'client_ids' => ['nullable', 'array'],
            'client_ids.*' => ['exists:clients,id'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'city_names' => ['nullable', 'array'],
            'city_names.*' => ['string', 'max:120'],
            'segment_filters' => ['nullable', 'array'],
            'segment_filters.min_orders' => ['nullable', 'integer', 'min:0'],
            'segment_filters.max_orders' => ['nullable', 'integer', 'min:0'],
            'segment_filters.last_active_days' => ['nullable', 'integer', 'min:1'],
            'segment_filters.vip_only' => ['nullable', 'boolean'],
            'segment_filters.vip_min_orders' => ['nullable', 'integer', 'min:1'],
            'required_piece_ids' => ['nullable', 'array'],
            'required_piece_ids.*' => ['integer', 'exists:pieces,id'],
            'bundle_rules' => ['nullable', 'array'],
        ];
    }
}
