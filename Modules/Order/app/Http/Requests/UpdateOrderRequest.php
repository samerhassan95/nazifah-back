<?php

namespace Modules\Order\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Must be authenticated
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $id = $this->route('id') ?? $this->route(strtolower('Order'));

        return [
            'client_id' => 'sometimes|integer|exists:clients,id',
            'vendor_id' => 'sometimes|integer|exists:vendors,id',
            'driver_id' => 'sometimes|integer|exists:drivers,id',
            'status' => 'sometimes|string|max:255',
            'payment_status' => 'sometimes|string|max:255',
            'total_amount' => 'sometimes|string|max:255',
        ];
    }
}
