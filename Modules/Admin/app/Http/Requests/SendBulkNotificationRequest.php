<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendBulkNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'array'],
            'title.ar' => ['required', 'string', 'max:255'],
            'title.en' => ['required', 'string', 'max:255'],
            'body' => ['required', 'array'],
            'body.ar' => ['required', 'string'],
            'body.en' => ['required', 'string'],
            'recipient_type' => 'required|string|in:all,clients,vendors,drivers,owners,admins',
            'recipient_ids' => 'nullable|array',
            'recipient_ids.*' => 'integer',
            'data' => 'nullable|array',
        ];
    }
}
