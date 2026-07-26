<?php

namespace Modules\Discount\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDiscountRequest extends FormRequest
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
        $id = $this->route('id') ?? $this->route('discount');

        return [
            'code' => ['nullable', 'string', Rule::unique('discounts', 'code')->ignore($id), 'alpha_num', 'max:50'],
            'discount_type' => ['nullable', 'in:percentage,fixed'],
            'value' => ['nullable', 'numeric', 'min:0.01', function ($attribute, $value, $fail) {
                $type = $this->discount_type;
                if ($type === 'percentage' && $value > 100) {
                    $fail('The value must not be greater than 100 when discount type is percentage.');
                }
            }],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'is_active' => ['nullable', 'boolean'],
            'applicable_to' => ['nullable', 'in:all,specific_users,specific_groups'],
            'user_ids' => ['required_if:applicable_to,specific_users', 'array'],
            'group_ids' => ['required_if:applicable_to,specific_groups', 'array'],
        ];
    }
}
