<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOwnerRequest extends FormRequest
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
        $id = $this->route('id') ?? $this->route(strtolower('Owner'));

        return [
            'name' => 'sometimes|array',
            'name.en' => 'sometimes|string|max:255',
            'name.ar' => 'nullable|string|max:255',
            'phone' => 'sometimes|string|unique:owners,phone,'.$id,
            'whatsapp' => 'nullable|string',
            'email' => 'sometimes|email|unique:owners,email,'.$id,
            'password' => 'sometimes|string|min:8',
            'id_image' => 'nullable|string',
            'is_verified' => 'sometimes|boolean',
        ];
    }
}
