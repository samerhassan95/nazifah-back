<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\AdminSetting;

class AdminGeneralSettingController extends Controller
{
    public function __construct(
        private UploadFilesService $uploadService
    ) {}

    /**
     * Get all general settings
     */
    public function index(): JsonResponse
    {
        try {
            $settings = [
                'image' => AdminSetting::getValue('platform_image'),
                'platform_name' => AdminSetting::getValue('platform_name'),
                'language' => AdminSetting::getValue('language', 'arabic'),
                'timezone' => AdminSetting::getValue('timezone', 'Asia/Riyadh'),
                'currency' => AdminSetting::getValue('currency', 'SAR'),
            ];

            return successResponse($settings, 'General settings retrieved successfully');

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to retrieve general settings', null, 500);
        }
    }

    /**
     * Update general settings
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'platform_name' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'in:arabic,english'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'currency' => ['nullable', 'string', 'max:10'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make('Validation failed', $validator->errors(), 422);
        }

        try {
            // Handle image upload
            if ($request->hasFile('image')) {
                $imagePath = $this->uploadService->uploadFile($request->file('image'), 'settings');
                AdminSetting::setValue('platform_image', $imagePath);
            }

            // Update other settings
            if ($request->has('platform_name')) {
                AdminSetting::setValue('platform_name', $request->platform_name);
            }

            if ($request->has('language')) {
                AdminSetting::setValue('language', $request->language);
            }

            if ($request->has('timezone')) {
                AdminSetting::setValue('timezone', $request->timezone);
            }

            if ($request->has('currency')) {
                AdminSetting::setValue('currency', $request->currency);
            }

            $settings = [
                'image' => AdminSetting::getValue('platform_image'),
                'platform_name' => AdminSetting::getValue('platform_name'),
                'language' => AdminSetting::getValue('language', 'arabic'),
                'timezone' => AdminSetting::getValue('timezone', 'Asia/Riyadh'),
                'currency' => AdminSetting::getValue('currency', 'SAR'),
            ];

            return successResponse($settings, 'General settings updated successfully');

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to update general settings', null, 500);
        }
    }

    /**
     * Get all available timezones
     */
    public function timezones(): JsonResponse
    {
        try {
            $timezones = \DateTimeZone::listIdentifiers();

            $formattedTimezones = array_map(function ($timezone) {
                return [
                    'value' => $timezone,
                    'label' => $timezone,
                    'offset' => $this->getTimezoneOffset($timezone),
                ];
            }, $timezones);

            return successResponse($formattedTimezones, 'Timezones retrieved successfully');

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to retrieve timezones', null, 500);
        }
    }

    /**
     * Get timezone offset
     */
    private function getTimezoneOffset(string $timezone): string
    {
        try {
            $dateTime = new \DateTime('now', new \DateTimeZone($timezone));
            $offset = $dateTime->format('P');

            return "UTC{$offset}";
        } catch (\Exception $e) {
            return 'UTC+00:00';
        }
    }
}
