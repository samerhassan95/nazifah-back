<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|string|in:pending,confirmed,preparing,ready,picked_up,delivered,cancelled',
            'notes' => 'nullable|string',
        ];
    }
}
