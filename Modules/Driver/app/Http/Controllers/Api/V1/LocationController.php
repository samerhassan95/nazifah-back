<?php

namespace Modules\Driver\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LocationController extends Controller
{
    /**
     * Update driver location
     */
    public function update(Request $request): JsonResponse
    {
        $driver = $request->user();

        $validator = Validator::make($request->all(), [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $driver->update([
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        return successResponse([
            'location' => [
                'latitude' => $driver->latitude,
                'longitude' => $driver->longitude,
            ],
        ], 'Location updated successfully');
    }

    /**
     * Get driver location
     */
    public function show(Request $request): JsonResponse
    {
        $driver = $request->user();

        return successResponse([
            'location' => [
                'latitude' => $driver->latitude,
                'longitude' => $driver->longitude,
                'address' => $driver->location,
            ],
        ], 'Location retrieved successfully');
    }
}
