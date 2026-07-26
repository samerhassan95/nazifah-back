<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        return [
            'category_id' => 'required|exists:categories,id',
            'service_name' => ['required', 'array'],
            'service_name.ar' => ['required', 'string', 'max:255'],
            'service_name.en' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string'],
            'description.en' => ['nullable', 'string'],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'price' => 'prohibited',
            'discount_price' => 'nullable|numeric|min:0',
            'preparation_time' => 'nullable|integer',
            'icon_id' => 'required|exists:icons,id',
            'is_active' => 'boolean',
        ];
    }
}
