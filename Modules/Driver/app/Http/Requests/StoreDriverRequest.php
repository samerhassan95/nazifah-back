<?php

namespace Modules\Driver\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriverRequest extends FormRequest
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
            'phone' => 'required|string|unique:users,phone',
            'full_name' => ['required', 'array'],
            'full_name.ar' => ['required', 'string', 'max:255'],
            'full_name.en' => ['required', 'string', 'max:255'],
            'email' => 'required|email|unique:users,email',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
