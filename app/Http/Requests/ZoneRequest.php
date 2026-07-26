<?php

namespace App\Http\Requests;

class ZoneRequest extends BaseApiRequest
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
        ];
    }
}
