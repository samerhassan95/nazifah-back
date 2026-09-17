<?php

namespace Modules\Branch\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone') && ! $this->has('phone_number')) {
            $this->merge([
                'phone_number' => $this->input('phone'),
            ]);
        } elseif ($this->has('phone_number') && ! $this->has('phone')) {
            $this->merge([
                'phone' => $this->input('phone_number'),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'vendor_id' => 'sometimes|integer|exists:vendors,id',
            'name' => ['sometimes', 'array'],
            'name.ar' => ['sometimes', 'string', 'max:255'],
            'name.en' => ['sometimes', 'string', 'max:255'],
            'location' => ['nullable', 'array'],
            'location.ar' => ['nullable', 'string'],
            'location.en' => ['nullable', 'string'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string'],
            'description.en' => ['nullable', 'string'],
            'address' => 'nullable|string|max:1000',
            'phone' => 'nullable|string',
            'phone_number' => 'nullable|string',
            'latitude' => 'sometimes|required|numeric|between:-90,90',
            'longitude' => 'sometimes|required|numeric|between:-180,180',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
