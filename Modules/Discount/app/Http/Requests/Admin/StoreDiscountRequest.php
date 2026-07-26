<?php

namespace Modules\Discount\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiscountRequest extends FormRequest
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
            'code' => ['required', 'string', 'unique:discounts,code', 'alpha_num', 'max:50'],
            'discount_type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'numeric', 'min:0.01', function ($attribute, $value, $fail) {
                if ($this->discount_type === 'percentage' && $value > 100) {
                    $fail('The value must not be greater than 100 when discount type is percentage.');
                }
            }],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_active' => ['boolean'],
            'applicable_to' => ['in:all,specific_users,specific_groups'],
            'user_ids' => ['required_if:applicable_to,specific_users', 'array'],
            'group_ids' => ['required_if:applicable_to,specific_groups', 'array'],
        ];
    }
}
