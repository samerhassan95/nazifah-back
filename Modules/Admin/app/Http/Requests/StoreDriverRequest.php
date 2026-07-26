<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriverRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'phone' => 'required|string|unique:drivers,phone',
            'full_name' => 'required|array',
            'full_name.en' => 'required_without:full_name.ar|string|max:255',
            'full_name.ar' => 'required_without:full_name.en|string|max:255',
            'email' => 'required|email|unique:drivers,email',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'is_verified' => 'sometimes|boolean',
            'is_available' => 'sometimes|boolean',
        ];
    }
}
