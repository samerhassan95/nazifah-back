<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\DeewanSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Http\Resources\AdminResource;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\AdminAuthSession;

class AuthController extends Controller
{
    /**
     * Admin login with email/phone and password
     */
    public function login(Request $request): JsonResponse
    {
        // Accept either 'login' or 'phone' field for backward compatibility
        $loginField = $request->has('login') ? 'login' : 'phone';

        $validator = Validator::make($request->all(), [
            $loginField => ['required', 'string'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make(__('admin::auth.validation_error'), $validator->errors(), 422);
        }

        try {
            $loginValue = $request->input($loginField);

            // Determine if login value is email or phone
            $isEmail = filter_var($loginValue, FILTER_VALIDATE_EMAIL);

            // Query admin by email or phone
            if ($isEmail) {
                $admin = Admin::where('email', $loginValue)->first();
            } else {
                // Remove any non-numeric characters for phone validation
                $phone = preg_replace('/[^0-9]/', '', $loginValue);
                $admin = Admin::where('phone', $phone)->first();
            }

            if (! $admin || ! Hash::check($request->password, $admin->password)) {
                return ErrorResponse::make(__('admin::auth.failed'), null, 401);
            }

            if (! $admin->is_verified) {
                return ErrorResponse::make(__('admin::auth.not_verified'), null, 403);
            }

            // Revoke old tokens
            // $admin->tokens()->delete();

            // Create new token
            $token = $admin->createToken('admin-token')->plainTextToken;

            return successResponse([
                'admin' => new AdminResource($admin),
                'token' => $token,
            ], __('admin::auth.login_successful'));

        } catch (\Exception $e) {
            return ErrorResponse::make(__('admin::auth.login_failed'), null, 500);
        }
    }

    /**
     * Get authenticated admin details
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $admin = $request->user();

            return successResponse([
                'admin' => new AdminResource($admin),
            ], __('admin::auth.profile_retrieved'));

        } catch (\Exception $e) {
            return ErrorResponse::make(__('admin::auth.profile_failed'), null, 500);
        }
    }

    /**
     * Admin logout (revoke token)
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $admin = $request->user();

            if (! $admin) {
                return ErrorResponse::make(__('admin::auth.unauthorized'), null, 401);
            }

            // Only delete the current token (not all tokens)
            $currentToken = $admin->currentAccessToken();

            if ($currentToken) {
                $currentToken->delete();
            }

            return successResponse(null, __('admin::auth.logout_successful'));

        } catch (\Exception $e) {
            return ErrorResponse::make(__('admin::auth.logout_failed'), null, 500);
        }
    }

    /**
     * Change admin password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make(__('admin::auth.validation_error'), $validator->errors(), 422);
        }

        try {
            $admin = $request->user();

            if (! Hash::check($request->current_password, $admin->password)) {
                return ErrorResponse::make(__('admin::auth.current_password_incorrect'), null, 422);
            }

            $admin->update([
                'password' => Hash::make($request->new_password),
            ]);

            // Revoke all tokens
            $admin->tokens()->delete();

            // Create new token
            $token = $admin->createToken('admin-token')->plainTextToken;

            return successResponse([
                'token' => $token,
                'token_type' => 'Bearer',
            ], __('admin::auth.password_changed'));

        } catch (\Exception $e) {
            return ErrorResponse::make(__('admin::auth.password_change_failed'), null, 500);
        }
    }

    /**
     * Send OTP to admin phone for password reset (returns session_key)
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make(__('admin::auth.validation_error'), $validator->errors(), 422);
        }

        try {
            $admin = Admin::where('phone', $request->phone)->first();

            if (! $admin) {
                return ErrorResponse::make(__('admin::auth.admin_not_found'), null, 404);
            }

            $session = AdminAuthSession::createForPhone($request->phone);

            if (! $session->canSendOtp()) {
                $secondsRemaining = $session->getSecondsUntilReset();

                return ErrorResponse::make(
                    __('auth.rate_limit_exceeded'),
                    [
                        'retry_after' => $secondsRemaining,
                        'message' => __('auth.rate_limit_message', ['seconds' => $secondsRemaining]),
                    ],
                    429
                );
            }

            $otp = $session->generateOtp();

            if (config('deewan.otp_channel') === 'sms') {
                app(DeewanSmsService::class)->sendOtp($request->phone, $otp, 'forgot_password', app()->getLocale());
            }

            if (! app()->environment('production')) {
            }

            return successResponse([
                'session_key' => $session->session_key,
                'phone' => $request->phone,
                'remaining_attempts' => $session->getRemainingAttempts(),
                'message' => __('admin::auth.otp_message'),
                'expires_in' => 10, // minutes
            ], __('admin::auth.otp_sent'));

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Rate limit exceeded')) {
                return ErrorResponse::make(__('auth.rate_limit_exceeded'), null, 429);
            }

            return ErrorResponse::make(__('admin::auth.otp_send_failed'), null, 500);
        }
    }

    /**
     * Reset password using session_key and OTP (combined verify + reset)
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_key' => ['required', 'string', 'size:64'],
            'otp_code' => ['required', 'string', 'size:5'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'fcm_token' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string'],
            'device_type' => ['nullable', 'string', 'in:android,ios,web'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make(__('admin::auth.validation_error'), $validator->errors(), 422);
        }

        try {
            $session = AdminAuthSession::where('session_key', $request->session_key)->first();

            if (! $session) {
                return ErrorResponse::make(__('auth.invalid_session'), null, 404);
            }

            if (! $session->isValid()) {
                return ErrorResponse::make(__('auth.session_expired'), null, 400);
            }

            if (! $session->verifyOtp($request->otp_code)) {
                return ErrorResponse::make(__('admin::auth.invalid_otp'), null, 422);
            }

            $admin = Admin::where('phone', $session->phone)->first();

            if (! $admin) {
                return ErrorResponse::make(__('admin::auth.admin_not_found'), null, 404);
            }

            // Update password
            $admin->update([
                'password' => Hash::make($request->password),
            ]);

            // Add or update FCM token if provided
            if ($request->filled('fcm_token')) {
                $admin->addFcmToken(
                    $request->fcm_token,
                    $request->device_id,
                    $request->device_type,
                    $request->input('lang')
                );
            }

            // Revoke all existing tokens
            $admin->tokens()->delete();

            // Create new login token
            $token = $admin->createToken('admin-token')->plainTextToken;

            return successResponse([
                'token' => $token,
                'token_type' => 'Bearer',
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'phone' => $admin->phone,
                ],
            ], __('admin::auth.password_reset'));

        } catch (\Exception $e) {
            return ErrorResponse::make(__('admin::auth.password_reset_failed'), null, 500);
        }
    }
}
