<?php

namespace Modules\Piece\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePieceRequest extends FormRequest
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
        $id = $this->route('id') ?? $this->route(strtolower('Piece'));

        return [
            'name' => ['sometimes', 'array'],
            'name.ar' => ['sometimes', 'string', 'max:255'],
            'name.en' => ['sometimes', 'string', 'max:255'],
            'icon_id' => 'sometimes|required|integer|exists:icons,id',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
