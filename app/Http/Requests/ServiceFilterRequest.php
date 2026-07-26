<?php

namespace App\Http\Requests;

class ServiceFilterRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:services,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'parent_id.integer' => 'Parent service ID must be a valid number',
            'parent_id.exists' => 'The selected parent service does not exist',
            'vendor_id.integer' => 'Vendor ID must be a valid number',
            'vendor_id.exists' => 'The selected vendor does not exist',
            'category_id.integer' => 'Category ID must be a valid number',
            'category_id.exists' => 'The selected category does not exist',
            'per_page.integer' => 'Per page must be a valid number',
            'per_page.min' => 'Per page must be at least 1',
            'per_page.max' => 'Per page cannot exceed 100',
        ];
    }
}
