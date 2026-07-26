<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        return [
            'phone' => 'required|string|unique:clients,phone',
            'full_name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:clients,email',
            'image' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,pdf,doc,docx|max:10240',
            'is_verified' => 'boolean',
        ];
    }
}
