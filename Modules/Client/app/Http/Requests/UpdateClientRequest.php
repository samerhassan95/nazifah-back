<?php

namespace Modules\Client\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Must be authenticated
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $id = $this->route('id') ?? $this->route(strtolower('Client'));

        return [
            'phone' => 'sometimes|string|unique:clients,phone,'.$id,
            'full_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:clients,email,'.$id,
            'is_verified' => 'sometimes|boolean',
        ];
    }
}
