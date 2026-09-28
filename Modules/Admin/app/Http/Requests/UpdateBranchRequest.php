<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        $branchId = $this->route('id');
        $vendorId = $this->input('vendor_id')
            ?? Branch::whereKey($branchId)->value('vendor_id');

        return [
            'vendor_id' => 'nullable|exists:vendors,id',
            'name' => ['nullable', 'array'],
            'name.ar' => [
                'nullable', 'string',
                Rule::unique('branches', 'name->ar')->where(fn ($q) => $q->where('vendor_id', $vendorId))->ignore($branchId)->whereNull('deleted_at'),
            ],
            'name.en' => [
                'nullable', 'string',
                Rule::unique('branches', 'name->en')->where(fn ($q) => $q->where('vendor_id', $vendorId))->ignore($branchId)->whereNull('deleted_at'),
            ],
            'location' => ['nullable', 'array'],
            'location.ar' => ['nullable', 'string'],
            'location.en' => ['nullable', 'string'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string'],
            'description.en' => ['nullable', 'string'],
            'phone' => 'nullable|string',
            'phone_number' => 'nullable|string',
            'address' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_active' => 'nullable|boolean',
        ];
    }
}
