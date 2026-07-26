<?php

namespace Modules\Order\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
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
        return [
            'client_id' => 'required|integer|exists:clients,id',
            'vendor_id' => 'required|integer|exists:vendors,id',
            'driver_id' => 'required|integer|exists:drivers,id',
            'status' => 'required|string|max:255',
            'payment_status' => 'required|string|max:255',
            'total_amount' => 'required|string|max:255',
        ];
    }
}
