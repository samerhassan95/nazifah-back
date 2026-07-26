<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\AdminSetting;

class AdminSettingController extends Controller
{
    /**
     * Display a listing of admin settings.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = AdminSetting::query();

            // Search by key
            if ($request->has('search')) {
                $query->where('key', 'like', '%'.$request->search.'%');
            }

            // Sort
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->input('per_page', 15);
            $settings = $query->paginate($perPage);

            return successResponse(
                $settings,
                'Settings retrieved successfully'
            );

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to retrieve settings', null, 500);
        }
    }

    /**
     * Store a newly created setting.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'key' => ['required', 'string', 'max:255', 'unique:admin_settings,key'],
            'value' => ['required'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make('Validation failed', $validator->errors(), 422);
        }

        try {
            $setting = AdminSetting::setValue($request->key, $request->value);

            return successResponse(
                $setting,
                'Setting created successfully',
                201
            );

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to create setting', null, 500);
        }
    }

    /**
     * Display the specified setting.
     */
    public function show($id): JsonResponse
    {
        try {
            $setting = AdminSetting::find($id);

            if (! $setting) {
                return ErrorResponse::make('Setting not found', null, 404);
            }

            return successResponse(
                $setting,
                'Setting retrieved successfully'
            );

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to retrieve setting', null, 500);
        }
    }

    /**
     * Get setting by key.
     */
    public function getByKey(string $key): JsonResponse
    {
        try {
            $value = AdminSetting::getValue($key);

            if ($value === null) {
                return ErrorResponse::make('Setting not found', null, 404);
            }

            return successResponse([
                'key' => $key,
                'value' => $value,
            ], 'Setting retrieved successfully');

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to retrieve setting', null, 500);
        }
    }

    /**
     * Update the specified setting.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'value' => ['required'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make('Validation failed', $validator->errors(), 422);
        }

        try {
            $setting = AdminSetting::find($id);

            if (! $setting) {
                return ErrorResponse::make('Setting not found', null, 404);
            }

            $setting->value = (string) $request->value;
            $setting->save();

            return successResponse(
                $setting->fresh(),
                'Setting updated successfully'
            );

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to update setting', null, 500);
        }
    }

    /**
     * Update setting by key.
     */
    public function updateByKey(Request $request, string $key): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'value' => ['required'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make('Validation failed', $validator->errors(), 422);
        }

        try {
            $setting = AdminSetting::setValue($key, $request->value);

            return successResponse(
                $setting,
                'Setting updated successfully'
            );

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to update setting', null, 500);
        }
    }

    /**
     * Remove the specified setting.
     */
    public function destroy($id): JsonResponse
    {
        try {
            $setting = AdminSetting::find($id);

            if (! $setting) {
                return ErrorResponse::make('Setting not found', null, 404);
            }

            $setting->delete();

            return successResponse(
                null,
                'Setting deleted successfully'
            );

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to delete setting', null, 500);
        }
    }

    /**
     * Delete setting by key.
     */
    public function destroyByKey(string $key): JsonResponse
    {
        try {
            $setting = AdminSetting::where('key', $key)->first();

            if (! $setting) {
                return ErrorResponse::make('Setting not found', null, 404);
            }

            $setting->delete();

            return successResponse(
                null,
                'Setting deleted successfully'
            );

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to delete setting', null, 500);
        }
    }
}
