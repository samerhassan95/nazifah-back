<?php

namespace Modules\Driver\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\DeewanSmsService;
use App\Services\UploadFilesService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverAuthSession;

class AuthController extends Controller
{
    protected $uploadFilesService;

    protected $deewanSms;

    public function __construct(UploadFilesService $uploadFilesService, DeewanSmsService $deewanSms)
    {
        $this->uploadFilesService = $uploadFilesService;
        $this->deewanSms = $deewanSms;
    }

    /**
     * Login driver by phone number (sends OTP and returns session_key)
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Check if driver exists with this phone
            $driver = Driver::where('phone', $request->phone)->first();
            if (! $driver) {
                return errorResponse(
                    __('auth.account_not_found'),
                    [
                        'is_new_user' => true,
                        'message' => 'You do not have an account. Please contact admin to create your account.',
                    ],
                    404
                );
            }

            $session = DriverAuthSession::createForPhone($request->phone);

            if (! $session->canSendOtp()) {
                $secondsRemaining = $session->getSecondsUntilReset();

                return errorResponse(
                    __('auth.rate_limit_exceeded'),
                    [
                        'retry_after' => $secondsRemaining,
                        'message' => __('auth.rate_limit_message', ['seconds' => $secondsRemaining]),
                    ],
                    429
                );
            }

            $otp = $session->generateOtp();
            $isNewUser = ! $session->driver_id;

            if (config('deewan.otp_channel') === 'sms') {
                $this->deewanSms->sendOtp($request->phone, $otp, 'login', app()->getLocale());
            }

            if (! app()->environment('production')) {
            } else {

            }

            return successResponse([
                'session_key' => $session->session_key,
                'phone' => $request->phone,
                'is_new_user' => $isNewUser,
                'remaining_attempts' => $session->getRemainingAttempts(),
                'message' => __('auth.otp_sent_message'),
            ], __('auth.otp_sent_successfully'));

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Rate limit exceeded')) {
                return errorResponse(__('auth.rate_limit_exceeded'), null, 429);
            }

            return errorResponse(__('auth.send_otp_failed'), null, 500);
        }
    }

    /**
     * Register new driver (sends OTP and returns session_key)
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:drivers,phone'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Check if driver already exists
            $existingDriver = Driver::where('phone', $request->phone)->first();
            if ($existingDriver) {
                return errorResponse('Phone number already registered. Please login instead.', null, 400);
            }

            $registrationData = [
                'full_name' => $request->full_name,
            ];

            $session = DriverAuthSession::createForPhone($request->phone, $registrationData);

            if (! $session->canSendOtp()) {
                $secondsRemaining = $session->getSecondsUntilReset();

                return errorResponse(
                    __('auth.rate_limit_exceeded'),
                    [
                        'retry_after' => $secondsRemaining,
                        'message' => __('auth.rate_limit_message', ['seconds' => $secondsRemaining]),
                    ],
                    429
                );
            }

            $otp = $session->generateOtp();
            $isNewUser = ! $session->driver_id;

            if (config('deewan.otp_channel') === 'sms') {
                $this->deewanSms->sendOtp($request->phone, $otp, 'register', app()->getLocale());
            }

            if (! app()->environment('production')) {
            } else {

            }

            return successResponse([
                'session_key' => $session->session_key,
                'phone' => $request->phone,
                'is_new_user' => $isNewUser,
                'remaining_attempts' => $session->getRemainingAttempts(),
                'message' => __('auth.otp_sent_message'),
            ], __('auth.otp_sent_successfully'), 201);

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Rate limit exceeded')) {
                return errorResponse(__('auth.rate_limit_exceeded'), null, 429);
            }

            return errorResponse(__('auth.send_otp_failed'), null, 500);
        }
    }

    /**
     * Verify OTP using session_key
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_key' => ['required', 'string', 'size:64'],
            'otp_code' => ['required', 'string', 'size:5'],
            'fcm_token' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string'],
            'device_type' => ['nullable', 'string', 'in:android,ios,web'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Find the session - only non-verified sessions
            $session = DriverAuthSession::where('session_key', $request->session_key)
                ->first();

            if (! $session) {
                return notFoundResponse(__('auth.invalid_session'));
            }

            if (! $session->isValid()) {
                return errorResponse(__('auth.session_expired'), null, 400);
            }

            if (! $session->verifyOtp($request->otp_code)) {
                return unauthorizedResponse(__('auth.invalid_otp'));
            }

            // Mark session as verified (single-use)
            $session->update(['otp_code' => null]);

            // Check if driver exists
            $driver = Driver::where('phone', $session->phone)->first();

            // If driver doesn't exist and we have registration data, create the driver
            if (! $driver && $session->full_name) {
                $driver = Driver::create([
                    'phone' => $session->phone,
                    'full_name' => [
                        'ar' => $session->full_name,
                        'en' => $session->full_name,
                    ],
                    'email' => $session->email,
                    'is_available' => false,
                ]);

                // Update session with driver_id
                $session->update(['driver_id' => $driver->id]);
            } elseif (! $driver) {
                return notFoundResponse('Driver not found. Please register first.');
            }

            // Check if driver is banned
            if ($driver->is_banned) {
                return ErrorResponse::make(
                    __('auth.account_banned'),
                    [
                        'is_banned' => true,
                        'ban_reason' => $driver->ban_reason,
                        'banned_at' => $driver->banned_at?->toDateTimeString(),
                    ],
                    403
                );
            }

            // Check if driver exists
            $driver = Driver::where('phone', $session->phone)->first();

            // Add or update FCM token if provided
            if ($request->filled('fcm_token')) {
                $driver->addFcmToken(
                    $request->fcm_token,
                    $request->device_id,
                    $request->device_type,
                    $request->input('lang')
                );
            }

            // Generate auth token
            $token = $driver->createToken('driver_auth_token')->plainTextToken;

            return successResponse([
                'driver' => [
                    'id' => $driver->id,
                    'phone' => $driver->phone,
                    'full_name' => $driver->full_name,
                    'email' => $driver->email,
                    'image' => $this->uploadFilesService->getFullUrl($driver->image),
                    'is_available' => $driver->is_available,
                    'is_banned' => (bool) ($driver->is_banned ?? false),
                    'ban_reason' => $driver->ban_reason,
                    'banned_at' => $driver->banned_at?->toDateTimeString(),
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ], __('auth.login_successful'));

        } catch (\Exception $e) {
            return errorResponse(__('auth.send_otp_failed'), null, 500);
        }
    }

    /**
     * Resend OTP using session_key
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_key' => ['required', 'string', 'size:64'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $session = DriverAuthSession::where('session_key', $request->session_key)
                ->first();

            if (! $session) {
                return notFoundResponse(__('auth.invalid_session'));
            }

            if (! $session->isValid()) {
                return errorResponse(__('auth.session_expired'), null, 400);
            }

            if (! $session->canSendOtp()) {
                $secondsRemaining = $session->getSecondsUntilReset();

                return errorResponse(
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
                $this->deewanSms->sendOtp($session->phone, $otp, 'verify_account', app()->getLocale());
            }

            if (! app()->environment('production')) {
            } else {

            }

            return successResponse([
                'session_key' => $session->session_key,
                'phone' => $session->phone,
                'remaining_attempts' => $session->getRemainingAttempts(),
                'message' => __('auth.otp_resent_message'),
            ], __('auth.otp_resent_successfully'));

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Rate limit exceeded')) {
                return errorResponse(__('auth.rate_limit_exceeded'), null, 429);
            }

            return errorResponse(__('auth.send_otp_failed'), null, 500);
        }
    }

    /**
     * Create/Complete driver profile
     */
    public function createProfile(Request $request): JsonResponse
    {
        $driver = $request->user();

        $validator = Validator::make($request->all(), [
            'full_name' => ['nullable', 'array'],
            'full_name.ar' => ['nullable', 'string', 'max:255'],
            'full_name.en' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:drivers,email,'.$driver->id],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $data = [];

        if ($request->has('full_name')) {
            if (is_array($request->full_name)) {
                $data['full_name'] = [
                    'ar' => $request->full_name['ar'] ?? $request->full_name['en'] ?? '',
                    'en' => $request->full_name['en'] ?? $request->full_name['ar'] ?? '',
                ];
            } else {
                $data['full_name'] = [
                    'ar' => $request->full_name,
                    'en' => $request->full_name,
                ];
            }
        }

        if ($request->has('email')) {
            $data['email'] = $request->email;
        }

        // Handle coordinates - only update if provided
        if ($request->has('latitude')) {
            $data['latitude'] = $request->latitude;
        }

        if ($request->has('longitude')) {
            $data['longitude'] = $request->longitude;
        }

        if ($request->has('is_available')) {
            $data['is_available'] = $request->boolean('is_available');
        }

        if ($request->hasFile('image')) {
            if ($driver->image) {
                $this->uploadFilesService->deleteFile($driver->image);
            }

            $data['image'] = $this->uploadFilesService->uploadImage($request->file('image'), 'drivers/profiles');
        }

        $driver->update($data);
        $driver->refresh();

        return successResponse([
            'id' => $driver->id,
            'full_name_local' => $driver->full_name,
            'full_name' => method_exists($driver, 'getTranslations')
                ? $driver->getTranslations('full_name')
                : ($driver->full_name ?? []),
            'email' => $driver->email,
            'phone' => $driver->phone,
            'image' => $this->uploadFilesService->getFullUrl($driver->image),
            'location' => [
                'latitude' => $driver->latitude ? (float) $driver->latitude : null,
                'longitude' => $driver->longitude ? (float) $driver->longitude : null,
            ],
        ], 'Profile created successfully');
    }

    /**
     * Get driver profile
     */
    public function me(Request $request): JsonResponse
    {
        $driver = $request->user();

        return successResponse([
            'driver' => [
                'id' => $driver->id,
                'full_name' => $driver->full_name,
                'email' => $driver->email,
                'phone' => $driver->phone,
                'image' => $this->uploadFilesService->getFullUrl($driver->image),
                'rating' => $driver->rating,
                'total_orders' => $driver->total_orders,
                'is_available' => $driver->is_available,
            ],
        ], 'Driver profile retrieved successfully');
    }

    /**
     * Logout driver
     */
    public function updateFcmToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fcm_token' => ['required', 'string'],
            'device_type' => ['required', 'string', 'in:android,ios,web'],
            'device_id' => ['required', 'string', 'max:255'],
            'lang' => ['required', 'string', 'in:ar,en'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $driver = $request->user();
        $driver->addFcmToken(
            $request->input('fcm_token'),
            $request->input('device_id'),
            $request->input('device_type'),
            $request->input('lang')
        );

        return successResponse(null, __('notification.fcm_token_updated'));
    }

    public function updateLang(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => ['required', 'string', 'max:255'],
            'lang' => ['required', 'string', 'in:ar,en'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $driver = $request->user();
        $fcmToken = $driver->updateFcmTokenLang(
            $request->input('device_id'),
            $request->input('lang')
        );

        if (! $fcmToken) {
            return notFoundResponse(__('notification.fcm_device_not_found'));
        }

        return successResponse([
            'device_id' => $fcmToken->device_id,
            'lang' => $fcmToken->lang,
        ], __('notification.lang_updated'));
    }

    /**
     * Logout driver
     */
    public function logout(Request $request): JsonResponse
    {
        $driver = $request->user();
        $driver->update(['is_available' => false]);
        $request->user()->currentAccessToken()->delete();

        return successResponse(null, __('auth.logout_successful'));
    }

    /**
     * Register fingerprint for authenticated driver (same flow as customer).
     */
    public function registerFingerprint(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fingerprint' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make(__('auth.validation_error'), $validator->errors(), 422);
        }

        try {
            $driver = $request->user();
            $driver->update(['fingerprint' => $request->fingerprint]);

            return successResponse([
                'driver' => [
                    'id' => $driver->id,
                    'phone' => $driver->phone,
                    'full_name' => $driver->full_name,
                    'has_fingerprint' => ! empty($driver->fingerprint),
                ],
            ], __('auth.fingerprint_registered'));
        } catch (Exception $e) {
            return serverErrorResponse(__('auth.fingerprint_login_failed'));
        }
    }

    /**
     * Remove fingerprint from account (fingerprint logout / unregister biometric).
     */
    public function removeFingerprint(Request $request): JsonResponse
    {
        try {
            $driver = $request->user();
            $driver->update(['fingerprint' => null]);

            return successResponse([
                'driver' => [
                    'id' => $driver->id,
                    'phone' => $driver->phone,
                    'has_fingerprint' => false,
                ],
            ], __('auth.fingerprint_removed'));
        } catch (Exception $e) {
            return serverErrorResponse(__('auth.fingerprint_login_failed'));
        }
    }

    /**
     * Login with fingerprint (returns token like verify-otp).
     */
    public function loginWithFingerprint(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'fingerprint' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make(__('auth.validation_error'), $validator->errors(), 422);
        }

        try {
            $driver = Driver::where('phone', $request->phone)->first();

            if (! $driver) {
                return ErrorResponse::make(__('auth.user_not_found'), null, 404);
            }

            if ($driver->is_banned) {
                return ErrorResponse::make(
                    __('auth.account_banned'),
                    [
                        'is_banned' => true,
                        'ban_reason' => $driver->ban_reason,
                        'banned_at' => $driver->banned_at?->toDateTimeString(),
                    ],
                    403
                );
            }

            if (empty($driver->fingerprint)) {
                return ErrorResponse::make(__('auth.fingerprint_not_registered'), null, 400);
            }

            if ($driver->fingerprint !== $request->fingerprint) {
                return ErrorResponse::make(__('auth.invalid_fingerprint'), null, 401);
            }

            if ($request->filled('fcm_token')) {
                $driver->addFcmToken(
                    $request->fcm_token,
                    $request->device_id,
                    $request->device_type,
                    $request->input('lang')
                );
            }

            $token = $driver->createToken('driver_auth_token')->plainTextToken;

            return successResponse([
                'driver' => [
                    'id' => $driver->id,
                    'phone' => $driver->phone,
                    'full_name' => $driver->full_name,
                    'email' => $driver->email,
                    'image' => $this->uploadFilesService->getFullUrl($driver->image),
                    'is_available' => $driver->is_available,
                    'is_banned' => (bool) ($driver->is_banned ?? false),
                    'ban_reason' => $driver->ban_reason,
                    'banned_at' => $driver->banned_at?->toDateTimeString(),
                    'has_fingerprint' => true,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ], __('auth.login_successful'));
        } catch (Exception $e) {
            if (! app()->environment('production')) {
                return serverErrorResponse(__('auth.fingerprint_login_failed').': '.$e->getMessage());
            }

            return serverErrorResponse(__('auth.fingerprint_login_failed'));
        }
    }
}
