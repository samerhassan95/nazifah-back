<?php

namespace App\Http\Requests;

class LaundryFilterRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', 'string', 'in:rating,distance,name,created_at'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'zone_id.integer' => 'Zone ID must be a valid number',
            'zone_id.exists' => 'The selected zone does not exist',
            'category_id.integer' => 'Category ID must be a valid number',
            'category_id.exists' => 'The selected category does not exist',
            'latitude.numeric' => 'Latitude must be a valid number',
            'latitude.between' => 'Latitude must be between -90 and 90',
            'longitude.numeric' => 'Longitude must be a valid number',
            'longitude.between' => 'Longitude must be between -180 and 180',
            'radius.numeric' => 'Radius must be a valid number',
            'radius.min' => 'Radius must be at least 0.1 km',
            'radius.max' => 'Radius cannot exceed 100 km',
            'search.string' => 'Search term must be text',
            'search.max' => 'Search term cannot exceed 255 characters',
            'sort_by.in' => 'Sort by must be one of: rating, distance, name, created_at',
            'per_page.integer' => 'Per page must be a valid number',
            'per_page.min' => 'Per page must be at least 1',
            'per_page.max' => 'Per page cannot exceed 100',
        ];
    }
}
