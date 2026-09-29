<?php

namespace Modules\Vendor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can create vendors
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('vendors', 'email')->whereNull('deleted_at')],
            'phone' => ['required', 'string', Rule::unique('vendors', 'phone')->whereNull('deleted_at')],
            'password' => 'required|string|min:8|confirmed',
            'logo' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
            'vat_number' => 'nullable|string|max:255',
            'official_number' => 'nullable|string|max:255',

        ];
    }
}
