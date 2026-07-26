<?php

namespace Modules\Ad\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $id = $this->route('id') ?? $this->route(strtolower('Ad'));

        return [
            'title' => ['sometimes', 'array'],
            'title.ar' => ['sometimes', 'string', 'max:255'],
            'title.en' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'image_url' => 'sometimes|string|max:255',
            'link_url' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
