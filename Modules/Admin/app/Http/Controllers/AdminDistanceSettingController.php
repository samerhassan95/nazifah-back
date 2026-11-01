<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\DistanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\AdminSetting;

class AdminDistanceSettingController extends Controller
{
    /**
     * Get distance calculation settings
     */
    public function index(): JsonResponse
    {
        try {
            $settings = [
                'distance_mode' => AdminSetting::getValue('distance_mode', DistanceService::MODE_AERIAL),
                'google_maps_api_key' => AdminSetting::getValue('google_maps_api_key'),
            ];

            return successResponse($settings, 'Distance settings retrieved successfully');
        } catch (\Exception $e) {
            return ErrorResponse::make(adminErrorMessage('Failed to retrieve distance settings', $e), null, 500);
        }
    }

    /**
     * Update distance calculation settings
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'distance_mode' => ['nullable', 'string', 'in:'.DistanceService::MODE_AERIAL.','.DistanceService::MODE_GOOGLE_MAPS],
            'google_maps_api_key' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make('Validation failed', $validator->errors(), 422);
        }

        try {
            if ($request->has('distance_mode')) {
                AdminSetting::setValue('distance_mode', $request->distance_mode);
            }

            if ($request->has('google_maps_api_key')) {
                AdminSetting::setValue('google_maps_api_key', $request->google_maps_api_key);
            }

            $settings = [
                'distance_mode' => AdminSetting::getValue('distance_mode', DistanceService::MODE_AERIAL),
                'google_maps_api_key' => AdminSetting::getValue('google_maps_api_key'),
            ];

            return successResponse($settings, 'Distance settings updated successfully');
        } catch (\Exception $e) {
            return ErrorResponse::make(adminErrorMessage('Failed to update distance settings', $e), null, 500);
        }
    }
}
