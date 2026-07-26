<?php

namespace Modules\Vendor\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VendorEmployeeAuthSession extends Model
{
    use Cacheable;

    protected $fillable = [
        'vendor_employee_id',
        'phone',
        'session_key',
        'otp_code',
        'otp_expires_at',
        'otp_attempts',
        'otp_attempts_reset_at',
        'expires_at',
        'is_verified',
        'name',
        'email',
        'vendor_id',
    ];

    protected $casts = [
        'otp_expires_at' => 'datetime',
        'otp_attempts_reset_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_verified' => 'boolean',
    ];

    protected $hidden = [
        'otp_code',
    ];

    const MAX_OTP_ATTEMPTS = 5;

    const OTP_RESET_MINUTES = 30;

    const SESSION_VALIDITY_MINUTES = 60;

    const OTP_VALIDITY_MINUTES = 10;

    /**
     * Get the vendor employee for this session
     */
    public function vendorEmployee()
    {
        return $this->belongsTo(VendorEmployee::class);
    }

    /**
     * Get the vendor for this session (for registration)
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Create or update a session for a phone number
     */
    public static function createForPhone(string $phone, ?array $registrationData = null): self
    {
        // Normalize phone number
        $phone = normalizePhone($phone);

        // Try to find an existing vendor employee with this phone
        $vendorEmployee = VendorEmployee::where('phone', $phone)->first();

        // Check for existing valid session
        $session = static::where('phone', $phone)
            ->where('expires_at', '>', now())
            ->first();

        if ($session) {
            // Update registration data if provided
            if ($registrationData) {
                $session->update([
                    'name' => $registrationData['name'] ?? $session->name,
                    'email' => $registrationData['email'] ?? $session->email,
                    'vendor_id' => $registrationData['vendor_id'] ?? $session->vendor_id,
                ]);
            }

            return $session;
        }

        // Create new session
        $sessionData = [
            'vendor_employee_id' => $vendorEmployee?->id,
            'phone' => $phone,
            'session_key' => Str::random(64),
            'expires_at' => now()->addMinutes(self::SESSION_VALIDITY_MINUTES),
            'otp_attempts' => 0,
        ];

        if ($registrationData) {
            $sessionData['name'] = $registrationData['name'] ?? null;
            $sessionData['email'] = $registrationData['email'] ?? null;
            $sessionData['vendor_id'] = $registrationData['vendor_id'] ?? null;
        }

        return static::create($sessionData);
    }

    /**
     * Check if session is valid (not expired)
     */
    public function isValid(): bool
    {
        return $this->expires_at && $this->expires_at->isFuture();
    }

    /**
     * Check if can send OTP (rate limiting)
     */
    public function canSendOtp(): bool
    {
        // Reset attempts if reset time has passed
        if ($this->otp_attempts_reset_at && $this->otp_attempts_reset_at->isPast()) {
            $this->update([
                'otp_attempts' => 0,
                'otp_attempts_reset_at' => null,
            ]);

            return true;
        }

        return $this->otp_attempts < self::MAX_OTP_ATTEMPTS;
    }

    /**
     * Generate OTP for this session
     */
    public function generateOtp(int $length = 5): string
    {
        if (! $this->canSendOtp()) {
            throw new \Exception('Rate limit exceeded. Please wait before requesting another OTP.');
        }

        // Generate OTP based on environment
        if (! app()->environment('production')) {
            // Non-production: always use 12345
            $otp = '12345';
        } else {
            // Production: generate random OTP
            $otp = str_pad((string) random_int(0, 99999), $length, '0', STR_PAD_LEFT);
        }

        $newAttempts = $this->otp_attempts + 1;
        $resetAt = $newAttempts >= self::MAX_OTP_ATTEMPTS
            ? now()->addMinutes(self::OTP_RESET_MINUTES)
            : null;

        $this->update([
            'otp_code' => $otp,
            'otp_expires_at' => now()->addMinutes(self::OTP_VALIDITY_MINUTES),
            'otp_attempts' => $newAttempts,
            'otp_attempts_reset_at' => $resetAt,
        ]);

        return $otp;
    }

    /**
     * Verify OTP
     */
    public function verifyOtp(string $code): bool
    {
        if (! $this->otp_expires_at || $this->otp_expires_at->isPast()) {
            return false;
        }

        if ($this->otp_code === $code) {
            $this->update([
                'otp_code' => null,
                'otp_expires_at' => null,
                'is_verified' => true,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get seconds until OTP attempts reset
     */
    public function getSecondsUntilReset(): int
    {
        if (! $this->otp_attempts_reset_at) {
            return 0;
        }

        return max(0, now()->diffInSeconds($this->otp_attempts_reset_at, false));
    }

    /**
     * Get remaining OTP attempts
     */
    public function getRemainingAttempts(): int
    {
        return max(0, self::MAX_OTP_ATTEMPTS - $this->otp_attempts);
    }
}
