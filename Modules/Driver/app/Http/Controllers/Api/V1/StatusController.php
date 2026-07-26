<?php

namespace Modules\Driver\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StatusController extends Controller
{
    /**
     * Update driver availability status (available for orders or not)
     * PUT /api/v1/driver/status/availability
     */
    public function updateAvailability(Request $request): JsonResponse
    {
        $driver = $request->user();

        $validator = Validator::make($request->all(), [
            'is_available' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $driver->update([
            'is_available' => $request->boolean('is_available'),
        ]);

        return successResponse([
            'id' => $driver->id,
            'full_name' => method_exists($driver, 'getTranslations') ? $driver->getTranslations('full_name') : $driver->full_name,
            'email' => $driver->email,
            'phone' => $driver->phone,
            'image' => $driver->image,
            'rating' => (float) ($driver->rating ?? 0),
            'total_orders' => (int) ($driver->total_orders ?? 0),
            'is_available' => (bool) $driver->is_available,
            'latitude' => $driver->latitude ? (float) $driver->latitude : null,
            'longitude' => $driver->longitude ? (float) $driver->longitude : null,
            'branch_id' => $driver->branch_id,
            'vendor_id' => $driver->vendor_id,

        ], 'Availability status updated successfully');
    }

    /**
     * Get driver status
     * GET /api/v1/driver/status
     */
    public function show(Request $request): JsonResponse
    {
        $driver = $request->user();

        return successResponse([
            'id' => $driver->id,
            'full_name' => method_exists($driver, 'getTranslations') ? $driver->getTranslations('full_name') : $driver->full_name,
            'email' => $driver->email,
            'phone' => $driver->phone,
            'image' => $driver->image,
            'rating' => (float) ($driver->rating ?? 0),
            'total_orders' => (int) ($driver->total_orders ?? 0),
            'is_available' => (bool) $driver->is_available,
            'latitude' => $driver->latitude ? (float) $driver->latitude : null,
            'longitude' => $driver->longitude ? (float) $driver->longitude : null,
            'branch_id' => $driver->branch_id,
            'vendor_id' => $driver->vendor_id,

        ], 'Driver status retrieved successfully');
    }
}
