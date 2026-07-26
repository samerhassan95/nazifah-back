<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BannerOffer\Enums\BannerDestinationType;
use Modules\Notification\Enums\UserTargetType;

class UpdateBannerOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|array',
            'title.ar' => 'nullable|string|max:255',
            'title.en' => 'nullable|string|max:255',
            'description' => 'nullable|array',
            'description.ar' => 'nullable|string',
            'description.en' => 'nullable|string',
            'image' => 'sometimes|file|image|max:5120',
            'link' => [
                Rule::requiredIf(fn () => $this->input('destination_type') === BannerDestinationType::EXTERNAL_URL->value),
                'nullable',
                'string',
                'max:500',
            ],
            'destination_type' => ['sometimes', Rule::in(BannerDestinationType::values())],
            'destination_id' => [
                Rule::requiredIf(fn () => $this->filled('destination_type')
                    && $this->input('destination_type') !== BannerDestinationType::EXTERNAL_URL->value),
                'nullable',
                'integer',
                'min:1',
            ],
            'user_target_type' => ['nullable', Rule::in(UserTargetType::values())],
            'target_user_ids' => [
                Rule::requiredIf(fn () => in_array($this->input('user_target_type'), [
                    UserTargetType::SPECIFIC_USERS->value,
                    UserTargetType::CLIENT->value,
                    UserTargetType::VENDOR->value,
                    UserTargetType::DRIVER->value,
                ], true)),
                'nullable',
                'array',
            ],
            'target_user_ids.*' => 'integer|min:1',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'is_active' => 'boolean',
            'order' => 'nullable|integer|min:0',
        ];
    }
}
