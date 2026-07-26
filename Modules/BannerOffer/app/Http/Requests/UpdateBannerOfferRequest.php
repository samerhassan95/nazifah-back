<?php

namespace Modules\BannerOffer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBannerOfferRequest extends FormRequest
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
        $id = $this->route('id') ?? $this->route(strtolower('BannerOffer'));

        return [
            'title' => ['sometimes', 'array'],
            'title.ar' => ['sometimes', 'string', 'max:255'],
            'title.en' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'image_url' => 'sometimes|string|max:255',
            'link_url' => 'sometimes|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
