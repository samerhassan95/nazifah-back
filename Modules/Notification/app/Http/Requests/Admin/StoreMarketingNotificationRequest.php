<?php

namespace Modules\Notification\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Notification\Enums\UserTargetType;

class StoreMarketingNotificationRequest extends FormRequest
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
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'target_type' => ['required', Rule::in(UserTargetType::values())],
            'target_ids' => [
                'required_if:target_type,specific_users',
                'required_if:target_type,specific_groups',
                'array',
            ],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'deep_link' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'segment_filters' => ['nullable', 'array'],
            'segment_filters.min_orders' => ['nullable', 'integer', 'min:0'],
            'segment_filters.max_orders' => ['nullable', 'integer', 'min:0'],
            'segment_filters.last_active_days' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
