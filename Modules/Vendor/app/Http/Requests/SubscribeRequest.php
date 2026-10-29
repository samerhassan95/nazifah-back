<?php

namespace Modules\Vendor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only vendor employees can perform this action. property_exists()
        // used to be checked here, but Eloquent attributes (vendor_id) live
        // in the model's internal $attributes array and are resolved via the
        // __get magic method — they never register as a real PHP property,
        // so property_exists() was always false and this rejected every
        // single request with a 403, regardless of who was authenticated.
        return $this->user() instanceof \Modules\Vendor\Models\VendorEmployee;
    }

    public function rules(): array
    {
        return [
            'subscription_plan_id' => 'required|exists:subscription_plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
            'payment_method' => 'required|string',
        ];
    }
}
