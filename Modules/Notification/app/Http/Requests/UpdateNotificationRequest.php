<?php

namespace Modules\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationRequest extends FormRequest
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
        $id = $this->route('id') ?? $this->route(strtolower('Notification'));

        return [
            'title' => ['sometimes', 'array'],
            'title.ar' => ['sometimes', 'string', 'max:255'],
            'title.en' => ['sometimes', 'string', 'max:255'],
            'message' => ['nullable', 'array'],
            'message.ar' => ['nullable', 'string', 'max:1000'],
            'message.en' => ['nullable', 'string', 'max:1000'],
            'type' => 'sometimes|string',
            'recipient_type' => 'sometimes|string|max:255',
            'recipient_id' => 'sometimes|integer|exists:recipients,id',
        ];
    }
}
