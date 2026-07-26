<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeewanSmsService;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Vendor\Models\Vendor;
use Modules\Vendor\Models\VendorAuthSession;
use Modules\Vendor\Models\VendorEmployee;

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
     * Login vendor by phone number (sends OTP and returns session_key)
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
            $phone = normalizePhone($request->phone);

            $employee = VendorEmployee::findByPhone($phone);
            if (! $employee) {
                // No employee found - user needs to register first
                return errorResponse(
                    __('auth.account_not_found'),
                    [
                        'is_new_user' => true,
                        'message' => __('auth.account_not_found'),
                    ],
                    200
                );
            }

            if (! $employee->is_active) {
                return errorResponse(__('auth.account_not_active'), null, 403);
            }

            if ($employee->is_banned) {
                return errorResponse(
                    __('auth.account_banned'),
                    [
                        'is_banned' => true,
                        'ban_reason' => $employee->ban_reason,
                        'banned_at' => $employee->banned_at?->toDateTimeString(),
                    ],
                    403
                );
            }

            $session = VendorAuthSession::createForPhone($phone);

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
                $this->deewanSms->sendOtp($phone, $otp, 'login', app()->getLocale());
            }

            if (! app()->environment('production')) {
            } else {

            }

            return successResponse([
                'session_key' => $session->session_key,
                'phone' => $phone,
                'is_new_user' => false,
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
     * Register new vendor (sends OTP and returns session_key)
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'array'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:vendors,phone'],
            'email' => ['required', 'email', 'unique:vendors,email'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        // Ensure at least one name (ar or en) is provided
        if (empty($request->name['ar']) && empty($request->name['en'])) {
            return validationErrorResponse(['name' => ['At least one name (ar or en) is required']]);
        }

        try {
            $phone = normalizePhone($request->phone);

            $existingEmployee = VendorEmployee::findByPhone($phone);
            if ($existingEmployee) {
                return errorResponse('Phone number already registered. Please login instead.', null, 400);
            }

            $registrationData = [
                'name' => $request->name, // Store as array with ar and/or en
                'email' => $request->email,
                'vat_number' => $request->vat_number,
            ];

            $session = VendorAuthSession::createForPhone($phone, $registrationData);

            if (! $session->canSendOtp()) {
                $secondsRemaining = $session->getSecondsUntilReset();

                return errorResponse(
                    'Rate limit exceeded. Please wait before requesting another OTP.',
                    [
                        'retry_after' => $secondsRemaining,
                        'message' => "Please wait {$secondsRemaining} seconds before requesting another OTP",
                    ],
                    429
                );
            }

            $otp = $session->generateOtp();
            $isNewUser = ! $session->vendor_id;

            if (config('deewan.otp_channel') === 'sms') {
                $this->deewanSms->sendOtp($phone, $otp, 'register', app()->getLocale());
            }

            if (! app()->environment('production')) {
            } else {

            }

            return successResponse([
                'session_key' => $session->session_key,
                'phone' => $phone,
                'is_new_user' => $isNewUser,
                'remaining_attempts' => $session->getRemainingAttempts(),
                'message' => __('auth.otp_sent_message'),
            ], __('auth.otp_sent_successfully'), 201);

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Rate limit exceeded')) {
                return errorResponse(__('auth.rate_limit_exceeded'), null, 429);
            }

            return errorResponse('Failed to register. Please try again.', null, 500);
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
            $session = VendorAuthSession::where('session_key', $request->session_key)
                ->where('is_verified', false)
                ->first();

            if (! $session) {
                return notFoundResponse(__('auth.invalid_session'));
            }

            if (! $session->isValid()) {
                return unauthorizedResponse(__('auth.session_expired'));
            }

            return DB::transaction(function () use ($request, $session) {
                if (! $session->verifyOtp($request->otp_code)) {
                    return unauthorizedResponse(__('auth.invalid_otp'));
                }

                $vendor = $session->vendor;

                if (! $vendor) {
                    if (! $session->name || ! $session->email) {
                        return errorResponse(
                            __('auth.account_not_found'),
                            [
                                'is_new_user' => true,
                                'message' => __('auth.account_not_found'),
                            ],
                            404
                        );
                    }

                    $vendorName = $session->name;
                    $nameData = [];
                    if (is_array($vendorName)) {
                        if (isset($vendorName['ar'])) {
                            $nameData['ar'] = $vendorName['ar'];
                        }
                        if (isset($vendorName['en'])) {
                            $nameData['en'] = $vendorName['en'];
                        }
                    } else {
                        $nameData['ar'] = $vendorName;
                    }

                    $vendor = Vendor::create([
                        'phone' => $session->phone,
                        'name' => $nameData,
                        'email' => $session->email,
                        'vat_number' => $session->vat_number,
                        'is_active' => false,
                    ]);

                    $session->update(['vendor_id' => $vendor->id]);

                    $employee = $vendor->owner() ?? $vendor->createOwnerEmployee();
                } else {
                    if ($vendor->is_banned) {
                        return errorResponse(
                            __('auth.account_banned'),
                            [
                                'is_banned' => true,
                                'ban_reason' => $vendor->ban_reason,
                                'banned_at' => $vendor->banned_at?->toDateTimeString(),
                            ],
                            403
                        );
                    }

                    $employee = VendorEmployee::findByPhone($session->phone)
                        ?? $vendor->createOwnerEmployee();

                    if ($employee && $employee->vendor_id !== $vendor->id) {
                        return notFoundResponse(__('auth.employee_not_found'));
                    }
                }

                if (! $employee) {
                    return errorResponse('Unable to create or find employee account. Please contact support.', null, 500);
                }

                if (! $employee->is_active) {
                    return errorResponse(__('auth.account_not_active'), null, 403);
                }

                if ($employee->is_banned) {
                    return errorResponse(
                        __('auth.account_banned'),
                        [
                            'is_banned' => true,
                            'ban_reason' => $employee->ban_reason,
                            'banned_at' => $employee->banned_at?->toDateTimeString(),
                        ],
                        403
                    );
                }

                if (! $employee->is_verified) {
                    $employee->update(['is_verified' => true]);
                }

                if ($request->filled('fcm_token')) {
                    $employee->addFcmToken(
                        $request->fcm_token,
                        $request->device_id,
                        $request->device_type,
                        $request->input('lang')
                    );
                }

                $token = $employee->createToken('vendor_employee_auth_token')->plainTextToken;

                $session->update(['is_verified' => true]);

                $vendorName = $vendor->name;
                if (is_array($vendorName)) {
                    $locale = app()->getLocale();
                    $vendorName = $vendorName[$locale] ?? $vendorName['ar'] ?? $vendorName['en'] ?? '';
                }

                try {
                    $employee->load(['vendor', 'branch', 'vendorRole', 'branchAssignments.branch', 'branchAssignments.role']);
                } catch (\Throwable) {
                    $employee->load(['vendor', 'branch', 'vendorRole']);
                }

                return successResponse([
                    'id' => $employee->id,
                    'vendor_id' => $employee->vendor_id,
                    'branch_id' => $employee->branch_id,
                    'vendor_role_id' => $employee->vendor_role_id,
                    'phone' => $employee->phone,
                    'email' => $employee->email,
                    'name' => $employee->name,
                    'image' => $this->uploadFilesService->getFullUrl($employee->image),
                    'role' => $employee->getApiRoleName(),
                    'is_verified' => $employee->is_verified,
                    'is_active' => $employee->is_active,
                    'is_banned' => (bool) ($employee->is_banned ?? false),
                    'ban_reason' => $employee->ban_reason,
                    'banned_at' => $employee->banned_at?->toDateTimeString(),
                    'access' => $employee->getAccessPayload(),
                    'vendor' => [
                        'id' => $vendor->id,
                        'phone' => $vendor->phone,
                        'email' => $vendor->email,
                        'name' => $vendorName,
                        'logo' => $this->uploadFilesService->getFullUrl($vendor->logo),
                        'vat_number' => $vendor->vat_number,
                        'is_active' => $vendor->is_active,
                        'is_banned' => (bool) ($vendor->is_banned ?? false),
                        'ban_reason' => $vendor->ban_reason,
                        'banned_at' => $vendor->banned_at?->toDateTimeString(),
                    ],
                    'branch' => $employee->branch ? [
                        'id' => $employee->branch->id,
                        'name' => $employee->branch->name,
                        'is_active' => $employee->branch->is_active,
                    ] : null,
                    'token' => $token,
                ], __('auth.login_successful'));
            });
        } catch (\Throwable $e) {
            Log::error('Vendor verify OTP failed', [
                'session_key' => $request->session_key,
                'phone' => $session->phone ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (! app()->environment('production')) {
                return errorResponse('Failed to verify OTP. Please try again. '.$e->getMessage(), null, 500);
            }

            return errorResponse('Failed to verify OTP. Please try again.', null, 500);
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
            // Find the session
            $session = VendorAuthSession::where('session_key', $request->session_key)->first();

            if (! $session) {
                return notFoundResponse(__('auth.invalid_session'));
            }

            // Check if session is expired
            if (! $session->isValid()) {
                return unauthorizedResponse(__('auth.session_expired'));
            }

            // Check if rate limit allows sending OTP
            if (! $session->canSendOtp()) {
                $secondsRemaining = $session->getSecondsUntilReset();

                return errorResponse(
                    __('auth.rate_limit_exceeded'),
                    [
                        'retry_after' => $secondsRemaining,
                        'message' => "Please wait {$secondsRemaining} seconds before requesting another OTP",
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

            return successResponse([
                'session_key' => $session->session_key,
                'phone' => $session->phone,
                'remaining_attempts' => $session->getRemainingAttempts(),
                'message' => 'OTP resent to your phone number',
            ], 'OTP resent successfully');

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Rate limit exceeded')) {
                return errorResponse('Rate limit exceeded. Please wait before requesting another OTP.', null, 429);
            }

            return errorResponse('Failed to resend OTP. Please try again.', null, 500);
        }
    }

    /**
     * Get vendor profile (authenticated as VendorEmployee)
     */
    public function me(Request $request): JsonResponse
    {
        $employee = $request->user();
        $employee->load(['vendor', 'branch', 'vendorRole', 'branchAssignments.branch', 'branchAssignments.role']);
        $vendor = $employee->vendor;

        return successResponse([
            'id' => $employee->id,
            'vendor_id' => $employee->vendor_id,
            'branch_id' => $employee->branch_id,
            'vendor_role_id' => $employee->vendor_role_id,
            'name' => $employee->name,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'image' => $this->uploadFilesService->getFullUrl($employee->image),
            'role' => $employee->getApiRoleName(),
            'is_active' => $employee->is_active,
            'is_verified' => $employee->is_verified,
            'is_banned' => (bool) ($employee->is_banned ?? false),
            'ban_reason' => $employee->ban_reason,
            'banned_at' => $employee->banned_at?->toDateTimeString(),
            'access' => $employee->getAccessPayload(),
            'vendor' => $vendor ? [
                'id' => $vendor->id,
                'name' => [
                    'ar' => $vendor->getTranslation('name', 'ar'),
                    'en' => $vendor->getTranslation('name', 'en'),
                ],
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'logo' => $this->uploadFilesService->getFullUrl($vendor->logo),
                'is_banned' => (bool) ($vendor->is_banned ?? false),
                'ban_reason' => $vendor->ban_reason,
                'banned_at' => $vendor->banned_at?->toDateTimeString(),
            ] : null,
            'branch' => $employee->branch ? [
                'id' => $employee->branch->id,
                'name' => $employee->branch->name,
                'phone_number' => $employee->branch->phone_number,
                'location' => $employee->branch->location,
                'latitude' => $employee->branch->latitude,
                'longitude' => $employee->branch->longitude,
                'is_active' => $employee->branch->is_active,
            ] : null,
        ], 'Profile retrieved successfully');
    }

    /**
     * Get vendor profile only
     */
    public function getVendorProfile(Request $request): JsonResponse
    {
        try {
            $employee = $request->user();
            $vendor = $employee->vendor;

            if (! $vendor) {
                return notFoundResponse('Vendor not found');
            }

            // Format attachments
            $attachments = [];
            if ($vendor->attachments && is_array($vendor->attachments)) {
                $attachments = collect($vendor->attachments)->map(function ($attachment, $index) {
                    return [
                        'id' => $index + 1,
                        'type' => $attachment['type'] ?? $this->getFileType($attachment['url'] ?? ''),
                        'url' => $this->uploadFilesService->getFullUrl($attachment['url'] ?? $attachment),
                    ];
                })->values()->toArray();
            }

            return successResponse([
                'id' => $vendor->id,
                'name' => [
                    'ar' => $vendor->getTranslation('name', 'ar'),
                    'en' => $vendor->getTranslation('name', 'en'),
                ],
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'logo' => $this->uploadFilesService->getFullUrl($vendor->logo),
                'official_number' => $vendor->official_number,
                'vat_number' => $vendor->vat_number ?? 'لا يوجد',
                'delivery_price_per_km' => (float) ($vendor->delivery_price_per_km ?? 0),
                'wallet_balance' => (float) ($vendor->wallet_balance ?? 0),
                'is_active' => (bool) $vendor->is_active,
                'is_verified' => (bool) $vendor->is_verified,
                'is_banned' => (bool) ($vendor->is_banned ?? false),
                'ban_reason' => $vendor->ban_reason,
                'banned_at' => $vendor->banned_at?->toDateTimeString(),
                'attachments' => $attachments,
                'created_at' => $vendor->created_at?->toDateTimeString(),
                'updated_at' => $vendor->updated_at?->toDateTimeString(),
            ], 'Vendor profile retrieved successfully');
        } catch (\Exception $e) {
            return errorResponse('Error retrieving vendor profile: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Get file type from URL
     */
    private function getFileType(string $url): string
    {
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

        if (in_array($extension, $imageExtensions)) {
            return 'image';
        } elseif (in_array($extension, $documentExtensions)) {
            return $extension;
        }

        return 'file';
    }

    /**
     * Update vendor profile (authenticated as VendorEmployee)
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendor = $employee->vendor;

        if (! $vendor) {
            return errorResponse('Vendor not found', null, 404);
        }

        // Only owner or employees with edit_vendor_profile permission can update vendor profile
        if (! $employee->hasVendorPermission('edit_vendor_profile')) {
            return errorResponse('Only vendor owner can update vendor profile.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'nullable'],
            'email' => ['sometimes', 'email', 'unique:vendors,email,'.$vendor->id],
            'phone' => ['sometimes', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:vendors,phone,'.$vendor->id],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'mimes:jpeg,png,jpg,gif,webp,pdf,doc,docx', 'max:10240'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'official_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'delivery_price_per_km' => ['sometimes', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $data = [];

        if ($request->has('name')) {
            $name = $request->input('name');
            if (is_array($name)) {
                $data['name'] = $name;
            } else {
                $data['name'] = ['ar' => $name, 'en' => $name];
            }
        }

        if ($request->has('email')) {
            $data['email'] = $request->email;
        }

        if ($request->has('phone')) {
            $data['phone'] = $request->phone;
        }

        if ($request->has('vat_number')) {
            $data['vat_number'] = $request->vat_number;
        }

        if ($request->has('official_number')) {
            $data['official_number'] = $request->official_number;
        }

        if ($request->has('delivery_price_per_km')) {
            $data['delivery_price_per_km'] = $request->delivery_price_per_km;
        }

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $data['logo'] = $this->uploadFilesService->uploadLogo(
                $request->file('logo'),
                'vendors/logos',
                $vendor->logo
            );
        }

        // Handle attachments upload (attachments[0], attachments[1], etc.)
        if ($request->hasFile('attachments')) {
            $attachments = $request->file('attachments');
            $existingAttachments = $vendor->attachments ?? [];

            // Process each attachment
            foreach ($attachments as $index => $file) {
                // Check if it's an image or document
                $mimeType = $file->getMimeType();
                $extension = $file->getClientOriginalExtension();

                if (str_starts_with($mimeType, 'image/')) {
                    // Upload as image
                    $uploadedPath = $this->uploadFilesService->uploadImage(
                        $file,
                        'vendors/attachments'
                    );
                    $type = 'image';
                } else {
                    // Upload as document/file
                    $uploadedPath = $this->uploadFilesService->uploadFile(
                        $file,
                        'vendors/attachments'
                    );
                    $type = strtolower($extension);
                }

                // Add to attachments array
                $existingAttachments[] = [
                    'url' => $uploadedPath,
                    'type' => $type,
                ];
            }

            $data['attachments'] = $existingAttachments;
        }

        if (! empty($data)) {
            $vendor->update($data);

            // If phone was updated, also update the owner employee's phone
            if (isset($data['phone'])) {
                $owner = $vendor->owner();
                if ($owner) {
                    $owner->update(['phone' => $data['phone']]);
                }
            }

            $vendor->refresh();
            $vendor->load(['branches']);
        }

        // Format attachments for response
        $attachments = [];
        if ($vendor->attachments && is_array($vendor->attachments)) {
            $attachments = collect($vendor->attachments)->map(function ($attachment, $index) {
                return [
                    'id' => $index + 1,
                    'type' => $attachment['type'] ?? $this->getFileType($attachment['url'] ?? ''),
                    'url' => $this->uploadFilesService->getFullUrl($attachment['url'] ?? $attachment),
                ];
            })->values()->toArray();
        }

        return successResponse([
            'vendor' => [
                'id' => $vendor->id,
                'name' => [
                    'ar' => $vendor->getTranslation('name', 'ar'),
                    'en' => $vendor->getTranslation('name', 'en'),
                ],
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'logo' => $this->uploadFilesService->getFullUrl($vendor->logo),
                'vat_number' => $vendor->vat_number,
                'official_number' => $vendor->official_number,
                'delivery_price_per_km' => (float) ($vendor->delivery_price_per_km ?? 0),
                'wallet_balance' => (float) ($vendor->wallet_balance ?? 0),
                'is_active' => (bool) $vendor->is_active,
                'is_verified' => (bool) $vendor->is_verified,
                'is_banned' => (bool) ($vendor->is_banned ?? false),
                'ban_reason' => $vendor->ban_reason,
                'banned_at' => $vendor->banned_at?->toDateTimeString(),
                'attachments' => $attachments,

                'branches_count' => $vendor->branches->count(),
                'created_at' => $vendor->created_at?->toDateTimeString(),
                'updated_at' => $vendor->updated_at?->toDateTimeString(),
            ],
        ], 'Profile updated successfully');
    }

    /**
     * Update authenticated employee personal profile
     */
    public function updateEmployeeProfile(Request $request): JsonResponse
    {
        $employee = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:vendor_employees,email,'.$employee->id],
            'image' => ['sometimes', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $data = [];

        if ($request->has('name')) {
            $data['name'] = $request->name;
        }

        if ($request->has('email')) {
            $data['email'] = $request->email;
        }

        if ($request->hasFile('image')) {
            $data['image'] = $this->uploadFilesService->uploadLogo(
                $request->file('image'),
                'vendor_employees/images',
                $employee->image
            );
        }

        if (! empty($data)) {
            $employee->update($data);
            $employee->refresh();
        }

        $employee->load(['vendor', 'branch', 'vendorRole', 'branchAssignments.branch', 'branchAssignments.role']);

        return successResponse([
            'id' => $employee->id,
            'vendor_id' => $employee->vendor_id,
            'branch_id' => $employee->branch_id,
            'vendor_role_id' => $employee->vendor_role_id,
            'name' => $employee->name,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'image' => $this->uploadFilesService->getFullUrl($employee->image),
            'role' => $employee->getApiRoleName(),
            'is_active' => $employee->is_active,
            'is_verified' => $employee->is_verified,
            'is_banned' => (bool) ($employee->is_banned ?? false),
            'ban_reason' => $employee->ban_reason,
            'banned_at' => $employee->banned_at?->toDateTimeString(),
            'access' => $employee->getAccessPayload(),
        ], __('auth.profile_updated'));
    }

    /**
     * Logout vendor employee
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

        $employee = $request->user();
        $employee->addFcmToken(
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

        $employee = $request->user();
        $fcmToken = $employee->updateFcmTokenLang(
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
     * Logout vendor employee
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return successResponse(null, 'Logged out successfully');
    }
}
