<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\AdminSetting;

class AdminPlatformSettingController extends Controller
{
    public function __construct(
        private UploadFilesService $uploadService
    ) {}

    /**
     * Get all settings
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $locale = $request->input('language', app()->getLocale());
            $settings = AdminSetting::all();

            $data = $settings->map(function ($setting) {
                return [
                    'id' => $setting->id,
                    'key' => $setting->key,
                    'type' => $setting->type,
                    'value' => $setting->getTypedValue(),
                ];
            });

            $message = $locale === 'ar'
                ? 'تم استرجاع الإعدادات بنجاح'
                : 'Settings retrieved successfully';

            return successResponse($data, $message);

        } catch (\Exception $e) {
            $locale = $request->input('language', app()->getLocale());
            $message = $locale === 'ar'
                ? 'فشل في استرجاع الإعدادات'
                : 'Failed to retrieve settings';

            return ErrorResponse::make($message, null, 500);
        }
    }

    /**
     * Get single setting by key
     */
    public function show(Request $request, string $key): JsonResponse
    {
        try {
            $locale = $request->input('language', app()->getLocale());
            $setting = AdminSetting::where('key', $key)->first();

            if (! $setting) {
                $message = $locale === 'ar'
                    ? 'الإعداد غير موجود'
                    : 'Setting not found';

                return ErrorResponse::make($message, null, 404);
            }

            $data = [
                'id' => $setting->id,
                'key' => $setting->key,
                'type' => $setting->type,
                'value' => $setting->getTypedValue(),
            ];

            $message = $locale === 'ar'
                ? 'تم استرجاع الإعداد بنجاح'
                : 'Setting retrieved successfully';

            return successResponse($data, $message);

        } catch (\Exception $e) {
            $locale = $request->input('language', app()->getLocale());
            $message = $locale === 'ar'
                ? 'فشل في استرجاع الإعداد'
                : 'Failed to retrieve setting';

            return ErrorResponse::make($message, null, 500);
        }
    }

    /**
     * Update single setting
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $locale = $request->input('language', app()->getLocale());

        try {
            $setting = AdminSetting::where('key', $key)->first();

            if (! $setting) {
                $message = $locale === 'ar'
                    ? 'الإعداد غير موجود'
                    : 'Setting not found';

                return ErrorResponse::make($message, null, 404);
            }

            // Validate based on type
            $rules = $this->getValidationRules($setting->type);
            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                $message = $locale === 'ar' ? 'فشل التحقق من البيانات' : 'Validation failed';

                return ErrorResponse::make($message, $validator->errors(), 422);
            }

            // Handle image upload
            if ($setting->type === AdminSetting::TYPE_IMAGE) {
                if ($request->hasFile('value')) {
                    $imagePath = $this->uploadService->uploadFile($request->file('value'), 'settings', $setting->value);
                    $setting->value = $imagePath;
                } elseif ($request->filled('value_base64')) {
                    $imagePath = $this->uploadService->uploadBase64Image($request->value_base64, 'settings', $setting->value);
                    $setting->value = $imagePath;
                }
            } else {
                $value = $request->input('value');

                // Convert value based on type
                if ($setting->type === AdminSetting::TYPE_BOOLEAN) {
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                } elseif ($setting->type === AdminSetting::TYPE_JSON) {
                    $value = is_string($value) ? $value : json_encode($value);
                } else {
                    $value = (string) $value;
                }

                $setting->value = $value;
            }

            $setting->save();
            AdminSetting::clearCache();

            $message = $locale === 'ar'
                ? 'تم تحديث الإعداد بنجاح'
                : 'Setting updated successfully';

            return successResponse([
                'key' => $setting->key,
                'type' => $setting->type,
                'value' => $setting->getTypedValue(),
            ], $message);

        } catch (\Exception $e) {
            $message = $locale === 'ar'
                ? 'فشل في تحديث الإعداد'
                : 'Failed to update setting';

            return ErrorResponse::make($message, null, 500);
        }
    }

    /**
     * Update multiple settings at once
     */
    public function updateBulk(Request $request): JsonResponse
    {
        $locale = $request->input('language', app()->getLocale());

        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*.key' => 'required|string|exists:admin_settings,key',
            'settings.*.value' => 'required',
        ]);

        if ($validator->fails()) {
            $message = $locale === 'ar' ? 'فشل التحقق من البيانات' : 'Validation failed';

            return ErrorResponse::make($message, $validator->errors(), 422);
        }

        try {
            DB::beginTransaction();

            $updated = [];
            foreach ($request->input('settings') as $settingData) {
                $setting = AdminSetting::where('key', $settingData['key'])->first();

                if ($setting) {
                    AdminSetting::setValue($settingData['key'], $settingData['value'], $setting->type);
                    $updated[] = $settingData['key'];
                }
            }

            DB::commit();
            AdminSetting::clearCache();

            $message = $locale === 'ar'
                ? 'تم تحديث الإعدادات بنجاح'
                : 'Settings updated successfully';

            return successResponse([
                'updated_count' => count($updated),
                'updated_keys' => $updated,
            ], $message);

        } catch (\Exception $e) {
            DB::rollBack();
            $message = $locale === 'ar'
                ? 'فشل في تحديث الإعدادات'
                : 'Failed to update settings';

            return ErrorResponse::make($message, null, 500);
        }
    }

    /**
     * Create new setting
     */
    public function store(Request $request): JsonResponse
    {
        $locale = $request->input('language', app()->getLocale());

        $validator = Validator::make($request->all(), [
            'key' => 'required|string|unique:admin_settings,key|max:255',
            'type' => 'required|in:text,number,boolean,json,image',
            'value' => 'nullable',
        ]);

        if ($validator->fails()) {
            $message = $locale === 'ar' ? 'فشل التحقق من البيانات' : 'Validation failed';

            return ErrorResponse::make($message, $validator->errors(), 422);
        }

        try {
            $setting = AdminSetting::create([
                'key' => $request->input('key'),
                'type' => $request->input('type'),
                'value' => $request->input('value'),
            ]);

            $message = $locale === 'ar'
                ? 'تم إضافة الإعداد بنجاح'
                : 'Setting created successfully';

            return successResponse([
                'id' => $setting->id,
                'key' => $setting->key,
                'type' => $setting->type,
                'value' => $setting->getTypedValue(),
            ], $message, 201);

        } catch (\Exception $e) {
            $message = $locale === 'ar'
                ? 'فشل في إضافة الإعداد'
                : 'Failed to create setting';

            return ErrorResponse::make($message, null, 500);
        }
    }

    /**
     * Delete setting
     */
    public function destroy(Request $request, string $key): JsonResponse
    {
        $locale = $request->input('language', app()->getLocale());

        try {
            $setting = AdminSetting::where('key', $key)->first();

            if (! $setting) {
                $message = $locale === 'ar'
                    ? 'الإعداد غير موجود'
                    : 'Setting not found';

                return ErrorResponse::make($message, null, 404);
            }

            $setting->delete();
            AdminSetting::clearCache();

            $message = $locale === 'ar'
                ? 'تم حذف الإعداد بنجاح'
                : 'Setting deleted successfully';

            return successResponse(null, $message);

        } catch (\Exception $e) {
            $message = $locale === 'ar'
                ? 'فشل في حذف الإعداد'
                : 'Failed to delete setting';

            return ErrorResponse::make($message, null, 500);
        }
    }

    /**
     * Get validation rules based on setting type
     */
    private function getValidationRules(string $type): array
    {
        switch ($type) {
            case AdminSetting::TYPE_NUMBER:
                return ['value' => 'required|numeric'];

            case AdminSetting::TYPE_BOOLEAN:
                return ['value' => 'required|boolean'];

            case AdminSetting::TYPE_JSON:
                return ['value' => 'required|json'];

            case AdminSetting::TYPE_IMAGE:
                return [
                    'value' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                    'value_base64' => 'nullable|string',
                ];

            case AdminSetting::TYPE_TEXT:
            default:
                return ['value' => 'required|string'];
        }
    }
}
