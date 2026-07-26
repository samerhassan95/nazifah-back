<?php

namespace Modules\Service\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
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
        $id = $this->route('id') ?? $this->route(strtolower('Service'));

        return [
            'category_id' => 'sometimes|integer|exists:categories,id',
            'service_name' => ['sometimes', 'array'],
            'service_name.ar' => ['sometimes', 'string', 'max:255'],
            'service_name.en' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'price' => 'sometimes|numeric',
            'icon_id' => 'sometimes|required|integer|exists:icons,id',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
