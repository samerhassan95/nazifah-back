<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        $clientId = $this->route('id');

        return [
            // Basic info
            'phone' => ['sometimes', 'string', Rule::unique('clients', 'phone')->ignore($clientId)],
            'full_name' => 'sometimes|string|max:255',
            'email' => ['nullable', 'email', Rule::unique('clients', 'email')->ignore($clientId)],
            'image' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,pdf,doc,docx|max:10240',

            // Status flags
            'is_verified' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',

            // Addresses (array of addresses)
            'addresses' => 'sometimes|array',
            'addresses.*.id' => 'sometimes|integer|exists:addresses,id',
            'addresses.*.title' => 'sometimes|string|max:255',
            'addresses.*.national_address' => 'nullable|string|max:255',
            'addresses.*.street_name' => 'nullable|string|max:255',
            'addresses.*.building_number' => 'nullable|string|max:50',
            'addresses.*.street_number' => 'nullable|string|max:50',
            'addresses.*.floor' => 'nullable|string|max:50',
            'addresses.*.apartment' => 'nullable|string|max:50',
            'addresses.*.latitude' => 'required_with:addresses.*.longitude|numeric|between:-90,90',
            'addresses.*.longitude' => 'required_with:addresses.*.latitude|numeric|between:-180,180',
            'addresses.*.notes' => 'nullable|string|max:500',
            'addresses.*.is_default' => 'sometimes|boolean',
            'addresses.*.zone_id' => 'nullable|integer|exists:zones,id',
        ];
    }
}
