<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'phone' => ['required', 'string', Rule::unique('drivers', 'phone')->whereNull('deleted_at')],
            'full_name' => 'required|array',
            'full_name.en' => 'required|string|max:255',
            'full_name.ar' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('drivers', 'email')->whereNull('deleted_at')],
            'image' => 'required|image:allow_svg|mimes:jpeg,png,jpg,gif,webp,svg|max:5120',
            'id_number' => 'nullable|string|max:50',
            'image_document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'is_verified' => 'sometimes|boolean',
            'is_available' => 'sometimes|boolean',
        ];
    }
}
