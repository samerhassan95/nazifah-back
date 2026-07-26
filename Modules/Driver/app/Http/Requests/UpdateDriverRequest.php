<?php

namespace Modules\Driver\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'phone' => 'sometimes|string|unique:users,phone,'.$id,
            'full_name' => ['sometimes', 'array'],
            'full_name.ar' => ['sometimes', 'string', 'max:255'],
            'full_name.en' => ['sometimes', 'string', 'max:255'],
            'email' => 'sometimes|email|unique:users,email,'.$id,
            'is_active' => 'sometimes|boolean',
        ];
    }
}
