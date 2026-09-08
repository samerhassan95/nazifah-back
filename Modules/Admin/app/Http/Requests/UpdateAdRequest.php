<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can perform this action
        return $this->user() instanceof \Modules\Admin\Models\Admin;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:image,video,gif'],
            'title' => ['sometimes', 'array'],
            'title.ar' => ['sometimes', 'string'],
            'title.en' => ['sometimes', 'string'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string'],
            'description.en' => ['nullable', 'string'],
            'image' => [
                'sometimes',
                'file',
                function ($attribute, $value, $fail) {
                    $type = $this->input('type');
                    $mime = $value->getMimeType();
                    $sizeKb = $value->getSize() / 1024;

                    if ($type === 'video') {
                        $allowed = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'];
                        if (! in_array($mime, $allowed, true)) {
                            $fail('The content file must be a valid video (mp4, mov, avi, webm).');
                        } elseif ($sizeKb > 20480) {
                            $fail('The content file must not be greater than 20MB.');
                        }
                    } else {
                        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        if (! in_array($mime, $allowed, true)) {
                            $fail('The content file must be a valid image (jpeg, png, gif, webp).');
                        } elseif ($sizeKb > 5120) {
                            $fail('The content file must not be greater than 5MB.');
                        }
                    }
                },
            ],
            'link' => 'nullable|string|url',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'is_active' => 'boolean',
        ];
    }
}
