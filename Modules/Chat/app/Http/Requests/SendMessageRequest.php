<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Must be authenticated
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'conversation_id' => ['nullable', 'string', 'exists:conversations,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'message' => ['required', 'string', 'max:5000'],
            'type' => ['nullable', 'string', 'in:support,vendor,driver,order'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'message_type' => ['nullable', 'string', 'in:text,image,file'],
            'file' => ['nullable', 'file', 'max:10240'],
        ];
    }
}
