<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOwnerRequest extends FormRequest
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
            'phone' => 'required|string|unique:owners,phone',
            'name' => 'required|array',
            'name.en' => 'required_without:name.ar|string|max:255',
            'name.ar' => 'required_without:name.en|string|max:255',
            'email' => 'required|email|unique:owners,email',
            'password' => 'required|string|min:8',
            'whatsapp' => 'nullable|string',
            'id_image' => 'nullable|string',
            'is_verified' => 'sometimes|boolean',
        ];
    }
}
