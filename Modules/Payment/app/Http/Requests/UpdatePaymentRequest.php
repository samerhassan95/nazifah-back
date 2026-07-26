<?php

namespace Modules\Payment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
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
        $id = $this->route('id') ?? $this->route(strtolower('Payment'));

        return [
            'order_id' => 'sometimes|integer|exists:orders,id',
            'amount' => 'sometimes|numeric',
            'payment_method' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|max:255',
            'transaction_id' => 'sometimes|integer|exists:transactions,id',
        ];
    }
}
