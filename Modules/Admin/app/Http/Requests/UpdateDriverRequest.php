<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDriverRequest extends FormRequest
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
        $id = $this->route('id') ?? $this->route(strtolower('Driver'));

        return [
            'phone' => ['sometimes', 'string', Rule::unique('drivers', 'phone')->ignore($id)->whereNull('deleted_at')],
            'full_name' => 'sometimes|array',
            'full_name.en' => 'sometimes|string|max:255',
            'full_name.ar' => 'nullable|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('drivers', 'email')->ignore($id)->whereNull('deleted_at')],
            'image' => 'nullable|image:allow_svg|mimes:jpeg,png,jpg,gif,webp,svg|max:5120',
            'id_number' => 'nullable|string|max:50',
            'image_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'is_available' => 'sometimes|boolean',
        ];
    }
}
