<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
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
        return [
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:255', Rule::unique('vendors', 'name->ar')->whereNull('deleted_at')],
            'name.en' => ['required', 'string', 'max:255', Rule::unique('vendors', 'name->en')->whereNull('deleted_at')],
            'logo' => 'nullable|image:allow_svg|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
            'email' => ['required', 'email', Rule::unique('vendors', 'email')->whereNull('deleted_at')],
            'official_number' => ['nullable', 'string', 'digits:10'],
            'vat_number' => 'nullable|string',
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/', Rule::unique('vendors', 'phone')->whereNull('deleted_at')],
            'delivery_price_per_km' => 'nullable|numeric|min:0',
            'is_verified' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
