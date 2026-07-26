<?php

namespace App\Http\Requests;

class BranchServiceRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service_ids' => ['nullable', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'service_ids.array' => 'Service IDs must be an array',
            'service_ids.min' => 'At least one service ID is required',
            'service_ids.*.integer' => 'Each service ID must be a valid number',
            'service_ids.*.exists' => 'One or more selected services do not exist',
        ];
    }
}
