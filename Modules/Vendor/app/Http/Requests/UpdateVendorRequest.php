<?php

namespace Modules\Vendor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can update vendors
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $id = $this->route('id') ?? $this->route(strtolower('Vendor'));

        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$id,
            'phone' => 'sometimes|string|unique:users,phone,'.$id,
            'is_verified' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
