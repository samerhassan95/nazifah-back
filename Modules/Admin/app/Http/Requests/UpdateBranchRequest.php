<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => 'nullable|exists:vendors,id',
            'name' => ['nullable', 'array'],
            'name.ar' => ['nullable', 'string'],
            'name.en' => ['nullable', 'string'],
            'location' => ['nullable', 'array'],
            'location.ar' => ['nullable', 'string'],
            'location.en' => ['nullable', 'string'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string'],
            'description.en' => ['nullable', 'string'],
            'phone' => 'nullable|string',
            'phone_number' => 'nullable|string',
            'address' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_active' => 'nullable|boolean',
        ];
    }
}
