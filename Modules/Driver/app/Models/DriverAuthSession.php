<?php

namespace Modules\Driver\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DriverAuthSession extends Model
{
    use Cacheable;

    protected $table = 'driver_auth_sessions';

    protected $fillable = [
        'session_key',
        'phone',
        'driver_id',
        'otp_code',
        'full_name',
        'email',
        'otp_expires_at',
        'otp_attempts',
        'rate_limit_reset_at',
        'expires_at',
    ];

    protected $hidden = [
        'otp_code',
        'otp_expires_at',
    ];

    protected $casts = [
        'otp_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'rate_limit_reset_at' => 'datetime',
        'otp_attempts' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const MAX_OTP_ATTEMPTS = 3;

    const RATE_LIMIT_DURATION = 60; // seconds

    /**
     * Get the driver that owns the session
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Generate a unique session key
     */
    public static function generateSessionKey(): string
    {
        do {
            $sessionKey = Str::random(64);
        } while (self::where('session_key', $sessionKey)->exists());

        return $sessionKey;
    }

    /**
     * Check if session can send OTP (rate limit check)
     */
    public function canSendOtp(): bool
    {
        // Refresh the model to get latest data
        $this->refresh();

        // If no rate limit set yet, can send
        if (! $this->rate_limit_reset_at) {
            return true;
        }

        // If rate limit period has passed, allow next batch
        if ($this->rate_limit_reset_at->isPast()) {
            return true;
        }

        // Check if attempts are within limit
        return $this->otp_attempts < self::MAX_OTP_ATTEMPTS;
    }

    /**
     * Get remaining OTP attempts
     */
    public function getRemainingAttempts(): int
    {
        // Refresh the model to get latest data
        $this->refresh();

        if (! $this->rate_limit_reset_at || $this->rate_limit_reset_at->isPast()) {
            return self::MAX_OTP_ATTEMPTS;
        }

        return max(0, self::MAX_OTP_ATTEMPTS - $this->otp_attempts);
    }

    /**
     * Get seconds until rate limit reset
     */
    public function getSecondsUntilReset(): int
    {
        // Refresh the model to get latest data
        $this->refresh();

        if (! $this->rate_limit_reset_at || $this->rate_limit_reset_at->isPast()) {
            return 0;
        }

        return max(0, now()->diffInSeconds($this->rate_limit_reset_at));
    }

    /**
     * Check if OTP is valid
     */
    public function isOtpValid(): bool
    {
        return $this->otp_expires_at && $this->otp_expires_at->isFuture();
    }

    /**
     * Check if session is valid
     */
    public function isValid(): bool
    {
        return $this->expires_at && $this->expires_at->isFuture();
    }

    /**
     * Generate and save OTP
     */
    public function generateOtp(int $length = 5): string
    {
        // Refresh to get latest state
        $this->refresh();

        // Check if rate limit period has passed - if so, reset the counter
        if ($this->rate_limit_reset_at && $this->rate_limit_reset_at->isPast()) {
            // Rate limit period ended, reset for new cycle
            $this->update([
                'otp_attempts' => 0,
                'rate_limit_reset_at' => null,
            ]);
            $this->refresh();
        }

        // Now check if we can send
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

        // Calculate new attempts and reset time
        if (! $this->rate_limit_reset_at) {
            // Starting a new rate limit period
            $attempts = 1;
            $resetAt = now()->addSeconds(self::RATE_LIMIT_DURATION);
        } else {
            // Within current rate limit period
            $attempts = $this->otp_attempts + 1;
            $resetAt = $this->rate_limit_reset_at;
        }

        $this->update([
            'otp_code' => $otp,
            'otp_expires_at' => now()->addMinutes(10),
            'otp_attempts' => $attempts,
            'rate_limit_reset_at' => $resetAt,
        ]);

        return $otp;
    }

    /**
     * Verify OTP and mark session as verified
     */
    public function verifyOtp(string $code): bool
    {
        if (! $this->isOtpValid()) {
            return false;
        }

        if ($this->otp_code === $code) {
            $this->update([
                'otp_code' => null,
                'otp_expires_at' => null,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Clean up expired sessions
     */
    public static function cleanupExpiredSessions(): int
    {
        return self::where('expires_at', '<', now())->delete();
    }

    /**
     * Create or get existing session for a phone number
     */
    public static function createForPhone(string $phone, ?array $registrationData = null): self
    {
        // Normalize phone number
        $phone = normalizePhone($phone);

        // First, check if there's an active unverified session for this phone
        $existingSession = self::where('phone', $phone)
            ->where('expires_at', '>', now())
            ->first();

        if ($existingSession) {
            // Update registration data if provided
            if ($registrationData !== null) {
                $existingSession->update($registrationData);
            }

            return $existingSession;
        }

        // Clean up old expired sessions for this phone
        self::where('phone', $phone)
            ->where('expires_at', '<=', now())
            ->delete();

        // Check if driver exists
        $driver = Driver::where('phone', $phone)->first();

        $sessionData = [
            'session_key' => self::generateSessionKey(),
            'phone' => $phone,
            'driver_id' => $driver ? $driver->id : null,
            'expires_at' => now()->addHours(24),
        ];

        if ($registrationData !== null) {
            $sessionData = array_merge($sessionData, $registrationData);
        }

        return self::create($sessionData);
    }
}
