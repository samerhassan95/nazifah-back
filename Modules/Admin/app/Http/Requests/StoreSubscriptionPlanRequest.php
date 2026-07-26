<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'array'],
            'tagline.ar' => ['nullable', 'string', 'max:255'],
            'tagline.en' => ['nullable', 'string', 'max:255'],
            'price_month' => 'required|numeric|min:0',
            'price_year' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'branch_count' => 'required|integer|min:1',
            'order_count' => 'nullable|integer|min:0',
            'has_discount_codes' => 'boolean',
            'has_special_delivery' => 'boolean',
            'has_reports' => 'boolean',
            'is_featured' => 'boolean',
            'has_discount' => 'boolean',
            'discount_percentage' => 'nullable|integer|min:0|max:100',
        ];
    }
}
