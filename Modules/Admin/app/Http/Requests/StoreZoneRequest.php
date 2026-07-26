<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreZoneRequest extends FormRequest
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
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'points' => [
                'required',
                'array',
                'min:3',
            ],
            'points.*' => [
                'required',
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
            'points.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'points.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'is_active' => 'sometimes|boolean',
            'zone_color' => 'nullable|string|max:7',
        ];
    }
}
