<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Http\Resources\AdminResource;

class AdminAccountController extends Controller
{
    public function __construct(
        private UploadFilesService $uploadService
    ) {}

    /**
     * Get current admin account details
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $admin = $request->user();
            $admin->load('role.permissions');

            $locale = $request->input('language', app()->getLocale());
            $message = $locale === 'ar'
                ? 'تم استرجاع بيانات الحساب بنجاح'
                : 'Account details retrieved successfully';

            return successResponse(
                new AdminResource($admin),
                $message
            );

        } catch (\Exception $e) {
            $locale = $request->input('language', app()->getLocale());
            $message = $locale === 'ar'
                ? 'فشل في استرجاع بيانات الحساب'
                : 'Failed to retrieve account details';

            return ErrorResponse::make($message, null, 500);
        }
    }

    /**
     * Update current admin account
     */
    public function update(Request $request): JsonResponse
    {
        $admin = $request->user();
        $locale = $request->input('language', app()->getLocale());

        $validator = Validator::make($request->all(), [
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'image_base64' => ['nullable', 'string'], // Support base64 images
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:admins,email,'.$admin->id],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:admins,phone,'.$admin->id],
            'password' => ['nullable', 'string', 'min:6'],
            'confirm_password' => ['nullable', 'required_with:password', 'same:password'],
        ], [
            'email.unique' => $locale === 'ar' ? 'البريد الإلكتروني مستخدم بالفعل' : 'Email already taken',
            'phone.unique' => $locale === 'ar' ? 'رقم الهاتف مستخدم بالفعل' : 'Phone already taken',
            'phone.regex' => $locale === 'ar' ? 'رقم الهاتف غير صالح' : 'Invalid phone number',
            'password.min' => $locale === 'ar' ? 'كلمة المرور يجب أن تكون 6 أحرف على الأقل' : 'Password must be at least 6 characters',
            'confirm_password.same' => $locale === 'ar' ? 'كلمة المرور غير متطابقة' : 'Password confirmation does not match',
            'image.max' => $locale === 'ar' ? 'حجم الصورة يجب أن لا يتجاوز 2 ميجابايت' : 'Image size must not exceed 2MB',
        ]);

        if ($validator->fails()) {
            $message = $locale === 'ar' ? 'فشل التحقق من البيانات' : 'Validation failed';

            return ErrorResponse::make($message, $validator->errors(), 422);
        }

        try {
            $data = [];

            // Handle file upload
            if ($request->hasFile('image')) {
                $imagePath = $this->uploadService->uploadFile($request->file('image'), 'admins', $admin->image);
                $data['image'] = $imagePath;
            }
            // Handle base64 image
            elseif ($request->filled('image_base64')) {
                $imagePath = $this->uploadService->uploadBase64Image($request->image_base64, 'admins', $admin->image);
                $data['image'] = $imagePath;
            }

            if ($request->filled('name')) {
                $data['name'] = $request->name;
            }

            if ($request->filled('email')) {
                $data['email'] = $request->email;
            }

            if ($request->filled('phone')) {
                $data['phone'] = $request->phone;
            }

            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }

            $admin->update($data);
            $admin->load('role.permissions');

            $message = $locale === 'ar'
                ? 'تم تحديث الحساب بنجاح'
                : 'Account updated successfully';

            return successResponse(
                new AdminResource($admin->fresh()),
                $message
            );

        } catch (\Exception $e) {
            $message = $locale === 'ar'
                ? 'فشل في تحديث الحساب'
                : 'Failed to update account';

            return ErrorResponse::make($message, null, 500);
        }
    }

    /**
     * Change password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $admin = $request->user();
        $locale = $request->input('language', app()->getLocale());

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6'],
            'confirm_password' => ['required', 'same:new_password'],
        ], [
            'current_password.required' => $locale === 'ar' ? 'كلمة المرور الحالية مطلوبة' : 'Current password is required',
            'new_password.required' => $locale === 'ar' ? 'كلمة المرور الجديدة مطلوبة' : 'New password is required',
            'new_password.min' => $locale === 'ar' ? 'كلمة المرور يجب أن تكون 6 أحرف على الأقل' : 'Password must be at least 6 characters',
            'confirm_password.same' => $locale === 'ar' ? 'كلمة المرور غير متطابقة' : 'Password confirmation does not match',
        ]);

        if ($validator->fails()) {
            $message = $locale === 'ar' ? 'فشل التحقق من البيانات' : 'Validation failed';

            return ErrorResponse::make($message, $validator->errors(), 422);
        }

        try {
            // Check current password
            if (! Hash::check($request->current_password, $admin->password)) {
                $message = $locale === 'ar'
                    ? 'كلمة المرور الحالية غير صحيحة'
                    : 'Current password is incorrect';

                return ErrorResponse::make($message, null, 400);
            }

            // Update password
            $admin->update([
                'password' => Hash::make($request->new_password),
            ]);

            $message = $locale === 'ar'
                ? 'تم تغيير كلمة المرور بنجاح'
                : 'Password changed successfully';

            return successResponse(null, $message);

        } catch (\Exception $e) {
            $message = $locale === 'ar'
                ? 'فشل في تغيير كلمة المرور'
                : 'Failed to change password';

            return ErrorResponse::make($message, null, 500);
        }
    }
}
