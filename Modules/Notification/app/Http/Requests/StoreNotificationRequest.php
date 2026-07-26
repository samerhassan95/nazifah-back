<?php

namespace Modules\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNotificationRequest extends FormRequest
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
            'title' => ['required', 'array'],
            'title.ar' => ['required', 'string', 'max:255'],
            'title.en' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'array'],
            'message.ar' => ['nullable', 'string', 'max:1000'],
            'message.en' => ['nullable', 'string', 'max:1000'],
            'type' => 'required|string',
            'recipient_type' => 'required|string|max:255',
            'recipient_id' => 'required|integer|exists:recipients,id',
        ];
    }
}
