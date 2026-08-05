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
            'usage_condition' => ['sometimes', 'string', 'in:all,services'],
            'usage_service_ids' => ['nullable', 'array'],
            'usage_service_ids.*' => ['integer', 'exists:services,id'],
            'application_scope' => ['sometimes', 'string', 'in:order_total,services'],
            'discount_service_ids' => ['nullable', 'array'],
            'discount_service_ids.*' => ['integer', 'exists:services,id'],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after:start_date'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'vendor_ids' => ['nullable', 'array'],
            'vendor_ids.*' => ['exists:vendors,id'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['exists:zones,id'],
            'client_ids' => ['nullable', 'array'],
            'client_ids.*' => ['exists:clients,id'],
        ];
    }
}
