<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        $vendorId = $this->route('vendor') ?? $this->route('id');

        return [
            'name' => ['nullable', 'array'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
            'email' => ['nullable', 'email', Rule::unique('vendors', 'email')->ignore($vendorId)],
            'official_number' => 'nullable|string',
            'vat_number' => 'nullable|string',
            'phone' => ['nullable', 'string', Rule::unique('vendors', 'phone')->ignore($vendorId)],
            'delivery_price_per_km' => 'nullable|numeric|min:0',
            'is_verified' => 'nullable|boolean',
            'rejection_reason' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'is_banned' => 'nullable|boolean',
            'rating' => 'nullable|numeric|min:0|max:5',
        ];
    }
}
