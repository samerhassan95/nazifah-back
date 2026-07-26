<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorRequest extends FormRequest
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
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
            'email' => 'required|email|unique:vendors,email',
            'official_number' => 'nullable|string',
            'vat_number' => 'nullable|string',
            'phone' => 'required|string|unique:vendors,phone',
            'delivery_price_per_km' => 'nullable|numeric|min:0',
            'is_verified' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
