<?php

namespace Modules\Client\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateProfileRequest extends FormRequest
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
        $userId = $this->user() ? $this->user()->id : null;

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:clients,email,'.$userId],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            // Full Name
            'full_name.required' => __('client.full_name_required'),
            'full_name.string' => __('client.full_name_string'),
            'full_name.max' => __('client.full_name_max'),

            // Email
            'email.required' => __('client.email_required'),
            'email.email' => __('client.email_invalid'),
            'email.max' => __('client.email_max'),
            'email.unique' => __('client.email_unique'),

            // Image
            'image.image' => __('client.image_invalid'),
            'image.mimes' => __('client.image_mimes'),
            'image.max' => __('client.image_max'),
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'full_name' => __('client.full_name'),
            'email' => __('client.email'),
            'image' => __('client.image'),
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status' => false,
                'code' => 422,
                'message' => __('client.validation_error'),
                'errors' => $validator->errors(),
            ], 422)
        );
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Additional custom validation logic can go here
        });
    }
}
