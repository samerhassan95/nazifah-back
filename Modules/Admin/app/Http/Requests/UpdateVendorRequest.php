<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVendorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('unified_number')) {
            $this->merge(['official_number' => $this->input('unified_number')]);
        }
    }

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
            'name.ar' => ['nullable', 'string', 'max:255', Rule::unique('vendors', 'name->ar')->ignore($vendorId)->whereNull('deleted_at')],
            'name.en' => ['nullable', 'string', 'max:255', Rule::unique('vendors', 'name->en')->ignore($vendorId)->whereNull('deleted_at')],
            'logo' => 'nullable|image:allow_svg|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
            'email' => ['nullable', 'email', Rule::unique('vendors', 'email')->ignore($vendorId)->whereNull('deleted_at')],
            'official_number' => 'nullable|string',
            'vat_number' => 'nullable|string',
            'phone' => ['nullable', 'string', Rule::unique('vendors', 'phone')->ignore($vendorId)->whereNull('deleted_at')],
            'delivery_price_per_km' => 'nullable|numeric|min:0',
            'is_verified' => 'nullable|boolean',
            'rejection_reason' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'is_banned' => 'nullable|boolean',
            'rating' => 'nullable|numeric|min:0|max:5',
        ];
    }
}
