<?php

namespace Modules\Client\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\DeewanSmsService;
use App\Services\UploadFilesService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Client\Models\AuthSession;
use Modules\Client\Models\Client;

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
     * Send OTP to phone number (creates session for both login and register)
     */
    public function sendOtp(Request $request): JsonResponse
    {
        // Step 1 — validate phone only first
        $phoneValidator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ], [
            'phone.required' => __('auth.phone_required'),
            'phone.regex' => __('auth.phone_invalid'),
        ]);

        if ($phoneValidator->fails()) {
            return validationErrorResponse($phoneValidator->errors());
        }

        // Step 2 — now we know the phone, check if new user
        $isNewUser = ! Client::where('phone', $request->phone)->exists();
        $isRegistrationAttempt = $request->has('full_name');

        // Step 3 — handle business logic for register vs login
        if ($isRegistrationAttempt && ! $isNewUser) {
            // Registering with an existing phone
            return ErrorResponse::make(__('auth.phone_already_registered'), null, 422);
        }

        if (! $isRegistrationAttempt && $isNewUser) {
            // Logging in with a non-existent phone
            return ErrorResponse::make(__('auth.account_not_found'), null, 404);
        }

        // Step 4 — if new user, validate full_name
        if ($isNewUser) {
            $nameValidator = Validator::make($request->all(), [
                'full_name' => ['required', 'string', 'max:255'],
            ], [
                'full_name.required' => __('auth.full_name_required_for_registration'),
            ]);

            if ($nameValidator->fails()) {
                $errors = $nameValidator->errors()->toArray();
                $errors['is_new_user'] = true;

                return validationErrorResponse($errors);
            }
        }

        // Step 4 — proceed normally
        try {
            $session = AuthSession::createForPhone($request->phone, $request->full_name);

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

            // Send OTP and capture the result
            $otpSent = false;
            if (config('deewan.otp_channel') === 'sms') {
                $otpSent = $this->deewanSms->sendOtp($request->phone, $otp, 'login', app()->getLocale());
            } else {
                // If SMS is disabled, consider it as sent for development
                $otpSent = true;
            }

            return successResponse([
                'send_otp' => $otpSent,
                'session_key' => $session->session_key,
                'phone' => $request->phone,
                'is_new_user' => $isNewUser,
                'remaining_attempts' => $session->getRemainingAttempts(),
                'message' => $otpSent ? __('auth.otp_sent_message') : __('auth.send_otp_failed_message'),
                'otp' => ! app()->environment('production') ? $otp : null,
            ], $otpSent ? __('auth.otp_sent_successfully') : __('auth.send_otp_failed'));

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Rate limit exceeded')) {
                return ErrorResponse::make(__('auth.rate_limit_exceeded'), null, 429);
            }

            return ErrorResponse::make(__('auth.send_otp_failed'), null, 500);
        }
    }

    /**
     * Verify OTP using session key
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
            return ErrorResponse::make(__('auth.validation_error'), $validator->errors(), 422);
        }

        try {
            // Find the session
            $session = AuthSession::where('session_key', $request->session_key)->first();

            if (! $session) {
                return ErrorResponse::make(__('auth.invalid_session'), null, 404);
            }

            // Check if session is expired
            if (! $session->isValid()) {
                return ErrorResponse::make(__('auth.session_expired'), null, 401);
            }

            // Verify OTP
            if (! $session->verifyOtp($request->otp_code)) {
                return ErrorResponse::make(__('auth.invalid_otp'), null, 401);
            }

            // Check if client exists in database by phone
            $client = Client::where('phone', $session->phone)->first();

            // Get full name from request or session
            $fullName = $session->name;

            if (! $client) {
                // Create new client (registration) - full_name must be available
                if (! $fullName) {
                    return ErrorResponse::make(
                        __('auth.validation_error'),
                        ['full_name' => [__('validation.required', ['attribute' => __('client.full_name') ?: 'full name'])]],
                        422
                    );
                }

                $client = Client::create([
                    'phone' => $session->phone,
                    'full_name' => $fullName,
                    'email' => null,
                    'is_verified' => true,
                ]);

                $session->update(['client_id' => $client->id]);
            } else {
                // Check if client is banned
                if ($client->is_banned) {
                    return ErrorResponse::make(
                        __('auth.account_banned'),
                        [
                            'is_banned' => true,
                            'ban_reason' => $client->ban_reason,
                            'banned_at' => $client->banned_at?->toDateTimeString(),
                        ],
                        403
                    );
                }

                // Update existing client
                $updateData = [];

                // Update name if provided (full_name is a string, not JSON)
                if ($fullName) {
                    $updateData['full_name'] = $fullName;
                }

                // Update verification status if needed
                if (! $client->is_verified) {
                    $updateData['is_verified'] = true;
                }

                if (! empty($updateData)) {
                    $client->update($updateData);
                }
            }

            // Add or update FCM token if provided
            if ($request->filled('fcm_token')) {
                $client->addFcmToken(
                    $request->fcm_token,
                    $request->device_id,
                    $request->device_type,
                    $request->input('lang')
                );
            }

            // Create authentication token
            $token = $client->createToken('user_auth_token')->plainTextToken;

            $zoneId = getZoneIdFromRequest($request);
            if ($zoneId === null && $client) {
                $defaultAddress = $client->defaultAddress();
                if ($defaultAddress && $defaultAddress->zone_id) {
                    $zoneId = (int) $defaultAddress->zone_id;
                }
            }

            return successResponse([
                'user' => [
                    'id' => $client->id,
                    'phone' => $client->phone,
                    'full_name' => $client->full_name,
                    'email' => $client->email,
                    'image' => $client->image, // Already has accessor that returns full URL
                    'is_verified' => $client->is_verified,
                    'is_banned' => (bool) ($client->is_banned ?? false),
                    'ban_reason' => $client->ban_reason,
                    'banned_at' => $client->banned_at?->toDateTimeString(),
                ],
                'session_key' => $session->session_key,
                'token' => $token,
                'token_type' => 'Bearer',
                'zone_id' => $zoneId,
            ], __('auth.login_successful'));

        } catch (Exception $e) {
            // In non-production, return the exception message to speed up debugging.
            if (! app()->environment('production')) {
                return serverErrorResponse(__('auth.otp_verify_failed').': '.$e->getMessage());
            }

            return serverErrorResponse(__('auth.otp_verification_failed'));
        }
    }

    /**
     * Resend OTP using session key
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_key' => ['required', 'string', 'size:64'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make(__('auth.validation_error'), $validator->errors(), 422);
        }

        try {
            // Find the session
            $session = AuthSession::where('session_key', $request->session_key)->first();

            if (! $session) {
                return ErrorResponse::make(__('auth.invalid_session'), null, 404);
            }

            // Check if session is expired
            if (! $session->isValid()) {
                return ErrorResponse::make(__('auth.session_expired'), null, 401);
            }

            // Check if rate limit allows sending OTP
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

            // Generate new OTP
            $otp = $session->generateOtp();

            if (config('deewan.otp_channel') === 'sms') {
                $this->deewanSms->sendOtp($session->phone, $otp, 'verify_account', app()->getLocale());
            }

            if (! app()->environment('production')) {
            } else {

            }

            // TODO: Send OTP via SMS service

            return successResponse([
                'session_key' => $session->session_key,
                'phone' => $session->phone,
                'remaining_attempts' => $session->getRemainingAttempts(),
                'message' => __('auth.otp_resent_message'),
            ], __('auth.otp_resent_successfully'));

        } catch (\Exception $e) {
            // Check if it's a rate limit exception
            if (str_contains($e->getMessage(), 'Rate limit exceeded')) {
                return ErrorResponse::make(__('auth.rate_limit_exceeded'), null, 429);
            }

            return ErrorResponse::make(__('auth.send_otp_failed'), null, 500);
        }
    }

    /**
     * Create/Complete user profile (with optional address)
     */
    public function createProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:clients,email,'.$user->id],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],

            // Optional address fields
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'address_label' => ['nullable', 'string', 'max:100'],
            'building_number' => ['nullable', 'string', 'max:50'],
            'street_name' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $data = [
            'full_name' => $request->full_name,
            'email' => $request->email,
        ];

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($user->image) {
                $this->uploadFilesService->deleteFile($user->image);
            }

            $data['image'] = $this->uploadFilesService->uploadImage($request->file('image'), 'clients/profiles');
        }

        $user->update($data);

        // Handle address creation/update if location data is provided
        $address = null;
        if ($request->has('latitude') && $request->has('longitude')) {
            // Find zone by coordinates
            $zone = \Modules\Zone\Models\Zone::findZoneByCoordinates(
                $request->latitude,
                $request->longitude
            );

            if (! $zone) {
                return errorResponse(__('zones.location_not_in_service_area'), null, 400);
            }

            // Get or create default address
            $address = $user->defaultAddress();

            $addressData = [
                'client_id' => $user->id,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'address_text' => $request->filled('address_text') ? $request->input('address_text') : null,
                'address_label' => $request->address_label ?? 'Home',
                'building_number' => $request->building_number,
                'street_name' => $request->street_name,
                'district' => $request->district,
                'city' => $request->city,
                'postal_code' => $request->postal_code,
                'zone_id' => $zone->id,
                'is_default' => true,
            ];

            if ($address) {
                // Update existing address
                $address->update($addressData);
            } else {
                // Create new address
                $address = \Modules\Address\Models\Address::create($addressData);
            }
        }

        // Prepare response with address data
        $addressData = [
            'text' => null,
            'latitude' => null,
            'longitude' => null,
        ];

        if ($address) {
            $addressData = [
                'id' => $address->id,
                'text' => $address->address_text,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
                'zone_id' => $address->zone_id,
                'zone_name' => $address->zone ? $address->zone->name : null,
            ];
        }

        return successResponse([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'image' => $user->image,
                'address' => $addressData,
                'is_banned' => (bool) ($user->is_banned ?? false),
                'ban_reason' => $user->ban_reason,
                'banned_at' => $user->banned_at?->toDateTimeString(),
            ],
        ], __('auth.profile_updated'));
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return successResponse(null, __('auth.logout_successful'));
    }

    /**
     * Delete user account
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Delete all auth sessions for this user
            AuthSession::where('client_id', $user->id)->delete();
            AuthSession::where('phone', $user->phone)->delete();

            // Delete all tokens
            $user->tokens()->delete();

            // Delete user's image if exists
            if ($user->image) {
                $this->uploadFilesService->deleteFile($user->image);
            }

            // Delete the user account
            $user->delete();

            return successResponse(null, __('auth.account_deleted'));

        } catch (Exception $e) {
            return serverErrorResponse(__('auth.failed_to_delete_account') ?: 'Failed to delete account. Please try again later.');
        }
    }

    /**
     * Register fingerprint for authenticated user
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
            $user = $request->user();

            // Update user's fingerprint
            $user->update([
                'fingerprint' => $request->fingerprint,
            ]);

            return successResponse([
                'user' => [
                    'id' => $user->id,
                    'phone' => $user->phone,
                    'full_name' => $user->full_name,
                    'has_fingerprint' => ! empty($user->fingerprint),
                ],
            ], __('auth.fingerprint_registered'));

        } catch (Exception $e) {
            return serverErrorResponse(__('auth.failed_to_register_fingerprint') ?: 'Failed to register fingerprint. Please try again later.');
        }
    }

    /**
     * Remove fingerprint from account (unregister biometric).
     */
    public function removeFingerprint(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $user->update(['fingerprint' => null]);

            return successResponse([
                'user' => [
                    'id' => $user->id,
                    'phone' => $user->phone,
                    'has_fingerprint' => false,
                ],
            ], __('auth.fingerprint_removed'));
        } catch (Exception $e) {
            return serverErrorResponse(__('auth.fingerprint_login_failed'));
        }
    }

    /**
     * Login with fingerprint (returns token like verify-otp)
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
            // Find client by phone
            $client = Client::where('phone', $request->phone)->first();

            if (! $client) {
                return ErrorResponse::make(__('auth.user_not_found'), null, 404);
            }

            // Check if client is banned
            if ($client->is_banned) {
                return ErrorResponse::make(
                    __('auth.account_banned'),
                    [
                        'is_banned' => true,
                        'ban_reason' => $client->ban_reason,
                        'banned_at' => $client->banned_at?->toDateTimeString(),
                    ],
                    403
                );
            }

            // Check if fingerprint is registered
            if (empty($client->fingerprint)) {
                return ErrorResponse::make(__('auth.fingerprint_not_registered'), null, 400);
            }

            // Verify fingerprint
            if ($client->fingerprint !== $request->fingerprint) {
                return ErrorResponse::make(__('auth.invalid_fingerprint'), null, 401);
            }

            // Create authentication token
            $token = $client->createToken('user_auth_token')->plainTextToken;

            return successResponse([
                'user' => [
                    'id' => $client->id,
                    'phone' => $client->phone,
                    'full_name' => $client->full_name,
                    'email' => $client->email,
                    'image' => $client->image,
                    'is_verified' => $client->is_verified,
                    'is_banned' => (bool) ($client->is_banned ?? false),
                    'ban_reason' => $client->ban_reason,
                    'banned_at' => $client->banned_at?->toDateTimeString(),
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
