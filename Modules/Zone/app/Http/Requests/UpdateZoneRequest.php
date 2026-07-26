<?php

namespace Modules\Zone\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateZoneRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can manage zones
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $id = $this->route('id') ?? $this->route(strtolower('Zone'));

        return [
            'name' => ['sometimes', 'array'],
            'name.ar' => ['sometimes', 'string', 'max:255'],
            'name.en' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'points' => [
                'nullable',
                'array',
                'min:3',
            ],
            'points.*' => [
                'required_with:points',
                'array',
                function ($attribute, $value, $fail) {
                    if (! is_array($value)) {
                        return;
                    }

                    $extraKeys = array_diff(array_keys($value), ['latitude', 'longitude']);
                    if (! empty($extraKeys)) {
                        $fail('Each point must contain only latitude and longitude.');
                    }
                },
            ],
            'points.*.latitude' => ['required_with:points', 'numeric', 'between:-90,90'],
            'points.*.longitude' => ['required_with:points', 'numeric', 'between:-180,180'],
            'is_active' => 'sometimes|boolean',
        ];
    }
}
